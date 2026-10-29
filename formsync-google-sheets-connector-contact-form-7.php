<?php
/**
 * Plugin Name:       FormSync - Google Sheets Connector for Contact Form 7
 * Description:       Send Contact Form 7 submissions to Google Sheets through the official Google Sheets API, with per-form spreadsheet selection, field mapping and safe error logging.
 * Version:           1.2.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  contact-form-7
 * Author:            Saqib K.
 * Author URI:        https://skfreelancers.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       formsync-google-sheets-connector-contact-form-7
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

// Earlier builds of this plugin ("Sheets Sync for Contact Form 7" and "Form2Sheet")
// share this plugin's code and settings. If one of them is also active (or another
// copy is already loaded), stay inactive instead of redeclaring classes and
// constants, and ask the administrator to deactivate one of them. Settings are
// shared, so nothing is lost. WordPress loads plugins alphabetically, so a copy may
// load before an earlier build; that is why earlier builds are detected by their
// active-plugins entry, not only by constants.
$skfss_legacy_active = false;
foreach ( array(
	'sheets-sync-for-contact-form-7/sheets-sync-for-contact-form-7.php',
	'form2sheet-google-sheets-contact-form-7/form2sheet-google-sheets-contact-form-7.php',
) as $skfss_legacy_file ) {
	$skfss_is_active = in_array( $skfss_legacy_file, (array) get_option( 'active_plugins', array() ), true );
	if ( ! $skfss_is_active && is_multisite() ) {
		$skfss_is_active = array_key_exists( $skfss_legacy_file, (array) get_site_option( 'active_sitewide_plugins', array() ) );
	}
	if ( $skfss_is_active && file_exists( WP_PLUGIN_DIR . '/' . $skfss_legacy_file ) ) {
		$skfss_legacy_active = true;
		break;
	}
}
unset( $skfss_legacy_file, $skfss_is_active );

if ( defined( 'SKFSS_VERSION' ) || $skfss_legacy_active ) {
	unset( $skfss_legacy_active );
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'plugins-network' ), true ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'FormSync is already running from another copy of this plugin (for example "Form2Sheet" or "Sheets Sync for Contact Form 7"). Deactivate one of them. Your settings and integrations are shared and are kept.', 'formsync-google-sheets-connector-contact-form-7' )
			);
		}
	);
	return;
}
unset( $skfss_legacy_active );

define( 'SKFSS_VERSION', '1.2.2' );
define( 'SKFSS_DB_VERSION', '1.0.0' );
define( 'SKFSS_FILE', __FILE__ );
define( 'SKFSS_PATH', plugin_dir_path( __FILE__ ) );
define( 'SKFSS_URL', plugin_dir_url( __FILE__ ) );

require_once SKFSS_PATH . 'includes/class-autoloader.php';
Autoloader::register();

register_activation_hook( __FILE__, array( Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'instance' ), 20 );
