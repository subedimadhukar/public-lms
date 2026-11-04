<?php
/**
 * Gradebook.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Services;

use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Repositories\QuizAttemptRepository;
use PublicLMS\Repositories\SubmissionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Learners by gradable items for one course, and its CSV export (free, ADR-019, ADR-043).
 *
 * Columns are the course's published quizzes and assignments in curriculum
 * order. A quiz cell is the learner's best graded attempt; an assignment cell
 * is the current hand-in, graded or waiting. The average is the plain mean of
 * the graded cells as percentages; missing work is left out rather than
 * counted as zero, and weighted grades are Pro. Missing work is labelled
 * and counted per learner instead: "Not handed in" (assignments) and "Not
 * taken" (quizzes), kept apart from "Waiting for a grade"; none of the three
 * counts in the average (decision 4 of phase 2).
 */
class Gradebook {

	/**
	 * Course structure.
	 *
	 * @var CourseStructureRepositoryInterface
	 */
	private $structure;

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
	 * Progress.
	 *
	 * @var ProgressService
	 */
	private $progress;

	/**
	 * Constructor.
	 *
	 * @param CourseStructureRepositoryInterface $structure   Course structure.
	 * @param EnrollmentRepository               $enrollments Enrolments.
	 * @param QuizAttemptRepository              $attempts    Quiz attempts.
	 * @param SubmissionRepository               $submissions Hand-ins.
	 * @param ProgressService                    $progress    Progress.
	 */
	public function __construct( CourseStructureRepositoryInterface $structure, EnrollmentRepository $enrollments, QuizAttemptRepository $attempts, SubmissionRepository $submissions, ProgressService $progress ) {
		$this->structure   = $structure;
		$this->enrollments = $enrollments;
		$this->attempts    = $attempts;
		$this->submissions = $submissions;
		$this->progress    = $progress;
	}

	/**
	 * Returns the gradable items of a course in curriculum order.
	 *
	 * @param int $course_id Course ID.
	 * @return array<int, array{id: int, type: string, title: string, max: float}>
	 */
	public function columns( int $course_id ): array {
		$columns = array();
		foreach ( $this->structure->get_public_structure( $course_id )['sections'] as $section ) {
			foreach ( (array) $section['items'] as $item ) {
				if ( 'quiz' !== $item['type'] && 'assignment' !== $item['type'] ) {
					continue;
				}
				$columns[] = array(
					'id'    => (int) $item['id'],
					'type'  => (string) $item['type'],
					'title' => wp_strip_all_tags( get_the_title( (int) $item['id'] ) ),
					'max'   => 'quiz' === $item['type'] ? 100.0 : \PublicLMS\Assignments\AssignmentSettings::get( (int) $item['id'] )['points'],
				);
			}
		}
		return $columns;
	}

	/**
	 * Returns a page of the gradebook.
	 *
	 * @param int $course_id Course ID.
	 * @param int $after     Continue after this enrolment ID.
	 * @param int $limit     Learners per page.
	 * @return array{columns: array<int, array<string, mixed>>, rows: array<int, array<string, mixed>>, next: int, total: int}
	 */
	public function page( int $course_id, int $after = 0, int $limit = 50 ): array {
		$columns = $this->columns( $course_id );
		$raw     = $this->enrollments->find_by_course( $course_id, null, $after, $limit + 1 );
		$next    = 0;
		if ( count( $raw ) > $limit ) {
			$raw  = array_slice( $raw, 0, $limit );
			$next = (int) end( $raw )['id'];
		}
		$enrollments = array_values(
			array_filter(
				$raw,
				static function ( $row ) {
					return EnrollmentRepository::STATUS_CANCELLED !== $row['status'];
				}
			)
		);

		$user_ids    = array_map( 'intval', wp_list_pluck( $enrollments, 'user_id' ) );
		$quiz_ids    = array();
		$assignments = array();
		foreach ( $columns as $column ) {
			if ( 'quiz' === $column['type'] ) {
				$quiz_ids[] = $column['id'];
			} else {
				$assignments[] = $column['id'];
			}
		}
		$best    = $this->attempts->best_by_users( $user_ids, $quiz_ids );
		$handins = $this->submissions->current_by_users( $user_ids, $assignments );

		$rows = array();
		foreach ( $enrollments as $enrolment ) {
			$user_id = (int) $enrolment['user_id'];
			$user    = get_userdata( $user_id );
			$cells   = array();
			$scores  = array();
			$counts  = array(
				'not_handed_in' => 0,
				'not_taken'     => 0,
				'waiting'       => 0,
			);
			foreach ( $columns as $column ) {
				$cell = 'quiz' === $column['type']
					? $this->quiz_cell( $best[ $user_id ][ $column['id'] ] ?? null )
					: $this->assignment_cell( $handins[ $user_id ][ $column['id'] ] ?? null, $column['max'] );
				if ( null !== $cell['percent'] ) {
					$scores[] = $cell['percent'];
				}
				if ( 'none' === $cell['status'] ) {
					++$counts[ 'quiz' === $column['type'] ? 'not_taken' : 'not_handed_in' ];
				} elseif ( 'submitted' === $cell['status'] ) {
					++$counts['waiting'];
				}
				$cells[ (string) $column['id'] ] = $cell;
			}
			$rows[] = array(
				'enrollment_id' => (int) $enrolment['id'],
				'user_id'       => $user_id,
				'name'          => false === $user ? '' : (string) $user->display_name,
				'email'         => false === $user ? '' : (string) $user->user_email,
				'status'        => (string) $enrolment['status'],
				'progress'      => $this->progress->summary( $user_id, $course_id )['percent'],
				'cells'         => (object) $cells,
				'counts'        => $counts,
				'average'       => empty( $scores ) ? null : round( array_sum( $scores ) / count( $scores ), 1 ),
			);
		}

		return array(
			'columns' => $columns,
			'rows'    => $rows,
			'next'    => $next,
			'total'   => $this->enrollments->count_by_course( $course_id ) - $this->enrollments->count_by_course( $course_id, EnrollmentRepository::STATUS_CANCELLED ),
		);
	}

	/**
	 * Builds the whole gradebook of a course as CSV.
	 *
	 * @param int $course_id Course ID.
	 * @return string
	 */
	public function csv( int $course_id ): string {
		$columns = $this->columns( $course_id );
		$header  = array( __( 'Learner', 'public-lms' ), __( 'Email', 'public-lms' ), __( 'Progress (%)', 'public-lms' ) );
		foreach ( $columns as $column ) {
			$header[] = 'quiz' === $column['type']
				/* translators: %s: quiz title. */
				? sprintf( __( '%s (best %%)', 'public-lms' ), $column['title'] )
				/* translators: 1: assignment title, 2: highest grade. */
				: sprintf( __( '%1$s (out of %2$s)', 'public-lms' ), $column['title'], self::number( $column['max'] ) );
		}
		$header[] = __( 'Average (%)', 'public-lms' );
		$header[] = __( 'Not handed in', 'public-lms' );
		$header[] = __( 'Not taken', 'public-lms' );
		$header[] = __( 'Waiting for a grade', 'public-lms' );
		$lines    = array( self::csv_line( $header ) );

		$after = 0;
		do {
			$page = $this->page( $course_id, $after, 200 );
			foreach ( $page['rows'] as $row ) {
				$line  = array( $row['name'], $row['email'], (string) $row['progress'] );
				$cells = (array) $row['cells'];
				foreach ( $columns as $column ) {
					$cell = $cells[ (string) $column['id'] ];
					if ( 'none' === $cell['status'] || 'submitted' === $cell['status'] ) {
						$line[] = (string) $cell['label'];
					} elseif ( 'quiz' === $column['type'] ) {
						$line[] = self::number( (float) $cell['percent'] );
					} else {
						$line[] = null === $cell['grade'] ? '' : self::number( (float) $cell['grade'] );
					}
				}
				$line[]  = null === $row['average'] ? '' : self::number( (float) $row['average'] );
				$line[]  = (string) $row['counts']['not_handed_in'];
				$line[]  = (string) $row['counts']['not_taken'];
				$line[]  = (string) $row['counts']['waiting'];
				$lines[] = self::csv_line( $line );
			}
			$after = $page['next'];
		} while ( $after > 0 );

		return "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Builds a quiz cell from the learner's best attempt.
	 *
	 * @param array<string, mixed>|null $best Best attempt summary, or null.
	 * @return array<string, mixed>
	 */
	private function quiz_cell( ?array $best ): array {
		if ( null === $best ) {
			return array(
				'status'   => 'none',
				'label'    => __( 'Not taken', 'public-lms' ),
				'percent'  => null,
				'grade'    => null,
				'passed'   => null,
				'attempts' => 0,
			);
		}
		return array(
			'status'   => 'graded',
			'label'    => '',
			'percent'  => round( (float) $best['percent'], 1 ),
			'grade'    => round( (float) $best['percent'], 1 ),
			'passed'   => (bool) $best['passed'],
			'attempts' => (int) $best['attempts'],
		);
	}

	/**
	 * Builds an assignment cell from the learner's current hand-in.
	 *
	 * @param array<string, mixed>|null $handin Hand-in summary, or null.
	 * @param float                     $max    Highest grade.
	 * @return array<string, mixed>
	 */
	private function assignment_cell( ?array $handin, float $max ): array {
		if ( null === $handin ) {
			return array(
				'status'        => 'none',
				'label'         => __( 'Not handed in', 'public-lms' ),
				'percent'       => null,
				'grade'         => null,
				'passed'        => null,
				'submission_id' => 0,
			);
		}
		$out_of = null === $handin['max_grade'] ? $max : (float) $handin['max_grade'];
		$graded = SubmissionRepository::STATUS_GRADED === $handin['status'] && null !== $handin['grade'];
		return array(
			'status'        => $graded ? 'graded' : 'submitted',
			'label'         => $graded ? '' : __( 'Waiting for a grade', 'public-lms' ),
			'percent'       => $graded && $out_of > 0 ? round( (float) $handin['grade'] / $out_of * 100, 1 ) : null,
			'grade'         => $graded ? (float) $handin['grade'] : null,
			'max'           => $out_of,
			'passed'        => $graded ? (bool) $handin['passed'] : null,
			'submission_id' => (int) $handin['id'],
		);
	}

	/**
	 * Formats a number for CSV without thousands separators.
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private static function number( float $value ): string {
		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Encodes one CSV line, neutralising cells a spreadsheet would run as formulas.
	 *
	 * @param array<int, string> $cells Cells.
	 * @return string
	 */
	public static function csv_line( array $cells ): string {
		$out = array();
		foreach ( $cells as $cell ) {
			$cell = (string) $cell;
			if ( '' !== $cell && in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $cell ) ) {
				$cell = "'" . $cell;
			}
			$out[] = '"' . str_replace( '"', '""', $cell ) . '"';
		}
		return implode( ',', $out );
	}
}
