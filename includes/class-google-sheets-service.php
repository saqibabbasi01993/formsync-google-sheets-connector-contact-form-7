<?php
/**
 * Google Sheets / Drive operations used by the plugin.
 *
 * All Google URLs live here (and in the auth class). Relatively static data
 * (spreadsheet list, metadata, header rows) is cached in transients whose keys include
 * a generation number, so the whole cache can be invalidated at once when credentials
 * change (works with persistent object caches too).
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sheets service.
 */
final class Google_Sheets_Service {

	const SHEETS_BASE    = 'https://sheets.googleapis.com/v4/spreadsheets/';
	const DRIVE_FILES    = 'https://www.googleapis.com/drive/v3/files';
	const CACHE_GEN      = 'skfss_cache_gen';
	const MAX_DRIVE_PAGE = 5;

	/**
	 * Google HTTP client.
	 *
	 * @var Google_Http_Client
	 */
	private $http;

	/**
	 * Google authentication provider.
	 *
	 * @var Google_Auth_Interface
	 */
	private $auth;

	/**
	 * Constructor.
	 *
	 * @param Google_Http_Client    $http HTTP client.
	 * @param Google_Auth_Interface $auth Auth provider.
	 */
	public function __construct( Google_Http_Client $http, Google_Auth_Interface $auth ) {
		$this->http = $http;
		$this->auth = $auth;
	}

	/**
	 * Lists spreadsheets visible to the connected account (Drive API).
	 *
	 * @param bool $refresh Bypass cache.
	 * @return array<int,array{id:string,name:string}>|WP_Error
	 */
	public function list_spreadsheets( bool $refresh = false ) {
		$key = $this->cache_key( 'files', 'all' );
		if ( ! $refresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$files = array();
		$token = '';
		for ( $page = 0; $page < self::MAX_DRIVE_PAGE; $page++ ) {
			$query = array(
				'q'                         => "mimeType='application/vnd.google-apps.spreadsheet' and trashed=false",
				'fields'                    => 'nextPageToken,files(id,name)',
				'pageSize'                  => 200,
				'orderBy'                   => 'name_natural',
				'supportsAllDrives'         => 'true',
				'includeItemsFromAllDrives' => 'true',
			);
			if ( '' !== $token ) {
				$query['pageToken'] = $token;
			}

			$data = $this->http->request( 'GET', self::DRIVE_FILES . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ), null, 'drive_list' );
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			foreach ( (array) ( $data['files'] ?? array() ) as $file ) {
				if ( is_array( $file ) && isset( $file['id'], $file['name'] ) && Security::is_valid_spreadsheet_id( (string) $file['id'] ) ) {
					$files[] = array(
						'id'   => (string) $file['id'],
						'name' => sanitize_text_field( (string) $file['name'] ),
					);
				}
			}

			$token = isset( $data['nextPageToken'] ) && is_string( $data['nextPageToken'] ) ? $data['nextPageToken'] : '';
			if ( '' === $token ) {
				break;
			}
		}

		set_transient( $key, $files, 10 * MINUTE_IN_SECONDS );
		return $files;
	}

	/**
	 * Spreadsheet metadata including worksheets.
	 *
	 * @param string $spreadsheet_id Spreadsheet ID.
	 * @param bool   $refresh        Bypass cache.
	 * @return array{id:string,name:string,url:string,sheets:array}|WP_Error
	 */
	public function get_spreadsheet( string $spreadsheet_id, bool $refresh = false ) {
		if ( ! Security::is_valid_spreadsheet_id( $spreadsheet_id ) ) {
			return Google_Http_Client::error( 'not_found', 0, '', 'metadata', false );
		}

		$key = $this->cache_key( 'meta', $spreadsheet_id );
		if ( ! $refresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$query = array( 'fields' => 'spreadsheetId,spreadsheetUrl,properties.title,sheets.properties(sheetId,title,index,gridProperties(rowCount,columnCount,frozenRowCount))' );
		$data  = $this->http->request( 'GET', self::SHEETS_BASE . rawurlencode( $spreadsheet_id ) . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ), null, 'metadata' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$sheets = array();
		foreach ( (array) ( $data['sheets'] ?? array() ) as $sheet ) {
			$props = is_array( $sheet ) && isset( $sheet['properties'] ) && is_array( $sheet['properties'] ) ? $sheet['properties'] : array();
			if ( ! isset( $props['sheetId'], $props['title'] ) ) {
				continue;
			}
			$sheets[] = array(
				'id'      => (string) (int) $props['sheetId'],
				'title'   => (string) $props['title'],
				'index'   => (int) ( $props['index'] ?? 0 ),
				'columns' => (int) ( $props['gridProperties']['columnCount'] ?? 26 ),
				'rows'    => (int) ( $props['gridProperties']['rowCount'] ?? 1000 ),
				'frozen'  => (int) ( $props['gridProperties']['frozenRowCount'] ?? 0 ),
			);
		}

		$result = array(
			'id'     => $spreadsheet_id,
			'name'   => sanitize_text_field( (string) ( $data['properties']['title'] ?? '' ) ),
			'url'    => esc_url_raw( (string) ( $data['spreadsheetUrl'] ?? self::spreadsheet_url( $spreadsheet_id ) ) ),
			'sheets' => $sheets,
		);

		set_transient( $key, $result, 10 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Finds a worksheet by its ID (preferred, survives renames) or by name.
	 * When not found in cached metadata, metadata is re-fetched once before failing,
	 * so a deleted worksheet is detected and nothing is written elsewhere.
	 *
	 * @param string $spreadsheet_id Spreadsheet ID.
	 * @param string $worksheet_id   Worksheet ID ('' to match by name).
	 * @param string $worksheet_name Worksheet name.
	 * @param bool   $refresh        Force fresh metadata.
	 * @return array{id:string,title:string,index:int,columns:int}|WP_Error
	 */
	public function find_worksheet( string $spreadsheet_id, string $worksheet_id, string $worksheet_name, bool $refresh = false ) {
		$meta = $this->get_spreadsheet( $spreadsheet_id, $refresh );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		foreach ( $meta['sheets'] as $sheet ) {
			if ( '' !== $worksheet_id ? $sheet['id'] === $worksheet_id : $sheet['title'] === $worksheet_name ) {
				return $sheet;
			}
		}

		if ( ! $refresh ) {
			return $this->find_worksheet( $spreadsheet_id, $worksheet_id, $worksheet_name, true );
		}

		return Google_Http_Client::error( 'worksheet_missing', 0, '', 'metadata', false );
	}

	/**
	 * Reads the first row of a worksheet (trailing empty cells removed).
	 *
	 * @param string $spreadsheet_id Spreadsheet ID.
	 * @param string $title          Worksheet title.
	 * @param bool   $refresh        Bypass cache.
	 * @return string[]|WP_Error
	 */
	public function get_header_row( string $spreadsheet_id, string $title, bool $refresh = false ) {
		$key = $this->cache_key( 'head', $spreadsheet_id . '|' . $title );
		if ( ! $refresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$range = self::quote_sheet( $title ) . '!1:1';
		$data  = $this->http->request( 'GET', self::SHEETS_BASE . rawurlencode( $spreadsheet_id ) . '/values/' . rawurlencode( $range ) . '?majorDimension=ROWS', null, 'values_read' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$headers = array();
		$row     = isset( $data['values'][0] ) && is_array( $data['values'][0] ) ? $data['values'][0] : array();
		foreach ( $row as $cell ) {
			$headers[] = is_scalar( $cell ) ? trim( (string) $cell ) : '';
		}
		while ( ! empty( $headers ) && '' === end( $headers ) ) {
			array_pop( $headers );
		}

		set_transient( $key, $headers, 5 * MINUTE_IN_SECONDS );
		return $headers;
	}

	/**
	 * Writes header cells into row 1, starting at a 0-based column index. Only the
	 * given cells are written, so existing headers are never overwritten.
	 *
	 * @param string   $spreadsheet_id Spreadsheet ID.
	 * @param string   $title          Worksheet title.
	 * @param int      $start_index    0-based start column.
	 * @param string[] $cells          Header values (already formula-guarded).
	 * @return true|WP_Error
	 */
	public function write_header_cells( string $spreadsheet_id, string $title, int $start_index, array $cells ) {
		$range  = self::quote_sheet( $title ) . '!' . self::column_letter( $start_index + 1 ) . '1';
		$result = $this->write_values( $spreadsheet_id, $range, array_values( $cells ) );

		delete_transient( $this->cache_key( 'head', $spreadsheet_id . '|' . $title ) );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Adds one row after the LAST row that contains data anywhere in the sheet.
	 *
	 * Uses the batchUpdate appendCells request rather than values.append.
	 * values.append does not simply write after the last row: it first guesses
	 * a "table" starting at the given range and appends to that guess. When row 1
	 * is empty, or rows are hidden, moved or separated by blank rows, the guess
	 * can put the new row at the top of the sheet or into a gap. appendCells has
	 * no such heuristic: Google always places the row after the last row with
	 * data in the whole sheet (adding grid rows when needed), so it can never
	 * land above existing data, in a blank gap, or in the header row of a sheet
	 * that has one.
	 *
	 * Values are written as literal strings (stringValue), which is what the
	 * default RAW value input option did for appends: nothing is parsed as a
	 * formula, number or date.
	 *
	 * @param string   $spreadsheet_id Spreadsheet ID.
	 * @param array    $worksheet      Worksheet from find_worksheet().
	 * @param string[] $row            Cell values (already formula-guarded).
	 * @return array{range:string}|WP_Error
	 */
	public function append_row( string $spreadsheet_id, array $worksheet, array $row ) {
		$data = $this->http->request(
			'POST',
			self::SHEETS_BASE . rawurlencode( $spreadsheet_id ) . ':batchUpdate',
			array(
				'requests'                     => array(
					array(
						'appendCells' => array(
							'sheetId' => (int) $worksheet['id'],
							'rows'    => array(
								array( 'values' => self::cell_data( $row ) ),
							),
							'fields'  => 'userEnteredValue',
						),
					),
				),
				'includeSpreadsheetInResponse' => false,
			),
			'append',
			false
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// appendCells does not report the row it used; record the sheet only.
		return array( 'range' => self::quote_sheet( (string) $worksheet['title'] ) );
	}

	/**
	 * Converts a row of values into Sheets API CellData objects. Values become
	 * literal strings; empty values become an empty CellData object, which must
	 * be a JSON object ({}), not an array ([]), or Google rejects the request.
	 *
	 * @param string[] $row Cell values.
	 * @return array<int,array|\stdClass>
	 */
	private static function cell_data( array $row ): array {
		$cells = array();
		foreach ( array_values( $row ) as $value ) {
			$value   = (string) $value;
			$cells[] = '' === $value
				? new \stdClass()
				: array( 'userEnteredValue' => array( 'stringValue' => $value ) );
		}
		return $cells;
	}

	/**
	 * Inserts one row directly below the rows kept at the top (header row, plus any
	 * title rows the administrator reserved), so the newest submission is always
	 * the first data row. Existing data rows move down by one.
	 *
	 * Both steps are sent as ONE spreadsheets.batchUpdate call. Google applies a
	 * batchUpdate atomically: either the row is inserted AND filled, or nothing
	 * changes. Two separate calls ("insert a blank row", then "write row 2") would
	 * be unsafe: with two simultaneous submissions the second insert can land
	 * between the first insert and its write, so both writes hit the same row and
	 * one submission is overwritten. Inside a single batch that cannot happen.
	 *
	 * The kept rows are never part of the request: with $top_rows = N the insert
	 * starts at 0-based index N (sheet row N + 1) and the values are written at
	 * that same index. N is at least 1, so row 1 is never touched.
	 *
	 * The new row inherits formatting from the row BELOW it (the previous newest
	 * submission), not from the kept rows above, so header or title styling is
	 * not copied onto data.
	 *
	 * Values are written as literal strings (stringValue). This is the same as
	 * the RAW value input option used for appends: nothing is parsed as a formula,
	 * a number or a date.
	 *
	 * @param string   $spreadsheet_id Spreadsheet ID.
	 * @param array    $worksheet      Worksheet from find_worksheet().
	 * @param string[] $row            Cell values (already formula-guarded).
	 * @param int      $top_rows       Rows kept at the top (1 = header row only).
	 * @return array{range:string}|WP_Error
	 */
	public function insert_row_at_top( string $spreadsheet_id, array $worksheet, array $row, int $top_rows = 1 ) {
		$sheet_id = (int) $worksheet['id'];
		$index    = max( 1, $top_rows ); // Never 0: row 1 must never move or change.

		$cells = self::cell_data( $row );

		$data = $this->http->request(
			'POST',
			self::SHEETS_BASE . rawurlencode( $spreadsheet_id ) . ':batchUpdate',
			array(
				'requests'                     => array(
					array(
						'insertDimension' => array(
							'range'             => array(
								'sheetId'    => $sheet_id,
								'dimension'  => 'ROWS',
								'startIndex' => $index,
								'endIndex'   => $index + 1,
							),
							'inheritFromBefore' => false,
						),
					),
					array(
						'updateCells' => array(
							'start'  => array(
								'sheetId'     => $sheet_id,
								'rowIndex'    => $index,
								'columnIndex' => 0,
							),
							'rows'   => array(
								array( 'values' => $cells ),
							),
							'fields' => 'userEnteredValue',
						),
					),
				),
				'includeSpreadsheetInResponse' => false,
			),
			'insert_row',
			false
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array( 'range' => self::quote_sheet( (string) $worksheet['title'] ) . '!A' . ( $index + 1 ) );
	}

	/**
	 * Overwrites an existing row range (foundation for future "update row" features).
	 *
	 * @param string   $spreadsheet_id Spreadsheet ID.
	 * @param string   $range          A1 range such as 'Sheet1'!A5.
	 * @param string[] $row            Cell values (already formula-guarded).
	 * @return true|WP_Error
	 */
	public function update_row( string $spreadsheet_id, string $range, array $row ) {
		$result = $this->write_values( $spreadsheet_id, $range, array_values( $row ) );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Adds columns to a worksheet when a row would be wider than its grid.
	 *
	 * @param string $spreadsheet_id Spreadsheet ID.
	 * @param array  $worksheet      Worksheet from find_worksheet().
	 * @param int    $needed         Required column count.
	 * @return true|WP_Error
	 */
	public function ensure_column_count( string $spreadsheet_id, array $worksheet, int $needed ) {
		$current = (int) $worksheet['columns'];
		if ( $needed <= $current ) {
			return true;
		}

		$data = $this->http->request(
			'POST',
			self::SHEETS_BASE . rawurlencode( $spreadsheet_id ) . ':batchUpdate',
			array(
				'requests' => array(
					array(
						'appendDimension' => array(
							'sheetId'   => (int) $worksheet['id'],
							'dimension' => 'COLUMNS',
							'length'    => $needed - $current,
						),
					),
				),
			),
			'batch_update',
			false
		);

		delete_transient( $this->cache_key( 'meta', $spreadsheet_id ) );
		return is_wp_error( $data ) ? $data : true;
	}

	/**
	 * Runs the connection test used by the admin screens.
	 *
	 * @param string $spreadsheet_id Optional spreadsheet ID.
	 * @param string $worksheet_id   Optional worksheet ID.
	 * @param string $worksheet_name Optional worksheet name.
	 * @return array{success:bool,checks:array<int,array{label:string,ok:bool,message:string}>}
	 */
	public function test_connection( string $spreadsheet_id = '', string $worksheet_id = '', string $worksheet_name = '' ): array {
		$checks = array();

		$token    = $this->auth->get_access_token( true );
		$checks[] = array(
			'label'   => __( 'Google authentication', 'formsync-google-sheets-connector-contact-form-7' ),
			'ok'      => ! is_wp_error( $token ),
			'message' => is_wp_error( $token ) ? $token->get_error_message() : __( 'Signed in', 'formsync-google-sheets-connector-contact-form-7' ),
		);
		if ( is_wp_error( $token ) ) {
			return array(
				'success' => false,
				'checks'  => $checks,
			);
		}

		if ( '' === $spreadsheet_id ) {
			$files    = $this->list_spreadsheets( true );
			$checks[] = array(
				'label'   => __( 'Spreadsheet list (Google Drive API)', 'formsync-google-sheets-connector-contact-form-7' ),
				'ok'      => ! is_wp_error( $files ),
				'message' => is_wp_error( $files )
					? $files->get_error_message() . ' ' . __( 'You can still enter spreadsheet IDs manually.', 'formsync-google-sheets-connector-contact-form-7' )
					/* translators: %d: number of spreadsheets. */
					: sprintf( _n( '%d spreadsheet shared with this account', '%d spreadsheets shared with this account', count( $files ), 'formsync-google-sheets-connector-contact-form-7' ), count( $files ) ),
			);
			return array(
				'success' => ! is_wp_error( $files ),
				'checks'  => $checks,
			);
		}

		$meta     = $this->get_spreadsheet( $spreadsheet_id, true );
		$type     = is_wp_error( $meta ) ? (string) ( $meta->get_error_data()['error_type'] ?? '' ) : '';
		$checks[] = array(
			'label'   => __( 'Google Sheets API', 'formsync-google-sheets-connector-contact-form-7' ),
			'ok'      => ! in_array( $type, array( 'api_disabled', 'google_unavailable', 'network_error' ), true ),
			'message' => in_array( $type, array( 'api_disabled', 'google_unavailable', 'network_error' ), true ) ? $meta->get_error_message() : __( 'Available', 'formsync-google-sheets-connector-contact-form-7' ),
		);
		$checks[] = array(
			'label'   => __( 'Spreadsheet', 'formsync-google-sheets-connector-contact-form-7' ),
			'ok'      => ! is_wp_error( $meta ),
			/* translators: %s: spreadsheet name. */
			'message' => is_wp_error( $meta ) ? $meta->get_error_message() : sprintf( __( 'Accessible: %s', 'formsync-google-sheets-connector-contact-form-7' ), $meta['name'] ),
		);
		if ( is_wp_error( $meta ) ) {
			return array(
				'success' => false,
				'checks'  => $checks,
			);
		}

		if ( '' !== $worksheet_id || '' !== $worksheet_name ) {
			$sheet    = $this->find_worksheet( $spreadsheet_id, $worksheet_id, $worksheet_name );
			$checks[] = array(
				'label'   => __( 'Worksheet', 'formsync-google-sheets-connector-contact-form-7' ),
				'ok'      => ! is_wp_error( $sheet ),
				/* translators: %s: worksheet name. */
				'message' => is_wp_error( $sheet ) ? $sheet->get_error_message() : sprintf( __( 'Found: %s', 'formsync-google-sheets-connector-contact-form-7' ), $sheet['title'] ),
			);
			if ( is_wp_error( $sheet ) ) {
				return array(
					'success' => false,
					'checks'  => $checks,
				);
			}
		}

		return array(
			'success' => true,
			'checks'  => $checks,
		);
	}

	/**
	 * Clears cached metadata for one spreadsheet (its header caches expire in 5 min).
	 *
	 * @param string $spreadsheet_id Spreadsheet ID.
	 */
	public function invalidate_spreadsheet_cache( string $spreadsheet_id ): void {
		delete_transient( $this->cache_key( 'meta', $spreadsheet_id ) );
	}

	/**
	 * Clears a cached header row.
	 *
	 * @param string $spreadsheet_id Spreadsheet ID.
	 * @param string $title          Worksheet title.
	 */
	public function invalidate_header_cache( string $spreadsheet_id, string $title ): void {
		delete_transient( $this->cache_key( 'head', $spreadsheet_id . '|' . $title ) );
	}

	/**
	 * Invalidates every cached Google response (e.g. after credentials change).
	 */
	public static function flush_all_caches(): void {
		update_option( self::CACHE_GEN, (int) get_option( self::CACHE_GEN, 0 ) + 1 );
	}

	/**
	 * Browser URL of a spreadsheet, optionally opened at a worksheet.
	 *
	 * @param string $spreadsheet_id Spreadsheet ID.
	 * @param string $worksheet_id   Worksheet ID.
	 * @return string
	 */
	public static function spreadsheet_url( string $spreadsheet_id, string $worksheet_id = '' ): string {
		$url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode( $spreadsheet_id ) . '/edit';
		return '' !== $worksheet_id ? $url . '#gid=' . rawurlencode( $worksheet_id ) : $url;
	}

	/**
	 * Quotes a worksheet title for A1 notation ('It''s' style escaping).
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function quote_sheet( string $title ): string {
		return "'" . str_replace( "'", "''", $title ) . "'";
	}

	/**
	 * Converts a 1-based column number to letters (1 => A, 27 => AA).
	 *
	 * @param int $number Column number.
	 * @return string
	 */
	public static function column_letter( int $number ): string {
		$letters = '';
		while ( $number > 0 ) {
			$mod     = ( $number - 1 ) % 26;
			$letters = chr( 65 + $mod ) . $letters;
			$number  = intdiv( $number - $mod - 1, 26 );
		}
		return $letters;
	}

	/**
	 * Value input option for values.update writes (header cells and update_row()).
	 * RAW stores values without parsing, so formulas are never evaluated and
	 * leading zeros are kept. Submission rows do not use this: appended and
	 * inserted rows are always sent as literal strings via batchUpdate.
	 *
	 * @return string
	 */
	public static function value_input_option(): string {
		$option = (string) apply_filters( 'skfss_value_input_option', 'RAW' );
		return in_array( $option, array( 'RAW', 'USER_ENTERED' ), true ) ? $option : 'RAW';
	}

	/**
	 * PUTs one row of values at a range.
	 *
	 * @param string   $spreadsheet_id Spreadsheet ID.
	 * @param string   $range          A1 range.
	 * @param string[] $row            Values.
	 * @return array|WP_Error
	 */
	private function write_values( string $spreadsheet_id, string $range, array $row ) {
		$url = self::SHEETS_BASE . rawurlencode( $spreadsheet_id ) . '/values/' . rawurlencode( $range ) . '?valueInputOption=' . self::value_input_option();
		return $this->http->request(
			'PUT',
			$url,
			array(
				'range'          => $range,
				'majorDimension' => 'ROWS',
				'values'         => array( $row ),
			),
			'values_write',
			true
		);
	}

	/**
	 * Builds a transient key scoped to the cache generation and connected account.
	 *
	 * @param string $type Cache type.
	 * @param string $id   Identifier.
	 * @return string
	 */
	private function cache_key( string $type, string $id ): string {
		return 'skfss_' . $type . '_' . md5( (int) get_option( self::CACHE_GEN, 0 ) . '|' . $this->auth->get_account_label() . '|' . $id );
	}
}
