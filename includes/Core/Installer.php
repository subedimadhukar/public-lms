<?php
/**
 * Installation, upgrades and database schema.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Core;

use PublicLMS\Capabilities\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and migrates the plugin's tables, roles and options.
 *
 * Content (courses, lessons, quizzes and so on) lives in post types. Anything
 * recorded per learner, per attempt or per order lives in the tables below.
 * Every schema change bumps DB_VERSION; dbDelta() then brings existing tables
 * up to date on the next request. See docs/ARCHITECTURE.md, ADR-001 to ADR-003.
 */
final class Installer {

	/**
	 * Current schema version. Bump on every change to schema().
	 */
	const DB_VERSION = '3';

	/**
	 * Option holding the installed schema version.
	 */
	const DB_VERSION_OPTION = 'publiclms_db_version';

	/**
	 * Option holding the plugin version that last ran the installer.
	 */
	const VERSION_OPTION = 'publiclms_version';

	/**
	 * Option flag asking for a rewrite flush once post types are registered.
	 */
	const FLUSH_OPTION = 'publiclms_flush_rewrite_rules';

	/**
	 * Table names without the WordPress table prefix, in creation order.
	 */
	const TABLES = array(
		'plms_enrollments',
		'plms_progress',
		'plms_course_progress',
		'plms_quiz_attempts',
		'plms_quiz_answers',
		'plms_orders',
		'plms_order_items',
		'plms_transactions',
		'plms_subscriptions',
		'plms_events',
		'plms_notifications',
		'plms_report_daily',
		'plms_assignment_submissions',
		'plms_certificates',
	);

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			self::for_each_site( array( self::class, 'install' ) );
			return;
		}
		self::install();
	}

	/**
	 * Deactivation hook. Keeps all data; only scheduled work and rewrite rules are cleared.
	 *
	 * @param bool $network_wide Whether the plugin is being network-deactivated.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ): void {
		$callback = static function () {
			flush_rewrite_rules( false );
		};

		if ( is_multisite() && $network_wide ) {
			self::for_each_site( $callback );
			return;
		}
		$callback();
	}

	/**
	 * Installs or upgrades the current site.
	 *
	 * @return void
	 */
	public static function install(): void {
		self::create_tables();
		Roles::install();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
		update_option( self::VERSION_OPTION, PUBLICLMS_VERSION, true );
		update_option( self::FLUSH_OPTION, 1, true );

		/**
		 * Fires after Public LMS has installed or upgraded the current site.
		 *
		 * @param string $db_version The schema version now installed.
		 */
		do_action( 'publiclms_installed', self::DB_VERSION );
	}

	/**
	 * Runs the installer when the stored schema or plugin version is out of date.
	 *
	 * Cheap enough for every request: two autoloaded option reads.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed_db = (string) get_option( self::DB_VERSION_OPTION, '' );
		$installed    = (string) get_option( self::VERSION_OPTION, '' );

		if ( self::DB_VERSION === $installed_db && PUBLICLMS_VERSION === $installed ) {
			return;
		}
		self::install();
	}

	/**
	 * Flushes rewrite rules once, after post types are registered on `init`.
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrite_rules(): void {
		if ( ! get_option( self::FLUSH_OPTION ) ) {
			return;
		}
		delete_option( self::FLUSH_OPTION );
		flush_rewrite_rules( false );
	}

	/**
	 * Installs the plugin on a site created while it is network-active.
	 *
	 * @param \WP_Site $site The new site.
	 * @return void
	 */
	public static function install_on_new_site( $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( PUBLICLMS_BASENAME ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		self::install();
		restore_current_blog();
	}

	/**
	 * Adds the plugin's tables to the list WordPress drops when a site is deleted.
	 *
	 * @param array<int|string, string> $tables Tables WordPress will drop.
	 * @return array<int|string, string>
	 */
	public static function drop_tables_on_site_delete( $tables ): array {
		return array_merge( (array) $tables, self::tables() );
	}

	/**
	 * Returns a table name with the current site's prefix.
	 *
	 * @param string $name Table name from TABLES, without the WordPress prefix.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . $name;
	}

	/**
	 * Returns every table name with the current site's prefix.
	 *
	 * @return array<int, string>
	 */
	public static function tables(): array {
		return array_map( array( self::class, 'table' ), self::TABLES );
	}

	/**
	 * Creates or updates every table through dbDelta().
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::schema() );
	}

	/**
	 * Returns the CREATE TABLE statements in the format dbDelta() expects.
	 *
	 * Conventions: ids are BIGINT UNSIGNED; datetimes are stored in UTC; money
	 * is DECIMAL(19,4) with an ISO 4217 currency code beside it; JSON payloads
	 * are LONGTEXT. Indexed VARCHAR columns stay at 191 characters or less so
	 * keys fit utf8mb4 on older InnoDB row formats.
	 *
	 * @return string
	 */
	public static function schema(): string {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();
		$t       = array();
		foreach ( self::TABLES as $name ) {
			$t[ $name ] = self::table( $name );
		}

		return "CREATE TABLE {$t['plms_enrollments']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  source varchar(20) NOT NULL DEFAULT 'manual',
  source_id bigint(20) unsigned DEFAULT NULL,
  group_id bigint(20) unsigned DEFAULT NULL,
  enrolled_at datetime NOT NULL,
  expires_at datetime DEFAULT NULL,
  completed_at datetime DEFAULT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_course (user_id,course_id),
  KEY course_status (course_id,status),
  KEY group_id (group_id),
  KEY expires_at (expires_at)
) $collate;
CREATE TABLE {$t['plms_progress']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  item_id bigint(20) unsigned NOT NULL,
  item_type varchar(20) NOT NULL DEFAULT 'lesson',
  status varchar(20) NOT NULL DEFAULT 'in_progress',
  started_at datetime DEFAULT NULL,
  completed_at datetime DEFAULT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_item (user_id,item_id),
  KEY user_course (user_id,course_id),
  KEY course_status (course_id,status)
) $collate;
CREATE TABLE {$t['plms_course_progress']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  enrollment_id bigint(20) unsigned DEFAULT NULL,
  items_completed int(10) unsigned NOT NULL DEFAULT 0,
  items_total int(10) unsigned NOT NULL DEFAULT 0,
  percent tinyint(3) unsigned NOT NULL DEFAULT 0,
  last_item_id bigint(20) unsigned DEFAULT NULL,
  started_at datetime DEFAULT NULL,
  completed_at datetime DEFAULT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_course (user_id,course_id),
  KEY course_completed (course_id,completed_at)
) $collate;
CREATE TABLE {$t['plms_quiz_attempts']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  quiz_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL DEFAULT 0,
  attempt_number smallint(5) unsigned NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT 'in_progress',
  score decimal(10,2) DEFAULT NULL,
  max_score decimal(10,2) DEFAULT NULL,
  percent decimal(5,2) DEFAULT NULL,
  passed tinyint(1) DEFAULT NULL,
  question_ids longtext DEFAULT NULL,
  time_spent int(10) unsigned DEFAULT NULL,
  started_at datetime NOT NULL,
  submitted_at datetime DEFAULT NULL,
  graded_at datetime DEFAULT NULL,
  graded_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY user_quiz (user_id,quiz_id),
  KEY quiz_status (quiz_id,status),
  KEY course_status (course_id,status),
  KEY submitted_at (submitted_at)
) $collate;
CREATE TABLE {$t['plms_quiz_answers']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  attempt_id bigint(20) unsigned NOT NULL,
  question_id bigint(20) unsigned NOT NULL,
  answer longtext DEFAULT NULL,
  is_correct tinyint(1) DEFAULT NULL,
  points decimal(10,2) DEFAULT NULL,
  max_points decimal(10,2) DEFAULT NULL,
  feedback text DEFAULT NULL,
  answered_at datetime NOT NULL,
  graded_at datetime DEFAULT NULL,
  graded_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY attempt_question (attempt_id,question_id),
  KEY question_id (question_id)
) $collate;
CREATE TABLE {$t['plms_orders']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_key varchar(64) NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  currency char(3) NOT NULL,
  subtotal decimal(19,4) NOT NULL DEFAULT 0,
  discount_total decimal(19,4) NOT NULL DEFAULT 0,
  tax_total decimal(19,4) NOT NULL DEFAULT 0,
  total decimal(19,4) NOT NULL DEFAULT 0,
  gateway varchar(40) NOT NULL DEFAULT '',
  gateway_order_id varchar(191) DEFAULT NULL,
  coupon_code varchar(100) DEFAULT NULL,
  billing_email varchar(191) DEFAULT NULL,
  customer_ip varchar(45) DEFAULT NULL,
  meta longtext DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  completed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_key (order_key),
  KEY user_id (user_id),
  KEY status_created (status,created_at),
  KEY gateway_order_id (gateway_order_id)
) $collate;
CREATE TABLE {$t['plms_order_items']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  item_type varchar(20) NOT NULL DEFAULT 'course',
  object_id bigint(20) unsigned NOT NULL,
  name varchar(255) NOT NULL DEFAULT '',
  quantity int(10) unsigned NOT NULL DEFAULT 1,
  unit_price decimal(19,4) NOT NULL DEFAULT 0,
  subtotal decimal(19,4) NOT NULL DEFAULT 0,
  discount decimal(19,4) NOT NULL DEFAULT 0,
  tax decimal(19,4) NOT NULL DEFAULT 0,
  total decimal(19,4) NOT NULL DEFAULT 0,
  meta longtext DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id),
  KEY object (item_type,object_id)
) $collate;
CREATE TABLE {$t['plms_transactions']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  gateway varchar(40) NOT NULL,
  type varchar(20) NOT NULL DEFAULT 'payment',
  status varchar(20) NOT NULL DEFAULT 'pending',
  amount decimal(19,4) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL,
  gateway_transaction_id varchar(191) DEFAULT NULL,
  payload longtext DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id),
  KEY gateway_transaction_id (gateway_transaction_id),
  KEY created_at (created_at)
) $collate;
CREATE TABLE {$t['plms_subscriptions']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  order_id bigint(20) unsigned DEFAULT NULL,
  plan_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  gateway varchar(40) NOT NULL DEFAULT '',
  gateway_subscription_id varchar(191) DEFAULT NULL,
  billing_period varchar(10) NOT NULL DEFAULT 'month',
  billing_interval smallint(5) unsigned NOT NULL DEFAULT 1,
  amount decimal(19,4) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL,
  trial_ends_at datetime DEFAULT NULL,
  current_period_end datetime DEFAULT NULL,
  next_payment_at datetime DEFAULT NULL,
  cancelled_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY plan_status (plan_id,status),
  KEY status_next_payment (status,next_payment_at),
  KEY gateway_subscription_id (gateway_subscription_id)
) $collate;
CREATE TABLE {$t['plms_events']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_type varchar(64) NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  course_id bigint(20) unsigned DEFAULT NULL,
  object_type varchar(20) DEFAULT NULL,
  object_id bigint(20) unsigned DEFAULT NULL,
  payload longtext DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY type_created (event_type,created_at),
  KEY user_id (user_id),
  KEY course_created (course_id,created_at)
) $collate;
CREATE TABLE {$t['plms_notifications']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  event_id bigint(20) unsigned DEFAULT NULL,
  channel varchar(20) NOT NULL DEFAULT 'inapp',
  type varchar(64) NOT NULL,
  title varchar(255) NOT NULL DEFAULT '',
  message text DEFAULT NULL,
  link text DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'unread',
  created_at datetime NOT NULL,
  read_at datetime DEFAULT NULL,
  sent_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY user_status (user_id,status),
  KEY channel_status (channel,status),
  KEY created_at (created_at)
) $collate;
CREATE TABLE {$t['plms_report_daily']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  report_date date NOT NULL,
  course_id bigint(20) unsigned NOT NULL DEFAULT 0,
  enrollments int(10) unsigned NOT NULL DEFAULT 0,
  completions int(10) unsigned NOT NULL DEFAULT 0,
  active_learners int(10) unsigned NOT NULL DEFAULT 0,
  lessons_completed int(10) unsigned NOT NULL DEFAULT 0,
  quiz_attempts int(10) unsigned NOT NULL DEFAULT 0,
  quiz_passes int(10) unsigned NOT NULL DEFAULT 0,
  revenue decimal(19,4) NOT NULL DEFAULT 0,
  refunds decimal(19,4) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY date_course (report_date,course_id),
  KEY course_date (course_id,report_date)
) $collate;
CREATE TABLE {$t['plms_assignment_submissions']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  assignment_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL DEFAULT 0,
  attempt smallint(5) unsigned NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT 'submitted',
  content longtext DEFAULT NULL,
  files longtext DEFAULT NULL,
  grade decimal(10,2) DEFAULT NULL,
  max_grade decimal(10,2) DEFAULT NULL,
  passed tinyint(1) DEFAULT NULL,
  feedback longtext DEFAULT NULL,
  submitted_at datetime NOT NULL,
  graded_at datetime DEFAULT NULL,
  graded_by bigint(20) unsigned DEFAULT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_assignment (user_id,assignment_id),
  KEY assignment_status (assignment_id,status),
  KEY course_status (course_id,status),
  KEY submitted_at (submitted_at)
) $collate;
CREATE TABLE {$t['plms_certificates']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  certificate_key varchar(32) NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  template_id bigint(20) unsigned NOT NULL DEFAULT 0,
  learner_name varchar(255) NOT NULL DEFAULT '',
  course_title text DEFAULT NULL,
  completed_at datetime NOT NULL,
  issued_at datetime NOT NULL,
  revoked_at datetime DEFAULT NULL,
  data longtext DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY certificate_key (certificate_key),
  UNIQUE KEY user_course (user_id,course_id),
  KEY course_id (course_id)
) $collate;";
	}

	/**
	 * Runs a callback on every site of the network.
	 *
	 * @param callable $callback Callback to run in each site's context.
	 * @return void
	 */
	private static function for_each_site( callable $callback ): void {
		$site_ids = get_sites(
			array(
				'fields'                 => 'ids',
				'number'                 => 0,
				'update_site_meta_cache' => false,
			)
		);
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			$callback();
			restore_current_blog();
		}
	}
}
