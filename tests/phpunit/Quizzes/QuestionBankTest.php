<?php
/**
 * Tests for the question bank.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Quizzes;

use PublicLMS\Quizzes\QuestionBank;
use PublicLMS\Quizzes\QuizSettings;
use PublicLMS\Tests\TestCase;

/**
 * @coversDefaultClass \PublicLMS\Quizzes\QuestionBank
 */
class QuestionBankTest extends TestCase {

	/**
	 * Question bank.
	 *
	 * @var QuestionBank
	 */
	private $bank;

	public function set_up() {
		parent::set_up();
		$this->bank = publiclms()->container()->get( QuestionBank::class );
	}

	/**
	 * @covers ::sanitize
	 */
	public function test_invalid_questions_are_refused_with_a_reason() {
		$cases = array(
			array( 'type' => 'essay' ),
			array(
				'type'    => 'single',
				'text'    => 'Pick one',
				'options' => array(
					array(
						'text'    => 'A',
						'correct' => true,
					),
					array(
						'text'    => 'B',
						'correct' => true,
					),
				),
			),
			array(
				'type'    => 'multiple',
				'text'    => 'Pick some',
				'options' => array( array( 'text' => 'A' ), array( 'text' => 'B' ) ),
			),
			array(
				'type'     => 'fill_blank',
				'template' => 'No blanks here.',
			),
			array(
				'type'     => 'short_answer',
				'text'     => 'Name it',
				'accepted' => array( '  ' ),
			),
			array(
				'type'  => 'matching',
				'text'  => 'Match',
				'pairs' => array(
					array(
						'prompt' => 'A',
						'match'  => 'B',
					),
				),
			),
		);
		foreach ( $cases as $case ) {
			$this->assertWPError( $this->bank->sanitize( $case ), wp_json_encode( $case ) );
		}
	}

	/**
	 * The learner's view of every type carries no answer key.
	 *
	 * @covers ::public_view
	 */
	public function test_the_public_view_never_contains_the_answer_key() {
		foreach ( $this->sample_questions() as $type => $input ) {
			$question = $this->bank->get( $this->create_question( $input ) );
			$view     = $this->bank->public_view( $question, '42', true );
			$json     = (string) wp_json_encode( $view );

			$this->assertSame( $type, $view['type'] );
			foreach ( array( '"correct"', '"accepted"', '"blanks"', '"template"', '"answer"', '"pairs"', '"explanation"', 'Reconciliation', 'chart of accounts', 'COA' ) as $secret ) {
				$this->assertStringNotContainsString( $secret, $json, "{$type} leaks {$secret}." );
			}
		}
	}

	/**
	 * @covers ::public_view
	 * @covers ::segments
	 * @covers ::seeded_shuffle
	 */
	public function test_blanks_become_inputs_and_matches_shuffle_per_attempt() {
		$fill = $this->bank->get( $this->create_question( $this->sample_questions()['fill_blank'] ) );
		$this->assertSame(
			array(
				array(
					'type' => 'text',
					'text' => 'A ',
				),
				array(
					'type'  => 'blank',
					'index' => 0,
				),
				array(
					'type' => 'text',
					'text' => ' lists every ',
				),
				array(
					'type'  => 'blank',
					'index' => 1,
				),
				array(
					'type' => 'text',
					'text' => '.',
				),
			),
			$this->bank->public_view( $fill, '1', false )['segments']
		);

		$matching = $this->bank->get( $this->create_question( $this->sample_questions()['matching'] ) );
		$this->assertSame( $this->bank->public_view( $matching, '7', false )['matches'], $this->bank->public_view( $matching, '7', false )['matches'], 'Same seed, same order.' );
	}

	/**
	 * @covers ::save
	 * @covers ::quizzes_using
	 */
	public function test_editing_keeps_option_ids_and_quizzes_using_a_question_are_found() {
		$id       = $this->create_question( $this->sample_questions()['single'] );
		$question = $this->bank->get( $id );
		$first    = $question['options'][0]['id'];

		$question['options'][0]['text'] = 'Profit and loss statement';
		$this->bank->save( $this->bank->sanitize( $question ), $id );
		$this->assertSame( $first, $this->bank->get( $id )['options'][0]['id'] );
		$this->assertSame( 'Profit and loss statement', $this->bank->get( $id )['options'][0]['text'] );

		$fixture = $this->create_course_with_lessons();
		$quiz    = $this->create_quiz( $fixture['sections'][0] );
		QuizSettings::set_question_ids( $quiz, array( $id ) );
		$this->assertSame( array( $quiz ), $this->bank->quizzes_using( $id ) );
		$this->assertSame( array(), $this->bank->quizzes_using( $id + 1000 ) );
	}
}
