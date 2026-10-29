<?php
/**
 * Idempotency ledger for submission syncs.
 *
 * Each (integration, submission) pair is claimed with INSERT IGNORE against a UNIQUE
 * index, which is atomic even under concurrent requests. A submission that already
 * succeeded is never appended again; a failed one may be claimed again (this is the
 * hook point for a future retry queue). Only an HMAC key is stored, never values.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

/**
 * Submission tracker.
 */
final class Submission_Tracker {

	const CLAIMED   = 'claimed';
	const DUPLICATE = 'duplicate';

	/** Minutes after which an unfinished "processing" claim is considered abandoned. */
	const STALE_MINUTES = 10;

	/**
	 * Claims a submission for an integration.
	 *
	 * @param int    $integration_id Integration ID.
	 * @param int    $form_id        Form ID.
	 * @param string $key            64-char submission key.
	 * @return string self::CLAIMED or self::DUPLICATE.
	 */
	public function claim( int $integration_id, int $form_id, string $key ): string {
		global $wpdb;
		$table = Installer::table( 'submissions' );
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic claim on a custom table.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (integration_id, form_id, submission_key, status, attempts, created_at, updated_at) VALUES (%d, %d, %s, %s, 1, %s, %s)',
				$table,
				$integration_id,
				$form_id,
				$key,
				'processing',
				$now,
				$now
			)
		);
		if ( 1 === (int) $inserted ) {
			return self::CLAIMED;
		}

		$stale = gmdate( 'Y-m-d H:i:s', time() - self::STALE_MINUTES * MINUTE_IN_SECONDS );

		// Re-claim only failed or abandoned attempts; the WHERE clause keeps this atomic.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic claim on a custom table.
		$reclaimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'processing', attempts = attempts + 1, updated_at = %s WHERE integration_id = %d AND submission_key = %s AND ( status = 'failed' OR ( status = 'processing' AND updated_at < %s ) )",
				$table,
				$now,
				$integration_id,
				$key,
				$stale
			)
		);

		return 1 === (int) $reclaimed ? self::CLAIMED : self::DUPLICATE;
	}

	/**
	 * Marks a claim as completed.
	 *
	 * @param int    $integration_id Integration ID.
	 * @param string $key            Submission key.
	 * @param string $range          Range written by Google (for future row updates).
	 */
	public function complete( int $integration_id, string $key, string $range ): void {
		$this->update( $integration_id, $key, 'success', '', 0, $range );
	}

	/**
	 * Marks a claim as failed so it can be retried later.
	 *
	 * @param int    $integration_id Integration ID.
	 * @param string $key            Submission key.
	 * @param string $error_type     Error type.
	 * @param int    $http_status    HTTP status.
	 */
	public function fail( int $integration_id, string $key, string $error_type, int $http_status ): void {
		$this->update( $integration_id, $key, 'failed', $error_type, $http_status, '' );
	}

	/**
	 * Removes ledger rows older than N days.
	 *
	 * @param int $days Days.
	 */
	public function purge_older_than( int $days ): void {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table maintenance.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE updated_at < %s', Installer::table( 'submissions' ), $cutoff ) );
	}

	/**
	 * Updates a ledger row.
	 *
	 * @param int    $integration_id Integration ID.
	 * @param string $key            Key.
	 * @param string $status         Status.
	 * @param string $error_type     Error type.
	 * @param int    $http_status    HTTP status.
	 * @param string $range          Range.
	 */
	private function update( int $integration_id, string $key, string $status, string $error_type, int $http_status, string $range ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		$wpdb->update(
			Installer::table( 'submissions' ),
			array(
				'status'      => $status,
				'error_type'  => substr( $error_type, 0, 50 ),
				'http_status' => max( 0, min( 65535, $http_status ) ),
				'sheet_range' => substr( $range, 0, 255 ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array(
				'integration_id' => $integration_id,
				'submission_key' => $key,
			),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d', '%s' )
		);
	}
}
