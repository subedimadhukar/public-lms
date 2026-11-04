<?php
/**
 * Assignment routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Access\AccessPolicy;
use PublicLMS\Assignments\AssignmentSettings;
use PublicLMS\Files\PrivateFiles;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use PublicLMS\Repositories\SubmissionRepository;
use PublicLMS\Services\AssignmentService;
use PublicLMS\Services\ProgressService;
use WP_Error;
use WP_Post;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /assignments (see AbstractItemController for the shared ones).
 *
 * Assignments add their settings (AssignmentSettings): points, pass grade,
 * typed answers, files per hand-in, size and types. Learners also get their
 * current hand-in and whether they may hand in now (`learner`).
 */
final class AssignmentsController extends AbstractItemController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'assignments';

	/**
	 * Assignment service.
	 *
	 * @var AssignmentService
	 */
	private $assignments;

	/**
	 * Hand-ins.
	 *
	 * @var SubmissionRepository
	 */
	private $submissions;

	/**
	 * Constructor.
	 *
	 * @param CourseStructureRepositoryInterface $structure   Course structure.
	 * @param AccessPolicy                       $policy      Access policy.
	 * @param ProgressService                    $progress    Progress.
	 * @param AssignmentService                  $assignments Assignment service.
	 * @param SubmissionRepository               $submissions Hand-ins.
	 */
	public function __construct( CourseStructureRepositoryInterface $structure, AccessPolicy $policy, ProgressService $progress, AssignmentService $assignments, SubmissionRepository $submissions ) {
		parent::__construct( $structure, $policy, $progress );
		$this->assignments = $assignments;
		$this->submissions = $submissions;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function item_type(): string {
		return 'assignment';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function post_type(): string {
		return PostTypes::ASSIGNMENT;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function settings_args(): array {
		$number  = static function ( string $description, float $max ): array {
			return array(
				'description'       => $description,
				'type'              => 'number',
				'minimum'           => 0,
				'maximum'           => $max,
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
			'points'        => $number( __( 'Highest grade.', 'public-lms' ), 1000 ),
			'pass_points'   => $number( __( 'Grade needed to complete the assignment; 0 completes it on handing in.', 'public-lms' ), 1000 ),
			'allow_text'    => $boolean( __( 'Learners may type an answer.', 'public-lms' ) ),
			'max_files'     => $number( __( 'Files per hand-in; 0 for a typed answer only.', 'public-lms' ), 10 ),
			'max_file_size' => $number( __( 'Largest file in megabytes; the server\'s limit applies too.', 'public-lms' ), 1024 ),
			'resubmit'      => $boolean( __( 'Learners may hand in again before grading or after a failing grade.', 'public-lms' ) ),
			'file_types'    => array(
				'description'       => __( 'File extensions accepted.', 'public-lms' ),
				'type'              => 'array',
				'items'             => array(
					'type' => 'string',
					'enum' => array_keys( PrivateFiles::SAFE_TYPES ),
				),
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                                   $item_id Assignment ID.
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	protected function apply_settings( int $item_id, $request ) {
		$values = array();
		foreach ( array_keys( AssignmentSettings::KEYS ) as $key ) {
			if ( isset( $request[ $key ] ) ) {
				$values[ $key ] = $request[ $key ];
			}
		}
		if ( isset( $values['pass_points'] ) ) {
			$points = isset( $values['points'] ) ? (float) $values['points'] : AssignmentSettings::get( $item_id )['points'];
			if ( (float) $values['pass_points'] > $points ) {
				return new WP_Error( 'rest_invalid_param', __( 'The pass grade cannot be higher than the highest grade.', 'public-lms' ), array( 'status' => 400 ) );
			}
		}
		AssignmentSettings::update( $item_id, $values );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_Post $post     Assignment.
	 * @param bool    $can_edit Whether the current user can edit the assignment's course.
	 * @return array<string, mixed>
	 */
	protected function settings_data( WP_Post $post, bool $can_edit ): array {
		$assignment_id = (int) $post->ID;
		$data          = AssignmentSettings::get( $assignment_id );

		$data['max_bytes'] = AssignmentSettings::max_bytes( $assignment_id );
		$data['accept']    = PrivateFiles::accept( $data['file_types'] );
		if ( $can_edit ) {
			$data['submissions_waiting'] = $this->submissions->count( null, SubmissionRepository::STATUS_SUBMITTED, $assignment_id );
		}
		$user_id = get_current_user_id();
		$current = $user_id > 0 ? $this->submissions->current( $user_id, $assignment_id ) : null;

		$data['learner'] = array(
			'submission' => null === $current ? null : $this->assignments->prepare( $current ),
			'can_submit' => $this->assignments->can_submit( $user_id, $assignment_id ),
		);
		return $data;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function settings_schema(): array {
		return array(
			'points'              => array( 'type' => 'number' ),
			'pass_points'         => array( 'type' => 'number' ),
			'allow_text'          => array( 'type' => 'boolean' ),
			'max_files'           => array( 'type' => 'integer' ),
			'max_file_size'       => array( 'type' => 'integer' ),
			'file_types'          => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'resubmit'            => array( 'type' => 'boolean' ),
			'max_bytes'           => array(
				'description' => __( 'Largest file accepted, in bytes, after the server\'s limit.', 'public-lms' ),
				'type'        => 'integer',
				'readonly'    => true,
			),
			'accept'              => array(
				'description' => __( 'Value for a file input\'s accept attribute.', 'public-lms' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'submissions_waiting' => array(
				'description' => __( 'Hand-ins waiting for a grade; editors only.', 'public-lms' ),
				'type'        => 'integer',
				'readonly'    => true,
			),
			'learner'             => array(
				'description' => __( 'The current user\'s hand-in and whether they may hand in now.', 'public-lms' ),
				'type'        => 'object',
				'readonly'    => true,
			),
		);
	}
}
