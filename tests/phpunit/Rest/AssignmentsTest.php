<?php
/**
 * Integration tests for assignments, private uploads, grading and the gradebook.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Rest;

use PublicLMS\Assignments\AssignmentSettings;
use PublicLMS\Files\PrivateFiles;
use PublicLMS\Quizzes\QuizSettings;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Rest\FileResponse;
use PublicLMS\Services\ProgressService;
use PublicLMS\Services\QuizService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers \PublicLMS\Rest\AssignmentsController
 * @covers \PublicLMS\Rest\SubmissionsController
 * @covers \PublicLMS\Rest\GradebookController
 * @covers \PublicLMS\Services\AssignmentService
 * @covers \PublicLMS\Services\Gradebook
 * @covers \PublicLMS\Files\PrivateFiles
 * @covers \PublicLMS\Repositories\SubmissionRepository
 */
class AssignmentsTest extends RestTestCase {

	/**
	 * Assignment in the fixture course.
	 *
	 * @var int
	 */
	private $assignment;

	/**
	 * A second enrolled learner.
	 *
	 * @var int
	 */
	private $classmate;

	public function set_up() {
		parent::set_up();
		add_filter( 'publiclms_private_files_allow_local', '__return_true' );
		$this->assignment = $this->create_assignment( $this->fixture['sections'][1], 20, array( 'post_author' => $this->instructor ) );
		$this->classmate  = self::factory()->user->create( array( 'display_name' => '=HYPERLINK("x")' ) );
		$enrollments      = publiclms()->container()->get( EnrollmentRepository::class );
		$enrollments->enroll( $this->subscriber, $this->fixture['course'] );
		$enrollments->enroll( $this->classmate, $this->fixture['course'] );
	}

	public function tear_down() {
		remove_filter( 'publiclms_private_files_allow_local', '__return_true' );
		parent::tear_down();
	}

	/**
	 * Hands in work as a user.
	 *
	 * @param int                              $user_id Learner.
	 * @param string                           $content Typed answer.
	 * @param array<int, array<string, mixed>> $files   Files in $_FILES shape.
	 * @return WP_REST_Response
	 */
	private function hand_in( int $user_id, string $content, array $files = array() ): WP_REST_Response {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'POST', '/publiclms/v1/assignments/' . $this->assignment . '/submissions' );
		$request->set_body_params( array( 'content' => $content ) );
		if ( ! empty( $files ) ) {
			$request->set_file_params(
				array(
					'files' => array(
						'name'     => wp_list_pluck( $files, 'name' ),
						'type'     => wp_list_pluck( $files, 'type' ),
						'tmp_name' => wp_list_pluck( $files, 'tmp_name' ),
						'error'    => wp_list_pluck( $files, 'error' ),
						'size'     => wp_list_pluck( $files, 'size' ),
					),
				)
			);
		}
		return rest_do_request( $request );
	}

	public function test_uploads_are_private_typed_and_sized() {
		$response = $this->hand_in( $this->subscriber, '<p>My reconciliation.</p><script>alert(1)</script>', array( $this->temp_upload( 'march.pdf', $this->pdf_bytes() ) ) );
		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertStringNotContainsString( '<script', $data['content'] );
		$this->assertSame( 'march.pdf', $data['files'][0]['name'] );
		$this->assertArrayNotHasKey( 'path', $data['files'][0], 'Storage paths never leave the server.' );
		$this->assertStringContainsString( '/publiclms/v1/submissions/' . $data['id'] . '/files/', rawurldecode( $data['files'][0]['url'] ) );

		$row  = publiclms()->container()->get( \PublicLMS\Repositories\SubmissionRepository::class )->find( $data['id'] );
		$path = publiclms()->container()->get( PrivateFiles::class )->path( $row['files'][0]['path'] );
		$this->assertFileExists( $path );
		$this->assertSame( '', pathinfo( $path, PATHINFO_EXTENSION ), 'Stored names have no extension.' );
		$this->assertMatchesRegularExpression( '#/publiclms-private/[a-f0-9]{32}/\d{4}/\d{2}/[a-f0-9]{32}$#', $path );
		$this->assertStringNotContainsString( wp_upload_dir()['baseurl'], (string) wp_json_encode( $data ) );
		$this->assertFileExists( dirname( $path, 3 ) . '/.htaccess' );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( dirname( $path, 4 ) . '/.htaccess' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test reads a guard file.

		$php = $this->hand_in( $this->classmate, '', array( $this->temp_upload( 'essay.pdf', '<?php echo "pwned"; ?>' ) ) );
		$this->assertSame( 400, $php->get_status(), 'Content that is not a PDF is refused.' );
		$this->assertSame( 400, $this->hand_in( $this->classmate, '', array( $this->temp_upload( 'shell.php', '<?php ?>' ) ) )->get_status() );
		$this->assertSame( 400, $this->hand_in( $this->classmate, '', array( $this->temp_upload( 'page.html', '<html></html>' ) ) )->get_status() );

		AssignmentSettings::update( $this->assignment, array( 'max_file_size' => 1 ) );
		$large = $this->hand_in( $this->classmate, '', array( $this->temp_upload( 'big.txt', str_repeat( 'a', MB_IN_BYTES + 10 ) ) ) );
		$this->assertSame( 400, $large->get_status() );
		$this->assertStringContainsString( 'larger than', $large->get_data()['message'] );

		AssignmentSettings::update( $this->assignment, array( 'max_files' => 1 ) );
		$two = $this->hand_in( $this->classmate, '', array( $this->temp_upload( 'a.txt', 'one' ), $this->temp_upload( 'b.txt', 'two' ) ) );
		$this->assertSame( 400, $two->get_status() );
		$this->assertSame( 400, $this->hand_in( $this->classmate, '   ' )->get_status(), 'Something must be handed in.' );
		$this->assertSame( 401, $this->hand_in( 0, 'Hello' )->get_status() );
		$outsider = self::factory()->user->create();
		$this->assertSame( 403, $this->hand_in( $outsider, 'Hello' )->get_status(), 'Learners must be enrolled.' );
	}

	public function test_files_download_only_through_the_permission_check() {
		$data = $this->hand_in( $this->subscriber, '', array( $this->temp_upload( 'notes.txt', 'Opening balance 1200' ) ) )->get_data();
		$file = '/submissions/' . $data['id'] . '/files/' . $data['files'][0]['id'];

		$own = $this->request( 'GET', $file, array(), $this->subscriber );
		$this->assertInstanceOf( FileResponse::class, $own );
		$this->assertSame( 'notes.txt', $own->file_name );
		$this->assertSame( 'Opening balance 1200', $own->contents() );
		$this->assertInstanceOf( FileResponse::class, $this->request( 'GET', $file, array(), $this->instructor ), 'The teacher can download.' );
		$this->assertSame( 404, $this->request( 'GET', $file, array(), $this->classmate )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', $file, array(), $this->other_instructor )->get_status() );
		$this->assertSame( 401, $this->request( 'GET', $file )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/submissions/' . $data['id'] . '/files/fmissing0000', array(), $this->subscriber )->get_status() );

		ob_start();
		$served = FileResponse::serve( false, $own );
		$body   = ob_get_clean();
		$this->assertTrue( $served );
		$this->assertSame( 'Opening balance 1200', $body );
	}

	public function test_grading_completes_the_assignment_at_the_pass_grade() {
		AssignmentSettings::update(
			$this->assignment,
			array(
				'points'      => 20,
				'pass_points' => 12,
			)
		);
		$first = $this->hand_in( $this->subscriber, 'Draft one' )->get_data();
		$again = $this->hand_in( $this->subscriber, 'Draft two' )->get_data();
		$this->assertSame( 2, $again['attempt'] );
		$this->assertSame( 'replaced', $this->request( 'GET', '/submissions/' . $first['id'], array(), $this->subscriber )->get_data()['status'] );

		$progress = publiclms()->container()->get( ProgressService::class );
		$this->assertNotContains( $this->assignment, $progress->summary( $this->subscriber, $this->fixture['course'] )['completed_items'] );
		$this->assertSame( 409, $this->request( 'POST', '/progress', array( 'item_id' => $this->assignment ), $this->subscriber )->get_status() );

		$grade = '/assignments/' . $this->assignment . '/submissions/' . $again['id'] . '/grade';
		$this->assertSame( 403, $this->request( 'PUT', $grade, array( 'grade' => 15 ), $this->other_instructor )->get_status() );
		$this->assertSame( 403, $this->request( 'PUT', $grade, array( 'grade' => 15 ), $this->subscriber )->get_status() );
		$this->assertSame( 400, $this->request( 'PUT', $grade, array( 'grade' => 25 ), $this->instructor )->get_status() );

		$failed = $this->request(
			'PUT',
			$grade,
			array(
				'grade'    => 9,
				'feedback' => 'Check March again.',
			),
			$this->instructor
		)->get_data();
		$this->assertFalse( $failed['passed'] );
		$this->assertSame( 'Check March again.', $failed['feedback'] );
		$this->assertNotContains( $this->assignment, $progress->summary( $this->subscriber, $this->fixture['course'] )['completed_items'] );

		$retry  = $this->hand_in( $this->subscriber, 'Draft three' )->get_data();
		$passed = $this->request( 'PUT', '/assignments/' . $this->assignment . '/submissions/' . $retry['id'] . '/grade', array( 'grade' => 16 ), $this->instructor )->get_data();
		$this->assertTrue( $passed['passed'] );
		$this->assertContains( $this->assignment, $progress->summary( $this->subscriber, $this->fixture['course'] )['completed_items'] );
		$this->assertSame( 403, $this->hand_in( $this->subscriber, 'Draft four' )->get_status(), 'A passed assignment is closed.' );

		$queue = $this->request( 'GET', '/submissions', array( 'status' => 'graded' ), $this->instructor );
		$this->assertSame( '2', $queue->get_headers()['X-WP-Total'] );
		$this->assertSame( array(), $this->request( 'GET', '/submissions', array(), $this->other_instructor )->get_data() );
		$this->assertSame( 403, $this->request( 'GET', '/submissions', array(), $this->subscriber )->get_status() );
	}

	public function test_without_a_pass_grade_handing_in_completes_the_assignment() {
		$this->hand_in( $this->subscriber, 'Done' );
		$this->assertContains( $this->assignment, publiclms()->container()->get( ProgressService::class )->summary( $this->subscriber, $this->fixture['course'] )['completed_items'] );
		$learner = $this->request( 'GET', '/assignments/' . $this->assignment, array(), $this->subscriber )->get_data()['learner'];
		$this->assertSame( 'submitted', $learner['submission']['status'] );
		$this->assertTrue( $learner['can_submit']['allowed'], 'Learners may replace work waiting for a grade.' );
	}

	public function test_gradebook_lists_learners_by_quizzes_and_assignments_with_a_csv() {
		$quiz     = $this->create_quiz( $this->fixture['sections'][0], 10, array( 'post_author' => $this->instructor ) );
		$question = $this->create_question( $this->sample_questions()['true_false'], $this->instructor );
		QuizSettings::set_question_ids( $quiz, array( $question ) );
		$quizzes = publiclms()->container()->get( QuizService::class );
		$attempt = $quizzes->start( $this->subscriber, $quiz );
		$quizzes->submit( $this->subscriber, (int) $attempt['attempt']['id'], array( (string) $question => true ) );

		$handin = $this->hand_in( $this->subscriber, 'Done' )->get_data();
		$this->request( 'PUT', '/assignments/' . $this->assignment . '/submissions/' . $handin['id'] . '/grade', array( 'grade' => 5 ), $this->instructor );
		$this->hand_in( $this->classmate, 'Mine' );

		$this->assertSame( 403, $this->request( 'GET', '/courses/' . $this->fixture['course'] . '/gradebook', array(), $this->other_instructor )->get_status() );
		$book = $this->request( 'GET', '/courses/' . $this->fixture['course'] . '/gradebook', array(), $this->instructor )->get_data();
		$this->assertSame( array( $quiz, $this->assignment ), wp_list_pluck( $book['columns'], 'id' ) );
		$rows = array_column( $book['rows'], null, 'user_id' );
		$this->assertSame( 100.0, ( (array) $rows[ $this->subscriber ]['cells'] )[ (string) $quiz ]['percent'] );
		$this->assertSame( 5.0, ( (array) $rows[ $this->subscriber ]['cells'] )[ (string) $this->assignment ]['grade'] );
		$this->assertSame( 75.0, $rows[ $this->subscriber ]['average'] );
		$this->assertSame( 'submitted', ( (array) $rows[ $this->classmate ]['cells'] )[ (string) $this->assignment ]['status'] );
		$this->assertNull( $rows[ $this->classmate ]['average'], 'Neither missing work nor work waiting for a grade counts in the average.' );
		$this->assertSame( 'Not taken', ( (array) $rows[ $this->classmate ]['cells'] )[ (string) $quiz ]['label'] );
		$this->assertSame( 'Waiting for a grade', ( (array) $rows[ $this->classmate ]['cells'] )[ (string) $this->assignment ]['label'] );
		$this->assertSame(
			array(
				'not_handed_in' => 0,
				'not_taken'     => 1,
				'waiting'       => 1,
			),
			$rows[ $this->classmate ]['counts']
		);
		$this->assertSame( array_fill_keys( array( 'not_handed_in', 'not_taken', 'waiting' ), 0 ), $rows[ $this->subscriber ]['counts'] );

		$csv = $this->request( 'GET', '/courses/' . $this->fixture['course'] . '/gradebook', array( 'format' => 'csv' ), $this->instructor );
		$this->assertInstanceOf( FileResponse::class, $csv );
		$body = $csv->contents();
		$this->assertStringContainsString( '"Check your understanding (best %)"', $body );
		$this->assertStringContainsString( '"Lucía Fernández","', $body );
		$this->assertStringContainsString( '"\'=HYPERLINK(""x"")"', $body, 'Formula-like cells are neutralised.' );
		$this->assertStringContainsString( '"Waiting for a grade"', $body );
		$this->assertStringContainsString( '"Not taken"', $body );
		$this->assertStringContainsString( '"Average (%)","Not handed in","Not taken","Waiting for a grade"', $body );
	}
}
