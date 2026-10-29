<?php
/**
 * Contact Form 7 submission hook. This is the only class that reads CF7 submission
 * data. It converts a submission into Submission_Payload objects and hands them to
 * the Sync_Service.
 *
 * Failure isolation: everything runs inside try/catch(Throwable) and nothing is
 * returned to CF7, so a Google outage (or any bug here) can never change the
 * visitor's normal CF7 success response.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

/**
 * Submission handler.
 */
final class Submission_Handler {

	/**
	 * Integration storage.
	 *
	 * @var Integration_Manager
	 */
	private $integrations;

	/**
	 * Field mapper.
	 *
	 * @var Field_Mapper
	 */
	private $mapper;

	/**
	 * Sync service.
	 *
	 * @var Sync_Service
	 */
	private $sync;

	/**
	 * Error logger.
	 *
	 * @var Error_Logger
	 */
	private $logger;

	/**
	 * Metadata collector.
	 *
	 * @var Metadata_Collector
	 */
	private $metadata;

	/**
	 * File upload handler.
	 *
	 * @var File_Handler
	 */
	private $files;

	/**
	 * Constructor.
	 *
	 * @param Integration_Manager $integrations Integrations.
	 * @param Field_Mapper        $mapper       Mapper.
	 * @param Sync_Service        $sync         Sync service.
	 * @param Error_Logger        $logger       Logger.
	 * @param Metadata_Collector  $metadata     Metadata collector.
	 * @param File_Handler        $files        File handler.
	 */
	public function __construct( Integration_Manager $integrations, Field_Mapper $mapper, Sync_Service $sync, Error_Logger $logger, Metadata_Collector $metadata, File_Handler $files ) {
		$this->integrations = $integrations;
		$this->mapper       = $mapper;
		$this->sync         = $sync;
		$this->logger       = $logger;
		$this->metadata     = $metadata;
		$this->files        = $files;
	}

	/**
	 * Registers CF7 hooks. wpcf7_mail_sent fires before CF7 deletes uploaded files.
	 *
	 * @param bool $also_on_mail_failure Also sync when CF7 could not send its email.
	 */
	public function register( bool $also_on_mail_failure ): void {
		add_action( 'wpcf7_mail_sent', array( $this, 'handle' ), 20 );
		if ( $also_on_mail_failure ) {
			add_action( 'wpcf7_mail_failed', array( $this, 'handle' ), 20 );
		}
	}

	/**
	 * Handles a successful CF7 submission.
	 *
	 * @param \WPCF7_ContactForm $contact_form Form.
	 */
	public function handle( $contact_form ): void {
		$form_id = 0;

		/**
		 * Filters the total time (seconds) Google requests may take for one
		 * submission. The visitor's request waits for them, so keep it short.
		 *
		 * @param int $seconds Budget. Default 15.
		 */
		Google_Http_Client::start_budget( (int) apply_filters( 'skfss_submission_time_budget', 15 ) );
		try {
			if ( ! $contact_form instanceof \WPCF7_ContactForm ) {
				return;
			}
			$form_id      = (int) $contact_form->id();
			$integrations = $this->integrations->get_for_form( $form_id, true );
			if ( empty( $integrations ) ) {
				return;
			}

			$submission = \WPCF7_Submission::get_instance();
			if ( ! $submission ) {
				return;
			}

			$fields = $this->mapper->get_form_fields( $contact_form );
			$key    = $this->submission_key( $form_id, $submission );

			$this->logger->debug(
				'submission_detected',
				'Contact Form 7 submission detected.',
				array(
					'form_id'        => $form_id,
					'form_title'     => $contact_form->title(),
					'count'          => count( $integrations ),
					'submission_ref' => substr( $key, 0, 12 ),
				)
			);

			foreach ( $integrations as $integration ) {
				try {
					$payload = $this->build_payload( $integration, $contact_form, $submission, $fields, $key );
					$this->sync->sync( $integration, $payload );
				} catch ( \Throwable $e ) {
					$this->log_exception( $e, $form_id, $integration->id() );
				}
			}
		} catch ( \Throwable $e ) {
			$this->log_exception( $e, $form_id, 0 );
		} finally {
			Google_Http_Client::end_budget();
		}
	}

	/**
	 * Builds the payload for one integration.
	 *
	 * @param Integration        $integration  Integration.
	 * @param \WPCF7_ContactForm $contact_form Form.
	 * @param \WPCF7_Submission  $submission   Submission.
	 * @param array              $fields       Form fields.
	 * @param string             $key          Submission key.
	 * @return Submission_Payload
	 */
	private function build_payload( Integration $integration, $contact_form, $submission, array $fields, string $key ): Submission_Payload {
		$posted      = (array) $submission->get_posted_data();
		$values      = array();
		$field_names = array();
		$file_fields = array();

		foreach ( $fields as $field ) {
			$field_names[] = $field['name'];
			if ( 'file' === $field['type'] ) {
				$file_fields[] = $field['name'];
				continue;
			}
			$values[ 'field:' . $field['name'] ] = $this->normalize_value( $posted[ $field['name'] ] ?? '' );
		}

		// Only touch files and metadata that this integration actually sends.
		$enabled = array();
		foreach ( $integration->mapping() as $row ) {
			if ( ! empty( $row['enabled'] ) && is_string( $row['source'] ?? null ) ) {
				$enabled[ $row['source'] ] = true;
			}
		}
		$auto = 'auto' === $integration->mapping_mode();

		$needed_files = array_values(
			array_filter(
				$file_fields,
				static function ( $name ) use ( $enabled, $auto ) {
					return $auto || isset( $enabled[ 'field:' . $name ] );
				}
			)
		);
		if ( ! empty( $needed_files ) ) {
			$mode  = $integration->file_mode();
			$files = $this->files->get_files( $submission, $needed_files, 'filename' !== $mode );
			foreach ( $files as $name => $entry ) {
				$values[ 'field:' . $name ] = File_Handler::format( $entry, $mode );
			}
		}

		$meta_keys = array();
		foreach ( array_keys( $enabled ) as $source ) {
			if ( 0 === strpos( $source, 'meta:' ) ) {
				$meta_keys[] = substr( $source, 5 );
			}
		}
		foreach ( $this->metadata->collect( $submission, array_intersect( $meta_keys, Field_Mapper::metadata_keys() ) ) as $meta_key => $value ) {
			$values[ 'meta:' . $meta_key ] = $this->normalize_value( $value );
		}

		return new Submission_Payload( (int) $contact_form->id(), (string) $contact_form->title(), $key, $field_names, $values );
	}

	/**
	 * Converts a posted value to a single string.
	 *
	 * Arrays (checkboxes, multi-selects) become "A, B, C". HTML is intentionally not
	 * stripped: the destination is a spreadsheet cell, not an HTML page, so stripping
	 * would silently destroy legitimate text such as "I <3 this". Invalid UTF-8 and
	 * control characters are removed and formula protection is applied later.
	 *
	 * @param mixed $value Posted value.
	 * @return string
	 */
	private function normalize_value( $value ): string {
		if ( is_array( $value ) ) {
			$parts = array();
			array_walk_recursive(
				$value,
				function ( $item ) use ( &$parts ) {
					if ( is_scalar( $item ) ) {
						$clean = $this->clean_string( (string) $item );
						if ( '' !== $clean ) {
							$parts[] = $clean;
						}
					}
				}
			);
			return implode( ', ', $parts );
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		return is_scalar( $value ) ? $this->clean_string( (string) $value ) : '';
	}

	/**
	 * Cleans one string.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function clean_string( string $value ): string {
		$value = wp_check_invalid_utf8( $value, true );
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		$value = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
		return trim( $value );
	}

	/**
	 * Deterministic, non-reversible key for this submission (HMAC with a site salt),
	 * used by the ledger to prevent the same submission being appended twice.
	 *
	 * @param int               $form_id    Form ID.
	 * @param \WPCF7_Submission $submission Submission.
	 * @return string 64 hex chars.
	 */
	private function submission_key( int $form_id, $submission ): string {
		$posted_hash = method_exists( $submission, 'get_posted_data_hash' )
			? (string) $submission->get_posted_data_hash()
			: (string) wp_json_encode( $submission->get_posted_data() );

		$material = implode(
			'|',
			array(
				$form_id,
				(string) $submission->get_meta( 'timestamp' ),
				(string) $submission->get_meta( 'remote_ip' ),
				$posted_hash,
			)
		);

		return hash_hmac( 'sha256', $material, wp_salt( 'nonce' ) );
	}

	/**
	 * Logs an unexpected exception without its message (which could contain data).
	 *
	 * @param \Throwable $e              Exception.
	 * @param int        $form_id        Form ID.
	 * @param int        $integration_id Integration ID.
	 */
	private function log_exception( \Throwable $e, int $form_id, int $integration_id ): void {
		try {
			$this->logger->error(
				'internal_error',
				'An unexpected error occurred while sending a submission to Google Sheets. The visitor\'s submission was not affected.',
				array(
					'form_id'        => $form_id,
					'integration_id' => $integration_id,
					'detail'         => get_class( $e ) . ' @ ' . wp_basename( $e->getFile() ) . ':' . $e->getLine(),
				)
			);
		} catch ( \Throwable $ignored ) {
			// Logging must never break the CF7 response either.
			unset( $ignored );
		}
	}
}
