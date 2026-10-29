<?php
/**
 * Uninstall routine.
 *
 * Conservative by default: nothing is deleted unless the site administrator
 * enabled "Delete plugin data when plugin is uninstalled" (per site on multisite).
 * Only data created by this plugin is removed: its options, transients, custom
 * tables (integrations, submission ledger, logs), scheduled event and the
 * skfss-uploads folder of file copies. Contact Form 7 forms, other media and
 * Google spreadsheets are never touched.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync\Uninstall;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes this plugin's data from the current site when the site opted in.
 */
function skfss_uninstall_site(): void {
	global $wpdb;

	$settings = get_option( 'skfss_settings', array() );
	if ( ! is_array( $settings ) || empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}

	foreach ( array( 'integrations', 'submissions', 'logs' ) as $name ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing this plugin's own table on uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'skfss_' . $name ) );
	}

	foreach ( array( 'skfss_settings', 'skfss_credentials', 'skfss_auth_alert', 'skfss_db_version', 'skfss_cache_gen' ) as $option ) {
		delete_option( $option );
	}

	// Transients: access token, cached spreadsheet metadata/headers and one-time notices.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cleanup of this plugin's own transients.
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_skfss_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_skfss_' ) . '%'
		)
	);

	wp_clear_scheduled_hook( 'skfss_daily_maintenance' );

	skfss_remove_upload_copies();
}

/**
 * Deletes the folder of file copies created for "link" file mode. The path is
 * fixed (uploads/skfss-uploads) and verified to be inside the uploads directory.
 */
function skfss_remove_upload_copies(): void {
	$uploads = wp_upload_dir( null, false );
	if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
		return;
	}

	$base = wp_normalize_path( untrailingslashit( (string) $uploads['basedir'] ) );
	$dir  = $base . '/skfss-uploads';
	$real = realpath( $dir );
	if ( false === $real || 0 !== strpos( wp_normalize_path( $real ) . '/', $base . '/' ) ) {
		return;
	}

	if ( ! class_exists( '\WP_Filesystem_Direct' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
	}
	$filesystem = new \WP_Filesystem_Direct( null );
	$filesystem->rmdir( $real, true );
}

if ( is_multisite() ) {
	$skfss_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $skfss_site_ids as $skfss_site_id ) {
		switch_to_blog( (int) $skfss_site_id );
		skfss_uninstall_site();
		restore_current_blog();
	}
} else {
	skfss_uninstall_site();
}
