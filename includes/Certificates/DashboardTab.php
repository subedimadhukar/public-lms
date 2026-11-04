<?php
/**
 * The Certificates tab of the student dashboard.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Certificates;

defined( 'ABSPATH' ) || exit;

/**
 * Adds "Certificates" to `publiclms/student-dashboard` through its tab hooks:
 * one row per certificate with the course, completion date, certificate ID
 * and the download link (ADR-044).
 */
final class DashboardTab {

	/**
	 * Certificates.
	 *
	 * @var CertificateService
	 */
	private $certificates;

	/**
	 * Constructor.
	 *
	 * @param CertificateService $certificates Certificates.
	 */
	public function __construct( CertificateService $certificates ) {
		$this->certificates = $certificates;
	}

	/**
	 * Hooks the tab.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'publiclms_dashboard_tabs', array( $this, 'tabs' ) );
		add_filter( 'publiclms_dashboard_rows', array( $this, 'rows' ), 10, 3 );
		add_action( 'publiclms_dashboard_panel', array( $this, 'panel' ), 10, 2 );
	}

	/**
	 * Adds the tab.
	 *
	 * @param array<string, string> $tabs Tabs.
	 * @return array<string, string>
	 */
	public function tabs( $tabs ): array {
		$tabs                 = (array) $tabs;
		$tabs['certificates'] = __( 'Certificates', 'public-lms' );
		return $tabs;
	}

	/**
	 * Supplies the learner's certificates when the tab is shown.
	 *
	 * @param array<string, array<int, mixed>> $rows    Rows by tab.
	 * @param int                              $user_id Learner.
	 * @param array<int, string>               $tabs    Tabs shown.
	 * @return array<string, array<int, mixed>>
	 */
	public function rows( $rows, $user_id, $tabs ): array {
		$rows = (array) $rows;
		if ( in_array( 'certificates', (array) $tabs, true ) ) {
			$rows['certificates'] = $this->certificates->for_user( (int) $user_id );
		}
		return $rows;
	}

	/**
	 * Prints the panel.
	 *
	 * @param string            $tab  Tab slug.
	 * @param array<int, mixed> $rows Certificates.
	 * @return void
	 */
	public function panel( $tab, $rows ): void {
		if ( 'certificates' !== $tab ) {
			return;
		}
		?>
		<table class="wp-block-publiclms-student-dashboard__table">
			<caption class="publiclms-visually-hidden"><?php esc_html_e( 'Your certificates', 'public-lms' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Course', 'public-lms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Completed', 'public-lms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Certificate ID', 'public-lms' ); ?></th>
					<th scope="col"><span class="publiclms-visually-hidden"><?php esc_html_e( 'Download', 'public-lms' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( (array) $rows as $row ) : ?>
					<?php $time = strtotime( (string) $row['completed_at'] . ' UTC' ); ?>
					<tr>
						<th scope="row"><a href="<?php echo esc_url( (string) get_permalink( (int) $row['course_id'] ) ); ?>"><?php echo esc_html( (string) $row['course_title'] ); ?></a></th>
						<td><?php echo esc_html( false === $time ? '' : (string) wp_date( (string) get_option( 'date_format' ), $time ) ); ?></td>
						<td><code><?php echo esc_html( (string) $row['certificate_key'] ); ?></code></td>
						<td>
							<a class="wp-element-button" href="<?php echo esc_url( CertificateService::download_url( (int) $row['course_id'] ) ); ?>">
								<?php esc_html_e( 'Download PDF', 'public-lms' ); ?>
								<span class="publiclms-visually-hidden"><?php echo esc_html( ': ' . (string) $row['course_title'] ); ?></span>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
