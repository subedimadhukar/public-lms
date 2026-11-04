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

	/** Section and item: opens this many days after the learner enrolled; 0 for no delay. */
	const DRIP_DAYS = '_plms_drip_days';

	/** Section and item: opens on this UTC date and time ('Y-m-d H:i:s'), or '' for no date. */
	const DRIP_DATE = '_plms_drip_date';

	/**
	 * Course: IDs of courses a learner must complete before enrolling.
	 * Item: IDs of lessons, quizzes or assignments of the same course to complete first.
	 */
	const PREREQUISITES = '_plms_prerequisites';

	/** Lesson: attachment IDs offered as downloads under the lesson. */
	const ATTACHMENTS = '_plms_attachments';

	/** Course: whether completing the course issues a certificate. */
	const CERTIFICATE = '_plms_certificate';

	/**
	 * Access modes for a course.
	 *
	 * - free:   any logged-in user can enrol themselves.
	 * - closed: enrolment by an instructor or manager only.
	 * - paid:   enrolment through checkout (phase 2); self-enrolment is refused until then.
	 */
	const ACCESS_MODES = array( 'free', 'closed', 'paid' );

	/**
	 * Longest drip delay accepted, in days (ten years).
	 */
	const MAX_DRIP_DAYS = 3650;

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

		$id_list = array(
			'schema' => array(
				'type'  => 'array',
				'items' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		);

		register_post_meta(
			PostTypes::COURSE,
			self::PREREQUISITES,
			array(
				'type'              => 'array',
				'description'       => __( 'Courses a learner must complete before enrolling.', 'public-lms' ),
				'single'            => true,
				'default'           => array(),
				'sanitize_callback' => array( self::class, 'sanitize_id_list' ),
				'auth_callback'     => $auth,
				'show_in_rest'      => $id_list,
			)
		);

		// Stored as '1' or '0' so "switched off" differs from "never set" (on by default).
		// Not on wp/v2: boolean meta stores false as '', which reads as unset.
		register_post_meta(
			PostTypes::COURSE,
			self::CERTIFICATE,
			array(
				'type'              => 'string',
				'description'       => __( 'Whether completing the course issues a certificate: 1 or 0.', 'public-lms' ),
				'single'            => true,
				'sanitize_callback' => array( self::class, 'sanitize_flag' ),
				'auth_callback'     => $auth,
				'show_in_rest'      => false,
			)
		);

		foreach ( array( PostTypes::SECTION, PostTypes::LESSON, PostTypes::QUIZ, PostTypes::ASSIGNMENT ) as $post_type ) {
			register_post_meta(
				$post_type,
				self::DRIP_DAYS,
				array(
					'type'              => 'integer',
					'description'       => __( 'Days after enrolment before this opens; 0 for no delay.', 'public-lms' ),
					'single'            => true,
					'default'           => 0,
					'sanitize_callback' => array( self::class, 'sanitize_drip_days' ),
					'auth_callback'     => $auth,
					'show_in_rest'      => array(
						'schema' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => self::MAX_DRIP_DAYS,
						),
					),
				)
			);
			register_post_meta(
				$post_type,
				self::DRIP_DATE,
				array(
					'type'              => 'string',
					'description'       => __( 'UTC date and time this opens, as Y-m-d H:i:s, or empty.', 'public-lms' ),
					'single'            => true,
					'default'           => '',
					'sanitize_callback' => array( self::class, 'sanitize_utc_datetime' ),
					'auth_callback'     => $auth,
					'show_in_rest'      => true,
				)
			);
		}

		foreach ( array( PostTypes::LESSON, PostTypes::QUIZ, PostTypes::ASSIGNMENT ) as $post_type ) {
			register_post_meta(
				$post_type,
				self::PREREQUISITES,
				array(
					'type'              => 'array',
					'description'       => __( 'Items of the same course to complete before this one opens.', 'public-lms' ),
					'single'            => true,
					'default'           => array(),
					'sanitize_callback' => array( self::class, 'sanitize_id_list' ),
					'auth_callback'     => $auth,
					'show_in_rest'      => $id_list,
				)
			);
		}

		register_post_meta(
			PostTypes::LESSON,
			self::ATTACHMENTS,
			array(
				'type'              => 'array',
				'description'       => __( 'Media library attachments offered as downloads under the lesson.', 'public-lms' ),
				'single'            => true,
				'default'           => array(),
				'sanitize_callback' => array( self::class, 'sanitize_id_list' ),
				'auth_callback'     => $auth,
				'show_in_rest'      => $id_list,
			)
		);

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
	 * Sanitises a list of post IDs: positive, unique, in the given order.
	 *
	 * @param mixed $value Raw value: an array, or a comma-separated string.
	 * @return array<int, int>
	 */
	public static function sanitize_id_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = '' === trim( $value ) ? array() : explode( ',', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$ids = array();
		foreach ( $value as $id ) {
			$id = is_scalar( $id ) ? absint( $id ) : 0;
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Sanitises an on/off flag to '1' or '0'.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_flag( $value ): string {
		if ( is_string( $value ) ) {
			$value = ! in_array( strtolower( trim( $value ) ), array( '', '0', 'false', 'no', 'off' ), true );
		}
		return (bool) $value ? '1' : '0';
	}

	/**
	 * Sanitises a drip delay in days.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_drip_days( $value ): int {
		return min( self::MAX_DRIP_DAYS, is_scalar( $value ) ? absint( $value ) : 0 );
	}

	/**
	 * Sanitises a UTC datetime to 'Y-m-d H:i:s', or '' when empty or invalid.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_utc_datetime( $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}
		$time = strtotime( trim( $value ) . ( preg_match( '/(Z|[+-]\d{2}:?\d{2}|UTC)$/i', trim( $value ) ) ? '' : ' UTC' ) );
		return false === $time ? '' : gmdate( 'Y-m-d H:i:s', $time );
	}

	/**
	 * Returns a stored ID list meta value.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return array<int, int>
	 */
	public static function id_list( int $post_id, string $key ): array {
		return self::sanitize_id_list( get_post_meta( $post_id, $key, true ) );
	}

	/**
	 * Returns the drip settings of a section or item.
	 *
	 * @param int $post_id Section or item ID.
	 * @return array{days: int, date: string}
	 */
	public static function drip( int $post_id ): array {
		return array(
			'days' => self::sanitize_drip_days( get_post_meta( $post_id, self::DRIP_DAYS, true ) ),
			'date' => self::sanitize_utc_datetime( get_post_meta( $post_id, self::DRIP_DATE, true ) ),
		);
	}

	/**
	 * Whether a course issues a certificate on completion. On unless switched off.
	 *
	 * @param int $course_id Course ID.
	 * @return bool
	 */
	public static function issues_certificate( int $course_id ): bool {
		$value = get_post_meta( $course_id, self::CERTIFICATE, true );
		return '' === $value || '1' === self::sanitize_flag( $value );
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
