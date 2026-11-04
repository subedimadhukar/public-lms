<?php
/**
 * Quiz routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Access\AccessPolicy;
use PublicLMS\PostTypes\Meta;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Quizzes\QuestionBank;
use PublicLMS\Quizzes\QuizSettings;
use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use PublicLMS\Services\ProgressService;
use PublicLMS\Services\QuizService;
use WP_Error;
use WP_Post;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /quizzes (see AbstractItemController for the shared ones).
 *
 * Quizzes add their settings (QuizSettings) and an ordered `questions` list
 * of question bank IDs, editable by whoever can edit the quiz's course and
 * the questions. Editors read the list with each question's type and points;
 * learners get only their own standing (`learner`): attempts used and left,
 * best score, whether they passed and whether a new attempt is allowed.
 * Neither ever gets an answer key from this route.
 */
final class QuizzesController extends AbstractItemController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'quizzes';

	/**
	 * Question bank.
	 *
	 * @var QuestionBank
	 */
	private $questions;

	/**
	 * Quiz service.
	 *
	 * @var QuizService
	 */
	private $quizzes;

	/**
	 * Constructor.
	 *
	 * @param CourseStructureRepositoryInterface $structure Course structure.
	 * @param AccessPolicy                       $policy    Access policy.
	 * @param ProgressService                    $progress  Progress.
	 * @param QuestionBank                       $questions Question bank.
	 * @param QuizService                        $quizzes   Quiz service.
	 */
	public function __construct( CourseStructureRepositoryInterface $structure, AccessPolicy $policy, ProgressService $progress, QuestionBank $questions, QuizService $quizzes ) {
		parent::__construct( $structure, $policy, $progress );
		$this->questions = $questions;
		$this->quizzes   = $quizzes;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function item_type(): string {
		return 'quiz';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function post_type(): string {
		return PostTypes::QUIZ;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function settings_args(): array {
		$integer = static function ( string $description, int $max ): array {
			return array(
				'description'       => $description,
				'type'              => 'integer',
				'minimum'           => 0,
				'maximum'           => $max,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			);
		};
		$boolean = static function ( string $description ): array {
			return array(
				'description'       => $description,
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'validate_callback' => 'rest_validate_request_arg',
			);
		};
		return array(
			'time_limit'        => $integer( __( 'Time limit in minutes; 0 for none.', 'public-lms' ), 1440 ),
			'max_attempts'      => $integer( __( 'Attempts allowed; 0 for unlimited.', 'public-lms' ), 100 ),
			'pass_mark'         => $integer( __( 'Percentage needed to pass.', 'public-lms' ), 100 ),
			'question_count'    => $integer( __( 'Questions drawn at random per attempt; 0 for all.', 'public-lms' ), 500 ),
			'shuffle_questions' => $boolean( __( 'Shuffle the question order for each attempt.', 'public-lms' ) ),
			'shuffle_answers'   => $boolean( __( 'Shuffle answer options for each attempt.', 'public-lms' ) ),
			'show_results'      => $boolean( __( 'Show right or wrong per question after submitting.', 'public-lms' ) ),
			'questions'         => array(
				'description'       => __( 'Question bank IDs, in order.', 'public-lms' ),
				'type'              => 'array',
				'items'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'maxItems'          => 500,
				'sanitize_callback' => array( Meta::class, 'sanitize_id_list' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                                   $item_id Quiz ID.
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	protected function apply_settings( int $item_id, $request ) {
		$values = array();
		foreach ( array_keys( QuizSettings::KEYS ) as $key ) {
			if ( isset( $request[ $key ] ) ) {
				$values[ $key ] = $request[ $key ];
			}
		}
		QuizSettings::update( $item_id, $values );

		if ( isset( $request['questions'] ) ) {
			$ids = Meta::sanitize_id_list( $request['questions'] );
			foreach ( $ids as $id ) {
				if ( PostTypes::QUESTION !== get_post_type( $id ) || 'trash' === get_post_status( $id ) ) {
					/* translators: %d: question ID. */
					return new WP_Error( 'rest_invalid_param', sprintf( __( 'Question %d is not in the question bank.', 'public-lms' ), $id ), array( 'status' => 400 ) );
				}
				if ( ! in_array( $id, QuizSettings::question_ids( $item_id ), true ) && ! current_user_can( 'edit_post', $id ) ) {
					return $this->forbidden();
				}
			}
			QuizSettings::set_question_ids( $item_id, $ids );
		}
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_Post $post     Quiz.
	 * @param bool    $can_edit Whether the current user can edit the quiz's course.
	 * @return array<string, mixed>
	 */
	protected function settings_data( WP_Post $post, bool $can_edit ): array {
		$quiz_id = (int) $post->ID;
		$data    = QuizSettings::get( $quiz_id );
		$ids     = QuizSettings::question_ids( $quiz_id );

		$data['question_total'] = $this->quizzes->question_total( $quiz_id );
		if ( $can_edit ) {
			$list = array();
			foreach ( $ids as $id ) {
				$question = $this->questions->get( $id );
				if ( null === $question ) {
					continue;
				}
				$list[] = array(
					'id'       => $id,
					'type'     => $question['type'],
					'title'    => $question['title'],
					'points'   => $question['points'],
					'status'   => $question['status'],
					'can_edit' => current_user_can( 'edit_post', $id ),
				);
			}
			$data['questions'] = $list;
		}
		$data['learner'] = $this->quizzes->learner_summary( get_current_user_id(), $quiz_id );
		return $data;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function settings_schema(): array {
		return array(
			'time_limit'        => array( 'type' => 'integer' ),
			'max_attempts'      => array( 'type' => 'integer' ),
			'pass_mark'         => array( 'type' => 'integer' ),
			'question_count'    => array( 'type' => 'integer' ),
			'shuffle_questions' => array( 'type' => 'boolean' ),
			'shuffle_answers'   => array( 'type' => 'boolean' ),
			'show_results'      => array( 'type' => 'boolean' ),
			'question_total'    => array(
				'description' => __( 'Questions an attempt shows.', 'public-lms' ),
				'type'        => 'integer',
				'readonly'    => true,
			),
			'questions'         => array(
				'description' => __( 'The question list, for editors only: ID, type, title and points.', 'public-lms' ),
				'type'        => 'array',
				'items'       => array( 'type' => 'object' ),
			),
			'learner'           => array(
				'description' => __( 'The current user\'s attempts, best score and whether a new attempt is allowed.', 'public-lms' ),
				'type'        => 'object',
				'readonly'    => true,
			),
		);
	}
}
