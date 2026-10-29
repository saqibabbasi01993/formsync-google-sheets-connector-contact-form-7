<?php
/**
 * Encrypted storage and validation of Google service-account credentials.
 *
 * Only the fields needed to sign tokens are kept. The private key is never returned
 * to the admin UI, AJAX responses, logs or JavaScript; get_public_summary() is the
 * only method used for display.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Credential store.
 */
final class Credential_Store {

	const OPTION        = 'skfss_credentials';
	const MAX_FILE_SIZE = 16384;

	/**
	 * Encryption helper.
	 *
	 * @var Crypto
	 */
	private $crypto;

	/**
	 * Per-request cache of decrypted credentials.
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Constructor.
	 *
	 * @param Crypto $crypto Crypto helper.
	 */
	public function __construct( Crypto $crypto ) {
		$this->crypto = $crypto;
	}

	/**
	 * Validates the contents of a service-account JSON key file.
	 *
	 * The token_uri inside the file is deliberately ignored: tokens are always
	 * requested from Google's fixed endpoint, so a tampered file cannot redirect the
	 * signed assertion (or the key) to another server.
	 *
	 * @param string $json File contents.
	 * @return array|WP_Error Normalized credentials.
	 */
	public static function validate_service_account_json( string $json ) {
		$data = json_decode( $json, true, 8 );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'skfss_invalid_json', __( 'The file is not valid JSON. Upload the key file exactly as downloaded from Google Cloud.', 'formsync-google-sheets-connector-contact-form-7' ) );
		}
		if ( ( $data['type'] ?? '' ) !== 'service_account' ) {
			return new WP_Error( 'skfss_not_service_account', __( 'This JSON file is not a service-account key. In Google Cloud, create a key for a service account and choose the JSON format.', 'formsync-google-sheets-connector-contact-form-7' ) );
		}

		$email = isset( $data['client_email'] ) && is_string( $data['client_email'] ) ? trim( $data['client_email'] ) : '';
		if ( ! is_email( $email ) || ! preg_match( '/\.gserviceaccount\.com$/', $email ) ) {
			return new WP_Error( 'skfss_invalid_email', __( 'The key file does not contain a valid service-account email address.', 'formsync-google-sheets-connector-contact-form-7' ) );
		}

		$key = isset( $data['private_key'] ) && is_string( $data['private_key'] ) ? $data['private_key'] : '';
		if ( false === strpos( $key, '-----BEGIN PRIVATE KEY-----' ) || ! function_exists( 'openssl_pkey_get_private' ) ) {
			return new WP_Error( 'skfss_invalid_key', __( 'The key file does not contain a usable private key.', 'formsync-google-sheets-connector-contact-form-7' ) );
		}
		$resource = openssl_pkey_get_private( $key );
		if ( false === $resource ) {
			return new WP_Error( 'skfss_invalid_key', __( 'The private key in this file could not be read. Create a new key in Google Cloud and upload it again.', 'formsync-google-sheets-connector-contact-form-7' ) );
		}

		return array(
			'client_email'   => $email,
			'private_key'    => $key,
			'private_key_id' => isset( $data['private_key_id'] ) && is_string( $data['private_key_id'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', $data['private_key_id'] ) : '',
			'project_id'     => isset( $data['project_id'] ) && is_string( $data['project_id'] ) ? sanitize_text_field( $data['project_id'] ) : '',
			'client_id'      => isset( $data['client_id'] ) && is_string( $data['client_id'] ) ? preg_replace( '/[^0-9]/', '', $data['client_id'] ) : '',
		);
	}

	/**
	 * Saves validated credentials (encrypted, not autoloaded).
	 *
	 * @param array $credentials Output of validate_service_account_json().
	 * @return true|WP_Error
	 */
	public function save_service_account( array $credentials ) {
		if ( ! Crypto::is_available() ) {
			return new WP_Error( 'skfss_no_crypto', __( 'This server cannot encrypt the key (the OpenSSL PHP extension with AES-256-GCM is required).', 'formsync-google-sheets-connector-contact-form-7' ) );
		}
		$credentials['stored_at'] = time();
		$encrypted                = $this->crypto->encrypt( (string) wp_json_encode( $credentials ) );
		if ( '' === $encrypted ) {
			return new WP_Error( 'skfss_encrypt_failed', __( 'The key could not be stored securely.', 'formsync-google-sheets-connector-contact-form-7' ) );
		}

		delete_option( self::OPTION );
		add_option( self::OPTION, $encrypted, '', false );
		$this->cache = null;

		return true;
	}

	/**
	 * Returns decrypted credentials for token signing, or null.
	 *
	 * @return array|null
	 */
	public function get_service_account(): ?array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}
		$stored = get_option( self::OPTION, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}
		$plain = $this->crypto->decrypt( $stored );
		$data  = null === $plain ? null : json_decode( $plain, true );
		if ( ! is_array( $data ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
			return null;
		}
		$this->cache = $data;
		return $data;
	}

	/**
	 * Whether credentials exist in the database (they may still be unreadable).
	 *
	 * @return bool
	 */
	public function has_credentials(): bool {
		$stored = get_option( self::OPTION, '' );
		return is_string( $stored ) && '' !== $stored;
	}

	/**
	 * Non-secret details safe to show administrators.
	 *
	 * @return array{client_email:string,project_id:string,key_hint:string,stored_at:int,readable:bool}
	 */
	public function get_public_summary(): array {
		$data = $this->get_service_account();
		$kid  = (string) ( $data['private_key_id'] ?? '' );

		return array(
			'client_email' => (string) ( $data['client_email'] ?? '' ),
			'project_id'   => (string) ( $data['project_id'] ?? '' ),
			'key_hint'     => '' !== $kid ? '…' . substr( $kid, -6 ) : '',
			'stored_at'    => (int) ( $data['stored_at'] ?? 0 ),
			'readable'     => null !== $data,
		);
	}

	/**
	 * Deletes stored credentials.
	 */
	public function delete(): void {
		delete_option( self::OPTION );
		$this->cache = null;
	}
}
