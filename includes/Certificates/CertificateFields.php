<?php
/**
 * The publiclms/certificate-field block bindings source.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Certificates;

defined( 'ABSPATH' ) || exit;

/**
 * Fills the certificate template with the certificate being rendered
 * (ADR-044). Keys: learner_name, course_title, completion_date,
 * certificate_id, instructor, site_title.
 *
 * Outside a render (the template's own preview on the front end) values are
 * empty, so the blocks keep their placeholder text.
 */
final class CertificateFields {

	/**
	 * Source name.
	 */
	const NAME = 'publiclms/certificate-field';

	/**
	 * Keys the source provides.
	 */
	const KEYS = array( 'learner_name', 'course_title', 'completion_date', 'certificate_id', 'instructor', 'site_title' );

	/**
	 * The certificate being rendered, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $current = null;

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
				'label'              => __( 'Certificate field', 'public-lms' ),
				'get_value_callback' => array( self::class, 'get_value' ),
			)
		);
	}

	/**
	 * Sets the certificate the next render fills in, or null to clear it.
	 *
	 * @param array<string, mixed>|null $certificate Certificate row.
	 * @return void
	 */
	public static function set_current( ?array $certificate ): void {
		self::$current = $certificate;
	}

	/**
	 * Returns the bound value.
	 *
	 * @param array<string, mixed> $source_args Binding arguments.
	 * @return string|null Null keeps the block's own text.
	 */
	public static function get_value( array $source_args ): ?string {
		$value = self::value( isset( $source_args['key'] ) ? sanitize_key( (string) $source_args['key'] ) : '' );
		return '' === $value ? null : esc_html( $value );
	}

	/**
	 * Returns one value of the current certificate as plain text.
	 *
	 * @param string $key One of KEYS.
	 * @return string
	 */
	public static function value( string $key ): string {
		$certificate = self::$current;
		if ( null === $certificate ) {
			return '';
		}
		$data = (array) ( $certificate['data'] ?? array() );
		switch ( $key ) {
			case 'learner_name':
				return (string) $certificate['learner_name'];
			case 'course_title':
				return (string) $certificate['course_title'];
			case 'completion_date':
				$time = strtotime( (string) $certificate['completed_at'] . ' UTC' );
				return false === $time ? '' : (string) wp_date( (string) get_option( 'date_format' ), $time );
			case 'certificate_id':
				return (string) $certificate['certificate_key'];
			case 'instructor':
				return (string) ( $data['instructor'] ?? '' );
			case 'site_title':
				return (string) ( $data['site'] ?? wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		}
		return '';
	}
}
