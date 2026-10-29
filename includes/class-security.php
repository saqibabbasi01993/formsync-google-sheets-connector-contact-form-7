<?php
/**
 * Capability, nonce and input helpers shared by admin screens and AJAX endpoints.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

/**
 * Security helpers.
 */
final class Security {

	const AJAX_NONCE  = 'skfss_admin_ajax';
	const PANEL_NONCE = 'skfss_save_panel';

	/**
	 * Capability required to manage Google credentials, integrations and logs.
	 *
	 * @return string
	 */
	public static function capability(): string {
		return (string) apply_filters( 'skfss_capability', 'manage_options' );
	}

	/**
	 * Whether the current user can manage this plugin.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( self::capability() );
	}

	/**
	 * Whether the current user can edit a given Contact Form 7 form.
	 *
	 * @param int $form_id Form ID; values <= 0 mean "a new form".
	 * @return bool
	 */
	public static function can_edit_form( int $form_id ): bool {
		if ( $form_id > 0 ) {
			return current_user_can( 'wpcf7_edit_contact_form', $form_id ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Meta capability registered by Contact Form 7.
		}
		return current_user_can( 'wpcf7_edit_contact_forms' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Capability registered by Contact Form 7.
	}

	/**
	 * Verifies an AJAX request (nonce + capability) or terminates it.
	 */
	public static function verify_ajax(): void {
		if ( ! check_ajax_referer( self::AJAX_NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Reload the page and try again.', 'formsync-google-sheets-connector-contact-form-7' ) ), 403 );
		}
		if ( ! self::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'formsync-google-sheets-connector-contact-form-7' ) ), 403 );
		}
	}

	/**
	 * Reads a sanitized text value from $_POST. Callers must verify the nonce first.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function post_text( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the calling handler.
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Validates a Google spreadsheet ID.
	 *
	 * @param string $id Candidate ID.
	 * @return bool
	 */
	public static function is_valid_spreadsheet_id( string $id ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{20,128}$/', $id );
	}

	/**
	 * Extracts a spreadsheet ID from an ID or a Google Sheets URL. This parses the
	 * administrator's own input; it never fetches or scrapes the URL.
	 *
	 * @param string $input ID or URL.
	 * @return string Valid ID or empty string.
	 */
	public static function parse_spreadsheet_id( string $input ): string {
		$input = trim( $input );
		if ( preg_match( '#/spreadsheets/d/([A-Za-z0-9_-]+)#', $input, $matches ) ) {
			$input = $matches[1];
		}
		return self::is_valid_spreadsheet_id( $input ) ? $input : '';
	}

	/**
	 * Validates a worksheet (sheet) ID: a non-negative integer as a string.
	 *
	 * @param string $id Candidate ID.
	 * @return string Valid ID or empty string.
	 */
	public static function sanitize_worksheet_id( string $id ): string {
		$id = trim( $id );
		return ( '' !== $id && ctype_digit( $id ) && strlen( $id ) <= 12 ) ? $id : '';
	}

	/**
	 * Sanitizes a CF7 field name (CF7 allows letters, digits, "-", "_", ":" and ".").
	 *
	 * @param string $name Candidate.
	 * @return string
	 */
	public static function sanitize_field_name( string $name ): string {
		$name = preg_replace( '/[^A-Za-z0-9:._-]/', '', $name );
		return substr( (string) $name, 0, 128 );
	}
}
