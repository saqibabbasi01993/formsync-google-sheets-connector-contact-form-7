<?php
/**
 * Writes one Submission_Payload to one Integration's worksheet.
 *
 * This class knows nothing about Contact Form 7. It never throws for Google failures:
 * every failure is returned as WP_Error, recorded on the integration, written to the
 * safe log and (for authentication problems) raised as an admin alert.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sync service.
 */
final class Sync_Service {

	const CELL_LIMIT = 50000;

	/**
	 * Google Sheets service.
	 *
	 * @var Google_Sheets_Service
	 */
	private $sheets;

	/**
	 * Google authentication provider.
	 *
	 * @var Google_Auth_Interface
	 */
	private $auth;

	/**
	 * Integration storage.
	 *
	 * @var Integration_Manager
	 */
	private $integrations;

	/**
	 * Field mapper.
	 *
	 * @var Field_Mapper
	 */
	private $mapper;

	/**
	 * Submission ledger.
	 *
	 * @var Submission_Tracker
	 */
	private $tracker;

	/**
	 * Error logger.
	 *
	 * @var Error_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Google_Sheets_Service $sheets       Sheets service.
	 * @param Google_Auth_Interface $auth         Auth.
	 * @param Integration_Manager   $integrations Integrations.
	 * @param Field_Mapper          $mapper       Mapper.
	 * @param Submission_Tracker    $tracker      Ledger.
	 * @param Error_Logger          $logger       Logger.
	 */
	public function __construct( Google_Sheets_Service $sheets, Google_Auth_Interface $auth, Integration_Manager $integrations, Field_Mapper $mapper, Submission_Tracker $tracker, Error_Logger $logger ) {
		$this->sheets       = $sheets;
		$this->auth         = $auth;
		$this->integrations = $integrations;
		$this->mapper       = $mapper;
		$this->tracker      = $tracker;
		$this->logger       = $logger;
	}

	/**
	 * Syncs a payload.
	 *
	 * @param Integration        $integration Integration.
	 * @param Submission_Payload $payload     Payload.
	 * @return true|WP_Error True on success or when skipped as a duplicate.
	 */
	public function sync( Integration $integration, Submission_Payload $payload ) {
		$context = $this->context( $integration, $payload );

		if ( ! $integration->is_configured() ) {
			$error = new WP_Error(
				'skfss_not_configured',
				__( 'This form\'s Google Sheets integration is enabled but no spreadsheet or worksheet is selected.', 'formsync-google-sheets-connector-contact-form-7' ),
				array(
					'error_type'  => 'not_configured',
					'http_status' => 0,
					'category'    => 'config',
				)
			);
			return $this->fail( $integration, $payload, $error, $context, false );
		}

		if ( ! $this->auth->is_configured() ) {
			$error = new WP_Error(
				'skfss_auth_unavailable',
				__( 'Google Sheets authentication needs attention.', 'formsync-google-sheets-connector-contact-form-7' ),
				array(
					'error_type'  => 'auth_unavailable',
					'http_status' => 0,
					'category'    => 'auth',
				)
			);
			return $this->fail( $integration, $payload, $error, $context, false );
		}

		if ( Submission_Tracker::DUPLICATE === $this->tracker->claim( $integration->id(), $payload->form_id(), $payload->key() ) ) {
			$this->logger->debug( 'duplicate_skipped', 'Submission already synced (or in progress); not appended again.', $context );
			return true;
		}

		$result = $this->write_row( $integration, $payload, $context );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $integration, $payload, $result, $context, true );
		}

		$this->tracker->complete( $integration->id(), $payload->key(), (string) $result['range'] );
		$this->integrations->record_sync( $integration->id(), true );
		$this->logger->debug( $integration->is_newest_first() ? 'row_inserted' : 'row_appended', $integration->is_newest_first() ? 'Row inserted below the rows kept at the top.' : 'Row appended to Google Sheets.', $context + array( 'detail' => (string) $result['range'] ) );

		return true;
	}

	/**
	 * Resolves the worksheet, prepares headers and appends the row.
	 *
	 * @param Integration        $integration Integration.
	 * @param Submission_Payload $payload     Payload.
	 * @param array              $context     Log context.
	 * @return array{range:string}|WP_Error
	 */
	private function write_row( Integration $integration, Submission_Payload $payload, array $context ) {
		$spreadsheet_id = $integration->spreadsheet_id();

		$worksheet = $this->sheets->find_worksheet( $spreadsheet_id, $integration->worksheet_id(), $integration->worksheet_name() );
		if ( is_wp_error( $worksheet ) ) {
			return $worksheet;
		}
		$title = $worksheet['title'];

		$headers = $this->sheets->get_header_row( $spreadsheet_id, $title );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}
		$this->logger->debug( 'headers_retrieved', 'Header row retrieved.', $context + array( 'count' => count( $headers ) ) );

		$columns = $this->mapper->resolve_columns( $integration, $payload->field_names(), $headers );
		if ( empty( $columns ) ) {
			return new WP_Error(
				'skfss_no_columns',
				__( 'No fields are selected for this integration, so nothing was sent.', 'formsync-google-sheets-connector-contact-form-7' ),
				array(
					'error_type'  => 'no_columns',
					'http_status' => 0,
					'category'    => 'mapping',
				)
			);
		}

		if ( $integration->auto_headers() ) {
			$headers = $this->ensure_headers( $spreadsheet_id, $worksheet, $headers, $columns, $context );
			if ( is_wp_error( $headers ) ) {
				return $headers;
			}
			// ensure_headers() grew the grid if needed; avoid growing it a second time.
			$worksheet['columns'] = max( (int) $worksheet['columns'], count( $headers ) );
		}

		$row = $this->build_row( $columns, $headers, $payload, $context );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$grown = $this->sheets->ensure_column_count( $spreadsheet_id, $worksheet, count( $row ) );
		if ( is_wp_error( $grown ) ) {
			return $grown;
		}

		if ( $integration->is_newest_first() ) {
			if ( empty( $headers ) ) {
				// Row 1 stays reserved for headers even while it is empty, so the data
				// still goes below it. Tell the administrator why row 1 is blank.
				$this->logger->warning( 'header_row_empty', 'Newest-first is on but the worksheet has no header row, so row 1 was left empty for headers. Turn on "Automatically create missing headers" or type headers into row 1.', $context );
			}
			$written = $this->sheets->insert_row_at_top( $spreadsheet_id, $worksheet, $row, $this->top_rows_for( $integration, $worksheet ) );
		} else {
			$written = $this->sheets->append_row( $spreadsheet_id, $worksheet, $row );
		}

		if ( is_wp_error( $written ) && 'worksheet_missing' === ( $written->get_error_data()['error_type'] ?? '' ) ) {
			$this->sheets->invalidate_spreadsheet_cache( $spreadsheet_id );
		}
		return $written;
	}

	/**
	 * Creates the header row on an empty sheet, or adds missing headers after the
	 * existing ones. Existing header cells are never overwritten.
	 *
	 * @param string $spreadsheet_id Spreadsheet ID.
	 * @param array  $worksheet      Worksheet.
	 * @param array  $headers        Existing headers.
	 * @param array  $columns        Resolved columns.
	 * @param array  $context        Log context.
	 * @return string[]|WP_Error Updated headers.
	 */
	private function ensure_headers( string $spreadsheet_id, array $worksheet, array $headers, array $columns, array $context ) {
		$missing = $this->missing_headers( $headers, $columns );
		if ( empty( $missing ) ) {
			return $headers;
		}

		// The header row may come from a short-lived cache. Re-read it before writing
		// so a column someone just added by hand is never overwritten.
		$headers = $this->sheets->get_header_row( $spreadsheet_id, $worksheet['title'], true );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}
		$missing = $this->missing_headers( $headers, $columns );
		if ( empty( $missing ) ) {
			return $headers;
		}

		$grown = $this->sheets->ensure_column_count( $spreadsheet_id, $worksheet, count( $headers ) + count( $missing ) );
		if ( is_wp_error( $grown ) ) {
			return $grown;
		}

		$cells  = array_map( array( Formula_Guard::class, 'protect' ), $missing );
		$result = $this->sheets->write_header_cells( $spreadsheet_id, $worksheet['title'], count( $headers ), $cells );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->logger->debug( empty( $headers ) ? 'headers_created' : 'headers_added', 'Header cells written.', $context + array( 'columns' => $missing ) );
		return array_merge( $headers, $missing );
	}

	/**
	 * How many rows stay above new submissions in newest-first mode.
	 *
	 * "Same as frozen rows" (0) uses the worksheet's frozen row count, with a
	 * minimum of 1 so the header row is always kept. The value is capped at the
	 * worksheet's row count so the insert position always exists in the grid.
	 *
	 * @param Integration $integration Integration.
	 * @param array       $worksheet   Worksheet from find_worksheet().
	 * @return int At least 1.
	 */
	private function top_rows_for( Integration $integration, array $worksheet ): int {
		$top = $integration->top_rows();
		if ( 0 === $top ) {
			$top = (int) ( $worksheet['frozen'] ?? 0 );
		}
		$top = max( 1, $top );

		$grid_rows = (int) ( $worksheet['rows'] ?? 0 );
		return $grid_rows > 0 ? min( $top, $grid_rows ) : $top;
	}

	/**
	 * Column names from the mapping that are not yet in the header row.
	 *
	 * @param string[] $headers Existing headers.
	 * @param array    $columns Resolved columns.
	 * @return string[]
	 */
	private function missing_headers( array $headers, array $columns ): array {
		$existing = array();
		foreach ( $headers as $header ) {
			$existing[ Field_Mapper::header_key( $header ) ] = true;
		}

		$missing = array();
		foreach ( $columns as $column ) {
			$key = Field_Mapper::header_key( $column['column'] );
			if ( '' !== $key && ! isset( $existing[ $key ] ) ) {
				$existing[ $key ] = true;
				$missing[]        = $column['column'];
			}
		}
		return $missing;
	}

	/**
	 * Builds the row. With headers, values go under matching header cells; without
	 * headers, values follow the mapping order. Every cell is formula-guarded.
	 *
	 * @param array              $columns Columns.
	 * @param string[]           $headers Headers.
	 * @param Submission_Payload $payload Payload.
	 * @param array              $context Log context.
	 * @return string[]|WP_Error
	 */
	private function build_row( array $columns, array $headers, Submission_Payload $payload, array $context ) {
		if ( empty( $headers ) ) {
			$row = array();
			foreach ( $columns as $column ) {
				$row[] = $payload->value( $column['source'] );
			}
			return array_map( array( $this, 'finalize_cell' ), $row );
		}

		$positions = array();
		foreach ( $headers as $index => $header ) {
			$key = Field_Mapper::header_key( $header );
			if ( '' !== $key && ! isset( $positions[ $key ] ) ) {
				$positions[ $key ] = $index;
			}
		}

		$row       = array_fill( 0, count( $headers ), '' );
		$matched   = 0;
		$unmatched = array();
		foreach ( $columns as $column ) {
			$key = Field_Mapper::header_key( $column['column'] );
			if ( ! isset( $positions[ $key ] ) ) {
				$unmatched[] = $column['column'];
				continue;
			}
			++$matched;
			$position = $positions[ $key ];
			$value    = $payload->value( $column['source'] );
			if ( '' === $value ) {
				continue;
			}
			// Two sources mapped to the same column are joined rather than overwritten.
			$row[ $position ] = '' === $row[ $position ] ? $value : $row[ $position ] . ', ' . $value;
		}

		if ( 0 === $matched ) {
			return new WP_Error(
				'skfss_no_matching_columns',
				__( 'None of the mapped columns exist in the worksheet header row. Turn on "Create missing headers" or update the field mapping.', 'formsync-google-sheets-connector-contact-form-7' ),
				array(
					'error_type'  => 'no_matching_columns',
					'http_status' => 0,
					'category'    => 'mapping',
				)
			);
		}
		if ( ! empty( $unmatched ) ) {
			$this->logger->debug( 'columns_not_in_sheet', 'Some mapped columns are not in the header row and were skipped.', $context + array( 'columns' => $unmatched ) );
		}

		while ( ! empty( $row ) && '' === end( $row ) ) {
			array_pop( $row );
		}

		return array_map( array( $this, 'finalize_cell' ), $row );
	}

	/**
	 * Truncates to Google's cell limit and applies formula protection.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function finalize_cell( string $value ): string {
		if ( strlen( $value ) > self::CELL_LIMIT ) {
			$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::CELL_LIMIT - 1 ) : substr( $value, 0, self::CELL_LIMIT - 1 );
		}
		return Formula_Guard::protect( $value );
	}

	/**
	 * Records a failure everywhere it needs to be visible.
	 *
	 * @param Integration        $integration Integration.
	 * @param Submission_Payload $payload     Payload.
	 * @param WP_Error           $error       Error.
	 * @param array              $context     Log context.
	 * @param bool               $claimed     Whether a ledger claim exists.
	 * @return WP_Error
	 */
	private function fail( Integration $integration, Submission_Payload $payload, WP_Error $error, array $context, bool $claimed ): WP_Error {
		$data = (array) $error->get_error_data();
		$type = (string) ( $data['error_type'] ?? 'unknown' );

		if ( $claimed ) {
			$this->tracker->fail( $integration->id(), $payload->key(), $type, (int) ( $data['http_status'] ?? 0 ) );
		}
		$this->integrations->record_sync( $integration->id(), false, $error->get_error_message() );
		$this->logger->log_wp_error( $error, $context + array( 'field_names' => $payload->field_names() ) );

		if ( in_array( $type, array( 'auth_failed', 'auth_unavailable' ), true ) ) {
			update_option( Google_Service_Account_Auth::ALERT_OPTION, time() );
		}

		/**
		 * Fires after a submission failed to sync. Hook point for notifications or a
		 * retry queue; receives no submitted values.
		 *
		 * @param Integration $integration Integration.
		 * @param string      $key         Submission key.
		 * @param WP_Error    $error       Error.
		 */
		do_action( 'skfss_sync_failed', $integration, $payload->key(), $error );

		return $error;
	}

	/**
	 * Safe diagnostic context.
	 *
	 * @param Integration        $integration Integration.
	 * @param Submission_Payload $payload     Payload.
	 * @return array
	 */
	private function context( Integration $integration, Submission_Payload $payload ): array {
		return array(
			'form_id'        => $payload->form_id(),
			'form_title'     => $payload->form_title(),
			'integration_id' => $integration->id(),
			'spreadsheet_id' => $integration->spreadsheet_id(),
			'worksheet'      => $integration->worksheet_name(),
			'worksheet_id'   => $integration->worksheet_id(),
			'submission_ref' => $payload->reference(),
		);
	}
}
