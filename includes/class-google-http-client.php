<?php
/**
 * Authenticated HTTP client for Google APIs.
 *
 * Responsibilities: attach the bearer token, refresh it once on 401, retry once on
 * transient failures, decode JSON and convert every failure into a WP_Error with a
 * consistent data shape:
 *
 *   error_type, http_status, google_status, category, retryable
 *
 * Raw Google error bodies are never returned to callers for row writes, because
 * Google may echo cell values back in validation messages.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Google HTTP client.
 */
final class Google_Http_Client {

	const TIMEOUT = 20;

	/**
	 * Absolute deadline (microtime) for the current time budget, 0 when none.
	 *
	 * Form submissions run inside the visitor's request, so all Google calls made
	 * for one submission share a budget. Admin screens have no budget.
	 *
	 * @var float
	 */
	private static $deadline = 0.0;

	/**
	 * Starts a time budget for all Google requests until end_budget() is called.
	 *
	 * @param int $seconds Budget in seconds.
	 */
	public static function start_budget( int $seconds ): void {
		self::$deadline = microtime( true ) + max( 3, $seconds );
	}

	/**
	 * Ends the current time budget.
	 */
	public static function end_budget(): void {
		self::$deadline = 0.0;
	}

	/**
	 * Seconds left in the current budget, or null when no budget is active.
	 *
	 * @return float|null
	 */
	public static function remaining(): ?float {
		return self::$deadline > 0 ? self::$deadline - microtime( true ) : null;
	}

	/**
	 * Whether a time budget is active and (almost) used up. Used to report a
	 * transport failure as "Google did not respond in time" rather than as a
	 * connectivity problem, which would send the administrator looking for the
	 * wrong cause.
	 *
	 * @return bool
	 */
	public static function budget_exhausted(): bool {
		$remaining = self::remaining();
		return null !== $remaining && $remaining < 1;
	}

	/**
	 * Timeout for the next request: the filtered default, capped to the budget.
	 *
	 * @param int $default_timeout Default timeout in seconds.
	 * @return float Timeout in seconds (0 when the budget is used up).
	 */
	public static function timeout( int $default_timeout ): float {
		$timeout   = (float) max( 1, (int) apply_filters( 'skfss_http_timeout', $default_timeout ) );
		$remaining = self::remaining();
		if ( null === $remaining ) {
			return $timeout;
		}
		return $remaining < 1 ? 0.0 : min( $timeout, $remaining );
	}

	/**
	 * Google authentication provider.
	 *
	 * @var Google_Auth_Interface
	 */
	private $auth;

	/**
	 * Constructor.
	 *
	 * @param Google_Auth_Interface $auth Auth provider.
	 */
	public function __construct( Google_Auth_Interface $auth ) {
		$this->auth = $auth;
	}

	/**
	 * Sends a request.
	 *
	 * @param string     $method     HTTP method.
	 * @param string     $url        Full URL.
	 * @param array|null $body       JSON body.
	 * @param string     $category   Endpoint category for diagnostics (drive_list, metadata, values_read, values_write, append, insert_row, batch_update).
	 * @param bool       $idempotent Whether the request may be safely repeated after an ambiguous failure.
	 * @return array|WP_Error Decoded response body.
	 */
	public function request( string $method, string $url, ?array $body, string $category, bool $idempotent = true ) {
		$token = $this->auth->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$refreshed = false;
		$retried   = false;

		while ( true ) {
			$timeout = self::timeout( self::TIMEOUT );
			if ( $timeout <= 0.0 ) {
				// The submission's time budget is used up; fail fast instead of making the visitor wait.
				return self::error( 'timed_out', 0, '', $category, true );
			}

			$args = array(
				'method'  => $method,
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			);
			if ( null !== $body ) {
				$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
				$args['body']                    = wp_json_encode( $body );
			}

			$response = wp_remote_request( $url, $args );
			$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

			// Expired or revoked token: sign a fresh one and try exactly once more.
			if ( 401 === $status && ! $refreshed ) {
				$refreshed = true;
				$this->auth->invalidate_token();
				$token = $this->auth->get_access_token( true );
				if ( is_wp_error( $token ) ) {
					return $token;
				}
				continue;
			}

			if ( ! $retried && $this->should_retry( $response, $status, $idempotent ) ) {
				$retried = true;
				$this->pause( $response );
				continue;
			}

			return $this->parse( $response, $status, $category );
		}
	}

	/**
	 * Decides whether a failed request should be retried once.
	 *
	 * A 429 or 503 means Google did not process the request, so even a row append is
	 * safe to repeat. Network errors and other 5xx responses are ambiguous (the write
	 * may have happened), so they are only retried for idempotent requests to avoid
	 * duplicate rows.
	 *
	 * @param array|WP_Error $response Response.
	 * @param int            $status   HTTP status.
	 * @param bool           $idempotent Idempotent request.
	 * @return bool
	 */
	private function should_retry( $response, int $status, bool $idempotent ): bool {
		$remaining = self::remaining();
		if ( null !== $remaining && $remaining < 5 ) {
			return false;
		}
		if ( 429 === $status || 503 === $status ) {
			return true;
		}
		if ( ! $idempotent ) {
			return false;
		}
		return is_wp_error( $response ) || $status >= 500;
	}

	/**
	 * Short back-off, honouring a small Retry-After value.
	 *
	 * @param array|WP_Error $response Response.
	 */
	private function pause( $response ): void {
		$seconds = 1;
		if ( ! is_wp_error( $response ) ) {
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			if ( $retry_after > 0 ) {
				$seconds = min( 3, $retry_after );
			}
		}
		usleep( $seconds * 1000000 );
	}

	/**
	 * Converts a response into data or a WP_Error.
	 *
	 * @param array|WP_Error $response Response.
	 * @param int            $status   HTTP status.
	 * @param string         $category Endpoint category.
	 * @return array|WP_Error
	 */
	private function parse( $response, int $status, string $category ) {
		if ( is_wp_error( $response ) ) {
			return self::error( self::budget_exhausted() ? 'timed_out' : 'network_error', 0, '', $category, true );
		}

		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = '' === trim( $raw ) ? array() : json_decode( $raw, true );

		if ( $status >= 200 && $status < 300 ) {
			if ( ! is_array( $data ) ) {
				return self::error( 'invalid_response', $status, '', $category, false );
			}
			return $data;
		}

		$error          = is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
		$google_status  = isset( $error['status'] ) && is_string( $error['status'] ) ? preg_replace( '/[^A-Z_]/', '', $error['status'] ) : '';
		$google_message = isset( $error['message'] ) && is_string( $error['message'] ) ? $error['message'] : '';
		$reasons        = self::collect_reasons( $error );

		switch ( true ) {
			case 400 === $status && ( false !== stripos( $google_message, 'Unable to parse range' ) || false !== stripos( $google_message, 'No grid with id' ) ):
				$type = 'worksheet_missing';
				break;
			case 400 === $status:
				$type = 'invalid_request';
				break;
			case 401 === $status:
				$type = 'auth_failed';
				break;
			case 403 === $status && array_intersect( $reasons, array( 'SERVICE_DISABLED', 'accessNotConfigured' ) ):
				$type = 'api_disabled';
				break;
			case 403 === $status && array_intersect( $reasons, array( 'RATE_LIMIT_EXCEEDED', 'rateLimitExceeded', 'userRateLimitExceeded' ) ):
				$type = 'rate_limited';
				break;
			case 403 === $status:
				$type = 'access_denied';
				break;
			case 404 === $status:
				$type = 'not_found';
				break;
			case 429 === $status:
				$type = 'rate_limited';
				break;
			case $status >= 500:
				$type = 'google_unavailable';
				break;
			default:
				$type = 'http_error';
		}

		// Row writes may echo submitted values in Google's message; keep those out.
		$detail = in_array( $category, array( 'append', 'values_write', 'insert_row' ), true ) ? '' : $google_message;

		return self::error( $type, $status, $google_status, $category, in_array( $type, array( 'rate_limited', 'google_unavailable', 'network_error' ), true ), $detail );
	}

	/**
	 * Collects machine-readable reasons from a Google error object.
	 *
	 * @param array $error Error object.
	 * @return string[]
	 */
	private static function collect_reasons( array $error ): array {
		$reasons = array();
		foreach ( (array) ( $error['details'] ?? array() ) as $detail ) {
			if ( is_array( $detail ) && isset( $detail['reason'] ) && is_string( $detail['reason'] ) ) {
				$reasons[] = $detail['reason'];
			}
		}
		foreach ( (array) ( $error['errors'] ?? array() ) as $item ) {
			if ( is_array( $item ) && isset( $item['reason'] ) && is_string( $item['reason'] ) ) {
				$reasons[] = $item['reason'];
			}
		}
		return $reasons;
	}

	/**
	 * Builds a standard WP_Error.
	 *
	 * @param string $type          Error type.
	 * @param int    $status        HTTP status.
	 * @param string $google_status Google status code.
	 * @param string $category      Endpoint category.
	 * @param bool   $retryable     Whether a retry could succeed.
	 * @param string $detail        Optional safe Google message.
	 * @return WP_Error
	 */
	public static function error( string $type, int $status, string $google_status, string $category, bool $retryable, string $detail = '' ): WP_Error {
		$message = self::message_for( $type );
		if ( '' !== $detail ) {
			/* translators: %s: error detail returned by Google. */
			$message .= ' ' . sprintf( __( 'Google said: %s', 'formsync-google-sheets-connector-contact-form-7' ), sanitize_text_field( wp_html_excerpt( $detail, 200, '…' ) ) );
		}

		return new WP_Error(
			'skfss_' . $type,
			$message,
			array(
				'error_type'    => $type,
				'http_status'   => $status,
				'google_status' => $google_status,
				'category'      => $category,
				'retryable'     => $retryable,
			)
		);
	}

	/**
	 * Administrator-facing message for an error type.
	 *
	 * @param string $type Error type.
	 * @return string
	 */
	public static function message_for( string $type ): string {
		$messages = array(
			'network_error'      => __( 'Could not connect to Google. Check this server\'s outgoing HTTPS connectivity.', 'formsync-google-sheets-connector-contact-form-7' ),
			'timed_out'          => __( 'Google did not respond in time, so this submission was not added to the sheet. The visitor\'s submission was not affected.', 'formsync-google-sheets-connector-contact-form-7' ),
			'invalid_response'   => __( 'Google returned a response that could not be read.', 'formsync-google-sheets-connector-contact-form-7' ),
			'invalid_request'    => __( 'Google rejected the request as invalid.', 'formsync-google-sheets-connector-contact-form-7' ),
			'auth_failed'        => __( 'Google Sheets authentication needs attention.', 'formsync-google-sheets-connector-contact-form-7' ),
			'auth_unavailable'   => __( 'Google is not connected.', 'formsync-google-sheets-connector-contact-form-7' ),
			'api_disabled'       => __( 'A required Google API is not enabled for this Google Cloud project. Enable the Google Sheets API and Google Drive API.', 'formsync-google-sheets-connector-contact-form-7' ),
			'access_denied'      => __( 'Google Sheets access was denied. Please verify that the configured Google account/service account has access to this spreadsheet.', 'formsync-google-sheets-connector-contact-form-7' ),
			'not_found'          => __( 'The spreadsheet was not found. It may have been deleted, or the ID is wrong.', 'formsync-google-sheets-connector-contact-form-7' ),
			'worksheet_missing'  => __( 'The selected worksheet no longer exists in this spreadsheet. Choose a worksheet again in the form\'s Google Sheets tab.', 'formsync-google-sheets-connector-contact-form-7' ),
			'rate_limited'       => __( 'Google is limiting requests right now. Try again shortly.', 'formsync-google-sheets-connector-contact-form-7' ),
			'google_unavailable' => __( 'Google Sheets is temporarily unavailable.', 'formsync-google-sheets-connector-contact-form-7' ),
			'http_error'         => __( 'Google returned an unexpected error.', 'formsync-google-sheets-connector-contact-form-7' ),
		);
		return $messages[ $type ] ?? $messages['http_error'];
	}
}
