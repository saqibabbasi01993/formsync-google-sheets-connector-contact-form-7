<?php
/**
 * Admin AJAX endpoints used by the CF7 panel and the plugin screens.
 *
 * Every endpoint: verifies the nonce, checks the capability, validates input,
 * performs the operation and answers with wp_send_json_success()/_error(). No
 * endpoint ever returns credentials, tokens or raw Google responses.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX controller.
 */
final class Ajax {

	/**
	 * Google Sheets service.
	 *
	 * @var Google_Sheets_Service
	 */
	private $sheets;

	/**
	 * Field mapper.
	 *
	 * @var Field_Mapper
	 */
	private $mapper;

	/**
	 * Constructor.
	 *
	 * @param Google_Sheets_Service $sheets Sheets service.
	 * @param Field_Mapper          $mapper Field mapper.
	 */
	public function __construct( Google_Sheets_Service $sheets, Field_Mapper $mapper ) {
		$this->sheets = $sheets;
		$this->mapper = $mapper;
	}

	/**
	 * Registers the (logged-in only) AJAX actions.
	 */
	public function register(): void {
		add_action( 'wp_ajax_skfss_list_spreadsheets', array( $this, 'list_spreadsheets' ) );
		add_action( 'wp_ajax_skfss_list_worksheets', array( $this, 'list_worksheets' ) );
		add_action( 'wp_ajax_skfss_test_connection', array( $this, 'test_connection' ) );
		add_action( 'wp_ajax_skfss_get_headers', array( $this, 'get_headers' ) );
	}

	/**
	 * Lists spreadsheets shared with the connected account.
	 */
	public function list_spreadsheets(): void {
		Security::verify_ajax();

		$refresh = '1' === Security::post_text( 'refresh' );
		$files   = $this->sheets->list_spreadsheets( $refresh );
		if ( is_wp_error( $files ) ) {
			$this->send_error( $files );
		}

		wp_send_json_success( array( 'spreadsheets' => array_values( $files ) ) );
	}

	/**
	 * Lists the worksheets (tabs) of one spreadsheet.
	 */
	public function list_worksheets(): void {
		Security::verify_ajax();

		$spreadsheet_id = Security::parse_spreadsheet_id( Security::post_text( 'spreadsheet_id' ) );
		if ( '' === $spreadsheet_id ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid spreadsheet ID or Google Sheets link.', 'formsync-google-sheets-connector-contact-form-7' ) ), 400 );
		}

		$refresh = '1' === Security::post_text( 'refresh' );
		$meta    = $this->sheets->get_spreadsheet( $spreadsheet_id, $refresh );
		if ( is_wp_error( $meta ) ) {
			$this->send_error( $meta );
		}

		$worksheets = array();
		foreach ( $meta['sheets'] as $sheet ) {
			$worksheets[] = array(
				'id'    => $sheet['id'],
				'title' => $sheet['title'],
			);
		}

		wp_send_json_success(
			array(
				'spreadsheet' => array(
					'id'   => $meta['id'],
					'name' => $meta['name'],
					'url'  => $meta['url'],
				),
				'worksheets'  => $worksheets,
			)
		);
	}

	/**
	 * Runs the connection test (optionally against a spreadsheet/worksheet).
	 */
	public function test_connection(): void {
		Security::verify_ajax();

		$raw_id         = Security::post_text( 'spreadsheet_id' );
		$spreadsheet_id = Security::parse_spreadsheet_id( $raw_id );
		if ( '' !== $raw_id && '' === $spreadsheet_id ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid spreadsheet ID or Google Sheets link.', 'formsync-google-sheets-connector-contact-form-7' ) ), 400 );
		}
		$worksheet_id   = Security::sanitize_worksheet_id( Security::post_text( 'worksheet_id' ) );
		$worksheet_name = Security::post_text( 'worksheet_name' );

		$result = $this->sheets->test_connection( $spreadsheet_id, $worksheet_id, $worksheet_name );

		$checks = array();
		foreach ( $result['checks'] as $check ) {
			$checks[] = array(
				'label'   => (string) $check['label'],
				'ok'      => (bool) $check['ok'],
				'message' => (string) $check['message'],
			);
		}

		$payload = array(
			'success' => (bool) $result['success'],
			'checks'  => $checks,
			'message' => $result['success'] ? __( 'Connection successful', 'formsync-google-sheets-connector-contact-form-7' ) : __( 'Connection failed', 'formsync-google-sheets-connector-contact-form-7' ),
		);

		// A failed test is a valid answer, not a transport error: the UI renders the checks either way.
		wp_send_json_success( $payload );
	}

	/**
	 * Reads the header row and suggests a mapping for the given fields.
	 */
	public function get_headers(): void {
		Security::verify_ajax();

		$spreadsheet_id = Security::parse_spreadsheet_id( Security::post_text( 'spreadsheet_id' ) );
		$worksheet_id   = Security::sanitize_worksheet_id( Security::post_text( 'worksheet_id' ) );
		$worksheet_name = Security::post_text( 'worksheet_name' );
		if ( '' === $spreadsheet_id || ( '' === $worksheet_id && '' === $worksheet_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Select a spreadsheet and worksheet first.', 'formsync-google-sheets-connector-contact-form-7' ) ), 400 );
		}

		$sheet = $this->sheets->find_worksheet( $spreadsheet_id, $worksheet_id, $worksheet_name );
		if ( is_wp_error( $sheet ) ) {
			$this->send_error( $sheet );
		}

		$headers = $this->sheets->get_header_row( $spreadsheet_id, $sheet['title'], true );
		if ( is_wp_error( $headers ) ) {
			$this->send_error( $headers );
		}

		$fields = array();
		// Nonce verified in Security::verify_ajax(); each element is sanitized below.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array();
		foreach ( array_slice( $raw_fields, 0, 200 ) as $name ) {
			$name = is_scalar( $name ) ? Security::sanitize_field_name( (string) $name ) : '';
			if ( '' !== $name ) {
				$fields[] = $name;
			}
		}

		$headers = array_map( 'strval', $headers );

		wp_send_json_success(
			array(
				'headers'     => array_map( 'sanitize_text_field', $headers ),
				'suggestions' => $this->mapper->suggest_mapping( array_values( array_unique( $fields ) ), $headers ),
			)
		);
	}

	/**
	 * Sends a safe error response for a WP_Error.
	 *
	 * @param WP_Error $error Error.
	 */
	private function send_error( WP_Error $error ): void {
		$data = (array) $error->get_error_data();
		wp_send_json_error(
			array(
				'message' => $error->get_error_message(),
				'type'    => sanitize_key( (string) ( $data['error_type'] ?? '' ) ),
			)
		);
	}
}
