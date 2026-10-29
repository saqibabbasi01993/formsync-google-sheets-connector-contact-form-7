<?php
/**
 * Spreadsheet formula-injection protection.
 *
 * Contact Form 7 accepts public input. A value such as =HYPERLINK("https://example.com","x")
 * or =IMPORTXML(...) could be evaluated by Google Sheets, or later by Excel/LibreOffice
 * when the sheet is exported as CSV. Two layers protect against this:
 *
 * 1. Values are sent with valueInputOption=RAW (see Google_Sheets_Service), so Google
 *    Sheets stores them as literal text and never evaluates them.
 * 2. Any value whose first character (or first character after leading spaces) is a
 *    formula trigger is prefixed with an apostrophe. This follows the OWASP CSV
 *    injection guidance and keeps the cell safe even after export or if a site switches
 *    to USER_ENTERED mode (where the apostrophe is Google's own "treat as text" marker).
 *
 * Triggers: = + - @ TAB CR LF and their full-width forms (＝ ＋ － ＠).
 *
 * To avoid corrupting legitimate data, values that are plainly numeric or phone-like
 * (e.g. "-42", "+1 (555) 123-4567", "-3.5") are left unchanged: they contain only
 * digits, spaces, dots, dashes and parentheses, so they cannot call functions or
 * reference other cells.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

/**
 * Formula guard.
 */
final class Formula_Guard {

	/**
	 * Characters that can start a formula or be used to smuggle one.
	 */
	const TRIGGERS = array( '=', '+', '-', '@', "\t", "\r", "\n", '＝', '＋', '－', '＠' );

	/**
	 * Neutralizes a value if Google Sheets (or a spreadsheet app) could treat it as a formula.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function protect( string $value ): string {
		if ( '' === $value ) {
			return $value;
		}

		$trimmed = ltrim( $value, ' ' );
		if ( ! self::starts_with_trigger( $value ) && ! self::starts_with_trigger( $trimmed ) ) {
			return $value;
		}

		if ( self::is_plain_number( $value ) ) {
			return $value;
		}

		return "'" . $value;
	}

	/**
	 * Reverses protect() for comparisons (e.g. matching header names read back from a
	 * sheet in RAW mode, where the apostrophe is stored literally).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function unprotect( string $value ): string {
		if ( '' !== $value && "'" === $value[0] ) {
			$inner = substr( $value, 1 );
			if ( self::protect( $inner ) === $value ) {
				return $inner;
			}
		}
		return $value;
	}

	/**
	 * Whether the string starts with a trigger character.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function starts_with_trigger( string $value ): bool {
		foreach ( self::TRIGGERS as $trigger ) {
			if ( '' !== $value && 0 === strpos( $value, $trigger ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Signed number or phone number made only of digits, spaces, dots, dashes and
	 * parentheses, with a leading + or - and no other characters.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_plain_number( string $value ): bool {
		return strlen( $value ) <= 40 && 1 === preg_match( '/^[+-][0-9][0-9 ().-]*$/', $value );
	}
}
