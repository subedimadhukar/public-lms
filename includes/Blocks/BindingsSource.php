<?php
/**
 * The publiclms/course-field block bindings source.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Blocks;

use PublicLMS\Courses\CourseFields;
use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes course values to core blocks (CONTRACTS.md A7, ADR-010).
 *
 * Arguments: `{ "key": "<key>" }` and optionally `"courseId"`. Without a
 * course ID the course comes from context: a course in a Query Loop or on
 * the course template, or the course of a lesson, quiz or assignment.
 *
 * Escaping: text keys are passed through esc_html(), because core inserts
 * bound rich text as HTML (after wp_kses_post()). URL keys are sanitised
 * with esc_url_raw(), because core escapes attribute values itself and a
 * second encoding would break query strings such as the enrol URL's nonce.
 */
final class BindingsSource {

	/**
	 * Source name.
	 */
	const NAME = 'publiclms/course-field';

	/**
	 * Course fields.
	 *
	 * @var CourseFields
	 */
	private $fields;

	/**
	 * Constructor.
	 *
	 * @param CourseFields $fields Course fields.
	 */
	public function __construct( CourseFields $fields ) {
		$this->fields = $fields;
	}

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_source' ), 20 );
	}

	/**
	 * Registers the source. Hooked to `init`.
	 *
	 * @return void
	 */
	public function register_source(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}
		register_block_bindings_source(
			self::NAME,
			array(
				'label'              => __( 'Course field', 'public-lms' ),
				'get_value_callback' => array( $this, 'get_value' ),
				'uses_context'       => array( 'postId', 'postType' ),
			)
		);
	}

	/**
	 * Returns the bound value.
	 *
	 * @param array<string, mixed> $source_args    Binding arguments.
	 * @param WP_Block             $block_instance Block being rendered.
	 * @param string               $attribute_name Bound attribute.
	 * @return string|null Null leaves the block's own value in place.
	 */
	public function get_value( array $source_args, $block_instance, string $attribute_name ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature set by core's get_value_callback.
		$key = isset( $source_args['key'] ) ? sanitize_key( (string) $source_args['key'] ) : '';
		if ( ! in_array( $key, CourseFields::KEYS, true ) ) {
			return null;
		}

		$post_id   = $block_instance instanceof WP_Block && isset( $block_instance->context['postId'] )
			? absint( $block_instance->context['postId'] )
			: (int) get_the_ID();
		$course_id = isset( $source_args['courseId'] ) ? absint( $source_args['courseId'] ) : 0;
		$course_id = $this->fields->resolve_course_id( $course_id > 0 ? $course_id : $post_id );
		if ( $course_id <= 0 ) {
			return null;
		}

		$value = $this->fields->get( $course_id, $key, 0, $post_id );
		if ( '' === $value ) {
			// Keep the block's own text (a pattern's placeholder) rather than print an empty element.
			return null;
		}
		if ( in_array( $key, CourseFields::URL_KEYS, true ) ) {
			return esc_url_raw( $value );
		}
		return esc_html( $value );
	}
}
