<?php
/**
 * Storage and validation of integrations (one row per feed, many feeds per form
 * supported by the schema; the UI currently manages one feed per form).
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

/**
 * Integration manager.
 */
final class Integration_Manager {

	/**
	 * Per-request cache keyed by form ID.
	 *
	 * @var array<int,Integration[]>
	 */
	private $form_cache = array();

	/**
	 * Returns one integration by ID.
	 *
	 * @param int $id ID.
	 * @return Integration|null
	 */
	public function get( int $id ): ?Integration {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cached per request by callers.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Installer::table( 'integrations' ), $id ) );
		return $row ? Integration::from_row( $row ) : null;
	}

	/**
	 * Integrations for a form.
	 *
	 * @param int  $form_id      Form ID.
	 * @param bool $enabled_only Only enabled integrations.
	 * @return Integration[]
	 */
	public function get_for_form( int $form_id, bool $enabled_only = false ): array {
		if ( ! isset( $this->form_cache[ $form_id ] ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cached per request below.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE form_id = %d ORDER BY id ASC', Installer::table( 'integrations' ), $form_id ) );

			$this->form_cache[ $form_id ] = array_map( array( Integration::class, 'from_row' ), (array) $rows );
		}

		$items = $this->form_cache[ $form_id ];
		if ( $enabled_only ) {
			$items = array_values(
				array_filter(
					$items,
					static function ( Integration $integration ) {
						return $integration->is_enabled();
					}
				)
			);
		}
		return $items;
	}

	/**
	 * The form's first integration (the one edited in the CF7 panel).
	 *
	 * @param int $form_id Form ID.
	 * @return Integration|null
	 */
	public function get_primary_for_form( int $form_id ): ?Integration {
		$items = $this->get_for_form( $form_id );
		return $items[0] ?? null;
	}

	/**
	 * All integrations.
	 *
	 * @return Integration[]
	 */
	public function get_all(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin screens only.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY form_id ASC, id ASC', Installer::table( 'integrations' ) ) );
		return array_map( array( Integration::class, 'from_row' ), (array) $rows );
	}

	/**
	 * Inserts or updates an integration.
	 *
	 * @param array<string,mixed> $data Sanitized data (form_id, enabled, spreadsheet_*, worksheet_*, settings).
	 * @param int                 $id   Existing ID or 0.
	 * @return int Integration ID (0 on failure).
	 */
	public function save( array $data, int $id = 0 ): int {
		global $wpdb;

		$now     = current_time( 'mysql', true );
		$row     = array(
			'form_id'          => absint( $data['form_id'] ?? 0 ),
			'title'            => substr( sanitize_text_field( (string) ( $data['title'] ?? '' ) ), 0, 191 ),
			'enabled'          => empty( $data['enabled'] ) ? 0 : 1,
			'spreadsheet_id'   => (string) ( $data['spreadsheet_id'] ?? '' ),
			'spreadsheet_name' => substr( sanitize_text_field( (string) ( $data['spreadsheet_name'] ?? '' ) ), 0, 255 ),
			'worksheet_id'     => (string) ( $data['worksheet_id'] ?? '' ),
			'worksheet_name'   => substr( sanitize_text_field( (string) ( $data['worksheet_name'] ?? '' ) ), 0, 255 ),
			'settings'         => (string) wp_json_encode( $data['settings'] ?? array() ),
			'updated_at'       => $now,
		);
		$formats = array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' );

		$table = Installer::table( 'integrations' );
		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
			$wpdb->update( $table, $row, array( 'id' => $id ), $formats, array( '%d' ) );
		} else {
			$row['created_at'] = $now;
			$formats[]         = '%s';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table write.
			$wpdb->insert( $table, $row, $formats );
			$id = (int) $wpdb->insert_id;
		}

		unset( $this->form_cache[ $row['form_id'] ] );
		return $id;
	}

	/**
	 * Records the outcome of a sync attempt.
	 *
	 * @param int    $id      Integration ID.
	 * @param bool   $success Success.
	 * @param string $message Safe admin-facing error message.
	 */
	public function record_sync( int $id, bool $success, string $message = '' ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		$wpdb->update(
			Installer::table( 'integrations' ),
			array(
				'last_submission_at' => $now,
				'last_sync_at'       => $now,
				'last_sync_status'   => $success ? 'success' : 'failed',
				'last_error'         => $success ? '' : substr( sanitize_text_field( $message ), 0, 255 ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		$this->form_cache = array();
	}

	/**
	 * Deletes all integrations of a form.
	 *
	 * @param int $form_id Form ID.
	 */
	public function delete_for_form( int $form_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		$wpdb->delete( Installer::table( 'integrations' ), array( 'form_id' => $form_id ), array( '%d' ) );
		unset( $this->form_cache[ $form_id ] );
	}

	/**
	 * Number of enabled integrations and of distinct forms using them.
	 *
	 * @return array{integrations:int,forms:int,failed:int}
	 */
	public function counts(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin screens only.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS integrations, COUNT(DISTINCT form_id) AS forms, SUM( CASE WHEN last_sync_status = 'failed' THEN 1 ELSE 0 END ) AS failed FROM %i WHERE enabled = 1",
				Installer::table( 'integrations' )
			)
		);
		return array(
			'integrations' => (int) ( $row->integrations ?? 0 ),
			'forms'        => (int) ( $row->forms ?? 0 ),
			'failed'       => (int) ( $row->failed ?? 0 ),
		);
	}

	/**
	 * Sanitizes per-form settings from the CF7 panel.
	 *
	 * @param array        $raw         Raw (unslashed) input.
	 * @param string[]     $field_names Current CF7 field names.
	 * @param array        $defaults    Global defaults (default_metadata, default_file_mode, default_row_order).
	 * @param Field_Mapper $mapper  Mapper.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( array $raw, array $field_names, array $defaults, Field_Mapper $mapper ): array {
		$config_mode  = isset( $raw['config_mode'] ) && 'manual' === $raw['config_mode'] ? 'manual' : 'select';
		$mapping_mode = isset( $raw['mapping_mode'] ) && 'manual' === $raw['mapping_mode'] ? 'manual' : 'auto';
		$file_mode    = isset( $raw['file_mode'] ) ? sanitize_key( (string) $raw['file_mode'] ) : '';
		$file_mode    = array_key_exists( $file_mode, Settings::file_modes() ) ? $file_mode : (string) $defaults['default_file_mode'];
		$row_order    = isset( $raw['row_order'] ) ? sanitize_key( (string) $raw['row_order'] ) : '';
		$row_order    = array_key_exists( $row_order, Settings::row_orders() ) ? $row_order : (string) ( $defaults['default_row_order'] ?? 'append' );

		$rows = array();
		foreach ( (array) ( $raw['mapping'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$rows[] = array(
				'source'  => isset( $item['source'] ) ? sanitize_text_field( (string) $item['source'] ) : '',
				'column'  => isset( $item['column'] ) ? sanitize_text_field( (string) $item['column'] ) : '',
				'enabled' => ! empty( $item['enabled'] ),
			);
		}

		return array(
			'config_mode'  => $config_mode,
			'mapping_mode' => $mapping_mode,
			'file_mode'    => $file_mode,
			'auto_headers' => ! empty( $raw['auto_headers'] ),
			'row_order'    => $row_order,
			'top_rows'     => isset( $raw['top_rows'] ) && is_numeric( $raw['top_rows'] ) && (int) $raw['top_rows'] >= 0 ? min( Settings::MAX_TOP_ROWS, (int) $raw['top_rows'] ) : 1,
			'mapping'      => $mapper->normalize_mapping( $rows, $field_names, (array) $defaults['default_metadata'] ),
		);
	}
}
