<?php
/**
 * The certificate template.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Certificates;

use PublicLMS\PostTypes\PostTypes;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The site's one certificate template: a `plms_certificate` post edited in
 * the block editor, with core blocks bound to `publiclms/certificate-field`
 * (ADR-044). Free has exactly one; Pro adds more and picks one per course.
 *
 * The default uses only headings, paragraphs and a separator with text
 * alignment and helper classes, because Dompdf lays out block flow and
 * tables but not flexbox; the frame and type come from the PDF stylesheet.
 */
class CertificateTemplate {

	/**
	 * Option holding the template's post ID.
	 */
	const OPTION = 'publiclms_certificate_template';

	/**
	 * Returns the template's post ID, creating the default template when there is none.
	 *
	 * @return int
	 */
	public function id(): int {
		$id = (int) get_option( self::OPTION, 0 );
		if ( $id > 0 && PostTypes::CERTIFICATE === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) {
			return $id;
		}
		$id = (int) wp_insert_post(
			array(
				'post_type'    => PostTypes::CERTIFICATE,
				'post_status'  => 'publish',
				'post_title'   => __( 'Certificate of Completion', 'public-lms' ),
				'post_content' => self::default_content(),
				'post_author'  => get_current_user_id(),
			)
		);
		if ( $id > 0 ) {
			update_option( self::OPTION, $id, false );
		}
		return $id;
	}

	/**
	 * Returns the template post.
	 *
	 * @return WP_Post|null
	 */
	public function post(): ?WP_Post {
		$post = get_post( $this->id() );
		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Returns the default template's block markup.
	 *
	 * @return string
	 */
	public static function default_content(): string {
		$bound = static function ( string $key, string $fallback, string $css_class, string $tag = 'p' ): string {
			$attrs = wp_json_encode(
				array(
					'align'     => 'center',
					'className' => $css_class,
					'metadata'  => array(
						'bindings' => array(
							'content' => array(
								'source' => 'publiclms/certificate-field',
								'args'   => array( 'key' => $key ),
							),
						),
					),
				)
			);
			return '<!-- wp:paragraph ' . $attrs . ' -->' . "\n" . '<' . $tag . ' class="has-text-align-center ' . esc_attr( $css_class ) . '">' . esc_html( $fallback ) . '</' . $tag . '>' . "\n" . '<!-- /wp:paragraph -->';
		};
		$plain = static function ( string $text, string $css_class ): string {
			return '<!-- wp:paragraph {"align":"center","className":"' . esc_attr( $css_class ) . '"} -->' . "\n" . '<p class="has-text-align-center ' . esc_attr( $css_class ) . '">' . esc_html( $text ) . '</p>' . "\n" . '<!-- /wp:paragraph -->';
		};

		return implode(
			"\n\n",
			array(
				$bound( 'site_title', __( 'Site name', 'public-lms' ), 'publiclms-certificate__site' ),
				'<!-- wp:heading {"textAlign":"center","level":1,"className":"publiclms-certificate__title"} -->' . "\n" . '<h1 class="wp-block-heading has-text-align-center publiclms-certificate__title">' . esc_html__( 'Certificate of Completion', 'public-lms' ) . '</h1>' . "\n" . '<!-- /wp:heading -->',
				$plain( __( 'This certifies that', 'public-lms' ), 'publiclms-certificate__lead' ),
				$bound( 'learner_name', __( 'Learner name', 'public-lms' ), 'publiclms-certificate__name' ),
				$plain( __( 'has completed the course', 'public-lms' ), 'publiclms-certificate__lead' ),
				$bound( 'course_title', __( 'Course title', 'public-lms' ), 'publiclms-certificate__course' ),
				$bound( 'completion_date', __( 'Completion date', 'public-lms' ), 'publiclms-certificate__date' ),
				'<!-- wp:separator {"className":"publiclms-certificate__rule"} -->' . "\n" . '<hr class="wp-block-separator has-alpha-channel-opacity publiclms-certificate__rule"/>' . "\n" . '<!-- /wp:separator -->',
				$bound( 'instructor', __( 'Instructor', 'public-lms' ), 'publiclms-certificate__instructor' ),
				$bound( 'certificate_id', __( 'Certificate ID', 'public-lms' ), 'publiclms-certificate__id' ),
			)
		) . "\n";
	}
}
