<?php
/**
 * PSR-4-style autoloader that maps namespaced classes to WordPress-style file names.
 *
 * SKFreelancers\SheetsSync\Google_Sheets_Service -> includes/class-google-sheets-service.php
 * SKFreelancers\SheetsSync\Google_Auth_Interface -> includes/interface-google-auth.php
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

/**
 * Class autoloader.
 */
final class Autoloader {

	/**
	 * Registers the autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Loads a class or interface belonging to this plugin's namespace.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		if ( '' === $relative || false !== strpos( $relative, '\\' ) ) {
			return;
		}

		$slug = strtolower( str_replace( '_', '-', $relative ) );
		if ( ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			return;
		}

		if ( '-interface' === substr( $slug, -10 ) ) {
			$file = 'interface-' . substr( $slug, 0, -10 ) . '.php';
		} else {
			$file = 'class-' . $slug . '.php';
		}

		$path = SKFSS_PATH . 'includes/' . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
