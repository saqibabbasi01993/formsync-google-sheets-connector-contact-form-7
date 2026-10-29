<?php
/**
 * Google service-account authentication (OAuth 2.0 JWT bearer flow).
 *
 * Flow:
 * 1. Build a JWT (RS256) signed with the service account's private key.
 * 2. POST it to https://oauth2.googleapis.com/token.
 * 3. Cache the resulting access token (encrypted) until shortly before it expires.
 * Service-account tokens cannot be "refreshed"; a new assertion is simply signed,
 * which is what get_access_token( true ) does.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Service-account auth provider.
 */
final class Google_Service_Account_Auth implements Google_Auth_Interface {

	const TOKEN_URL       = 'https://oauth2.googleapis.com/token';
	const TOKEN_TRANSIENT = 'skfss_access_token';
	const ALERT_OPTION    = 'skfss_auth_alert';
	const SCOPES          = array(
		'https://www.googleapis.com/auth/spreadsheets',
		'https://www.googleapis.com/auth/drive.metadata.readonly',
	);

	/**
	 * Credential store.
	 *
	 * @var Credential_Store
	 */
	private $store;

	/**
	 * Encryption helper.
	 *
	 * @var Crypto
	 */
	private $crypto;

	/**
	 * In-request token cache.
	 *
	 * @var string
	 */
	private $token = '';

	/**
	 * Constructor.
	 *
	 * @param Credential_Store $store  Credentials.
	 * @param Crypto           $crypto Crypto helper.
	 */
	public function __construct( Credential_Store $store, Crypto $crypto ) {
		$this->store  = $store;
		$this->crypto = $crypto;
	}

	/**
	 * Authentication method identifier.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return 'service_account';
	}

	/**
	 * Whether usable credentials are stored.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return null !== $this->store->get_service_account();
	}

	/**
	 * Service-account email shown to administrators.
	 *
	 * @return string
	 */
	public function get_account_label(): string {
		$credentials = $this->store->get_service_account();
		return (string) ( $credentials['client_email'] ?? '' );
	}

	/**
	 * Forgets the cached access token.
	 */
	public function invalidate_token(): void {
		$this->token = '';
		delete_transient( self::TOKEN_TRANSIENT );
	}

	/**
	 * Returns a valid access token, signing a new JWT assertion when needed.
	 *
	 * @param bool $force_refresh Ignore the cached token.
	 * @return string|\WP_Error
	 */
	public function get_access_token( bool $force_refresh = false ) {
		$credentials = $this->store->get_service_account();
		if ( null === $credentials ) {
			return new WP_Error(
				'skfss_auth_unavailable',
				__( 'Google is not connected, or the stored key can no longer be read. Upload the service-account key again on the Google Connection screen.', 'formsync-google-sheets-connector-contact-form-7' ),
				array(
					'error_type'  => 'auth_unavailable',
					'http_status' => 0,
					'category'    => 'auth',
				)
			);
		}

		$fingerprint = md5( $credentials['client_email'] . '|' . ( $credentials['private_key_id'] ?? '' ) );

		if ( ! $force_refresh ) {
			if ( '' !== $this->token ) {
				return $this->token;
			}
			$cached = $this->read_cached_token( $fingerprint );
			if ( '' !== $cached ) {
				$this->token = $cached;
				return $cached;
			}
		}

		return $this->request_token( $credentials, $fingerprint );
	}

	/**
	 * Reads the encrypted cached token if it still has at least a minute to live.
	 *
	 * @param string $fingerprint Credential fingerprint.
	 * @return string
	 */
	private function read_cached_token( string $fingerprint ): string {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( ! is_string( $cached ) || '' === $cached ) {
			return '';
		}
		$plain = $this->crypto->decrypt( $cached );
		$data  = null === $plain ? null : json_decode( $plain, true );
		if ( ! is_array( $data ) || ( $data['fp'] ?? '' ) !== $fingerprint || (int) ( $data['exp'] ?? 0 ) < time() + MINUTE_IN_SECONDS ) {
			return '';
		}
		return (string) ( $data['token'] ?? '' );
	}

	/**
	 * Exchanges a signed JWT for an access token.
	 *
	 * @param array  $credentials Credentials.
	 * @param string $fingerprint Credential fingerprint.
	 * @return string|WP_Error
	 */
	private function request_token( array $credentials, string $fingerprint ) {
		$assertion = $this->build_assertion( $credentials );
		if ( is_wp_error( $assertion ) ) {
			return $assertion;
		}

		$timeout = Google_Http_Client::timeout( 15 );
		if ( $timeout <= 0.0 ) {
			return Google_Http_Client::error( 'timed_out', 0, '', 'auth', true );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => $timeout,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $assertion,
				),
			)
		);

		if ( is_wp_error( $response ) && Google_Http_Client::budget_exhausted() ) {
			return Google_Http_Client::error( 'timed_out', 0, '', 'auth', true );
		}
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'skfss_network_error',
				__( 'Could not reach Google to sign in. Check that this server can make outgoing HTTPS connections.', 'formsync-google-sheets-connector-contact-form-7' ),
				array(
					'error_type'  => 'network_error',
					'http_status' => 0,
					'category'    => 'auth',
					'retryable'   => true,
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 === $status && is_array( $body ) && ! empty( $body['access_token'] ) && is_string( $body['access_token'] ) ) {
			$token   = $body['access_token'];
			$expires = max( 120, (int) ( $body['expires_in'] ?? 3600 ) );

			$payload = $this->crypto->encrypt(
				(string) wp_json_encode(
					array(
						'token' => $token,
						'exp'   => time() + $expires,
						'fp'    => $fingerprint,
					)
				)
			);
			if ( '' !== $payload ) {
				set_transient( self::TOKEN_TRANSIENT, $payload, $expires - MINUTE_IN_SECONDS );
			}

			$this->token = $token;
			if ( false !== get_option( self::ALERT_OPTION, false ) ) {
				delete_option( self::ALERT_OPTION );
			}
			return $token;
		}

		$google_error = is_array( $body ) && isset( $body['error'] ) && is_string( $body['error'] ) ? sanitize_key( $body['error'] ) : '';

		if ( $status >= 500 ) {
			$message = __( 'Google sign-in is temporarily unavailable. Try again in a few minutes.', 'formsync-google-sheets-connector-contact-form-7' );
			$type    = 'google_unavailable';
		} elseif ( 'invalid_grant' === $google_error ) {
			$message = __( 'Google rejected the service-account sign-in. The key may have been deleted or disabled, or this server\'s clock may be wrong. Upload a new key if the problem continues.', 'formsync-google-sheets-connector-contact-form-7' );
			$type    = 'auth_failed';
		} else {
			$message = __( 'Google rejected the service-account credentials. Upload a new key on the Google Connection screen.', 'formsync-google-sheets-connector-contact-form-7' );
			$type    = 'auth_failed';
		}

		return new WP_Error(
			'skfss_' . $type,
			$message,
			array(
				'error_type'    => $type,
				'http_status'   => $status,
				'google_status' => $google_error,
				'category'      => 'auth',
				'retryable'     => $status >= 500,
			)
		);
	}

	/**
	 * Builds and signs the JWT assertion.
	 *
	 * @param array $credentials Credentials.
	 * @return string|WP_Error
	 */
	private function build_assertion( array $credentials ) {
		$now    = time();
		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);
		if ( ! empty( $credentials['private_key_id'] ) ) {
			$header['kid'] = $credentials['private_key_id'];
		}
		$claims = array(
			'iss'   => $credentials['client_email'],
			'scope' => implode( ' ', (array) apply_filters( 'skfss_google_scopes', self::SCOPES ) ),
			'aud'   => self::TOKEN_URL,
			'iat'   => $now - 30,
			'exp'   => $now + 3000,
		);

		$input = self::base64url( (string) wp_json_encode( $header ) ) . '.' . self::base64url( (string) wp_json_encode( $claims ) );

		$key       = openssl_pkey_get_private( $credentials['private_key'] );
		$signature = '';
		if ( false === $key || ! openssl_sign( $input, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error(
				'skfss_auth_failed',
				__( 'The stored private key could not sign a Google sign-in request. Upload the key again.', 'formsync-google-sheets-connector-contact-form-7' ),
				array(
					'error_type'  => 'auth_failed',
					'http_status' => 0,
					'category'    => 'auth',
				)
			);
		}

		return $input . '.' . self::base64url( $signature );
	}

	/**
	 * Base64url encoding (RFC 7515).
	 *
	 * @param string $data Data.
	 * @return string
	 */
	public static function base64url( string $data ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by the JWT specification.
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
