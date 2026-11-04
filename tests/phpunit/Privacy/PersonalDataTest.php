<?php
/**
 * Tests for personal data export and erasure.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Privacy;

use PublicLMS\Privacy\PersonalData;
use PublicLMS\Quizzes\QuizSettings;
use PublicLMS\Repositories\CertificateRepository;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Repositories\ProgressRepository;
use PublicLMS\Repositories\QuizAttemptRepository;
use PublicLMS\Repositories\SubmissionRepository;
use PublicLMS\Services\AssignmentService;
use PublicLMS\Services\ProgressService;
use PublicLMS\Services\QuizService;
use PublicLMS\Tests\TestCase;

/**
 * @covers \PublicLMS\Privacy\PersonalData
 * @covers \PublicLMS\Repositories\PersonalDataRepository
 */
class PersonalDataTest extends TestCase {

	/**
	 * Learner with data in every table.
	 *
	 * @var int
	 */
	private $learner;

	/**
	 * Fixture.
	 *
	 * @var array{course: int, sections: array<int, int>, lessons: array<int, int>}
	 */
	private $fixture;

	public function set_up() {
		parent::set_up();
		add_filter( 'publiclms_private_files_allow_local', '__return_true' );
		$this->fixture = $this->create_course_with_lessons();
		$this->learner = self::factory()->user->create(
			array(
				'user_email'   => 'maya@example.test',
				'display_name' => 'Maya Lama',
			)
		);
		$c             = publiclms()->container();
		$c->get( EnrollmentRepository::class )->enroll( $this->learner, $this->fixture['course'] );
		foreach ( $this->fixture['lessons'] as $lesson ) {
			$c->get( ProgressService::class )->complete_item( $this->learner, $lesson );
		}
		$quiz     = $this->create_quiz( $this->fixture['sections'][0] );
		$question = $this->create_question( $this->sample_questions()['true_false'] );
		QuizSettings::set_question_ids( $quiz, array( $question ) );
		$attempt = $c->get( QuizService::class )->start( $this->learner, $quiz );
		$c->get( QuizService::class )->submit( $this->learner, (int) $attempt['attempt']['id'], array( (string) $question => true ) );
		$assignment = $this->create_assignment( $this->fixture['sections'][1] );
		$c->get( AssignmentService::class )->submit( $this->learner, $assignment, 'My reconciliation', array( $this->temp_upload( 'march.txt', 'Balance 1200' ) ) );

		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture for a table without a repository yet.
			$wpdb->prefix . 'plms_orders',
			array(
				'order_key'     => 'ord_test',
				'user_id'       => $this->learner,
				'status'        => 'completed',
				'currency'      => 'USD',
				'total'         => 49,
				'billing_email' => 'maya@example.test',
				'customer_ip'   => '203.0.113.9',
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	public function tear_down() {
		remove_filter( 'publiclms_private_files_allow_local', '__return_true' );
		parent::tear_down();
	}

	public function test_every_learner_table_is_exported() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		$groups    = array();
		foreach ( $exporters as $id => $exporter ) {
			if ( 0 !== strpos( $id, 'publiclms-' ) ) {
				continue;
			}
			$result = call_user_func( $exporter['callback'], 'maya@example.test', 1 );
			$this->assertTrue( $result['done'], $id );
			foreach ( $result['data'] as $item ) {
				$groups[ $item['group_id'] ][] = wp_list_pluck( $item['data'], 'value', 'name' );
			}
		}
		foreach ( array( 'enrollments', 'progress', 'quiz-attempts', 'submissions', 'certificates', 'activity', 'orders' ) as $group ) {
			$this->assertArrayHasKey( 'publiclms-' . $group, $groups, "{$group} is not exported." );
		}
		$this->assertSame( '100%', $groups['publiclms-enrollments'][0]['Progress'] );
		$this->assertCount( 6, $groups['publiclms-progress'], 'Four lessons, the passed quiz and the handed-in assignment.' );
		$this->assertSame( 'march.txt', $groups['publiclms-submissions'][0]['Files'] );
		$this->assertSame( 'Maya Lama', $groups['publiclms-certificates'][0]['Name'] );
		$this->assertSame( 'maya@example.test', $groups['publiclms-orders'][0]['Email'] );

		$nobody = call_user_func( $exporters['publiclms-enrollments']['callback'], 'nobody@example.test', 1 );
		$this->assertSame( array(), $nobody['data'] );
	}

	public function test_erasure_removes_learning_records_and_files_and_anonymises_orders() {
		$c     = publiclms()->container();
		$files = $c->get( SubmissionRepository::class )->page_for_user( $this->learner, 1 )[0]['files'];
		$path  = $c->get( \PublicLMS\Files\PrivateFiles::class )->path( $files[0]['path'] );
		$this->assertFileExists( $path );

		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		$result  = call_user_func( $erasers['publiclms-learning']['callback'], 'maya@example.test', 1 );
		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['done'] );

		$this->assertNull( $c->get( EnrollmentRepository::class )->find_by_user_and_course( $this->learner, $this->fixture['course'] ) );
		$this->assertSame( array(), $c->get( ProgressRepository::class )->course_progress_for_user( $this->learner ) );
		$this->assertSame( array(), $c->get( QuizAttemptRepository::class )->recent_for_user( $this->learner ) );
		$this->assertSame( array(), $c->get( SubmissionRepository::class )->page_for_user( $this->learner, 1 ) );
		$this->assertSame( array(), $c->get( CertificateRepository::class )->for_user( $this->learner ) );
		$this->assertFileDoesNotExist( $path );

		$orders = call_user_func( $erasers['publiclms-orders']['callback'], 'maya@example.test', 1 );
		$this->assertTrue( $orders['items_retained'] );
		$this->assertNotEmpty( $orders['messages'] );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_key = %s', $wpdb->prefix . 'plms_orders', 'ord_test' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reads the fixture.
		$this->assertNull( $row['user_id'] );
		$this->assertNull( $row['billing_email'] );
		$this->assertNull( $row['customer_ip'] );
		$this->assertSame( '49.0000', $row['total'], 'Amounts stay for the accounts.' );

		$again = call_user_func( $erasers['publiclms-learning']['callback'], 'maya@example.test', 1 );
		$this->assertFalse( $again['items_removed'] );
		$this->assertInstanceOf( PersonalData::class, $c->get( PersonalData::class ) );
	}
}
