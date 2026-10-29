<?php
/**
 * Safe logger for sync errors and optional debug events.
 *
 * Privacy/security rules enforced here, not by callers:
 * - Context is filtered through a whitelist of diagnostic keys. Submitted values,
 *   payloads, credentials and tokens have no key to travel in.
 * - Messages are truncated and stripped of markup.
 * - Debug events are only written when debug mode is enabled.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Error logger.
 */
final class Error_Logger {

	const LEVEL_ERROR   = 'error';
	const LEVEL_WARNING = 'warning';
	const LEVEL_DEBUG   = 'debug';

	/**
	 * Context keys allowed in the log.
	 */
	const CONTEXT_KEYS = array( 'form_id', 'form_title', 'integration_id', 'spreadsheet_id', 'worksheet', 'worksheet_id', 'http_status', 'google_status', 'endpoint', 'field_names', 'columns', 'submission_ref', 'count', 'detail' );

	/**
	 * Global settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Logs an error.
	 *
	 * @param string $type    Error type.
	 * @param string $message Safe message.
	 * @param array  $context Diagnostic context.
	 */
	public function error( string $type, string $message, array $context = array() ): void {
		$this->write( self::LEVEL_ERROR, $type, $message, $context );
	}

	/**
	 * Logs a warning.
	 *
	 * @param string $type    Type.
	 * @param string $message Safe message.
	 * @param array  $context Diagnostic context.
	 */
	public function warning( string $type, string $message, array $context = array() ): void {
		$this->write( self::LEVEL_WARNING, $type, $message, $context );
	}

	/**
	 * Logs a debug event when debug mode is enabled.
	 *
	 * @param string $event   Event name.
	 * @param string $message Safe message.
	 * @param array  $context Diagnostic context.
	 */
	public function debug( string $event, string $message, array $context = array() ): void {
		if ( $this->settings->get( 'debug' ) ) {
			$this->write( self::LEVEL_DEBUG, $event, $message, $context );
		}
	}

	/**
	 * Logs a WP_Error produced by the Google layer.
	 *
	 * @param WP_Error $error   Error.
	 * @param array    $context Diagnostic context.
	 */
	public function log_wp_error( WP_Error $error, array $context = array() ): void {
		$data = (array) $error->get_error_data();

		$context['http_status']   = (int) ( $data['http_status'] ?? 0 );
		$context['google_status'] = (string) ( $data['google_status'] ?? '' );
		$context['endpoint']      = (string) ( $data['category'] ?? '' );

		$this->error( (string) ( $data['error_type'] ?? 'unknown' ), $error->get_error_message(), $context );
	}

	/**
	 * Paginated logs.
	 *
	 * @param int    $page     Page (1-based).
	 * @param int    $per_page Per page.
	 * @param string $level    Optional level filter.
	 * @return object[]
	 */
	public function get_logs( int $page, int $per_page, string $level = '' ): array {
		global $wpdb;
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$table  = Installer::table( 'logs' );

		if ( '' !== $level ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin screen only.
			return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE level = %s ORDER BY id DESC LIMIT %d OFFSET %d', $table, $level, $per_page, $offset ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin screen only.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', $table, $per_page, $offset ) );
	}

	/**
	 * Counts logs.
	 *
	 * @param string $level Optional level filter.
	 * @return int
	 */
	public function count_logs( string $level = '' ): int {
		global $wpdb;
		$table = Installer::table( 'logs' );
		if ( '' !== $level ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin screen only.
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE level = %s', $table, $level ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin screen only.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	}

	/**
	 * Deletes all logs.
	 */
	public function clear(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table maintenance.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Installer::table( 'logs' ) ) );
	}

	/**
	 * Deletes logs older than N days.
	 *
	 * @param int $days Days.
	 */
	public function purge_older_than( int $days ): void {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table maintenance.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', Installer::table( 'logs' ), $cutoff ) );
	}

	/**
	 * Writes a log row.
	 *
	 * @param string $level   Level.
	 * @param string $type    Type.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	private function write( string $level, string $type, string $message, array $context ): void {
		global $wpdb;
		$context = self::sanitize_context( $context );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table write.
		$wpdb->insert(
			Installer::table( 'logs' ),
			array(
				'created_at'     => current_time( 'mysql', true ),
				'level'          => $level,
				'form_id'        => (int) ( $context['form_id'] ?? 0 ),
				'form_title'     => substr( (string) ( $context['form_title'] ?? '' ), 0, 255 ),
				'integration_id' => (int) ( $context['integration_id'] ?? 0 ),
				'spreadsheet_id' => substr( (string) ( $context['spreadsheet_id'] ?? '' ), 0, 191 ),
				'worksheet'      => substr( (string) ( $context['worksheet'] ?? '' ), 0, 255 ),
				'error_type'     => substr( sanitize_key( $type ), 0, 50 ),
				'http_status'    => max( 0, min( 65535, (int) ( $context['http_status'] ?? 0 ) ) ),
				'message'        => wp_html_excerpt( sanitize_text_field( $message ), 500, '…' ),
				'context'        => (string) wp_json_encode( $context ),
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Keeps only whitelisted, scalar-or-list-of-scalar context values.
	 *
	 * @param array $context Raw context.
	 * @return array
	 */
	public static function sanitize_context( array $context ): array {
		$clean = array();
		foreach ( self::CONTEXT_KEYS as $key ) {
			if ( ! array_key_exists( $key, $context ) ) {
				continue;
			}
			$value = $context[ $key ];
			if ( is_array( $value ) ) {
				$list = array();
				foreach ( array_slice( $value, 0, 100 ) as $item ) {
					if ( is_scalar( $item ) ) {
						$list[] = wp_html_excerpt( sanitize_text_field( (string) $item ), 100, '…' );
					}
				}
				$clean[ $key ] = $list;
			} elseif ( is_int( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$clean[ $key ] = wp_html_excerpt( sanitize_text_field( (string) $value ), 200, '…' );
			}
		}
		return $clean;
	}
}
