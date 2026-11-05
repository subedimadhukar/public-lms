<?php
/**
 * Checks that certificates can be printed, in every script the site uses.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Certificates;

use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\EnrollmentRepository;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * A Site Health test and the Certificate screen's notices (ADR-052):
 * - the PDF library is missing, or PHP lacks mbstring: critical, certificates
 *   cannot be made;
 * - PHP lacks GD: recommended, because text prints but some images
 *   (transparent PNG, GIF, WebP) are left out;
 * - a script in the names and titles certificates print has no font:
 *   recommended, naming the script and how to add a font for it;
 * - Chinese, Japanese or Korean text with no site font is drawn by the
 *   reader's PDF viewer (Adobe CJK fonts, nothing embedded): noted, good.
 *
 * The names checked are the issued certificates' learner names, course
 * titles, instructors and site names, the display names of enrolled
 * learners, the published course titles, the site name and the template.
 */
class CertificateHealth {

	/**
	 * How many names of each kind are checked.
	 */
	const LIMIT = 2000;

	/**
	 * Fonts.
	 *
	 * @var CertificateFonts
	 */
	private $fonts;

	/**
	 * Template.
	 *
	 * @var CertificateTemplate
	 */
	private $template;

	/**
	 * Database.
	 *
	 * @var wpdb
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * @param CertificateFonts    $fonts    Fonts.
	 * @param CertificateTemplate $template Template.
	 * @param wpdb                $db       Database.
	 */
	public function __construct( CertificateFonts $fonts, CertificateTemplate $template, wpdb $db ) {
		$this->fonts    = $fonts;
		$this->template = $template;
		$this->db       = $db;
	}

	/**
	 * Hooks the Site Health test.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'tests' ) );
	}

	/**
	 * Adds the Site Health test.
	 *
	 * @param array<string, mixed> $tests Tests.
	 * @return array<string, mixed>
	 */
	public function tests( $tests ): array {
		$tests                                     = (array) $tests;
		$tests['direct']['publiclms_certificates'] = array(
			'label' => __( 'Public LMS certificates', 'public-lms' ),
			'test'  => array( $this, 'site_health' ),
		);
		return $tests;
	}

	/**
	 * Returns what stops or limits certificates, worst first.
	 *
	 * @return array{status: string, problems: array<int, array{level: string, code: string, message: string}>, viewer: array<int, string>}
	 */
	public function check(): array {
		$problems = array();
		$viewer   = array();
		if ( ! CertificateRenderer::available() ) {
			$problems[] = array(
				'level'   => 'critical',
				'code'    => function_exists( 'mb_substr' ) ? 'library' : 'mbstring',
				'message' => function_exists( 'mb_substr' )
					? __( 'The PDF library is missing from this copy of Public LMS, so certificates cannot be created. Reinstall the plugin from a release zip.', 'public-lms' )
					: __( 'PHP’s mbstring extension is not enabled, so certificates cannot be created. Ask your host to enable it.', 'public-lms' ),
			);
		}
		if ( ! extension_loaded( 'gd' ) ) {
			$problems[] = array(
				'level'   => 'recommended',
				'code'    => 'gd',
				'message' => __( 'PHP’s GD extension is not enabled. Certificates still print their text, but images in the template that need GD (PNG with transparency, GIF and WebP) are left out. Ask your host to enable GD.', 'public-lms' ),
			);
		}
		if ( CertificateRenderer::available() ) {
			$plan = $this->fonts->plan( $this->names() );
			if ( array() !== $plan['missing'] ) {
				$problems[] = array(
					'level'   => 'recommended',
					'code'    => 'fonts',
					'message' => sprintf(
						/* translators: %s: script names, such as "Sinhala, Ethiopic". */
						__( 'Some names or titles on this site use a script that no certificate font covers: %s. Their certificates would show empty boxes. Upload a TTF or OTF font that covers it in the Font Library (Appearance → Editor → Styles → Typography), or add one with the publiclms_certificate_fonts filter.', 'public-lms' ),
						implode( wp_get_list_item_separator(), $plan['missing'] )
					),
				);
			}
			$viewer = $plan['viewer'];
		}
		$status = 'good';
		foreach ( $problems as $problem ) {
			if ( 'critical' === $problem['level'] ) {
				$status = 'critical';
			} elseif ( 'good' === $status ) {
				$status = 'recommended';
			}
		}
		return array(
			'status'   => $status,
			'problems' => $problems,
			'viewer'   => $viewer,
		);
	}

	/**
	 * Returns the Site Health result.
	 *
	 * @return array<string, mixed>
	 */
	public function site_health(): array {
		$check  = $this->check();
		$result = array(
			'label'       => __( 'Public LMS can create certificates in every script your site uses', 'public-lms' ),
			'status'      => $check['status'],
			'badge'       => array(
				'label' => __( 'Public LMS', 'public-lms' ),
				'color' => 'critical' === $check['status'] ? 'red' : 'blue',
			),
			'description' => '<p>' . esc_html__( 'Certificates are PDF files made on your server. Public LMS bundles fonts for Latin, Greek, Cyrillic, Arabic, Hebrew, Devanagari, Bengali, Tamil and Thai, and uses the Font Library’s TTF and OTF fonts for other scripts.', 'public-lms' ) . '</p>',
			'actions'     => '',
			'test'        => 'publiclms_certificates',
		);
		if ( 'critical' === $check['status'] ) {
			$result['label'] = __( 'Public LMS cannot create certificates', 'public-lms' );
		} elseif ( 'recommended' === $check['status'] ) {
			$result['label'] = __( 'Some Public LMS certificates may print incompletely', 'public-lms' );
		}
		foreach ( $check['problems'] as $problem ) {
			$result['description'] .= '<p>' . esc_html( $problem['message'] ) . '</p>';
		}
		if ( array() !== $check['viewer'] ) {
			$result['description'] .= '<p>' . esc_html( $this->viewer_message( $check['viewer'] ) ) . '</p>';
		}
		return $result;
	}

	/**
	 * Prints the Certificate screen's notices.
	 *
	 * @return void
	 */
	public function screen_notices(): void {
		$check = $this->check();
		foreach ( $check['problems'] as $problem ) {
			printf(
				'<div class="notice %1$s inline"><p>%2$s</p></div>',
				'critical' === $problem['level'] ? 'notice-error' : 'notice-warning',
				esc_html( $problem['message'] )
			);
		}
		if ( array() !== $check['viewer'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html( $this->viewer_message( $check['viewer'] ) ) . '</p></div>';
		}
	}

	/**
	 * Explains Adobe CJK fonts.
	 *
	 * @param array<int, string> $scripts Script names.
	 * @return string
	 */
	private function viewer_message( array $scripts ): string {
		return sprintf(
			/* translators: %s: Unicode script names, such as "Han, Hangul". */
			__( 'Certificates show Chinese, Japanese and Korean text (here: %s) with the PDF reader’s own fonts, because no font on this site covers it. Most PDF readers display it. To embed a font instead, upload a TTF or OTF font that covers it in the Font Library.', 'public-lms' ),
			implode( wp_get_list_item_separator(), $scripts )
		);
	}

	/**
	 * Collects the names and titles certificates print, or will print.
	 *
	 * @return string
	 */
	public function names(): string {
		$sample = CertificateRenderer::sample();
		$names  = array(
			wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
			(string) $sample['learner_name'],
			(string) $sample['course_title'],
			(string) $sample['data']['instructor'],
		);
		$post   = $this->template->post();
		if ( null !== $post ) {
			$names[] = wp_strip_all_tags( (string) $post->post_content );
		}

		$certificates = $this->db->prefix . 'plms_certificates';
		$enrollments  = $this->db->prefix . 'plms_enrollments';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; an occasional admin check.
		$rows = (array) $this->db->get_results( $this->db->prepare( 'SELECT learner_name, course_title, data FROM %i WHERE revoked_at IS NULL ORDER BY id DESC LIMIT %d', $certificates, self::LIMIT ), ARRAY_A );
		foreach ( $rows as $row ) {
			$names[] = (string) $row['learner_name'];
			$names[] = (string) $row['course_title'];
			$data    = json_decode( (string) $row['data'], true );
			if ( is_array( $data ) ) {
				$names[] = (string) ( $data['instructor'] ?? '' );
				$names[] = (string) ( $data['site'] ?? '' );
			}
		}
		$learners = (array) $this->db->get_col( $this->db->prepare( 'SELECT u.display_name FROM %i e INNER JOIN %i u ON u.ID = e.user_id WHERE e.status <> %s GROUP BY u.ID, u.display_name ORDER BY MAX(e.id) DESC LIMIT %d', $enrollments, $this->db->users, EnrollmentRepository::STATUS_CANCELLED, self::LIMIT ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $learners as $learner ) {
			$names[] = (string) $learner;
		}

		$courses = get_posts(
			array(
				'post_type'        => PostTypes::COURSE,
				'post_status'      => 'publish',
				'posts_per_page'   => self::LIMIT,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'lang'             => '',
			)
		);
		foreach ( $courses as $course ) {
			$names[] = get_the_title( (int) $course );
		}
		return implode( "\n", $names );
	}
}
