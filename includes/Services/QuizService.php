<?php
/**
 * Quiz service.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Services;

use PublicLMS\Access\AccessPolicy;
use PublicLMS\Core\RateLimiter;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Quizzes\Grader;
use PublicLMS\Quizzes\QuestionBank;
use PublicLMS\Quizzes\QuizSettings;
use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use PublicLMS\Repositories\EventRepositoryInterface;
use PublicLMS\Repositories\QuizAttemptRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Runs quiz attempts: start or resume, save answers, submit and grade
 * (ADR-016, ADR-042).
 *
 * Grading happens only here, on the server. The learner's view of a question
 * never holds its answer key, and results say right or wrong per question,
 * never what the right answer was. A passed attempt completes the quiz in
 * the learner's progress. An attempt past its time limit (plus a minute's
 * grace for slow connections) is graded with the answers saved before the
 * deadline; answers sent later are ignored.
 */
class QuizService {

	/**
	 * Seconds after the deadline in which a submission still counts.
	 */
	const GRACE = 60;

	/**
	 * Attempts.
	 *
	 * @var QuizAttemptRepository
	 */
	private $attempts;

	/**
	 * Question bank.
	 *
	 * @var QuestionBank
	 */
	private $questions;

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
	 * @param QuizAttemptRepository              $attempts  Attempts.
	 * @param QuestionBank                       $questions Question bank.
	 * @param CourseStructureRepositoryInterface $structure Course structure.
	 * @param ProgressService                    $progress  Progress.
	 * @param EventRepositoryInterface           $events    Event log.
	 * @param AccessPolicy                       $policy    Access policy.
	 * @param RateLimiter                        $limiter   Rate limiter.
	 */
	public function __construct( QuizAttemptRepository $attempts, QuestionBank $questions, CourseStructureRepositoryInterface $structure, ProgressService $progress, EventRepositoryInterface $events, AccessPolicy $policy, RateLimiter $limiter ) {
		$this->attempts  = $attempts;
		$this->questions = $questions;
		$this->structure = $structure;
		$this->progress  = $progress;
		$this->events    = $events;
		$this->policy    = $policy;
		$this->limiter   = $limiter;
	}

	/**
	 * Starts an attempt, or resumes the one in progress.
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return array{attempt: array<string, mixed>, resumed: bool}|WP_Error
	 */
	public function start( int $user_id, int $quiz_id ) {
		if ( $user_id <= 0 ) {
			return new WP_Error( 'publiclms_logged_out', __( 'Log in to take this quiz.', 'public-lms' ), array( 'status' => 401 ) );
		}
		$current = $this->attempts->in_progress( $user_id, $quiz_id );
		if ( null !== $current ) {
			$current = $this->expire_if_overdue( $current );
			if ( QuizAttemptRepository::STATUS_IN_PROGRESS === $current['status'] ) {
				return array(
					'attempt' => $current,
					'resumed' => true,
				);
			}
		}

		$decision = $this->policy->can_attempt_quiz( $quiz_id, $user_id );
		if ( ! $decision->is_allowed() ) {
			return new WP_Error(
				'publiclms_quiz_' . $decision->reason(),
				$decision->message(),
				array(
					'status' => 403,
					'reason' => $decision->reason(),
				)
			);
		}

		$retry = $this->limiter->hit( 'quiz_start', $user_id . ':' . $quiz_id, 20, HOUR_IN_SECONDS );
		if ( $retry > 0 ) {
			return $this->too_many( $retry );
		}

		$drawn = $this->draw( $quiz_id );
		if ( empty( $drawn ) ) {
			return new WP_Error( 'publiclms_quiz_empty', __( 'This quiz has no questions yet.', 'public-lms' ), array( 'status' => 409 ) );
		}
		$max = 0.0;
		foreach ( $drawn as $question_id ) {
			$question = $this->questions->get( $question_id );
			$max     += null === $question ? 0.0 : (float) $question['points'];
		}
		$id = $this->attempts->start( $user_id, $quiz_id, $this->structure->course_id_for( $quiz_id ), $drawn, $max );
		if ( $id <= 0 ) {
			return new WP_Error( 'publiclms_quiz_not_started', __( 'The quiz could not be started. Try again.', 'public-lms' ), array( 'status' => 500 ) );
		}
		$attempt = $this->attempts->find( $id );
		$this->events->record(
			'quiz.started',
			array(
				'user_id'     => $user_id,
				'course_id'   => $this->structure->course_id_for( $quiz_id ),
				'object_type' => 'quiz',
				'object_id'   => $quiz_id,
				'payload'     => array( 'attempt_id' => $id ),
			)
		);
		return array(
			'attempt' => null === $attempt ? array() : $attempt,
			'resumed' => false,
		);
	}

	/**
	 * Saves answers to an attempt in progress without grading them.
	 *
	 * @param int                  $user_id    User ID.
	 * @param int                  $attempt_id Attempt ID.
	 * @param array<string, mixed> $answers    Answers keyed by question ID.
	 * @return array<string, mixed>|WP_Error The attempt.
	 */
	public function save_answers( int $user_id, int $attempt_id, array $answers ) {
		$attempt = $this->own_attempt( $user_id, $attempt_id );
		if ( is_wp_error( $attempt ) ) {
			return $attempt;
		}
		$attempt = $this->expire_if_overdue( $attempt );
		if ( QuizAttemptRepository::STATUS_IN_PROGRESS !== $attempt['status'] ) {
			return new WP_Error( 'publiclms_attempt_closed', __( 'This attempt has already been submitted.', 'public-lms' ), array( 'status' => 409 ) );
		}
		$retry = $this->limiter->hit( 'quiz_answers', (string) $user_id, 120, MINUTE_IN_SECONDS );
		if ( $retry > 0 ) {
			return $this->too_many( $retry );
		}
		$this->store_answers( $attempt, $answers );
		return $attempt;
	}

	/**
	 * Submits and grades an attempt.
	 *
	 * @param int                       $user_id    User ID.
	 * @param int                       $attempt_id Attempt ID.
	 * @param array<string, mixed>|null $answers    Final answers keyed by question ID, or null to grade the saved ones.
	 * @return array<string, mixed>|WP_Error The graded attempt.
	 */
	public function submit( int $user_id, int $attempt_id, ?array $answers ) {
		$attempt = $this->own_attempt( $user_id, $attempt_id );
		if ( is_wp_error( $attempt ) ) {
			return $attempt;
		}
		if ( QuizAttemptRepository::STATUS_IN_PROGRESS !== $attempt['status'] ) {
			return new WP_Error( 'publiclms_attempt_closed', __( 'This attempt has already been submitted.', 'public-lms' ), array( 'status' => 409 ) );
		}
		$retry = $this->limiter->hit( 'quiz_submit', (string) $user_id, 6, MINUTE_IN_SECONDS );
		if ( $retry > 0 ) {
			return $this->too_many( $retry );
		}
		$deadline = $this->deadline( $attempt );
		if ( null !== $answers && ( null === $deadline || time() <= $deadline + self::GRACE ) ) {
			$this->store_answers( $attempt, $answers );
		}
		return $this->grade( $attempt );
	}

	/**
	 * Grades an attempt in progress whose time ran out; returns the attempt as it now stands.
	 *
	 * @param array<string, mixed> $attempt Attempt row.
	 * @return array<string, mixed>
	 */
	public function expire_if_overdue( array $attempt ): array {
		$deadline = $this->deadline( $attempt );
		if ( QuizAttemptRepository::STATUS_IN_PROGRESS !== $attempt['status'] || null === $deadline || time() <= $deadline + self::GRACE ) {
			return $attempt;
		}
		return $this->grade( $attempt );
	}

	/**
	 * Returns when an attempt's time runs out, or null without a time limit.
	 *
	 * @param array<string, mixed> $attempt Attempt row.
	 * @return int|null Unix timestamp.
	 */
	public function deadline( array $attempt ): ?int {
		$limit = QuizSettings::get( (int) $attempt['quiz_id'] )['time_limit'];
		if ( $limit <= 0 ) {
			return null;
		}
		$start = strtotime( (string) $attempt['started_at'] . ' UTC' );
		return false === $start ? null : $start + $limit * MINUTE_IN_SECONDS;
	}

	/**
	 * Returns the learner's view of an attempt in progress: questions without
	 * answer keys, saved answers, and the deadline.
	 *
	 * @param array<string, mixed> $attempt Attempt row.
	 * @return array<string, mixed>
	 */
	public function attempt_view( array $attempt ): array {
		$settings = QuizSettings::get( (int) $attempt['quiz_id'] );
		$saved    = $this->attempts->answers( (int) $attempt['id'] );
		$views    = array();
		$answers  = array();
		foreach ( (array) $attempt['question_ids'] as $index => $question_id ) {
			$question = $this->questions->get( (int) $question_id );
			if ( null === $question ) {
				continue;
			}
			$view           = $this->questions->public_view( $question, (string) $attempt['id'], $settings['shuffle_answers'] );
			$view['number'] = $index + 1;
			$views[]        = $view;
			if ( isset( $saved[ (int) $question_id ] ) && null !== $saved[ (int) $question_id ]['answer'] ) {
				$answers[ (string) $question_id ] = $saved[ (int) $question_id ]['answer'];
			}
		}
		$deadline = $this->deadline( $attempt );
		return array_merge(
			$this->summary( $attempt ),
			array(
				'questions'  => $views,
				'answers'    => (object) $answers,
				'deadline'   => $deadline,
				'time_left'  => null === $deadline ? null : max( 0, $deadline - time() ),
				'time_limit' => $settings['time_limit'],
			)
		);
	}

	/**
	 * Returns the results of a submitted attempt: score, pass, and right or
	 * wrong per question when the quiz shows results. Never the answer key.
	 *
	 * @param array<string, mixed> $attempt Attempt row.
	 * @return array<string, mixed>
	 */
	public function result_view( array $attempt ): array {
		$settings          = QuizSettings::get( (int) $attempt['quiz_id'] );
		$data              = $this->summary( $attempt );
		$data['pass_mark'] = $settings['pass_mark'];
		$data['questions'] = array();
		if ( ! $settings['show_results'] ) {
			return $data;
		}
		$saved = $this->attempts->answers( (int) $attempt['id'] );
		foreach ( (array) $attempt['question_ids'] as $index => $question_id ) {
			$question = $this->questions->get( (int) $question_id );
			$answer   = $saved[ (int) $question_id ] ?? null;
			if ( null === $question ) {
				continue;
			}
			$data['questions'][] = array(
				'id'          => (int) $question_id,
				'number'      => $index + 1,
				'text'        => wp_kses_post( $question['text'] ),
				'correct'     => null === $answer ? false : (bool) $answer['is_correct'],
				'answered'    => null !== $answer && null !== $answer['answer'],
				'points'      => null === $answer ? 0.0 : (float) $answer['points'],
				'max_points'  => (float) $question['points'],
				'explanation' => wp_kses_post( $question['explanation'] ),
			);
		}
		return $data;
	}

	/**
	 * Summarises a learner's standing on a quiz.
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return array{attempts_used: int, attempts_left: int|null, best_percent: float|null, passed: bool, in_progress: int, can_attempt: array<string, mixed>}
	 */
	public function learner_summary( int $user_id, int $quiz_id ): array {
		$settings = QuizSettings::get( $quiz_id );
		$used     = $user_id > 0 ? $this->attempts->count_finished( $user_id, $quiz_id ) : 0;
		$best     = $user_id > 0 ? $this->attempts->best( $user_id, $quiz_id ) : null;
		$current  = $user_id > 0 ? $this->attempts->in_progress( $user_id, $quiz_id ) : null;
		$passed   = false;
		foreach ( $user_id > 0 ? $this->attempts->attempts_for_user( $user_id, $quiz_id ) : array() as $row ) {
			$passed = $passed || ! empty( $row['passed'] );
		}
		return array(
			'attempts_used' => $used,
			'attempts_left' => $settings['max_attempts'] > 0 ? max( 0, $settings['max_attempts'] - $used ) : null,
			'best_percent'  => null === $best ? null : (float) $best['percent'],
			'passed'        => $passed,
			'in_progress'   => null === $current ? 0 : (int) $current['id'],
			'can_attempt'   => $this->policy->can_attempt_quiz( $quiz_id, $user_id )->to_array(),
		);
	}

	/**
	 * Returns how many questions an attempt shows: the draw size, or every published question.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return int
	 */
	public function question_total( int $quiz_id ): int {
		$published = count( $this->published_questions( $quiz_id ) );
		$count     = QuizSettings::get( $quiz_id )['question_count'];
		return $count > 0 ? min( $count, $published ) : $published;
	}

	/**
	 * Returns the published question IDs of a quiz, in the quiz's order.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return array<int, int>
	 */
	public function published_questions( int $quiz_id ): array {
		return array_values(
			array_filter(
				QuizSettings::question_ids( $quiz_id ),
				static function ( $id ) {
					return PostTypes::QUESTION === get_post_type( $id ) && 'publish' === get_post_status( $id );
				}
			)
		);
	}

	/**
	 * Returns the published question IDs an attempt draws, in the order shown.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return array<int, int>
	 */
	public function draw( int $quiz_id ): array {
		$settings = QuizSettings::get( $quiz_id );
		$all      = $this->published_questions( $quiz_id );
		$drawn    = $all;
		if ( $settings['question_count'] > 0 && $settings['question_count'] < count( $all ) ) {
			shuffle( $drawn );
			$drawn = array_slice( $drawn, 0, $settings['question_count'] );
			if ( ! $settings['shuffle_questions'] ) {
				$drawn = array_values( array_intersect( $all, $drawn ) );
			}
		} elseif ( $settings['shuffle_questions'] ) {
			shuffle( $drawn );
		}
		return array_map( 'intval', $drawn );
	}

	/**
	 * Cleans a learner's answer to the shape its question type expects, or null.
	 *
	 * @param array<string, mixed> $question Question with its answer key.
	 * @param mixed                $raw      Answer as sent.
	 * @return mixed
	 */
	public function sanitize_answer( array $question, $raw ) {
		switch ( $question['type'] ) {
			case QuestionBank::SINGLE:
				$ids = wp_list_pluck( (array) $question['options'], 'id' );
				return is_string( $raw ) && in_array( $raw, $ids, true ) ? $raw : null;

			case QuestionBank::MULTIPLE:
				$ids    = wp_list_pluck( (array) $question['options'], 'id' );
				$chosen = is_array( $raw ) ? array_values( array_intersect( $ids, array_filter( $raw, 'is_string' ) ) ) : array();
				return empty( $chosen ) ? null : $chosen;

			case QuestionBank::TRUE_FALSE:
				if ( is_bool( $raw ) ) {
					return $raw;
				}
				return 'true' === $raw ? true : ( 'false' === $raw ? false : null );

			case QuestionBank::SHORT_ANSWER:
				$text = is_scalar( $raw ) ? trim( sanitize_text_field( (string) $raw ) ) : '';
				return '' === $text ? null : mb_substr( $text, 0, 500 );

			case QuestionBank::FILL_BLANK:
				if ( ! is_array( $raw ) ) {
					return null;
				}
				$values = array();
				$any    = false;
				foreach ( array_keys( (array) $question['blanks'] ) as $index ) {
					$value    = isset( $raw[ $index ] ) && is_scalar( $raw[ $index ] ) ? trim( sanitize_text_field( (string) $raw[ $index ] ) ) : '';
					$values[] = mb_substr( $value, 0, 200 );
					$any      = $any || '' !== $value;
				}
				return $any ? $values : null;

			case QuestionBank::MATCHING:
				if ( ! is_array( $raw ) ) {
					return null;
				}
				$prompts = wp_list_pluck( (array) $question['pairs'], 'prompt_id' );
				$matches = wp_list_pluck( (array) $question['pairs'], 'match_id' );
				$clean   = array();
				foreach ( $raw as $prompt_id => $match_id ) {
					if ( in_array( (string) $prompt_id, $prompts, true ) && is_string( $match_id ) && in_array( $match_id, $matches, true ) ) {
						$clean[ (string) $prompt_id ] = $match_id;
					}
				}
				return empty( $clean ) ? null : $clean;
		}
		return null;
	}

	/**
	 * Saves answers that belong to the attempt's questions, ungraded.
	 *
	 * @param array<string, mixed> $attempt Attempt row.
	 * @param array<string, mixed> $answers Answers keyed by question ID.
	 * @return void
	 */
	private function store_answers( array $attempt, array $answers ): void {
		foreach ( $answers as $question_id => $raw ) {
			$question_id = absint( $question_id );
			if ( ! in_array( $question_id, (array) $attempt['question_ids'], true ) ) {
				continue;
			}
			$question = $this->questions->get( $question_id );
			if ( null === $question ) {
				continue;
			}
			$this->attempts->save_answer( (int) $attempt['id'], $question_id, array( 'answer' => $this->sanitize_answer( $question, $raw ) ) );
		}
	}

	/**
	 * Grades every question of an attempt from its saved answers and closes it.
	 *
	 * @param array<string, mixed> $attempt Attempt row.
	 * @return array<string, mixed> The graded attempt.
	 */
	private function grade( array $attempt ): array {
		$attempt_id = (int) $attempt['id'];
		$user_id    = (int) $attempt['user_id'];
		$quiz_id    = (int) $attempt['quiz_id'];
		$saved      = $this->attempts->answers( $attempt_id );
		$score      = 0.0;
		$max        = 0.0;

		foreach ( (array) $attempt['question_ids'] as $question_id ) {
			$question = $this->questions->get( (int) $question_id );
			if ( null === $question ) {
				continue;
			}
			$answer = $saved[ (int) $question_id ]['answer'] ?? null;
			$result = Grader::grade( $question, $answer );
			$score += $result['points'];
			$max   += $result['max_points'];
			$this->attempts->save_answer(
				$attempt_id,
				(int) $question_id,
				array(
					'answer'     => $answer,
					'is_correct' => $result['correct'],
					'points'     => $result['points'],
					'max_points' => $result['max_points'],
				)
			);
		}

		$settings = QuizSettings::get( $quiz_id );
		$percent  = $max > 0 ? round( $score / $max * 100, 2 ) : 100.0;
		$passed   = $percent >= $settings['pass_mark'];
		$started  = strtotime( (string) $attempt['started_at'] . ' UTC' );
		$was      = $this->attempts->best( $user_id, $quiz_id );

		$this->attempts->finish(
			$attempt_id,
			array(
				'status'     => QuizAttemptRepository::STATUS_GRADED,
				'score'      => round( $score, 2 ),
				'max_score'  => round( $max, 2 ),
				'percent'    => $percent,
				'passed'     => $passed ? 1 : 0,
				'time_spent' => false === $started ? 0 : max( 0, time() - $started ),
			)
		);
		$graded = $this->attempts->find( $attempt_id );
		$graded = null === $graded ? $attempt : $graded;

		$course_id = $this->structure->course_id_for( $quiz_id );
		$this->events->record(
			'quiz.graded',
			array(
				'user_id'     => $user_id,
				'course_id'   => $course_id,
				'object_type' => 'quiz',
				'object_id'   => $quiz_id,
				'payload'     => array(
					'attempt_id' => $attempt_id,
					'percent'    => $percent,
					'passed'     => $passed,
				),
			)
		);

		/**
		 * Fires after the server graded a quiz attempt.
		 *
		 * @param array<string, mixed> $attempt The graded attempt row.
		 * @param int                  $user_id User ID.
		 * @param int                  $quiz_id Quiz ID.
		 */
		do_action( 'publiclms_quiz_graded', $graded, $user_id, $quiz_id );

		if ( $passed ) {
			// Completes the quiz in the learner's progress; refused quietly when not enrolled (a preview quiz).
			$this->progress->complete_item( $user_id, $quiz_id );
			if ( null === $was || empty( $was['passed'] ) ) {
				/**
				 * Fires the first time a learner passes a quiz.
				 *
				 * @param array<string, mixed> $attempt The passing attempt.
				 * @param int                  $user_id User ID.
				 * @param int                  $quiz_id Quiz ID.
				 */
				do_action( 'publiclms_quiz_passed', $graded, $user_id, $quiz_id );
			}
		}
		return $graded;
	}

	/**
	 * Returns an attempt that belongs to the user, or an error.
	 *
	 * @param int $user_id    User ID.
	 * @param int $attempt_id Attempt ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function own_attempt( int $user_id, int $attempt_id ) {
		$attempt = $this->attempts->find( $attempt_id );
		if ( null === $attempt || $user_id <= 0 || (int) $attempt['user_id'] !== $user_id ) {
			return new WP_Error( 'publiclms_attempt_not_found', __( 'That attempt could not be found.', 'public-lms' ), array( 'status' => 404 ) );
		}
		return $attempt;
	}

	/**
	 * Returns the headline fields of an attempt.
	 *
	 * @param array<string, mixed> $attempt Attempt row.
	 * @return array<string, mixed>
	 */
	public function summary( array $attempt ): array {
		return array(
			'id'             => (int) $attempt['id'],
			'quiz_id'        => (int) $attempt['quiz_id'],
			'user_id'        => (int) $attempt['user_id'],
			'attempt_number' => (int) $attempt['attempt_number'],
			'status'         => (string) $attempt['status'],
			'score'          => $attempt['score'],
			'max_score'      => $attempt['max_score'],
			'percent'        => $attempt['percent'],
			'passed'         => $attempt['passed'],
			'started_at'     => self::utc( (string) $attempt['started_at'] ),
			'submitted_at'   => empty( $attempt['submitted_at'] ) ? null : self::utc( (string) $attempt['submitted_at'] ),
			'time_spent'     => $attempt['time_spent'],
			'question_count' => count( (array) $attempt['question_ids'] ),
		);
	}

	/**
	 * Formats a stored UTC datetime as ISO 8601 with a Z, for REST and state.
	 *
	 * @param string $mysql 'Y-m-d H:i:s' in UTC.
	 * @return string
	 */
	public static function utc( string $mysql ): string {
		return str_replace( ' ', 'T', $mysql ) . 'Z';
	}

	/**
	 * Returns a "slow down" error with the seconds to wait.
	 *
	 * @param int $retry Seconds until the limit resets.
	 * @return WP_Error
	 */
	private function too_many( int $retry ): WP_Error {
		return new WP_Error(
			'publiclms_rate_limited',
			__( 'Too many tries in a short time. Wait a moment and try again.', 'public-lms' ),
			array(
				'status'      => 429,
				'retry_after' => $retry,
			)
		);
	}
}
