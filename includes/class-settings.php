<?php
/**
 * Global (site-wide) settings. Per-form settings live on each integration record and
 * are never mixed in here.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

/**
 * Settings.
 */
final class Settings {

	/**
	 * Most rows that can be kept above new submissions in newest-first mode.
	 */
	const MAX_TOP_ROWS = 20;

	const OPTION = 'skfss_settings';
	const GROUP  = 'skfss_settings_group';

	/**
	 * Default settings. Conservative by design: no extra personal data collected by
	 * default, and data is kept on uninstall unless explicitly requested.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'auth_method'         => 'service_account',
			'default_file_mode'   => 'filename',
			'default_row_order'   => 'append',
			'default_metadata'    => array( 'submission_date', 'submission_time' ),
			'debug'               => false,
			'sync_on_mail_failed' => false,
			'log_retention_days'  => 30,
			'delete_on_uninstall' => false,
		);
	}

	/**
	 * Available authentication methods (filterable for a future OAuth module).
	 *
	 * @return array<string,string>
	 */
	public static function auth_methods(): array {
		return (array) apply_filters(
			'skfss_auth_methods',
			array( 'service_account' => __( 'Google service account', 'formsync-google-sheets-connector-contact-form-7' ) )
		);
	}

	/**
	 * File handling modes.
	 *
	 * @return array<string,string>
	 */
	public static function file_modes(): array {
		return array(
			'filename' => __( 'File name only', 'formsync-google-sheets-connector-contact-form-7' ),
			'url'      => __( 'Link to a copy of the file', 'formsync-google-sheets-connector-contact-form-7' ),
			'both'     => __( 'File name and link', 'formsync-google-sheets-connector-contact-form-7' ),
		);
	}

	/**
	 * Where new rows are written.
	 *
	 * "append" adds each submission after the last row (oldest first). "prepend"
	 * inserts each submission as row 2, directly below the header row, so the
	 * newest submission is always on top. Row 1 is never written in either mode.
	 *
	 * @return array<string,string>
	 */
	public static function row_orders(): array {
		return array(
			'append'  => __( 'Add to the bottom (oldest first)', 'formsync-google-sheets-connector-contact-form-7' ),
			'prepend' => __( 'Insert below the header row (newest first)', 'formsync-google-sheets-connector-contact-form-7' ),
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( string $key ) {
		$all = $this->all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Registers the option with the Settings API.
	 */
	public function register(): void {
		add_action(
			'admin_init',
			function () {
				register_setting(
					self::GROUP,
					self::OPTION,
					array(
						'type'              => 'array',
						'sanitize_callback' => array( $this, 'sanitize' ),
						'default'           => self::defaults(),
						'show_in_rest'      => false,
					)
				);
			}
		);
	}

	/**
	 * Sanitizes the settings form input.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$clean    = array();

		$method               = isset( $input['auth_method'] ) ? sanitize_key( $input['auth_method'] ) : '';
		$clean['auth_method'] = array_key_exists( $method, self::auth_methods() ) ? $method : $defaults['auth_method'];

		$file_mode                  = isset( $input['default_file_mode'] ) ? sanitize_key( $input['default_file_mode'] ) : '';
		$clean['default_file_mode'] = array_key_exists( $file_mode, self::file_modes() ) ? $file_mode : $defaults['default_file_mode'];

		$row_order                  = isset( $input['default_row_order'] ) ? sanitize_key( $input['default_row_order'] ) : '';
		$clean['default_row_order'] = array_key_exists( $row_order, self::row_orders() ) ? $row_order : $defaults['default_row_order'];

		$metadata = isset( $input['default_metadata'] ) && is_array( $input['default_metadata'] ) ? array_map( 'sanitize_key', $input['default_metadata'] ) : array();

		$clean['default_metadata'] = array_values( array_intersect( Field_Mapper::metadata_keys(), $metadata ) );

		$clean['debug']               = ! empty( $input['debug'] );
		$clean['sync_on_mail_failed'] = ! empty( $input['sync_on_mail_failed'] );
		$clean['delete_on_uninstall'] = ! empty( $input['delete_on_uninstall'] );

		$days                        = isset( $input['log_retention_days'] ) ? absint( $input['log_retention_days'] ) : $defaults['log_retention_days'];
		$clean['log_retention_days'] = min( 365, max( 1, $days ) );

		return $clean;
	}
}
