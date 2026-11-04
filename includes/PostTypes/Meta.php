<?php
/**
 * Post meta registration.
 *
 * @package PublicLMS
 */

namespace PublicLMS\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the course and item meta the plugin reads, with REST schemas.
 *
 * Every key is protected (leading underscore) and editable over REST only by
 * users who can edit the post. Structure (which section and course an item
 * belongs to) is not meta: it is post_parent and menu_order (ADR-028).
 */
final class Meta {

	/** Course: how learners get in. One of ACCESS_MODES. */
	const ACCESS_MODE = '_plms_access_mode';

	/** Course: regular price as a decimal string; empty for free courses. Used by checkout in phase 2. */
	const PRICE = '_plms_price';

	/** Course: sale price as a decimal string, or empty. */
	const SALE_PRICE = '_plms_sale_price';

	/** Course and item: duration in minutes. On a course, 0 means "sum of the lessons". */
	const DURATION = '_plms_duration';

	/** Item: open to anyone as a free preview. */
	const PREVIEW = '_plms_preview';

	/**
	 * Access modes for a course.
	 *
	 * - free:   any logged-in user can enrol themselves.
	 * - closed: enrolment by an instructor or manager only.
	 * - paid:   enrolment through checkout (phase 2); self-enrolment is refused until then.
	 */
	const ACCESS_MODES = array( 'free', 'closed', 'paid' );

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_meta' ), 6 );
	}

	/**
	 * Registers the meta keys. Hooked to `init` after the post types.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$auth = static function ( $allowed, $meta_key, $post_id, $user_id ) {
			return user_can( (int) $user_id, 'edit_post', (int) $post_id );
		};

		register_post_meta(
			PostTypes::COURSE,
			self::ACCESS_MODE,
			array(
				'type'              => 'string',
				'description'       => __( 'How learners enrol: free, closed or paid.', 'public-lms' ),
				'single'            => true,
				'default'           => 'free',
				'sanitize_callback' => array( self::class, 'sanitize_access_mode' ),
				'auth_callback'     => $auth,
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => self::ACCESS_MODES,
					),
				),
			)
		);

		foreach ( array( self::PRICE, self::SALE_PRICE ) as $key ) {
			register_post_meta(
				PostTypes::COURSE,
				$key,
				array(
					'type'              => 'string',
					'description'       => __( 'Price as a decimal number, or empty.', 'public-lms' ),
					'single'            => true,
					'default'           => '',
					'sanitize_callback' => array( self::class, 'sanitize_price' ),
					'auth_callback'     => $auth,
					'show_in_rest'      => true,
				)
			);
		}

		foreach ( array( PostTypes::COURSE, PostTypes::LESSON, PostTypes::QUIZ, PostTypes::ASSIGNMENT ) as $post_type ) {
			register_post_meta(
				$post_type,
				self::DURATION,
				array(
					'type'              => 'integer',
					'description'       => __( 'Duration in minutes.', 'public-lms' ),
					'single'            => true,
					'default'           => 0,
					'sanitize_callback' => 'absint',
					'auth_callback'     => $auth,
					'show_in_rest'      => array(
						'schema' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					),
				)
			);
		}

		foreach ( array( PostTypes::LESSON, PostTypes::QUIZ, PostTypes::ASSIGNMENT ) as $post_type ) {
			register_post_meta(
				$post_type,
				self::PREVIEW,
				array(
					'type'              => 'boolean',
					'description'       => __( 'Whether anyone can open this item as a free preview.', 'public-lms' ),
					'single'            => true,
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
					'auth_callback'     => $auth,
					'show_in_rest'      => true,
				)
			);
		}
	}

	/**
	 * Sanitises an access mode, falling back to free.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_access_mode( $value ): string {
		$value = is_string( $value ) ? sanitize_key( $value ) : '';
		return in_array( $value, self::ACCESS_MODES, true ) ? $value : 'free';
	}

	/**
	 * Sanitises a price to a non-negative decimal string with at most four decimals, or ''.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_price( $value ): string {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return '';
		}
		$number = (float) str_replace( ',', '.', (string) $value );
		if ( $number <= 0 ) {
			return '';
		}
		return rtrim( rtrim( number_format( $number, 4, '.', '' ), '0' ), '.' );
	}

	/**
	 * Returns a course's access mode.
	 *
	 * @param int $course_id Course ID.
	 * @return string
	 */
	public static function access_mode( int $course_id ): string {
		return self::sanitize_access_mode( get_post_meta( $course_id, self::ACCESS_MODE, true ) );
	}
}
