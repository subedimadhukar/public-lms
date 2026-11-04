<?php
/**
 * Renders publiclms/student-dashboard.
 *
 * The current learner's courses with progress, split into tabs that follow
 * the WAI-ARIA tabs pattern (arrow keys, Home and End move between tabs).
 * Without JavaScript every panel is shown under its own heading.
 *
 * Tabs: in-progress, completed, quiz-results (best score per quiz, phase 2)
 * and certificates (phase 2). Orders join with checkout.
 *
 * @package PublicLMS
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner content.
 * @var WP_Block             $block      Block instance.
 */

use PublicLMS\Blocks\Blocks;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\QuizAttemptRepository;

defined( 'ABSPATH' ) || exit;

$publiclms_blocks = Blocks::instance();

if ( ! is_user_logged_in() ) {
	printf(
		'<div %1$s><div class="publiclms-locked-notice"><p>%2$s</p><p><a class="wp-element-button" href="%3$s">%4$s</a></p></div></div>',
		get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes().
		esc_html__( 'Log in to see your courses and progress.', 'public-lms' ),
		esc_url( wp_login_url( (string) get_permalink() ) ),
		esc_html__( 'Log in', 'public-lms' )
	);
	return;
}

$publiclms_user      = get_current_user_id();
$publiclms_available = array(
	'in-progress'  => __( 'In progress', 'public-lms' ),
	'completed'    => __( 'Completed', 'public-lms' ),
	'quiz-results' => __( 'Quiz results', 'public-lms' ),
);
/**
 * Filters the dashboard tabs a site offers. Certificates add theirs here.
 *
 * @param array<string, string> $publiclms_available Tab labels keyed by slug.
 * @param int                   $user_id             The learner.
 */
$publiclms_available = (array) apply_filters( 'publiclms_dashboard_tabs', $publiclms_available, get_current_user_id() );
$publiclms_tabs      = array_values( array_intersect( array_map( 'strval', (array) ( $attributes['tabs'] ?? array() ) ), array_keys( $publiclms_available ) ) );
$publiclms_tabs      = empty( $publiclms_tabs ) ? array_keys( $publiclms_available ) : $publiclms_tabs;
$publiclms_default   = in_array( $attributes['defaultTab'] ?? '', $publiclms_tabs, true ) ? (string) $attributes['defaultTab'] : $publiclms_tabs[0];

$publiclms_courses = array_fill_keys( array_keys( $publiclms_available ), array() );
foreach ( $publiclms_blocks->enrollments->find_by_user( $publiclms_user, 'active' ) as $publiclms_row ) {
	$publiclms_course_id = (int) $publiclms_row['course_id'];
	if ( PostTypes::COURSE !== get_post_type( $publiclms_course_id ) || 'publish' !== get_post_status( $publiclms_course_id ) ) {
		continue;
	}
	$publiclms_summary = $publiclms_blocks->progress->summary( $publiclms_user, $publiclms_course_id );
	$publiclms_state   = $publiclms_blocks->fields->enroll_state( $publiclms_course_id );
	$publiclms_courses[ $publiclms_summary['completed'] ? 'completed' : 'in-progress' ][] = array(
		'id'      => $publiclms_course_id,
		'summary' => $publiclms_summary,
		'url'     => $publiclms_state['url'],
		'label'   => $publiclms_state['label'],
	);
}

// Quiz results: one row per quiz, best attempt first.
if ( in_array( 'quiz-results', $publiclms_tabs, true ) ) {
	$publiclms_attempts = publiclms()->container()->get( QuizAttemptRepository::class )->recent_for_user( $publiclms_user );
	$publiclms_quizzes  = array();
	foreach ( $publiclms_attempts as $publiclms_attempt ) {
		$publiclms_quiz_id = (int) $publiclms_attempt['quiz_id'];
		if ( 'publish' !== get_post_status( $publiclms_quiz_id ) ) {
			continue;
		}
		if ( ! isset( $publiclms_quizzes[ $publiclms_quiz_id ] ) ) {
			$publiclms_quizzes[ $publiclms_quiz_id ] = array(
				'id'       => $publiclms_quiz_id,
				'course'   => (int) $publiclms_attempt['course_id'],
				'best'     => null,
				'passed'   => false,
				'attempts' => 0,
				'last'     => (string) ( $publiclms_attempt['submitted_at'] ?? '' ),
			);
		}
		$publiclms_row = &$publiclms_quizzes[ $publiclms_quiz_id ];
		++$publiclms_row['attempts'];
		$publiclms_row['passed'] = $publiclms_row['passed'] || ! empty( $publiclms_attempt['passed'] );
		if ( null !== $publiclms_attempt['percent'] && ( null === $publiclms_row['best'] || (float) $publiclms_attempt['percent'] > $publiclms_row['best'] ) ) {
			$publiclms_row['best'] = (float) $publiclms_attempt['percent'];
		}
		unset( $publiclms_row );
	}
	$publiclms_courses['quiz-results'] = array_values( $publiclms_quizzes );
}

/**
 * Lets other tabs (certificates, Pro's orders) supply their rows: an array per tab slug.
 *
 * @param array<string, array<int, mixed>> $publiclms_courses Rows keyed by tab slug.
 * @param int                              $user_id           The learner.
 * @param array<int, string>               $tabs              Tabs shown.
 */
$publiclms_courses = (array) apply_filters( 'publiclms_dashboard_rows', $publiclms_courses, $publiclms_user, $publiclms_tabs );

wp_interactivity_state( Blocks::STORE, Blocks::derived_state() );

$publiclms_uid     = wp_unique_id( 'publiclms-dashboard-' );
$publiclms_catalog = get_post_type_archive_link( PostTypes::COURSE );
$publiclms_heading = sprintf(
	/* translators: %s: learner's display name. */
	__( 'Welcome back, %s', 'public-lms' ),
	wp_get_current_user()->display_name
);
?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>
	data-wp-interactive="publiclms/learning"
	<?php echo wp_interactivity_data_wp_context( array( 'selected' => $publiclms_default ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
>
	<h2 class="wp-block-publiclms-student-dashboard__heading"><?php echo esc_html( $publiclms_heading ); ?></h2>

	<?php if ( count( $publiclms_tabs ) > 1 ) : ?>
		<div class="wp-block-publiclms-student-dashboard__tabs" role="tablist" aria-label="<?php esc_attr_e( 'My courses', 'public-lms' ); ?>">
			<?php foreach ( $publiclms_tabs as $publiclms_tab ) : ?>
				<?php $publiclms_selected = $publiclms_tab === $publiclms_default; ?>
				<button
					type="button"
					role="tab"
					id="<?php echo esc_attr( $publiclms_uid . '-tab-' . $publiclms_tab ); ?>"
					class="wp-block-publiclms-student-dashboard__tab"
					aria-controls="<?php echo esc_attr( $publiclms_uid . '-panel-' . $publiclms_tab ); ?>"
					aria-selected="<?php echo $publiclms_selected ? 'true' : 'false'; ?>"
					tabindex="<?php echo $publiclms_selected ? '0' : '-1'; ?>"
					data-tab="<?php echo esc_attr( $publiclms_tab ); ?>"
					data-wp-bind--aria-selected="state.isSelectedTab"
					data-wp-bind--tabindex="state.tabIndex"
					data-wp-on--click="actions.selectTab"
					data-wp-on--keydown="actions.onTabKeydown"
				>
					<?php echo esc_html( $publiclms_available[ $publiclms_tab ] ); ?>
					<span class="wp-block-publiclms-student-dashboard__tab-count"><?php echo esc_html( (string) count( $publiclms_courses[ $publiclms_tab ] ) ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php foreach ( $publiclms_tabs as $publiclms_tab ) : ?>
		<?php $publiclms_selected = $publiclms_tab === $publiclms_default; ?>
		<div
			class="wp-block-publiclms-student-dashboard__panel"
			id="<?php echo esc_attr( $publiclms_uid . '-panel-' . $publiclms_tab ); ?>"
			<?php if ( count( $publiclms_tabs ) > 1 ) : ?>
				role="tabpanel"
				tabindex="0"
				aria-labelledby="<?php echo esc_attr( $publiclms_uid . '-tab-' . $publiclms_tab ); ?>"
				data-tab="<?php echo esc_attr( $publiclms_tab ); ?>"
				data-wp-bind--hidden="state.isTabHidden"
				<?php echo $publiclms_selected ? '' : 'hidden'; ?>
			<?php endif; ?>
		>
			<?php if ( empty( $publiclms_courses[ $publiclms_tab ] ) ) : ?>
				<p class="wp-block-publiclms-student-dashboard__empty">
					<?php
					$publiclms_empty = array(
						'completed'    => __( 'Courses you finish appear here.', 'public-lms' ),
						'quiz-results' => __( 'Your quiz scores appear here after your first quiz.', 'public-lms' ),
						'certificates' => __( 'Certificates you earn by completing courses appear here.', 'public-lms' ),
					);
					echo esc_html( $publiclms_empty[ $publiclms_tab ] ?? __( 'You are not taking any courses yet.', 'public-lms' ) );
					?>
					<?php if ( 'in-progress' === $publiclms_tab && $publiclms_catalog ) : ?>
						<a href="<?php echo esc_url( $publiclms_catalog ); ?>"><?php esc_html_e( 'Browse courses', 'public-lms' ); ?></a>
					<?php endif; ?>
				</p>
			<?php elseif ( 'quiz-results' === $publiclms_tab ) : ?>
				<table class="wp-block-publiclms-student-dashboard__table">
					<caption class="publiclms-visually-hidden"><?php esc_html_e( 'Your quiz results', 'public-lms' ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Quiz', 'public-lms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Course', 'public-lms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Best score', 'public-lms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Result', 'public-lms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Attempts', 'public-lms' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Last attempt', 'public-lms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $publiclms_courses['quiz-results'] as $publiclms_result ) : ?>
							<tr>
								<th scope="row"><a href="<?php echo esc_url( (string) get_permalink( $publiclms_result['id'] ) ); ?>"><?php echo esc_html( wp_strip_all_tags( get_the_title( $publiclms_result['id'] ) ) ); ?></a></th>
								<td><?php echo esc_html( $publiclms_result['course'] > 0 ? wp_strip_all_tags( get_the_title( $publiclms_result['course'] ) ) : '—' ); ?></td>
								<td><?php echo esc_html( null === $publiclms_result['best'] ? '—' : number_format_i18n( $publiclms_result['best'], 0 ) . '%' ); ?></td>
								<td><span class="wp-block-publiclms-student-dashboard__badge <?php echo $publiclms_result['passed'] ? 'is-passed' : 'is-failed'; ?>"><?php echo esc_html( $publiclms_result['passed'] ? __( 'Passed', 'public-lms' ) : __( 'Not passed', 'public-lms' ) ); ?></span></td>
								<td><?php echo esc_html( number_format_i18n( $publiclms_result['attempts'] ) ); ?></td>
								<td><?php echo esc_html( '' === $publiclms_result['last'] ? '—' : wp_date( (string) get_option( 'date_format' ), (int) strtotime( $publiclms_result['last'] . ' UTC' ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php elseif ( ! in_array( $publiclms_tab, array( 'in-progress', 'completed' ), true ) ) : ?>
				<?php
				/**
				 * Prints a dashboard tab panel added through `publiclms_dashboard_tabs`.
				 *
				 * @param string             $tab  Tab slug.
				 * @param array<int, mixed>  $rows Rows for the tab from `publiclms_dashboard_rows`.
				 * @param int                $user The learner.
				 */
				do_action( 'publiclms_dashboard_panel', $publiclms_tab, $publiclms_courses[ $publiclms_tab ], $publiclms_user );
				?>
			<?php else : ?>
				<ul class="wp-block-publiclms-student-dashboard__courses">
					<?php foreach ( $publiclms_courses[ $publiclms_tab ] as $publiclms_course ) : ?>
						<?php
						$publiclms_course_id = $publiclms_course['id'];
						$publiclms_percent   = (int) $publiclms_course['summary']['percent'];
						$publiclms_title     = wp_strip_all_tags( get_the_title( $publiclms_course_id ) );
						/* translators: %s: course title. */
						$publiclms_bar_label = sprintf( __( 'Progress in %s', 'public-lms' ), $publiclms_title );
						?>
						<li class="wp-block-publiclms-student-dashboard__course">
							<?php if ( has_post_thumbnail( $publiclms_course_id ) ) : ?>
								<?php
								echo get_the_post_thumbnail(
									$publiclms_course_id,
									'medium',
									array(
										'class' => 'wp-block-publiclms-student-dashboard__image',
										'alt'   => '',
									)
								);
								?>
							<?php endif; ?>
							<div class="wp-block-publiclms-student-dashboard__body">
								<h3 class="wp-block-publiclms-student-dashboard__title"><a href="<?php echo esc_url( (string) get_permalink( $publiclms_course_id ) ); ?>"><?php echo esc_html( $publiclms_title ); ?></a></h3>
								<div class="wp-block-publiclms-student-dashboard__progress" style="<?php echo esc_attr( '--publiclms-progress:' . $publiclms_percent . '%' ); ?>">
									<div
										class="wp-block-publiclms-course-progress__track"
										role="progressbar"
										aria-valuemin="0"
										aria-valuemax="100"
										aria-valuenow="<?php echo esc_attr( (string) $publiclms_percent ); ?>"
										aria-label="<?php echo esc_attr( $publiclms_bar_label ); ?>"
									><span class="wp-block-publiclms-course-progress__fill"></span></div>
									<p class="wp-block-publiclms-student-dashboard__meta">
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: lessons, quizzes and assignments completed, 2: all of them in the course, 3: percentage. */
												__( '%1$d of %2$d steps · %3$d%%', 'public-lms' ),
												$publiclms_course['summary']['items_completed'],
												$publiclms_course['summary']['items_total'],
												$publiclms_percent
											)
										);
										?>
									</p>
								</div>
								<a class="wp-block-publiclms-student-dashboard__action wp-element-button" href="<?php echo esc_url( $publiclms_course['url'] ); ?>">
									<?php echo esc_html( $publiclms_course['label'] ); ?>
									<span class="publiclms-visually-hidden"><?php echo esc_html( ': ' . $publiclms_title ); ?></span>
								</a>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>
