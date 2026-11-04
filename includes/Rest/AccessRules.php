<?php
/**
 * REST fields for drip and prerequisites.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\PostTypes\Meta;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Shared argument definitions, validation and output for the access rules
 * the course builder edits (ADR-040): drip on sections and items, item
 * prerequisites, and course prerequisites.
 *
 * Dates travel like WordPress's own `date` and `date_gmt` fields: `drip_date`
 * is in the site's timezone ("2026-12-01T09:00:00"), `drip_date_gmt` in UTC.
 * A value with an explicit offset or "Z" is taken as given. Storage is UTC
 * (`_plms_drip_date`, ADR-026).
 */
final class AccessRules {

	/**
	 * Returns the drip arguments for a section or item write route.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function drip_args(): array {
		return array(
			'drip_days' => array(
				'description'       => __( 'Days after enrolment before this opens; 0 for no delay.', 'public-lms' ),
				'type'              => 'integer',
				'minimum'           => 0,
				'maximum'           => Meta::MAX_DRIP_DAYS,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'drip_date' => array(
				'description'       => __( 'Date and time this opens, in the site timezone (or with an offset); empty for no date.', 'public-lms' ),
				'type'              => 'string',
				'pattern'           => '^$|^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?(Z|[+-]\d{2}:?\d{2})?$',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Returns the prerequisites argument.
	 *
	 * @param string $description Argument description.
	 * @return array<string, mixed>
	 */
	public static function prerequisites_arg( string $description ): array {
		return array(
			'description'       => $description,
			'type'              => 'array',
			'items'             => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'maxItems'          => 50,
			'sanitize_callback' => array( Meta::class, 'sanitize_id_list' ),
			'validate_callback' => 'rest_validate_request_arg',
		);
	}

	/**
	 * Writes drip_days and drip_date when the request has them.
	 *
	 * @param int                                   $post_id Section or item ID.
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return void
	 */
	public static function apply_drip( int $post_id, $request ): void {
		if ( isset( $request['drip_days'] ) ) {
			update_post_meta( $post_id, Meta::DRIP_DAYS, Meta::sanitize_drip_days( $request['drip_days'] ) );
		}
		if ( isset( $request['drip_date'] ) ) {
			update_post_meta( $post_id, Meta::DRIP_DATE, self::to_utc( (string) $request['drip_date'] ) );
		}
	}

	/**
	 * Returns the drip fields of a section or item for a response.
	 *
	 * @param int $post_id Section or item ID.
	 * @return array{drip_days: int, drip_date: string, drip_date_gmt: string}
	 */
	public static function drip_data( int $post_id ): array {
		$drip = Meta::drip( $post_id );
		return array(
			'drip_days'     => $drip['days'],
			'drip_date'     => '' === $drip['date'] ? '' : (string) get_date_from_gmt( $drip['date'], 'Y-m-d\TH:i:s' ),
			'drip_date_gmt' => '' === $drip['date'] ? '' : str_replace( ' ', 'T', $drip['date'] ),
		);
	}

	/**
	 * Converts a REST date (site timezone, or with an offset) to a UTC 'Y-m-d H:i:s', or ''.
	 *
	 * @param string $value Date as sent.
	 * @return string
	 */
	public static function to_utc( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/(Z|[+-]\d{2}:?\d{2})$/i', $value ) ) {
			return Meta::sanitize_utc_datetime( $value );
		}
		if ( 10 === strlen( $value ) ) {
			$value .= ' 00:00:00';
		}
		$gmt = get_gmt_from_date( str_replace( 'T', ' ', $value ), 'Y-m-d H:i:s' );
		return Meta::sanitize_utc_datetime( (string) $gmt );
	}

	/**
	 * Checks item prerequisites: lessons, quizzes or assignments of the item's own course.
	 *
	 * @param int                                $item_id   Item being edited.
	 * @param array<int, int>                    $required  Requested prerequisite IDs.
	 * @param CourseStructureRepositoryInterface $structure Course structure.
	 * @return true|WP_Error
	 */
	public static function validate_item_prerequisites( int $item_id, array $required, CourseStructureRepositoryInterface $structure ) {
		$course_id = $structure->course_id_for( $item_id );
		foreach ( $required as $id ) {
			$type = get_post_type( $id );
			if ( $id === $item_id
				|| ! in_array( $type, array( PostTypes::LESSON, PostTypes::QUIZ, PostTypes::ASSIGNMENT ), true )
				|| $course_id <= 0
				|| $structure->course_id_for( $id ) !== $course_id ) {
				return new WP_Error(
					'rest_invalid_param',
					/* translators: %d: post ID. */
					sprintf( __( 'Item %d is not another step of this course.', 'public-lms' ), $id ),
					array( 'status' => 400 )
				);
			}
		}
		return true;
	}

	/**
	 * Checks course prerequisites: other courses.
	 *
	 * @param int             $course_id Course being edited.
	 * @param array<int, int> $required  Requested prerequisite course IDs.
	 * @return true|WP_Error
	 */
	public static function validate_course_prerequisites( int $course_id, array $required ) {
		foreach ( $required as $id ) {
			if ( $id === $course_id || PostTypes::COURSE !== get_post_type( $id ) || 'trash' === get_post_status( $id ) ) {
				return new WP_Error(
					'rest_invalid_param',
					/* translators: %d: post ID. */
					sprintf( __( 'Course %d cannot be a prerequisite of this course.', 'public-lms' ), $id ),
					array( 'status' => 400 )
				);
			}
		}
		return true;
	}

	/**
	 * Returns the schema properties of the drip fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function drip_schema(): array {
		return array(
			'drip_days'     => array(
				'description' => __( 'Days after enrolment before this opens.', 'public-lms' ),
				'type'        => 'integer',
			),
			'drip_date'     => array(
				'description' => __( 'Opening date in the site timezone, or empty.', 'public-lms' ),
				'type'        => 'string',
			),
			'drip_date_gmt' => array(
				'description' => __( 'Opening date in UTC, or empty.', 'public-lms' ),
				'type'        => 'string',
				'readonly'    => true,
			),
		);
	}
}
