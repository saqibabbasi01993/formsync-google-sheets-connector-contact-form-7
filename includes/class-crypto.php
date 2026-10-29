<?php
/**
 * Symmetric encryption for secrets stored in the database (service-account key and
 * cached access tokens).
 *
 * Uses AES-256-GCM with a key derived from the site's WordPress salts, so a database
 * dump alone does not reveal the Google private key. If the salts change, decryption
 * fails safely and the administrator is asked to upload the key again.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

/**
 * Crypto helper.
 */
final class Crypto {

	const PREFIX = 'skfss1:';
	const CIPHER = 'aes-256-gcm';

	/**
	 * Whether encryption is available on this server.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'openssl_encrypt' ) && in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	/**
	 * Encrypts a string.
	 *
	 * @param string $plain Plain text.
	 * @return string Encoded cipher text, or empty string on failure.
	 */
	public function encrypt( string $plain ): string {
		if ( ! self::is_available() ) {
			return '';
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			return '';
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe storage of cipher text, not obfuscation.
		return self::PREFIX . base64_encode( $iv . $tag . $cipher );
	}

	/**
	 * Decrypts a string produced by encrypt().
	 *
	 * @param string $payload Encoded cipher text.
	 * @return string|null Plain text or null when it cannot be decrypted.
	 */
	public function decrypt( string $payload ): ?string {
		if ( ! self::is_available() || 0 !== strpos( $payload, self::PREFIX ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary-safe storage of cipher text, not obfuscation.
		$raw = base64_decode( substr( $payload, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return null;
		}
		$iv     = substr( $raw, 0, 12 );
		$tag    = substr( $raw, 12, 16 );
		$cipher = substr( $raw, 28 );
		$plain  = openssl_decrypt( $cipher, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag );

		return false === $plain ? null : $plain;
	}

	/**
	 * Derives the 256-bit key from WordPress salts.
	 *
	 * @return string
	 */
	private function key(): string {
		return hash_hmac( 'sha256', 'skfss-secret-storage-v1', wp_salt( 'secure_auth' ) . wp_salt( 'auth' ), true );
	}
}
