<?php
/**
 * Read-only value object for one integration (feed) record.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

/**
 * Integration value object.
 */
final class Integration {

	/**
	 * Raw integration row data, with settings decoded.
	 *
	 * @var array<string,mixed>
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data Row data with decoded settings.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Builds an integration from a database row.
	 *
	 * @param object|array $row Row.
	 * @return Integration
	 */
	public static function from_row( $row ): Integration {
		$row      = (array) $row;
		$settings = isset( $row['settings'] ) && is_string( $row['settings'] ) ? json_decode( $row['settings'], true ) : array();

		$row['settings'] = is_array( $settings ) ? $settings : array();
		return new self( $row );
	}

	/**
	 * Integration ID.
	 *
	 * @return int
	 */
	public function id(): int {
		return (int) ( $this->data['id'] ?? 0 );
	}

	/**
	 * Contact Form 7 form ID.
	 *
	 * @return int
	 */
	public function form_id(): int {
		return (int) ( $this->data['form_id'] ?? 0 );
	}

	/**
	 * Whether the integration is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return ! empty( $this->data['enabled'] );
	}

	/**
	 * Google spreadsheet ID.
	 *
	 * @return string
	 */
	public function spreadsheet_id(): string {
		return (string) ( $this->data['spreadsheet_id'] ?? '' );
	}

	/**
	 * Spreadsheet name as last reported by Google.
	 *
	 * @return string
	 */
	public function spreadsheet_name(): string {
		return (string) ( $this->data['spreadsheet_name'] ?? '' );
	}

	/**
	 * Worksheet (sheet) ID.
	 *
	 * @return string
	 */
	public function worksheet_id(): string {
		return (string) ( $this->data['worksheet_id'] ?? '' );
	}

	/**
	 * Worksheet name as last reported by Google.
	 *
	 * @return string
	 */
	public function worksheet_name(): string {
		return (string) ( $this->data['worksheet_name'] ?? '' );
	}

	/**
	 * Status of the last sync attempt (success, failed or empty).
	 *
	 * @return string
	 */
	public function last_sync_status(): string {
		return (string) ( $this->data['last_sync_status'] ?? '' );
	}

	/**
	 * Time of the last sync attempt.
	 *
	 * @return string GMT MySQL datetime or ''.
	 */
	public function last_sync_at(): string {
		return (string) ( $this->data['last_sync_at'] ?? '' );
	}

	/**
	 * Time of the last submission.
	 *
	 * @return string GMT MySQL datetime or ''.
	 */
	public function last_submission_at(): string {
		return (string) ( $this->data['last_submission_at'] ?? '' );
	}

	/**
	 * Safe message of the last sync error.
	 *
	 * @return string
	 */
	public function last_error(): string {
		return (string) ( $this->data['last_error'] ?? '' );
	}

	/**
	 * Per-form settings.
	 *
	 * @return array<string,mixed>
	 */
	public function settings(): array {
		return (array) ( $this->data['settings'] ?? array() );
	}

	/**
	 * Single per-form setting.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public function setting( string $key, $fallback = null ) {
		$settings = $this->settings();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
	}

	/**
	 * Saved mapping rows.
	 *
	 * @return array<int,array{source:string,column:string,enabled:bool}>
	 */
	public function mapping(): array {
		$mapping = $this->setting( 'mapping', array() );
		return is_array( $mapping ) ? $mapping : array();
	}

	/**
	 * Field mapping mode.
	 *
	 * @return string auto|manual
	 */
	public function mapping_mode(): string {
		return 'manual' === $this->setting( 'mapping_mode' ) ? 'manual' : 'auto';
	}

	/**
	 * How file fields are written.
	 *
	 * @return string filename|url|both
	 */
	public function file_mode(): string {
		$mode = (string) $this->setting( 'file_mode', 'filename' );
		return array_key_exists( $mode, Settings::file_modes() ) ? $mode : 'filename';
	}

	/**
	 * Where new rows are written: append (bottom) or prepend (row 2, newest first).
	 * Integrations saved before this option existed have no value and keep appending.
	 *
	 * @return string append|prepend
	 */
	public function row_order(): string {
		return 'prepend' === $this->setting( 'row_order' ) ? 'prepend' : 'append';
	}

	/**
	 * Rows kept at the top in newest-first mode: 1-20, or 0 to follow the
	 * worksheet's frozen rows. Integrations saved before this option keep 1.
	 *
	 * @return int
	 */
	public function top_rows(): int {
		$value = $this->setting( 'top_rows', 1 );
		return is_numeric( $value ) && (int) $value >= 0 ? min( Settings::MAX_TOP_ROWS, (int) $value ) : 1;
	}

	/**
	 * Whether the newest submission is inserted at the top (row 2).
	 *
	 * @return bool
	 */
	public function is_newest_first(): bool {
		return 'prepend' === $this->row_order();
	}

	/**
	 * Whether missing headers are created automatically.
	 *
	 * @return bool
	 */
	public function auto_headers(): bool {
		return (bool) $this->setting( 'auto_headers', true );
	}

	/**
	 * Whether the integration points at a spreadsheet and worksheet.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return Security::is_valid_spreadsheet_id( $this->spreadsheet_id() ) && ( '' !== $this->worksheet_id() || '' !== $this->worksheet_name() );
	}

	/**
	 * Browser URL of the configured spreadsheet/worksheet.
	 *
	 * @return string
	 */
	public function spreadsheet_url(): string {
		return $this->spreadsheet_id() ? Google_Sheets_Service::spreadsheet_url( $this->spreadsheet_id(), $this->worksheet_id() ) : '';
	}
}
