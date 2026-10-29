<?php
/**
 * Authentication abstraction. The Sheets service only depends on this interface, so a
 * future OAuth 2.0 provider (e.g. Google_OAuth_Auth) can be added without changing
 * the Sheets, sync or CF7 layers.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

/**
 * Google authentication provider.
 */
interface Google_Auth_Interface {

	/**
	 * Machine name of the method, e.g. "service_account" or "oauth".
	 *
	 * @return string
	 */
	public function get_method(): string;

	/**
	 * Whether usable credentials are configured.
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * Returns a valid access token, fetching or refreshing it when needed.
	 *
	 * @param bool $force_refresh Ignore any cached token.
	 * @return string|\WP_Error
	 */
	public function get_access_token( bool $force_refresh = false );

	/**
	 * Discards any cached token (e.g. after a 401 response).
	 */
	public function invalidate_token(): void;

	/**
	 * Human-readable identity (email) to show administrators and use in cache keys.
	 *
	 * @return string
	 */
	public function get_account_label(): string;
}
