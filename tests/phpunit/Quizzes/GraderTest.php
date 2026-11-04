<?php
/**
 * Tests for server-side grading.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Quizzes;

use PublicLMS\Quizzes\Grader;
use PublicLMS\Quizzes\QuestionBank;
use PublicLMS\Tests\TestCase;

/**
 * @coversDefaultClass \PublicLMS\Quizzes\Grader
 */
class GraderTest extends TestCase {

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
	 * Saves a sample question and reads it back with its key.
	 *
	 * @param string $type Sample type.
	 * @return array<string, mixed>
	 */
	private function question( string $type ): array {
		$question = $this->bank->get( $this->create_question( $this->sample_questions()[ $type ] ) );
		$this->assertNotNull( $question );
		return $question;
	}

	/**
	 * Returns option IDs of a choice question by their text.
	 *
	 * @param array<string, mixed> $question Question.
	 * @return array<string, string>
	 */
	private function options( array $question ): array {
		return wp_list_pluck( $question['options'], 'id', 'text' );
	}

	/**
	 * @covers ::grade
	 */
	public function test_single_choice_is_all_or_nothing() {
		$question = $this->question( 'single' );
		$ids      = $this->options( $question );
		$this->assertSame( 1.0, Grader::grade( $question, $ids['Profit and loss'] )['points'] );
		$this->assertTrue( Grader::grade( $question, $ids['Profit and loss'] )['correct'] );
		$this->assertSame( 0.0, Grader::grade( $question, $ids['Balance sheet'] )['points'] );
		$this->assertSame( 0.0, Grader::grade( $question, array( $ids['Profit and loss'] ) )['points'], 'Wrong shape scores zero.' );
		$this->assertSame( 0.0, Grader::grade( $question, null )['points'] );
	}

	/**
	 * @covers ::grade
	 */
	public function test_multiple_choice_needs_exactly_the_correct_set() {
		$question = $this->question( 'multiple' );
		$ids      = $this->options( $question );
		$this->assertSame( 2.0, Grader::grade( $question, array( $ids['Wages'], $ids['Rent'] ) )['points'] );
		$this->assertSame( 0.0, Grader::grade( $question, array( $ids['Rent'] ) )['points'] );
		$this->assertSame( 0.0, Grader::grade( $question, array( $ids['Rent'], $ids['Wages'], $ids['Sales'] ) )['points'] );
		$this->assertSame( 2.0, Grader::grade( $question, array( $ids['Rent'], $ids['Rent'], $ids['Wages'] ) )['max_points'] );
	}

	/**
	 * @covers ::grade
	 */
	public function test_true_false_needs_a_boolean() {
		$question = $this->question( 'true_false' );
		$this->assertTrue( Grader::grade( $question, true )['correct'] );
		$this->assertFalse( Grader::grade( $question, false )['correct'] );
		$this->assertFalse( Grader::grade( $question, 'true' )['correct'], 'The service converts strings before grading.' );
	}

	/**
	 * @covers ::grade
	 * @covers ::normalize
	 */
	public function test_fill_blank_gives_partial_credit_and_ignores_case_and_spacing() {
		$question = $this->question( 'fill_blank' );
		$this->assertSame( array( array( 'chart of accounts', 'COA' ), array( 'category' ) ), $question['blanks'] );

		$full = Grader::grade( $question, array( '  Chart   of ACCOUNTS ', 'Category' ) );
		$this->assertTrue( $full['correct'] );
		$this->assertSame( 2.0, $full['points'] );

		$half = Grader::grade( $question, array( 'coa', 'account' ) );
		$this->assertFalse( $half['correct'] );
		$this->assertSame( 1.0, $half['points'] );
		$this->assertSame( 0.0, Grader::grade( $question, 'chart of accounts' )['points'] );
	}

	/**
	 * @covers ::grade
	 */
	public function test_short_answer_accepts_any_listed_answer_case_insensitively() {
		$question = $this->question( 'short_answer' );
		$this->assertTrue( Grader::grade( $question, 'RECONCILIATION' )['correct'] );
		$this->assertTrue( Grader::grade( $question, ' reconciling ' )['correct'] );
		$this->assertFalse( Grader::grade( $question, 'reconcile' )['correct'] );
		$this->assertFalse( Grader::grade( $question, '' )['correct'] );
	}

	/**
	 * @covers ::grade
	 */
	public function test_matching_scores_each_pair() {
		$question = $this->question( 'matching' );
		$pairs    = $question['pairs'];
		$right    = array(
			$pairs[0]['prompt_id'] => $pairs[0]['match_id'],
			$pairs[1]['prompt_id'] => $pairs[1]['match_id'],
		);
		$this->assertTrue( Grader::grade( $question, $right )['correct'] );

		$swapped = array(
			$pairs[0]['prompt_id'] => $pairs[1]['match_id'],
			$pairs[1]['prompt_id'] => $pairs[1]['match_id'],
		);
		$this->assertSame( 0.5, Grader::grade( $question, $swapped )['points'] );
		$this->assertNotSame( $pairs[0]['prompt_id'], $pairs[0]['match_id'] );
	}
}
