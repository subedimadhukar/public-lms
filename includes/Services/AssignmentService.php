<?php
/**
 * Assignment service.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Services;

use PublicLMS\Access\AccessPolicy;
use PublicLMS\Assignments\AssignmentSettings;
use PublicLMS\Core\RateLimiter;
use PublicLMS\Files\PrivateFiles;
use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use PublicLMS\Repositories\EventRepositoryInterface;
use PublicLMS\Repositories\SubmissionRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Hand-ins and grading of assignments (ADR-043).
 *
 * A learner hands in typed text, files or both. Without a pass grade
 * (`pass_points` 0) handing in completes the assignment; with one, a grade
 * at or above it does. Before grading the learner may hand in again, which
 * replaces the waiting hand-in; after a failing grade they may try again
 * when the assignment allows it; after a pass they are done.
 */
class AssignmentService {

	/**
	 * Hand-ins.
	 *
	 * @var SubmissionRepository
	 */
	private $submissions;

	/**
	 * Private files.
	 *
	 * @var PrivateFiles
	 */
	private $files;

	/**
	 * Course structure.
	 *
	 * @var CourseStructureRepositoryInterface
	 */
	private $structure;

	/**
	 * Progress.
	 *
	 * @var ProgressService
	 */
	private $progress;

	/**
	 * Event log.
	 *
	 * @var EventRepositoryInterface
	 */
	private $events;

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private $policy;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private $limiter;

	/**
	 * Constructor.
	 *
	 * @param SubmissionRepository               $submissions Hand-ins.
	 * @param PrivateFiles                       $files       Private files.
	 * @param CourseStructureRepositoryInterface $structure   Course structure.
	 * @param ProgressService                    $progress    Progress.
	 * @param EventRepositoryInterface           $events      Event log.
	 * @param AccessPolicy                       $policy      Access policy.
	 * @param RateLimiter                        $limiter     Rate limiter.
	 */
	public function __construct( SubmissionRepository $submissions, PrivateFiles $files, CourseStructureRepositoryInterface $structure, ProgressService $progress, EventRepositoryInterface $events, AccessPolicy $policy, RateLimiter $limiter ) {
		$this->submissions = $submissions;
		$this->files       = $files;
		$this->structure   = $structure;
		$this->progress    = $progress;
		$this->events      = $events;
		$this->policy      = $policy;
		$this->limiter     = $limiter;
	}

	/**
	 * Says whether a learner may hand in now, and why not.
	 *
	 * @param int $user_id       Learner.
	 * @param int $assignment_id Assignment.
	 * @return array{allowed: bool, reason: string, message: string}
	 */
	public function can_submit( int $user_id, int $assignment_id ): array {
		$decision = $this->policy->can_submit_assignment( $assignment_id, $user_id );
		if ( ! $decision->is_allowed() ) {
			return array(
				'allowed' => false,
				'reason'  => $decision->reason(),
				'message' => $decision->message(),
			);
		}
		$current  = $this->submissions->current( $user_id, $assignment_id );
		$settings = AssignmentSettings::get( $assignment_id );
		if ( null !== $current ) {
			if ( SubmissionRepository::STATUS_GRADED === $current['status'] && ! empty( $current['passed'] ) ) {
				return array(
					'allowed' => false,
					'reason'  => 'passed',
					'message' => __( 'You passed this assignment.', 'public-lms' ),
				);
			}
			if ( ! $settings['resubmit'] ) {
				return array(
					'allowed' => false,
					'reason'  => 'submitted',
					'message' => SubmissionRepository::STATUS_GRADED === $current['status']
						? __( 'This assignment has been graded.', 'public-lms' )
						: __( 'You handed this in. It is waiting for a grade.', 'public-lms' ),
				);
			}
		}
		return array(
			'allowed' => true,
			'reason'  => '',
			'message' => '',
		);
	}

	/**
	 * Hands in work.
	 *
	 * @param int                              $user_id       Learner.
	 * @param int                              $assignment_id Assignment.
	 * @param string                           $content       Typed answer.
	 * @param array<int, array<string, mixed>> $uploads       Files in $_FILES shape, one entry per file.
	 * @return array<string, mixed>|WP_Error The new hand-in.
	 */
	public function submit( int $user_id, int $assignment_id, string $content, array $uploads ) {
		$can = $this->can_submit( $user_id, $assignment_id );
		if ( ! $can['allowed'] ) {
			return new WP_Error(
				'publiclms_cannot_submit',
				$can['message'],
				array(
					'status' => $user_id > 0 ? 403 : 401,
					'reason' => $can['reason'],
				)
			);
		}
		$retry = $this->limiter->hit( 'assignment_submit', $user_id . ':' . $assignment_id, 10, HOUR_IN_SECONDS );
		if ( $retry > 0 ) {
			return new WP_Error(
				'publiclms_rate_limited',
				__( 'Too many tries in a short time. Wait a moment and try again.', 'public-lms' ),
				array(
					'status'      => 429,
					'retry_after' => $retry,
				)
			);
		}

		$settings = AssignmentSettings::get( $assignment_id );
		$content  = $settings['allow_text'] ? trim( wp_kses_post( $content ) ) : '';
		$uploads  = array_values(
			array_filter(
				$uploads,
				static function ( $file ) {
					return is_array( $file ) && UPLOAD_ERR_NO_FILE !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
				}
			)
		);
		if ( count( $uploads ) > $settings['max_files'] ) {
			return $this->invalid(
				0 === $settings['max_files']
					? __( 'This assignment takes a typed answer only.', 'public-lms' )
					/* translators: %d: number of files. */
					: sprintf( _n( 'Hand in at most %d file.', 'Hand in at most %d files.', $settings['max_files'], 'public-lms' ), $settings['max_files'] )
			);
		}
		if ( '' === trim( wp_strip_all_tags( $content ) ) && empty( $uploads ) ) {
			return $this->invalid( __( 'Add your answer or a file before handing in.', 'public-lms' ) );
		}

		$stored = array();
		foreach ( $uploads as $upload ) {
			$file = $this->files->store( $upload, $settings['file_types'], AssignmentSettings::max_bytes( $assignment_id ) );
			if ( is_wp_error( $file ) ) {
				foreach ( $stored as $done ) {
					$this->files->delete( (string) $done['path'] );
				}
				return $file;
			}
			$stored[] = $file;
		}

		$previous = $this->submissions->current( $user_id, $assignment_id );
		if ( null !== $previous && SubmissionRepository::STATUS_SUBMITTED === $previous['status'] ) {
			$this->submissions->update(
				(int) $previous['id'],
				array(
					'status'     => SubmissionRepository::STATUS_REPLACED,
					'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}

		$course_id = $this->structure->course_id_for( $assignment_id );
		$id        = $this->submissions->create( $user_id, $assignment_id, $course_id, $content, $stored, $settings['points'] );
		if ( $id <= 0 ) {
			return new WP_Error( 'publiclms_submission_failed', __( 'Your work could not be saved. Try again.', 'public-lms' ), array( 'status' => 500 ) );
		}
		$submission = $this->submissions->find( $id );
		$submission = null === $submission ? array() : $submission;

		$this->events->record(
			'assignment.submitted',
			array(
				'user_id'     => $user_id,
				'course_id'   => $course_id,
				'object_type' => 'assignment',
				'object_id'   => $assignment_id,
				'payload'     => array( 'submission_id' => $id ),
			)
		);

		/**
		 * Fires after a learner handed in an assignment.
		 *
		 * @param array<string, mixed> $submission The hand-in.
		 * @param int                  $user_id    Learner.
		 * @param int                  $assignment Assignment ID.
		 */
		do_action( 'publiclms_assignment_submitted', $submission, $user_id, $assignment_id );

		if ( $settings['pass_points'] <= 0 ) {
			$this->progress->complete_item( $user_id, $assignment_id );
		}
		return $submission;
	}

	/**
	 * Grades a hand-in.
	 *
	 * @param int    $submission_id Hand-in.
	 * @param float  $grade         Points.
	 * @param string $feedback      Feedback for the learner.
	 * @param int    $grader_id     Who grades.
	 * @return array<string, mixed>|WP_Error The graded hand-in.
	 */
	public function grade( int $submission_id, float $grade, string $feedback, int $grader_id ) {
		$submission = $this->submissions->find( $submission_id );
		if ( null === $submission || SubmissionRepository::STATUS_REPLACED === $submission['status'] ) {
			return new WP_Error( 'publiclms_submission_not_found', __( 'That submission could not be found.', 'public-lms' ), array( 'status' => 404 ) );
		}
		$assignment_id = (int) $submission['assignment_id'];
		$settings      = AssignmentSettings::get( $assignment_id );
		$max           = null === $submission['max_grade'] ? $settings['points'] : (float) $submission['max_grade'];
		if ( $grade < 0 || $grade > $max ) {
			/* translators: %s: highest grade. */
			return $this->invalid( sprintf( __( 'Enter a grade from 0 to %s.', 'public-lms' ), number_format_i18n( $max, 2 ) ) );
		}
		$passed = $settings['pass_points'] <= 0 || $grade >= $settings['pass_points'];
		$now    = gmdate( 'Y-m-d H:i:s' );
		$this->submissions->update(
			$submission_id,
			array(
				'status'     => SubmissionRepository::STATUS_GRADED,
				'grade'      => round( $grade, 2 ),
				'passed'     => $passed ? 1 : 0,
				'feedback'   => trim( wp_kses_post( $feedback ) ),
				'graded_at'  => $now,
				'graded_by'  => $grader_id,
				'updated_at' => $now,
			)
		);
		$graded  = $this->submissions->find( $submission_id );
		$graded  = null === $graded ? $submission : $graded;
		$user_id = (int) $submission['user_id'];

		$this->events->record(
			'assignment.graded',
			array(
				'user_id'     => $user_id,
				'course_id'   => (int) $submission['course_id'],
				'object_type' => 'assignment',
				'object_id'   => $assignment_id,
				'payload'     => array(
					'submission_id' => $submission_id,
					'grade'         => $grade,
					'passed'        => $passed,
				),
			)
		);

		/**
		 * Fires after a teacher graded a hand-in.
		 *
		 * @param array<string, mixed> $submission The graded hand-in.
		 * @param int                  $grader_id  Who graded.
		 */
		do_action( 'publiclms_assignment_graded', $graded, $grader_id );

		if ( $passed ) {
			$this->progress->complete_item( $user_id, $assignment_id );
		}
		return $graded;
	}

	/**
	 * Returns a hand-in prepared for a response; file paths never leave the server.
	 *
	 * @param array<string, mixed> $submission Hand-in.
	 * @return array<string, mixed>
	 */
	public function prepare( array $submission ): array {
		$files = array();
		foreach ( (array) $submission['files'] as $file ) {
			$files[] = array(
				'id'   => (string) $file['id'],
				'name' => (string) $file['name'],
				'size' => (int) $file['size'],
				'type' => (string) $file['type'],
				'url'  => self::file_url( (int) $submission['id'], (string) $file['id'] ),
			);
		}
		$user = get_userdata( (int) $submission['user_id'] );
		return array(
			'id'            => (int) $submission['id'],
			'assignment_id' => (int) $submission['assignment_id'],
			'assignment'    => wp_strip_all_tags( get_the_title( (int) $submission['assignment_id'] ) ),
			'course_id'     => (int) $submission['course_id'],
			'course'        => (int) $submission['course_id'] > 0 ? wp_strip_all_tags( get_the_title( (int) $submission['course_id'] ) ) : '',
			'user'          => array(
				'id'   => (int) $submission['user_id'],
				'name' => false === $user ? '' : (string) $user->display_name,
			),
			'attempt'       => (int) $submission['attempt'],
			'status'        => (string) $submission['status'],
			'content'       => (string) $submission['content'],
			'files'         => $files,
			'grade'         => $submission['grade'],
			'max_grade'     => $submission['max_grade'],
			'passed'        => $submission['passed'],
			'feedback'      => (string) $submission['feedback'],
			'submitted_at'  => QuizService::utc( (string) $submission['submitted_at'] ),
			'graded_at'     => empty( $submission['graded_at'] ) ? null : QuizService::utc( (string) $submission['graded_at'] ),
		);
	}

	/**
	 * Returns the permission-checked download URL of a hand-in file, with the REST nonce.
	 *
	 * @param int    $submission_id Hand-in.
	 * @param string $file_id       File ID.
	 * @return string
	 */
	public static function file_url( int $submission_id, string $file_id ): string {
		return add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'publiclms/v1/submissions/' . $submission_id . '/files/' . rawurlencode( $file_id ) ) );
	}

	/**
	 * Returns the stored file of a hand-in, or null.
	 *
	 * @param array<string, mixed> $submission Hand-in.
	 * @param string               $file_id    File ID.
	 * @return array{path: string, name: string, type: string}|null
	 */
	public function file( array $submission, string $file_id ): ?array {
		foreach ( (array) $submission['files'] as $file ) {
			if ( (string) $file['id'] === $file_id ) {
				$path = $this->files->path( (string) $file['path'] );
				return '' === $path ? null : array(
					'path' => $path,
					'name' => (string) $file['name'],
					'type' => (string) $file['type'],
				);
			}
		}
		return null;
	}

	/**
	 * Deletes a learner's hand-ins and their files, for data erasure.
	 *
	 * @param int $user_id Learner.
	 * @return int Hand-ins deleted.
	 */
	public function delete_for_user( int $user_id ): int {
		$deleted = 0;
		do {
			$batch = 0;
			foreach ( $this->submissions->page_for_user( $user_id, 1, 100 ) as $row ) {
				foreach ( (array) $row['files'] as $file ) {
					$this->files->delete( (string) $file['path'] );
				}
				if ( $this->submissions->delete( (int) $row['id'] ) ) {
					++$batch;
				}
			}
			$deleted += $batch;
		} while ( $batch > 0 );
		return $deleted;
	}

	/**
	 * Returns a validation error.
	 *
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private function invalid( string $message ): WP_Error {
		return new WP_Error( 'publiclms_invalid_submission', $message, array( 'status' => 400 ) );
	}
}
