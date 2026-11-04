<?php
/**
 * Integration tests for quizzes, the question bank and attempts.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Rest;

use PublicLMS\Quizzes\QuizSettings;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Repositories\QuizAttemptRepository;
use PublicLMS\Services\ProgressService;

/**
 * @covers \PublicLMS\Rest\QuizzesController
 * @covers \PublicLMS\Rest\QuestionsController
 * @covers \PublicLMS\Rest\QuizAttemptsController
 * @covers \PublicLMS\Services\QuizService
 * @covers \PublicLMS\Repositories\QuizAttemptRepository
 */
class QuizzesTest extends RestTestCase {

	/**
	 * Quiz in the fixture course, after the first section's lessons.
	 *
	 * @var int
	 */
	private $quiz;

	/**
	 * Question IDs by sample type.
	 *
	 * @var array<string, int>
	 */
	private $questions = array();

	public function set_up() {
		parent::set_up();
		$this->quiz = $this->create_quiz( $this->fixture['sections'][0], 10, array( 'post_author' => $this->instructor ) );
		foreach ( $this->sample_questions() as $type => $input ) {
			$this->questions[ $type ] = $this->create_question( $input, $this->instructor );
		}
		QuizSettings::set_question_ids( $this->quiz, array_values( $this->questions ) );
		publiclms()->container()->get( EnrollmentRepository::class )->enroll( $this->subscriber, $this->fixture['course'] );
	}

	/**
	 * Returns answers that get every sample question right.
	 *
	 * @param array<int, array<string, mixed>> $questions Questions from the attempt view.
	 * @return array<string, mixed>
	 */
	private function right_answers( array $questions ): array {
		$bank    = publiclms()->container()->get( \PublicLMS\Quizzes\QuestionBank::class );
		$answers = array();
		foreach ( $questions as $view ) {
			$key = $bank->get( (int) $view['id'] );
			switch ( $key['type'] ) {
				case 'single':
				case 'multiple':
					$right = array();
					foreach ( $key['options'] as $option ) {
						if ( $option['correct'] ) {
							$right[] = $option['id'];
						}
					}
					$answers[ $view['id'] ] = 'single' === $key['type'] ? $right[0] : $right;
					break;
				case 'true_false':
					$answers[ $view['id'] ] = $key['answer'];
					break;
				case 'fill_blank':
					$answers[ $view['id'] ] = array( 'coa', 'Category' );
					break;
				case 'short_answer':
					$answers[ $view['id'] ] = 'reconciliation';
					break;
				case 'matching':
					$answers[ $view['id'] ] = wp_list_pluck( $key['pairs'], 'match_id', 'prompt_id' );
					break;
			}
		}
		return $answers;
	}

	public function test_authors_build_quizzes_from_the_bank() {
		$created = $this->request(
			'POST',
			'/questions',
			array(
				'type'    => 'true_false',
				'text'    => 'Receipts should be kept for seven years.',
				'answer'  => true,
				'quiz_id' => $this->quiz,
			),
			$this->instructor
		);
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( array( $this->quiz ), wp_list_pluck( $created->get_data()['used_in'], 'id' ) );
		$this->assertContains( $created->get_data()['id'], QuizSettings::question_ids( $this->quiz ) );

		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/questions',
				array(
					'type'    => 'single',
					'text'    => 'Only one option',
					'options' => array(
						array(
							'text'    => 'A',
							'correct' => true,
						),
					),
				),
				$this->instructor
			)->get_status()
		);
		$this->assertSame(
			403,
			$this->request(
				'POST',
				'/questions',
				array(
					'type' => 'true_false',
					'text' => 'x',
				),
				$this->subscriber
			)->get_status()
		);
		$this->assertSame( 403, $this->request( 'GET', '/questions/' . $this->questions['single'], array(), $this->other_instructor )->get_status() );
		$this->assertSame(
			403,
			$this->request(
				'POST',
				'/questions',
				array(
					'type'    => 'true_false',
					'text'    => 'x',
					'quiz_id' => $this->quiz,
				),
				$this->other_instructor
			)->get_status()
		);

		$list = $this->request( 'GET', '/questions', array( 'type' => 'matching' ), $this->instructor );
		$this->assertSame( array( $this->questions['matching'] ), wp_list_pluck( $list->get_data(), 'id' ) );
		$this->assertSame( array(), $this->request( 'GET', '/questions', array(), $this->other_instructor )->get_data(), 'Instructors see their own bank.' );
		$this->assertCount( 7, $this->request( 'GET', '/questions', array(), $this->manager )->get_data() );

		$patched = $this->request(
			'PATCH',
			'/quizzes/' . $this->quiz,
			array(
				'pass_mark'    => 80,
				'max_attempts' => 2,
				'time_limit'   => 15,
				'questions'    => array( $this->questions['single'], $this->questions['true_false'] ),
			),
			$this->instructor
		)->get_data();
		$this->assertSame( 80, $patched['pass_mark'] );
		$this->assertSame( 2, $patched['max_attempts'] );
		$this->assertSame( array( $this->questions['single'], $this->questions['true_false'] ), wp_list_pluck( $patched['questions'], 'id' ) );
		$this->assertSame( 403, $this->request( 'PATCH', '/quizzes/' . $this->quiz, array( 'questions' => array( $this->questions['single'] ) ), $this->other_instructor )->get_status() );

		$theirs = $this->create_question( $this->sample_questions()['single'], $this->other_instructor );
		$this->assertSame( 403, $this->request( 'PATCH', '/quizzes/' . $this->quiz, array( 'questions' => array( $theirs ) ), $this->instructor )->get_status() );

		$learner_view = $this->request( 'GET', '/quizzes/' . $this->quiz, array(), $this->subscriber )->get_data();
		$this->assertArrayNotHasKey( 'questions', $learner_view );
		$this->assertSame( 2, $learner_view['question_total'] );
		$this->assertSame( 2, $learner_view['learner']['attempts_left'] );
	}

	public function test_a_learner_takes_a_quiz_graded_on_the_server() {
		$this->assertSame( 401, $this->request( 'POST', '/quizzes/' . $this->quiz . '/attempts' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/quizzes/' . $this->quiz . '/attempts', array(), $this->other_instructor )->get_status() );

		$start = $this->request( 'POST', '/quizzes/' . $this->quiz . '/attempts', array(), $this->subscriber );
		$this->assertSame( 201, $start->get_status() );
		$attempt = $start->get_data();
		$json    = (string) wp_json_encode( $attempt );
		$this->assertCount( 6, $attempt['questions'] );
		$this->assertStringNotContainsString( '"correct"', $json );
		$this->assertStringNotContainsString( 'Reconciliation', $json );
		$this->assertStringNotContainsString( 'chart of accounts', $json );

		$resumed = $this->request( 'POST', '/quizzes/' . $this->quiz . '/attempts', array(), $this->subscriber );
		$this->assertSame( 200, $resumed->get_status(), 'An attempt in progress is resumed.' );
		$this->assertSame( $attempt['id'], $resumed->get_data()['id'] );

		$base    = '/quizzes/' . $this->quiz . '/attempts/' . $attempt['id'];
		$answers = $this->right_answers( $attempt['questions'] );
		$single  = $this->questions['single'];
		$saved   = $this->request( 'POST', $base . '/answers', array( 'answers' => array( $single => $answers[ $single ] ) ), $this->subscriber );
		$this->assertTrue( $saved->get_data()['saved'] );
		$this->assertSame( $answers[ $single ], ( (array) $this->request( 'GET', $base, array(), $this->subscriber )->get_data()['answers'] )[ $single ] );
		$this->assertSame( 404, $this->request( 'POST', $base . '/answers', array( 'answers' => array() ), $this->manager )->get_status(), 'Only the owner answers.' );

		$wrong                                   = $answers;
		$wrong[ $this->questions['true_false'] ] = false;
		$result                                  = $this->request( 'POST', $base . '/submit', array( 'answers' => $wrong ), $this->subscriber );
		$this->assertSame( 200, $result->get_status() );
		$data = $result->get_data();
		$this->assertSame( 8.0, (float) $data['max_score'] );
		$this->assertSame( 7.0, (float) $data['score'] );
		$this->assertEqualsWithDelta( 87.5, (float) $data['percent'], 0.01 );
		$this->assertTrue( $data['passed'] );
		$this->assertStringContainsString( '88%', $data['message'] );
		$by_id = wp_list_pluck( $data['questions'], 'correct', 'id' );
		$this->assertFalse( $by_id[ $this->questions['true_false'] ] );
		$this->assertTrue( $by_id[ $this->questions['matching'] ] );
		$this->assertStringNotContainsString( '"accepted"', (string) wp_json_encode( $data ) );

		$summary = publiclms()->container()->get( ProgressService::class )->summary( $this->subscriber, $this->fixture['course'] );
		$this->assertContains( $this->quiz, $summary['completed_items'], 'Passing completes the quiz.' );
		$this->assertSame( 5, $summary['items_total'] );

		$this->assertSame( 409, $this->request( 'POST', $base . '/submit', array(), $this->subscriber )->get_status() );
		$this->assertSame( 200, $this->request( 'GET', $base, array(), $this->instructor )->get_status(), 'The teacher sees results.' );
		$this->assertSame( 404, $this->request( 'GET', $base, array(), $this->other_instructor )->get_status() );
		$this->assertSame( 409, $this->request( 'POST', '/progress', array( 'item_id' => $this->quiz ), $this->subscriber )->get_status(), 'Quizzes are not marked complete by hand.' );
	}

	public function test_attempts_limit_and_failing_scores() {
		QuizSettings::update(
			$this->quiz,
			array(
				'max_attempts' => 1,
				'pass_mark'    => 100,
			)
		);
		$attempt = $this->request( 'POST', '/quizzes/' . $this->quiz . '/attempts', array(), $this->subscriber )->get_data();
		$result  = $this->request( 'POST', '/quizzes/' . $this->quiz . '/attempts/' . $attempt['id'] . '/submit', array( 'answers' => array() ), $this->subscriber )->get_data();
		$this->assertFalse( $result['passed'] );
		$this->assertSame( 0.0, (float) $result['score'] );
		$this->assertSame( 0, $result['learner']['attempts_left'] );

		$again = $this->request( 'POST', '/quizzes/' . $this->quiz . '/attempts', array(), $this->subscriber );
		$this->assertSame( 403, $again->get_status() );
		$this->assertSame( 'attempts_limit', $again->get_data()['data']['reason'] );
		$this->assertNotContains( $this->quiz, publiclms()->container()->get( ProgressService::class )->summary( $this->subscriber, $this->fixture['course'] )['completed_items'] );

		$this->assertSame( 201, $this->request( 'POST', '/quizzes/' . $this->quiz . '/attempts', array(), $this->instructor )->get_status(), 'Teachers are not limited.' );
		$this->assertCount( 1, $this->request( 'GET', '/quizzes/' . $this->quiz . '/attempts', array(), $this->subscriber )->get_data() );
		$this->assertSame( 403, $this->request( 'GET', '/quizzes/' . $this->quiz . '/attempts', array( 'user_id' => $this->instructor ), $this->subscriber )->get_status() );
	}

	public function test_overdue_attempts_are_graded_with_answers_saved_in_time() {
		QuizSettings::update( $this->quiz, array( 'time_limit' => 5 ) );
		$attempt = $this->request( 'POST', '/quizzes/' . $this->quiz . '/attempts', array(), $this->subscriber )->get_data();
		$this->assertEqualsWithDelta( 300, $attempt['time_left'], 5 );

		$answers = $this->right_answers( $attempt['questions'] );
		$single  = $this->questions['single'];
		$this->request( 'POST', '/quizzes/' . $this->quiz . '/attempts/' . $attempt['id'] . '/answers', array( 'answers' => array( $single => $answers[ $single ] ) ), $this->subscriber );

		$repo = publiclms()->container()->get( QuizAttemptRepository::class );
		$repo->update( $attempt['id'], array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ) ) );

		$late = $this->request( 'POST', '/quizzes/' . $this->quiz . '/attempts/' . $attempt['id'] . '/submit', array( 'answers' => $answers ), $this->subscriber )->get_data();
		$this->assertSame( 1.0, (float) $late['score'], 'Only the answer saved before the deadline counts.' );
	}

	public function test_starting_attempts_is_rate_limited() {
		$limit = static function () {
			return 1;
		};
		add_filter( 'publiclms_rate_limit', $limit );
		$attempt = $this->request( 'POST', '/quizzes/' . $this->quiz . '/attempts', array(), $this->subscriber )->get_data();
		$this->request( 'POST', '/quizzes/' . $this->quiz . '/attempts/' . $attempt['id'] . '/submit', array( 'answers' => array() ), $this->subscriber );
		$blocked = $this->request( 'POST', '/quizzes/' . $this->quiz . '/attempts', array(), $this->subscriber );
		remove_filter( 'publiclms_rate_limit', $limit );

		$this->assertSame( 429, $blocked->get_status() );
		$this->assertArrayHasKey( 'Retry-After', $blocked->get_headers() );
	}

	public function test_empty_quizzes_cannot_start() {
		QuizSettings::set_question_ids( $this->quiz, array() );
		$this->assertSame( 409, $this->request( 'POST', '/quizzes/' . $this->quiz . '/attempts', array(), $this->subscriber )->get_status() );
	}
}
