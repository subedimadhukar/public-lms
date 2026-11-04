<?php
/**
 * Personal data export and erasure.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Privacy;

use PublicLMS\Repositories\CertificateRepository;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Repositories\EventRepositoryInterface;
use PublicLMS\Repositories\PersonalDataRepository;
use PublicLMS\Repositories\ProgressRepository;
use PublicLMS\Repositories\QuizAttemptRepository;
use PublicLMS\Repositories\SubmissionRepository;
use PublicLMS\Services\AssignmentService;

defined( 'ABSPATH' ) || exit;

/**
 * Registers WordPress's personal data exporters and erasers for every
 * learner table (PRD §6, ADR-001, ADR-044).
 *
 * Export groups: enrolments (with course progress), step progress, quiz
 * attempts with answers, assignment hand-ins, certificates, activity log,
 * notifications and orders. Erasure deletes the learning records (and
 * hand-in files) and keeps orders for the accounts with the personal
 * details removed, saying so in the erasure report. `plms_report_daily`
 * holds only counts and is not personal data.
 */
final class PersonalData {

	/**
	 * Rows per export page.
	 */
	const PER_PAGE = 100;

	/**
	 * Raw table access.
	 *
	 * @var PersonalDataRepository
	 */
	private $tables;

	/**
	 * Progress.
	 *
	 * @var ProgressRepository
	 */
	private $progress;

	/**
	 * Enrolments.
	 *
	 * @var EnrollmentRepository
	 */
	private $enrollments;

	/**
	 * Quiz attempts.
	 *
	 * @var QuizAttemptRepository
	 */
	private $attempts;

	/**
	 * Hand-ins.
	 *
	 * @var SubmissionRepository
	 */
	private $submissions;

	/**
	 * Assignment service, which also deletes files.
	 *
	 * @var AssignmentService
	 */
	private $assignments;

	/**
	 * Certificates.
	 *
	 * @var CertificateRepository
	 */
	private $certificates;

	/**
	 * Event log.
	 *
	 * @var EventRepositoryInterface
	 */
	private $events;

	/**
	 * Constructor.
	 *
	 * @param PersonalDataRepository   $tables       Raw table access.
	 * @param ProgressRepository       $progress     Progress.
	 * @param EnrollmentRepository     $enrollments  Enrolments.
	 * @param QuizAttemptRepository    $attempts     Quiz attempts.
	 * @param SubmissionRepository     $submissions  Hand-ins.
	 * @param AssignmentService        $assignments  Assignment service.
	 * @param CertificateRepository    $certificates Certificates.
	 * @param EventRepositoryInterface $events       Event log.
	 */
	public function __construct( PersonalDataRepository $tables, ProgressRepository $progress, EnrollmentRepository $enrollments, QuizAttemptRepository $attempts, SubmissionRepository $submissions, AssignmentService $assignments, CertificateRepository $certificates, EventRepositoryInterface $events ) {
		$this->tables       = $tables;
		$this->progress     = $progress;
		$this->enrollments  = $enrollments;
		$this->attempts     = $attempts;
		$this->submissions  = $submissions;
		$this->assignments  = $assignments;
		$this->certificates = $certificates;
		$this->events       = $events;
	}

	/**
	 * Hooks the exporters and erasers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
	}

	/**
	 * Adds the exporters.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public function exporters( $exporters ): array {
		$exporters = (array) $exporters;
		$list      = array(
			'enrollments'   => __( 'Public LMS course enrolments', 'public-lms' ),
			'progress'      => __( 'Public LMS lesson progress', 'public-lms' ),
			'quiz-attempts' => __( 'Public LMS quiz attempts', 'public-lms' ),
			'submissions'   => __( 'Public LMS assignment hand-ins', 'public-lms' ),
			'certificates'  => __( 'Public LMS certificates', 'public-lms' ),
			'activity'      => __( 'Public LMS activity log', 'public-lms' ),
			'notifications' => __( 'Public LMS notifications', 'public-lms' ),
			'orders'        => __( 'Public LMS orders', 'public-lms' ),
		);
		foreach ( $list as $key => $name ) {
			$exporters[ 'publiclms-' . $key ] = array(
				'exporter_friendly_name' => $name,
				'callback'               => function ( $email, $page = 1 ) use ( $key ) {
					return $this->export( $key, (string) $email, (int) $page );
				},
			);
		}
		return $exporters;
	}

	/**
	 * Adds the erasers.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public function erasers( $erasers ): array {
		$erasers                       = (array) $erasers;
		$erasers['publiclms-learning'] = array(
			'eraser_friendly_name' => __( 'Public LMS learning records', 'public-lms' ),
			'callback'             => array( $this, 'erase_learning' ),
		);
		$erasers['publiclms-orders']   = array(
			'eraser_friendly_name' => __( 'Public LMS orders', 'public-lms' ),
			'callback'             => array( $this, 'erase_orders' ),
		);
		return $erasers;
	}

	/**
	 * Exports one group, one page at a time.
	 *
	 * @param string $key   Group key.
	 * @param string $email Email address of the person.
	 * @param int    $page  1-based page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $key, string $email, int $page ): array {
		$user    = get_user_by( 'email', $email );
		$user_id = false === $user ? 0 : (int) $user->ID;
		$page    = max( 1, $page );
		$items   = array();
		$count   = 0;

		if ( 'orders' !== $key && $user_id <= 0 ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		switch ( $key ) {
			case 'enrollments':
				$rows      = $this->tables->page( 'enrollments', $user_id, $page, self::PER_PAGE );
				$count     = count( $rows );
				$aggregate = $this->progress->course_progress_for_user( $user_id );
				foreach ( $rows as $row ) {
					$progress = $aggregate[ (int) $row['course_id'] ] ?? null;
					$items[]  = $this->item(
						'enrollments',
						__( 'Course enrolments', 'public-lms' ),
						'enrollment-' . $row['id'],
						array(
							__( 'Course', 'public-lms' )   => $this->title( (int) $row['course_id'] ),
							__( 'Status', 'public-lms' )   => (string) $row['status'],
							__( 'Source', 'public-lms' )   => (string) $row['source'],
							__( 'Enrolled', 'public-lms' ) => (string) $row['enrolled_at'],
							__( 'Expires', 'public-lms' )  => (string) $row['expires_at'],
							__( 'Completed', 'public-lms' ) => (string) $row['completed_at'],
							__( 'Progress', 'public-lms' ) => null === $progress ? '' : $progress['percent'] . '%',
						)
					);
				}
				break;

			case 'progress':
				$rows  = $this->tables->page( 'progress', $user_id, $page, self::PER_PAGE );
				$count = count( $rows );
				foreach ( $rows as $row ) {
					$items[] = $this->item(
						'progress',
						__( 'Lesson progress', 'public-lms' ),
						'progress-' . $row['id'],
						array(
							__( 'Course', 'public-lms' ) => $this->title( (int) $row['course_id'] ),
							__( 'Step', 'public-lms' )   => $this->title( (int) $row['item_id'] ),
							__( 'Type', 'public-lms' )   => (string) $row['item_type'],
							__( 'Status', 'public-lms' ) => (string) $row['status'],
							__( 'Completed', 'public-lms' ) => (string) $row['completed_at'],
						)
					);
				}
				break;

			case 'quiz-attempts':
				$rows  = $this->attempts->export_page( $user_id, $page, self::PER_PAGE );
				$count = count( $rows );
				foreach ( $rows as $row ) {
					$answers = array();
					foreach ( (array) $row['answers'] as $question_id => $answer ) {
						$answers[] = $this->title( (int) $question_id ) . ': ' . wp_json_encode( $answer['answer'] ) . ( null === $answer['is_correct'] ? '' : ( $answer['is_correct'] ? ' ✓' : ' ✗' ) );
					}
					$items[] = $this->item(
						'quiz-attempts',
						__( 'Quiz attempts', 'public-lms' ),
						'quiz-attempt-' . $row['id'],
						array(
							__( 'Quiz', 'public-lms' )    => $this->title( (int) $row['quiz_id'] ),
							__( 'Attempt', 'public-lms' ) => (string) $row['attempt_number'],
							__( 'Status', 'public-lms' )  => (string) $row['status'],
							__( 'Score', 'public-lms' )   => null === $row['score'] ? '' : $row['score'] . ' / ' . $row['max_score'],
							__( 'Percent', 'public-lms' ) => null === $row['percent'] ? '' : $row['percent'] . '%',
							__( 'Passed', 'public-lms' )  => null === $row['passed'] ? '' : ( $row['passed'] ? __( 'Yes', 'public-lms' ) : __( 'No', 'public-lms' ) ),
							__( 'Started', 'public-lms' ) => (string) $row['started_at'],
							__( 'Submitted', 'public-lms' ) => (string) $row['submitted_at'],
							__( 'Answers', 'public-lms' ) => implode( "\n", $answers ),
						)
					);
				}
				break;

			case 'submissions':
				$rows  = $this->submissions->page_for_user( $user_id, $page, self::PER_PAGE );
				$count = count( $rows );
				foreach ( $rows as $row ) {
					$items[] = $this->item(
						'submissions',
						__( 'Assignment hand-ins', 'public-lms' ),
						'submission-' . $row['id'],
						array(
							__( 'Assignment', 'public-lms' ) => $this->title( (int) $row['assignment_id'] ),
							__( 'Attempt', 'public-lms' )  => (string) $row['attempt'],
							__( 'Status', 'public-lms' )   => (string) $row['status'],
							__( 'Answer', 'public-lms' )   => wp_strip_all_tags( (string) $row['content'] ),
							__( 'Files', 'public-lms' )    => implode( ', ', wp_list_pluck( (array) $row['files'], 'name' ) ),
							__( 'Grade', 'public-lms' )    => null === $row['grade'] ? '' : $row['grade'] . ' / ' . $row['max_grade'],
							__( 'Feedback', 'public-lms' ) => wp_strip_all_tags( (string) $row['feedback'] ),
							__( 'Handed in', 'public-lms' ) => (string) $row['submitted_at'],
						)
					);
				}
				break;

			case 'certificates':
				$rows  = 1 === $page ? $this->certificates->for_user( $user_id ) : array();
				$count = 0;
				foreach ( $rows as $row ) {
					$items[] = $this->item(
						'certificates',
						__( 'Certificates', 'public-lms' ),
						'certificate-' . $row['id'],
						array(
							__( 'Certificate ID', 'public-lms' ) => (string) $row['certificate_key'],
							__( 'Course', 'public-lms' ) => (string) $row['course_title'],
							__( 'Name', 'public-lms' )   => (string) $row['learner_name'],
							__( 'Completed', 'public-lms' ) => (string) $row['completed_at'],
							__( 'Issued', 'public-lms' ) => (string) $row['issued_at'],
						)
					);
				}
				break;

			case 'activity':
				$rows  = $this->tables->page( 'events', $user_id, $page, self::PER_PAGE );
				$count = count( $rows );
				foreach ( $rows as $row ) {
					$items[] = $this->item(
						'activity',
						__( 'Activity log', 'public-lms' ),
						'event-' . $row['id'],
						array(
							__( 'Event', 'public-lms' )  => (string) $row['event_type'],
							__( 'Course', 'public-lms' ) => null === $row['course_id'] ? '' : $this->title( (int) $row['course_id'] ),
							__( 'Item', 'public-lms' )   => null === $row['object_id'] ? '' : (string) $row['object_type'] . ' ' . $row['object_id'],
							__( 'Date', 'public-lms' )   => (string) $row['created_at'],
						)
					);
				}
				break;

			case 'notifications':
				$rows  = $this->tables->page( 'notifications', $user_id, $page, self::PER_PAGE );
				$count = count( $rows );
				foreach ( $rows as $row ) {
					$items[] = $this->item(
						'notifications',
						__( 'Notifications', 'public-lms' ),
						'notification-' . $row['id'],
						array(
							__( 'Title', 'public-lms' )   => (string) $row['title'],
							__( 'Message', 'public-lms' ) => wp_strip_all_tags( (string) $row['message'] ),
							__( 'Status', 'public-lms' )  => (string) $row['status'],
							__( 'Date', 'public-lms' )    => (string) $row['created_at'],
						)
					);
				}
				break;

			case 'orders':
				$rows  = $this->tables->orders( $user_id, $email, $page, self::PER_PAGE );
				$count = count( $rows );
				foreach ( $rows as $row ) {
					$items[] = $this->item(
						'orders',
						__( 'Orders', 'public-lms' ),
						'order-' . $row['id'],
						array(
							__( 'Order', 'public-lms' )   => (string) $row['order_key'],
							__( 'Status', 'public-lms' )  => (string) $row['status'],
							__( 'Total', 'public-lms' )   => (string) $row['total'] . ' ' . $row['currency'],
							__( 'Payment', 'public-lms' ) => (string) $row['gateway'],
							__( 'Email', 'public-lms' )   => (string) $row['billing_email'],
							__( 'Date', 'public-lms' )    => (string) $row['created_at'],
						)
					);
				}
				break;
		}

		return array(
			'data' => $items,
			'done' => $count < self::PER_PAGE,
		);
	}

	/**
	 * Erases a person's learning records. Eraser callback.
	 *
	 * @param string $email Email address.
	 * @param int    $page  1-based page (everything goes in one pass).
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase_learning( $email, $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature set by core.
		$user    = get_user_by( 'email', (string) $email );
		$removed = 0;
		if ( false !== $user ) {
			$user_id  = (int) $user->ID;
			$removed += count( $this->tables->page( 'progress', $user_id, 1, 1 ) );
			$this->progress->delete_for_user( $user_id );
			$removed += $this->attempts->delete_for_user( $user_id );
			$removed += $this->assignments->delete_for_user( $user_id );
			$removed += $this->certificates->delete_for_user( $user_id );
			$removed += $this->events->delete_for_user( $user_id );
			$removed += $this->tables->delete_notifications( $user_id );
			$removed += $this->enrollments->delete_for_user( $user_id );
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Removes personal details from a person's orders. Eraser callback.
	 *
	 * @param string $email Email address.
	 * @param int    $page  1-based page (everything goes in one pass).
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase_orders( $email, $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature set by core.
		$user    = get_user_by( 'email', (string) $email );
		$changed = $this->tables->anonymize_orders( false === $user ? 0 : (int) $user->ID, (string) $email );
		return array(
			'items_removed'  => $changed > 0,
			'items_retained' => $changed > 0,
			'messages'       => $changed > 0
				? array( __( 'Public LMS orders were kept for the accounts, with the name, email and IP address removed.', 'public-lms' ) )
				: array(),
			'done'           => true,
		);
	}

	/**
	 * Builds one export item.
	 *
	 * @param string                $group       Group ID suffix.
	 * @param string                $label       Group label.
	 * @param string                $item_id     Item ID.
	 * @param array<string, string> $fields      Name => value.
	 * @return array<string, mixed>
	 */
	private function item( string $group, string $label, string $item_id, array $fields ): array {
		$data = array();
		foreach ( $fields as $name => $value ) {
			$data[] = array(
				'name'  => $name,
				'value' => $value,
			);
		}
		return array(
			'group_id'    => 'publiclms-' . $group,
			'group_label' => $label,
			'item_id'     => $item_id,
			'data'        => $data,
		);
	}

	/**
	 * Returns a post's title, or its ID when it is gone.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function title( int $post_id ): string {
		$title = $post_id > 0 ? wp_strip_all_tags( get_the_title( $post_id ) ) : '';
		return '' === $title ? '#' . $post_id : $title;
	}
}
