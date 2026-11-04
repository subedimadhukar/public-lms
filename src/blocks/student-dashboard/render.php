<?php
/**
 * Renders publiclms/student-dashboard.
 *
 * The current learner's courses with progress, split into tabs that follow
 * the WAI-ARIA tabs pattern (arrow keys, Home and End move between tabs).
 * Without JavaScript every panel is shown under its own heading.
 *
 * Phase 1 tabs: in-progress and completed. Quiz results, certificates and
 * orders join in phase 2.
 *
 * @package PublicLMS
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner content.
 * @var WP_Block             $block      Block instance.
 */

use PublicLMS\Blocks\Blocks;
use PublicLMS\PostTypes\PostTypes;

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
	'in-progress' => __( 'In progress', 'public-lms' ),
	'completed'   => __( 'Completed', 'public-lms' ),
);
$publiclms_tabs      = array_values( array_intersect( array_map( 'strval', (array) ( $attributes['tabs'] ?? array() ) ), array_keys( $publiclms_available ) ) );
$publiclms_tabs      = empty( $publiclms_tabs ) ? array_keys( $publiclms_available ) : $publiclms_tabs;
$publiclms_default   = in_array( $attributes['defaultTab'] ?? '', $publiclms_tabs, true ) ? (string) $attributes['defaultTab'] : $publiclms_tabs[0];

$publiclms_courses = array(
	'in-progress' => array(),
	'completed'   => array(),
);
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
					echo esc_html(
						'completed' === $publiclms_tab
							? __( 'Courses you finish appear here.', 'public-lms' )
							: __( 'You are not taking any courses yet.', 'public-lms' )
					);
					?>
					<?php if ( 'in-progress' === $publiclms_tab && $publiclms_catalog ) : ?>
						<a href="<?php echo esc_url( $publiclms_catalog ); ?>"><?php esc_html_e( 'Browse courses', 'public-lms' ); ?></a>
					<?php endif; ?>
				</p>
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
												/* translators: 1: lessons completed, 2: lessons in the course, 3: percentage. */
												__( '%1$d of %2$d lessons · %3$d%%', 'public-lms' ),
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
