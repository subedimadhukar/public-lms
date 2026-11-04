<?php
/**
 * The Orders tab of the student dashboard.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * Adds "Orders" to `publiclms/student-dashboard` for learners who have
 * placed at least one order: number, date, courses, total and status, with
 * a link to the order (ADR-046).
 */
final class OrdersTab {

	/**
	 * Order service.
	 *
	 * @var OrderService
	 */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param OrderService $service Order service.
	 */
	public function __construct( OrderService $service ) {
		$this->service = $service;
	}

	/**
	 * Hooks the tab.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'publiclms_dashboard_tabs', array( $this, 'tabs' ), 10, 2 );
		add_filter( 'publiclms_dashboard_rows', array( $this, 'rows' ), 10, 3 );
		add_action( 'publiclms_dashboard_panel', array( $this, 'panel' ), 10, 2 );
	}

	/**
	 * Adds the tab when the learner has orders.
	 *
	 * @param array<string, string> $tabs    Tabs.
	 * @param int                   $user_id Learner.
	 * @return array<string, string>
	 */
	public function tabs( $tabs, $user_id = 0 ): array {
		$tabs    = (array) $tabs;
		$user_id = (int) $user_id > 0 ? (int) $user_id : get_current_user_id();
		if ( $user_id > 0 && ! empty( $this->service->repository()->for_user( $user_id, 1 ) ) ) {
			$tabs['orders'] = __( 'Orders', 'public-lms' );
		}
		return $tabs;
	}

	/**
	 * Supplies the learner's orders when the tab is shown.
	 *
	 * @param array<string, array<int, mixed>> $rows    Rows by tab.
	 * @param int                              $user_id Learner.
	 * @param array<int, string>               $tabs    Tabs shown.
	 * @return array<string, array<int, mixed>>
	 */
	public function rows( $rows, $user_id, $tabs ): array {
		$rows = (array) $rows;
		if ( in_array( 'orders', (array) $tabs, true ) ) {
			$rows['orders'] = array_map(
				function ( $order ) {
					return $this->service->prepare( $order );
				},
				$this->service->repository()->for_user( (int) $user_id, 50 )
			);
		}
		return $rows;
	}

	/**
	 * Prints the panel.
	 *
	 * @param string            $tab  Tab slug.
	 * @param array<int, mixed> $rows Orders.
	 * @return void
	 */
	public function panel( $tab, $rows ): void {
		if ( 'orders' !== $tab ) {
			return;
		}
		?>
		<table class="wp-block-publiclms-student-dashboard__table">
			<caption class="publiclms-visually-hidden"><?php esc_html_e( 'Your orders', 'public-lms' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Order', 'public-lms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'public-lms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Courses', 'public-lms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Total', 'public-lms' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'public-lms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( (array) $rows as $row ) : ?>
					<?php $time = strtotime( (string) $row['created_at'] ); ?>
					<tr class="is-status-<?php echo esc_attr( (string) $row['status'] ); ?>">
						<th scope="row">
							<a href="<?php echo esc_url( (string) $row['view_url'] ); ?>">
								<?php
								/* translators: %d: order number. */
								echo esc_html( sprintf( __( 'Order #%d', 'public-lms' ), (int) $row['id'] ) );
								?>
							</a>
						</th>
						<td><?php echo esc_html( false === $time ? '' : (string) wp_date( (string) get_option( 'date_format' ), $time ) ); ?></td>
						<td><?php echo esc_html( implode( ', ', wp_list_pluck( (array) $row['items'], 'name' ) ) ); ?></td>
						<td><?php echo esc_html( (string) $row['total_formatted'] ); ?></td>
						<td><?php echo esc_html( (string) $row['status_label'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
