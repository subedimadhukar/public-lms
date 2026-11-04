<?php
/**
 * Renders publiclms/course-meta: a description list of course facts.
 *
 * Values come from CourseFields, the same class behind the bindings source,
 * so a pattern built from bound Paragraph blocks and this block agree.
 *
 * @package PublicLMS
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner content.
 * @var WP_Block             $block      Block instance.
 */

use PublicLMS\Blocks\Blocks;
use PublicLMS\Repositories\EnrollmentRepository;

defined( 'ABSPATH' ) || exit;

$publiclms_blocks = Blocks::instance();
$publiclms_course = $publiclms_blocks->course_id( $attributes, $block );
if ( $publiclms_course <= 0 || ! $publiclms_blocks->policy->can_view_course( $publiclms_course ) ) {
	return;
}

$publiclms_fields = $publiclms_blocks->fields;
$publiclms_rows   = array();
foreach ( (array) ( $attributes['fields'] ?? array() ) as $publiclms_field ) {
	switch ( $publiclms_field ) {
		case 'lessons':
			$publiclms_rows[] = array( __( 'Lessons', 'public-lms' ), $publiclms_fields->get( $publiclms_course, 'lesson_count' ) );
			break;
		case 'duration':
			$publiclms_rows[] = array( __( 'Duration', 'public-lms' ), $publiclms_fields->get( $publiclms_course, 'duration' ) );
			break;
		case 'level':
			$publiclms_rows[] = array( __( 'Level', 'public-lms' ), $publiclms_fields->get( $publiclms_course, 'level' ) );
			break;
		case 'instructor':
			$publiclms_rows[] = array( __( 'Instructor', 'public-lms' ), $publiclms_fields->get( $publiclms_course, 'instructor' ) );
			break;
		case 'learners':
			$publiclms_count  = $publiclms_blocks->enrollments->count_by_course( $publiclms_course, EnrollmentRepository::STATUS_ACTIVE );
			$publiclms_rows[] = array( __( 'Learners', 'public-lms' ), $publiclms_count > 0 ? $publiclms_fields->get( $publiclms_course, 'enrolled_count' ) : '' );
			break;
		case 'price':
			$publiclms_rows[] = array( __( 'Price', 'public-lms' ), $publiclms_fields->get( $publiclms_course, 'price' ) );
			break;
		case 'updated':
			$publiclms_rows[] = array( __( 'Last updated', 'public-lms' ), (string) get_the_modified_date( '', $publiclms_course ) );
			break;
	}
}
$publiclms_rows = array_filter(
	$publiclms_rows,
	static function ( $row ) {
		return '' !== $row[1];
	}
);
if ( empty( $publiclms_rows ) ) {
	return;
}

$publiclms_layout = 'inline' === ( $attributes['layout'] ?? 'list' ) ? 'inline' : 'list';
$publiclms_labels = ! empty( $attributes['showLabels'] );
?>
<dl <?php echo get_block_wrapper_attributes( array( 'class' => 'is-layout-' . $publiclms_layout ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>>
	<?php foreach ( $publiclms_rows as $publiclms_row ) : ?>
		<div class="wp-block-publiclms-course-meta__item">
			<dt class="wp-block-publiclms-course-meta__label<?php echo $publiclms_labels ? '' : ' publiclms-visually-hidden'; ?>"><?php echo esc_html( $publiclms_row[0] ); ?></dt>
			<dd class="wp-block-publiclms-course-meta__value"><?php echo esc_html( $publiclms_row[1] ); ?></dd>
		</div>
	<?php endforeach; ?>
</dl>
