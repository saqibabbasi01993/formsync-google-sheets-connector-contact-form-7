<?php
/**
 * Activation, schema management, multisite support and deactivation.
 *
 * Storage architecture:
 * - Global settings: one autoloaded option (skfss_settings).
 * - Credentials: one non-autoloaded, encrypted option (skfss_credentials).
 * - Integrations: custom table. Integrations must be listed and counted across all
 *   forms (dashboard, integrations screen) and a form may own several feeds in the
 *   future; a table with a form_id index serves both without meta queries.
 * - Submission ledger: custom table with a UNIQUE (integration_id, submission_key)
 *   index. This gives atomic duplicate protection under concurrent requests and is
 *   the foundation for a future retry queue. It never stores submitted values.
 * - Logs: custom table so they can be paginated, filtered and purged cheaply.
 *
 * Every table uses $wpdb->prefix, so each site in a multisite network has its own.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

/**
 * Installer.
 */
final class Installer {

	const CRON_HOOK         = 'skfss_daily_maintenance';
	const DB_VERSION_OPTION = 'skfss_db_version';

	/**
	 * Returns a prefixed table name.
	 *
	 * @param string $name Short name: integrations, submissions or logs.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'skfss_' . $name;
	}

	/**
	 * All table names owned by this plugin for the current site.
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		return array( self::table( 'integrations' ), self::table( 'submissions' ), self::table( 'logs' ) );
	}

	/**
	 * Activation callback.
	 *
	 * @param bool $network_wide Whether activated network-wide.
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( self::site_ids() as $site_id ) {
				switch_to_blog( $site_id );
				self::install_site();
				restore_current_blog();
			}
			return;
		}
		self::install_site();
	}

	/**
	 * Deactivation callback. Keeps all data; only unschedules events.
	 *
	 * @param bool $network_wide Whether deactivated network-wide.
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( self::site_ids() as $site_id ) {
				switch_to_blog( $site_id );
				wp_clear_scheduled_hook( self::CRON_HOOK );
				restore_current_blog();
			}
			return;
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Installs or upgrades the current site.
	 */
	public static function install_site(): void {
		self::create_tables();

		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		update_option( self::DB_VERSION_OPTION, SKFSS_DB_VERSION );
	}

	/**
	 * Runs the installer when the stored schema version differs (plugin updates do
	 * not trigger the activation hook).
	 */
	public static function maybe_upgrade(): void {
		if ( SKFSS_DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install_site();
		}
	}

	/**
	 * Registers multisite lifecycle hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'wp_initialize_site', array( __CLASS__, 'on_initialize_site' ), 200 );
		add_filter( 'wpmu_drop_tables', array( __CLASS__, 'on_drop_tables' ) );
	}

	/**
	 * Creates tables for a new site when the plugin is network-active.
	 *
	 * @param \WP_Site $site New site.
	 */
	public static function on_initialize_site( $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( plugin_basename( SKFSS_FILE ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		self::install_site();
		restore_current_blog();
	}

	/**
	 * Drops this plugin's tables when a site is deleted.
	 *
	 * @param string[] $tables Tables WordPress will drop.
	 * @return string[]
	 */
	public static function on_drop_tables( $tables ): array {
		return array_merge( (array) $tables, self::tables() );
	}

	/**
	 * Creates or updates the schema with dbDelta().
	 */
	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset      = $wpdb->get_charset_collate();
		$integrations = self::table( 'integrations' );
		$submissions  = self::table( 'submissions' );
		$logs         = self::table( 'logs' );

		// dbDelta requires this exact formatting (two spaces after PRIMARY KEY, one field per line).
		$sql = "CREATE TABLE {$integrations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			title varchar(191) NOT NULL DEFAULT '',
			enabled tinyint(1) NOT NULL DEFAULT 0,
			spreadsheet_id varchar(191) NOT NULL DEFAULT '',
			spreadsheet_name varchar(255) NOT NULL DEFAULT '',
			worksheet_id varchar(32) NOT NULL DEFAULT '',
			worksheet_name varchar(255) NOT NULL DEFAULT '',
			settings longtext NULL,
			last_submission_at datetime NULL DEFAULT NULL,
			last_sync_at datetime NULL DEFAULT NULL,
			last_sync_status varchar(20) NOT NULL DEFAULT '',
			last_error varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY form_enabled (form_id,enabled)
		) {$charset};
		CREATE TABLE {$submissions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			integration_id bigint(20) unsigned NOT NULL DEFAULT 0,
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			submission_key char(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT '',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			http_status smallint(5) unsigned NOT NULL DEFAULT 0,
			error_type varchar(50) NOT NULL DEFAULT '',
			sheet_range varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY integration_submission (integration_id,submission_key),
			KEY status (status),
			KEY updated_at (updated_at)
		) {$charset};
		CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			level varchar(10) NOT NULL DEFAULT 'error',
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			form_title varchar(255) NOT NULL DEFAULT '',
			integration_id bigint(20) unsigned NOT NULL DEFAULT 0,
			spreadsheet_id varchar(191) NOT NULL DEFAULT '',
			worksheet varchar(255) NOT NULL DEFAULT '',
			error_type varchar(50) NOT NULL DEFAULT '',
			http_status smallint(5) unsigned NOT NULL DEFAULT 0,
			message text NULL,
			context longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY level (level),
			KEY form_id (form_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Site IDs in the network.
	 *
	 * @return int[]
	 */
	private static function site_ids(): array {
		return array_map(
			'intval',
			get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			)
		);
	}
}
