<?php
/**
 * Quiz attempt routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Capabilities\Capabilities;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use PublicLMS\Repositories\QuizAttemptRepository;
use PublicLMS\Services\QuizService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes (ARCHITECTURE §4; graded on the server, ADR-016):
 *
 * - GET  /quizzes/{id}/attempts                    Own attempts, newest first; another learner's
 *                                                  (`user_id`) needs publiclms_view_course_reports.
 * - POST /quizzes/{id}/attempts                    Start an attempt, or resume the one in progress
 *                                                  (201 or 200). AccessPolicy::can_attempt_quiz;
 *                                                  rate-limited. Returns the questions without
 *                                                  answer keys.
 * - GET  /quizzes/{id}/attempts/{attempt}          One attempt: the questions while in progress, the
 *                                                  results once graded. Own, or report viewers.
 * - POST /quizzes/{id}/attempts/{attempt}/answers  Save answers without grading. Own attempt.
 * - POST /quizzes/{id}/attempts/{attempt}/submit   Grade and close. Own attempt; rate-limited.
 *
 * The quiz runner sends the REST nonce from Interactivity state.
 */
final class QuizAttemptsController extends AbstractController {

	/**
	 * Quiz service.
	 *
	 * @var QuizService
	 */
	private $quizzes;

	/**
	 * Attempts.
	 *
	 * @var QuizAttemptRepository
	 */
	private $attempts;

	/**
	 * Course structure.
	 *
	 * @var CourseStructureRepositoryInterface
	 */
	private $structure;

	/**
	 * Constructor.
	 *
	 * @param QuizService                        $quizzes   Quiz service.
	 * @param QuizAttemptRepository              $attempts  Attempts.
	 * @param CourseStructureRepositoryInterface $structure Course structure.
	 */
	public function __construct( QuizService $quizzes, QuizAttemptRepository $attempts, CourseStructureRepositoryInterface $structure ) {
		parent::__construct();
		$this->rest_base = 'quizzes';
		$this->quizzes   = $quizzes;
		$this->attempts  = $attempts;
		$this->structure = $structure;
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$answers = array(
			'description' => __( 'Answers keyed by question ID.', 'public-lms' ),
			'type'        => 'object',
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/attempts',
			array(
				'args' => array(
					'id' => $this->id_arg( __( 'Quiz ID.', 'public-lms' ) ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'user_id' => $this->id_arg( __( 'Learner whose attempts to list; defaults to the current user.', 'public-lms' ), false ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/attempts/(?P<attempt>[\d]+)',
			array(
				'args' => array(
					'id'      => $this->id_arg( __( 'Quiz ID.', 'public-lms' ) ),
					'attempt' => $this->id_arg( __( 'Attempt ID.', 'public-lms' ) ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/attempts/(?P<attempt>[\d]+)/answers',
			array(
				'args' => array(
					'id'      => $this->id_arg( __( 'Quiz ID.', 'public-lms' ) ),
					'attempt' => $this->id_arg( __( 'Attempt ID.', 'public-lms' ) ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_answers' ),
					'permission_callback' => array( $this, 'own_attempt_permissions_check' ),
					'args'                => array(
						'answers' => array_merge( $answers, array( 'required' => true ) ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/attempts/(?P<attempt>[\d]+)/submit',
			array(
				'args' => array(
					'id'      => $this->id_arg( __( 'Quiz ID.', 'public-lms' ) ),
					'attempt' => $this->id_arg( __( 'Attempt ID.', 'public-lms' ) ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit' ),
					'permission_callback' => array( $this, 'own_attempt_permissions_check' ),
					'args'                => array(
						'answers' => $answers,
					),
				),
			)
		);
	}

	/**
	 * Logged in; another learner's list needs the course's reports.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		$check = $this->quiz_check( $request );
		if ( true !== $check ) {
			return $check;
		}
		$user_id = isset( $request['user_id'] ) ? (int) $request['user_id'] : get_current_user_id();
		return get_current_user_id() === $user_id || $this->can_view_reports( (int) $request['id'] ) ? true : $this->forbidden();
	}

	/**
	 * Lists a learner's attempts.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$user_id = isset( $request['user_id'] ) ? (int) $request['user_id'] : get_current_user_id();
		$items   = array();
		foreach ( $this->attempts->attempts_for_user( $user_id, (int) $request['id'] ) as $attempt ) {
			$items[] = $this->quizzes->summary( $this->quizzes->expire_if_overdue( $attempt ) );
		}
		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) count( $items ) );
		return $response;
	}

	/**
	 * Logged in, and the quiz exists.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		return $this->quiz_check( $request );
	}

	/**
	 * Starts or resumes an attempt.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$result = $this->quizzes->start( get_current_user_id(), (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $this->with_retry_header( $result );
		}
		$response = rest_ensure_response( $this->quizzes->attempt_view( $result['attempt'] ) );
		$response->set_status( $result['resumed'] ? 200 : 201 );
		return $response;
	}

	/**
	 * Own attempt, or a report viewer of the quiz's course.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$check = $this->quiz_check( $request );
		if ( true !== $check ) {
			return $check;
		}
		$attempt = $this->attempts->find( (int) $request['attempt'] );
		if ( null === $attempt || (int) $attempt['quiz_id'] !== (int) $request['id'] ) {
			return $this->not_found();
		}
		if ( get_current_user_id() === (int) $attempt['user_id'] || $this->can_view_reports( (int) $request['id'] ) ) {
			return true;
		}
		return $this->not_found();
	}

	/**
	 * Returns an attempt: questions while in progress (to its owner), results once submitted.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$attempt = $this->attempts->find( (int) $request['attempt'] );
		if ( null === $attempt ) {
			return $this->not_found();
		}
		$attempt = $this->quizzes->expire_if_overdue( $attempt );
		if ( QuizAttemptRepository::STATUS_IN_PROGRESS === $attempt['status'] ) {
			return rest_ensure_response(
				get_current_user_id() === (int) $attempt['user_id']
					? $this->quizzes->attempt_view( $attempt )
					: $this->quizzes->summary( $attempt )
			);
		}
		return rest_ensure_response( $this->quizzes->result_view( $attempt ) );
	}

	/**
	 * The attempt belongs to the current user and to this quiz.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function own_attempt_permissions_check( $request ) {
		$check = $this->quiz_check( $request );
		if ( true !== $check ) {
			return $check;
		}
		$attempt = $this->attempts->find( (int) $request['attempt'] );
		if ( null === $attempt || (int) $attempt['quiz_id'] !== (int) $request['id'] || get_current_user_id() !== (int) $attempt['user_id'] ) {
			return $this->not_found();
		}
		return true;
	}

	/**
	 * Saves answers.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_answers( $request ) {
		$result = $this->quizzes->save_answers( get_current_user_id(), (int) $request['attempt'], (array) $request['answers'] );
		if ( is_wp_error( $result ) ) {
			return $this->with_retry_header( $result );
		}
		$deadline = $this->quizzes->deadline( $result );
		return rest_ensure_response(
			array(
				'saved'     => true,
				'time_left' => null === $deadline ? null : max( 0, $deadline - time() ),
			)
		);
	}

	/**
	 * Submits and grades an attempt.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit( $request ) {
		$answers = isset( $request['answers'] ) ? (array) $request['answers'] : null;
		$result  = $this->quizzes->submit( get_current_user_id(), (int) $request['attempt'], $answers );
		if ( is_wp_error( $result ) ) {
			return $this->with_retry_header( $result );
		}
		$data            = $this->quizzes->result_view( $result );
		$data['learner'] = $this->quizzes->learner_summary( get_current_user_id(), (int) $request['id'] );
		$data['message'] = ! empty( $result['passed'] )
			/* translators: %s: score as a percentage. */
			? sprintf( __( 'You passed with %s%%.', 'public-lms' ), number_format_i18n( (float) $result['percent'], 0 ) )
			/* translators: 1: score as a percentage, 2: pass mark. */
			: sprintf( __( 'You scored %1$s%%. The pass mark is %2$s%%.', 'public-lms' ), number_format_i18n( (float) $result['percent'], 0 ), number_format_i18n( (float) $data['pass_mark'], 0 ) );
		return rest_ensure_response( $data );
	}

	/**
	 * Logged in, and the route's quiz exists.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	private function quiz_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'publiclms_rest_logged_out', __( 'Please log in first.', 'public-lms' ), array( 'status' => 401 ) );
		}
		if ( PostTypes::QUIZ !== get_post_type( (int) $request['id'] ) ) {
			return $this->not_found();
		}
		return true;
	}

	/**
	 * Whether the current user may see others' attempts at a quiz.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return bool
	 */
	private function can_view_reports( int $quiz_id ): bool {
		$course_id = $this->structure->course_id_for( $quiz_id );
		return $course_id > 0
			? current_user_can( Capabilities::META_VIEW_COURSE_REPORTS, $course_id )
			: current_user_can( 'edit_post', $quiz_id );
	}

	/**
	 * Turns a rate-limit error into a 429 response with Retry-After.
	 *
	 * @param WP_Error $error Error.
	 * @return WP_REST_Response|WP_Error
	 */
	private function with_retry_header( WP_Error $error ) {
		$data = (array) $error->get_error_data();
		if ( empty( $data['retry_after'] ) ) {
			return $error;
		}
		$response = rest_convert_error_to_response( $error );
		$response->header( 'Retry-After', (string) (int) $data['retry_after'] );
		return $response;
	}
}
