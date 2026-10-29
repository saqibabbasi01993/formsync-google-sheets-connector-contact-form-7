<?php
/**
 * Adds the "Google Sheets" tab to the Contact Form 7 editor and saves its settings.
 *
 * Overwrite protection: settings are only saved when the request carries this
 * panel's own nonce AND the posted form ID matches the form being saved (or the form
 * is brand new). Programmatic saves, REST saves, imports and CF7's "Duplicate" action
 * therefore never touch an existing integration. A duplicated form starts without an
 * integration so two forms never write into the same sheet by accident.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

/**
 * CF7 editor integration.
 */
final class CF7_Integration {

	const PANEL_ID         = 'skfss-google-sheets-panel';
	const NOTICE_TRANSIENT = 'skfss_panel_notice_';

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_filter( 'wpcf7_editor_panels', array( $this, 'add_panel' ) );
		add_action( 'wpcf7_after_save', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'render_save_notice' ) );
	}

	/**
	 * Adds the panel for users who can both edit the form and manage integrations.
	 *
	 * @param array $panels Panels.
	 * @return array
	 */
	public function add_panel( $panels ): array {
		$panels = (array) $panels;
		if ( ! Security::can_manage() ) {
			return $panels;
		}
		$form    = \WPCF7_ContactForm::get_current();
		$form_id = $form ? (int) $form->id() : 0;
		if ( ! Security::can_edit_form( $form_id ) ) {
			return $panels;
		}

		$panels[ self::PANEL_ID ] = array(
			'title'    => __( 'Google Sheets', 'formsync-google-sheets-connector-contact-form-7' ),
			'callback' => array( $this, 'render_panel' ),
		);
		return $panels;
	}

	/**
	 * Renders the panel.
	 *
	 * @param \WPCF7_ContactForm $contact_form Form.
	 */
	public function render_panel( $contact_form ): void {
		$form_id     = (int) $contact_form->id();
		$settings    = $this->plugin->settings()->all();
		$mapper      = $this->plugin->mapper();
		$fields      = $mapper->get_form_fields( $contact_form );
		$field_names = wp_list_pluck( $fields, 'name' );
		$integration = $form_id > 0 ? $this->plugin->integrations()->get_primary_for_form( $form_id ) : null;
		$auth        = $this->plugin->auth();

		$view = array(
			'form_id'     => $form_id,
			'integration' => $integration,
			'fields'      => $fields,
			'field_types' => wp_list_pluck( $fields, 'type', 'name' ),
			'has_files'   => in_array( 'file', wp_list_pluck( $fields, 'type' ), true ),
			'mapping'     => $mapper->normalize_mapping( $integration ? $integration->mapping() : array(), $field_names, (array) $settings['default_metadata'] ),
			'mapper'      => $mapper,
			'connected'   => $auth->is_configured(),
			'account'     => $auth->get_account_label(),
			'config'      => array(
				'enabled'          => $integration ? $integration->is_enabled() : false,
				'config_mode'      => $integration ? (string) $integration->setting( 'config_mode', 'select' ) : 'select',
				'mapping_mode'     => $integration ? $integration->mapping_mode() : 'auto',
				'file_mode'        => $integration ? $integration->file_mode() : (string) $settings['default_file_mode'],
				'auto_headers'     => $integration ? $integration->auto_headers() : true,
				'row_order'        => $integration ? $integration->row_order() : (string) $settings['default_row_order'],
				'top_rows'         => $integration ? $integration->top_rows() : 1,
				'spreadsheet_id'   => $integration ? $integration->spreadsheet_id() : '',
				'spreadsheet_name' => $integration ? $integration->spreadsheet_name() : '',
				'worksheet_id'     => $integration ? $integration->worksheet_id() : '',
				'worksheet_name'   => $integration ? $integration->worksheet_name() : '',
			),
		);

		include SKFSS_PATH . 'admin/views/cf7-panel.php';
	}

	/**
	 * Saves the panel when CF7 saves the form.
	 *
	 * @param \WPCF7_ContactForm $contact_form Form.
	 */
	public function save( $contact_form ): void {
		if ( ! isset( $_POST['skfss_panel_nonce'] ) || ! $contact_form instanceof \WPCF7_ContactForm ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['skfss_panel_nonce'] ) ), Security::PANEL_NONCE ) ) {
			return;
		}

		$form_id = (int) $contact_form->id();
		if ( $form_id <= 0 || ! Security::can_manage() || ! Security::can_edit_form( $form_id ) ) {
			return;
		}

		// Each key is sanitized individually in sanitize_panel_input().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset( $_POST['skfss'] ) && is_array( $_POST['skfss'] ) ? wp_unslash( $_POST['skfss'] ) : array();

		// CF7 "Duplicate" posts the original form's fields while saving a new copy.
		$posted_form_id = isset( $raw['form_id'] ) ? (int) $raw['form_id'] : 0;
		if ( $posted_form_id > 0 && $posted_form_id !== $form_id ) {
			return;
		}

		$field_names = wp_list_pluck( $this->plugin->mapper()->get_form_fields( $contact_form ), 'name' );
		$data        = $this->sanitize_panel_input( $raw, $field_names );
		$messages    = array();
		$data        = $this->validate( $data, $messages );

		$data['form_id'] = $form_id;
		$data['title']   = $contact_form->title();

		$existing = $this->plugin->integrations()->get_primary_for_form( $form_id );
		$saved_id = $this->plugin->integrations()->save( $data, $existing ? $existing->id() : 0 );

		if ( '' !== $data['spreadsheet_id'] ) {
			$this->plugin->sheets()->invalidate_spreadsheet_cache( $data['spreadsheet_id'] );
			$this->plugin->sheets()->invalidate_header_cache( $data['spreadsheet_id'], $data['worksheet_name'] );
		}

		if ( 0 === $saved_id ) {
			$messages[] = array( 'error', __( 'The Google Sheets settings could not be saved.', 'formsync-google-sheets-connector-contact-form-7' ) );
		} elseif ( empty( $messages ) && $data['enabled'] ) {
			$messages[] = array( 'success', __( 'Google Sheets settings saved and verified.', 'formsync-google-sheets-connector-contact-form-7' ) );
		}

		if ( ! empty( $messages ) ) {
			set_transient( self::NOTICE_TRANSIENT . get_current_user_id(), $messages, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Sanitizes raw panel input.
	 *
	 * @param array    $raw         Raw input.
	 * @param string[] $field_names Field names.
	 * @return array<string,mixed>
	 */
	private function sanitize_panel_input( array $raw, array $field_names ): array {
		$settings = $this->plugin->integrations()->sanitize_settings( $raw, $field_names, $this->plugin->settings()->all(), $this->plugin->mapper() );

		if ( 'manual' === $settings['config_mode'] ) {
			$spreadsheet_id = Security::parse_spreadsheet_id( sanitize_text_field( (string) ( $raw['manual_spreadsheet'] ?? '' ) ) );
			$worksheet_id   = '';
			$worksheet_name = sanitize_text_field( (string) ( $raw['manual_worksheet'] ?? '' ) );
			$sheet_name     = '';
		} else {
			$spreadsheet_id = Security::parse_spreadsheet_id( sanitize_text_field( (string) ( $raw['spreadsheet_id'] ?? '' ) ) );
			$worksheet_id   = Security::sanitize_worksheet_id( sanitize_text_field( (string) ( $raw['worksheet_id'] ?? '' ) ) );
			$worksheet_name = sanitize_text_field( (string) ( $raw['worksheet_name'] ?? '' ) );
			$sheet_name     = sanitize_text_field( (string) ( $raw['spreadsheet_name'] ?? '' ) );
		}

		return array(
			'enabled'          => ! empty( $raw['enabled'] ),
			'spreadsheet_id'   => $spreadsheet_id,
			'spreadsheet_name' => $sheet_name,
			'worksheet_id'     => $worksheet_id,
			'worksheet_name'   => $worksheet_name,
			'settings'         => $settings,
		);
	}

	/**
	 * Verifies the spreadsheet and worksheet with Google before saving. Names are
	 * taken from Google, not from the browser. Definite problems (not found, access
	 * denied, missing worksheet) disable the integration; temporary problems keep it
	 * enabled with a warning.
	 *
	 * @param array $data     Sanitized data.
	 * @param array $messages Collected notices (by reference).
	 * @return array
	 */
	private function validate( array $data, array &$messages ): array {
		if ( '' === $data['spreadsheet_id'] ) {
			if ( $data['enabled'] ) {
				$data['enabled'] = false;
				$messages[]      = array( 'warning', __( 'Google Sheets was not enabled because no valid spreadsheet was selected.', 'formsync-google-sheets-connector-contact-form-7' ) );
			}
			return $data;
		}
		if ( '' === $data['worksheet_id'] && '' === $data['worksheet_name'] ) {
			$data['enabled'] = false;
			$messages[]      = array( 'warning', __( 'Google Sheets was not enabled because no worksheet was selected.', 'formsync-google-sheets-connector-contact-form-7' ) );
			return $data;
		}
		if ( ! $this->plugin->auth()->is_configured() ) {
			$messages[] = array( 'warning', __( 'Settings saved, but Google is not connected yet, so nothing will be sent until you connect it.', 'formsync-google-sheets-connector-contact-form-7' ) );
			return $data;
		}

		$sheet = $this->plugin->sheets()->find_worksheet( $data['spreadsheet_id'], $data['worksheet_id'], $data['worksheet_name'], true );
		if ( is_wp_error( $sheet ) ) {
			$type = (string) ( $sheet->get_error_data()['error_type'] ?? '' );
			if ( in_array( $type, array( 'not_found', 'access_denied', 'worksheet_missing', 'invalid_request' ), true ) ) {
				$data['enabled'] = false;
				/* translators: %s: error message. */
				$messages[] = array( 'error', sprintf( __( 'Google Sheets was disabled for this form: %s', 'formsync-google-sheets-connector-contact-form-7' ), $sheet->get_error_message() ) );
			} else {
				/* translators: %s: error message. */
				$messages[] = array( 'warning', sprintf( __( 'Settings saved, but the spreadsheet could not be verified right now: %s', 'formsync-google-sheets-connector-contact-form-7' ), $sheet->get_error_message() ) );
			}
			return $data;
		}

		$meta                     = $this->plugin->sheets()->get_spreadsheet( $data['spreadsheet_id'] );
		$data['worksheet_id']     = $sheet['id'];
		$data['worksheet_name']   = $sheet['title'];
		$data['spreadsheet_name'] = is_wp_error( $meta ) ? $data['spreadsheet_name'] : $meta['name'];

		return $data;
	}

	/**
	 * Loads assets on the CF7 form editor only.
	 *
	 * @param string $hook_suffix Hook suffix.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( ! self::is_editor_screen( (string) $hook_suffix ) || ! Security::can_manage() ) {
			return;
		}

		wp_enqueue_style( 'skfss-admin', SKFSS_URL . 'admin/css/admin.css', array(), SKFSS_VERSION );
		wp_enqueue_script( 'skfss-cf7-panel', SKFSS_URL . 'admin/js/cf7-panel.js', array(), SKFSS_VERSION, true );
		wp_localize_script(
			'skfss-cf7-panel',
			'SKFSS_PANEL',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Security::AJAX_NONCE ),
				'i18n'    => array(
					'loadingSpreadsheets' => __( 'Loading spreadsheets…', 'formsync-google-sheets-connector-contact-form-7' ),
					'loadingWorksheets'   => __( 'Loading worksheets…', 'formsync-google-sheets-connector-contact-form-7' ),
					'chooseSpreadsheet'   => __( '— Select a spreadsheet —', 'formsync-google-sheets-connector-contact-form-7' ),
					'chooseWorksheet'     => __( '— Select a worksheet —', 'formsync-google-sheets-connector-contact-form-7' ),
					/* translators: %d: number of spreadsheets. */
					'spreadsheetsLoaded'  => __( '%d spreadsheets loaded.', 'formsync-google-sheets-connector-contact-form-7' ),
					'noSpreadsheets'      => __( 'No spreadsheets are shared with the service account yet. Share a spreadsheet with it, then select Refresh.', 'formsync-google-sheets-connector-contact-form-7' ),
					'listFailed'          => __( 'Unable to retrieve spreadsheets. Please check your Google connection.', 'formsync-google-sheets-connector-contact-form-7' ),
					'worksheetLoaded'     => __( 'Worksheets loaded.', 'formsync-google-sheets-connector-contact-form-7' ),
					'testing'             => __( 'Testing connection…', 'formsync-google-sheets-connector-contact-form-7' ),
					'testOk'              => __( 'Connection successful', 'formsync-google-sheets-connector-contact-form-7' ),
					'testFailed'          => __( 'Connection failed', 'formsync-google-sheets-connector-contact-form-7' ),
					'selectFirst'         => __( 'Select a spreadsheet and worksheet first.', 'formsync-google-sheets-connector-contact-form-7' ),
					'matching'            => __( 'Reading sheet headers…', 'formsync-google-sheets-connector-contact-form-7' ),
					/* translators: %d: number of matched fields. */
					'matched'             => __( 'Matched %d fields to existing columns. Save the form to keep this mapping.', 'formsync-google-sheets-connector-contact-form-7' ),
					'noHeaders'           => __( 'The worksheet has no header row yet. Column names below will be used to create it.', 'formsync-google-sheets-connector-contact-form-7' ),
					'requestFailed'       => __( 'The request failed. Reload the page and try again.', 'formsync-google-sheets-connector-contact-form-7' ),
					'notInList'           => __( '(saved, not in list)', 'formsync-google-sheets-connector-contact-form-7' ),
				),
			)
		);
	}

	/**
	 * Displays the result of the last panel save.
	 */
	public function render_save_notice(): void {
		if ( ! self::is_editor_screen( (string) ( get_current_screen()->id ?? '' ) ) ) {
			return;
		}
		$key      = self::NOTICE_TRANSIENT . get_current_user_id();
		$messages = get_transient( $key );
		if ( ! is_array( $messages ) ) {
			return;
		}
		delete_transient( $key );

		foreach ( $messages as $message ) {
			$type = in_array( $message[0] ?? '', array( 'error', 'warning', 'success' ), true ) ? $message[0] : 'info';
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p><strong>%2$s</strong> %3$s</p></div>',
				esc_attr( $type ),
				esc_html__( 'Google Sheets:', 'formsync-google-sheets-connector-contact-form-7' ),
				esc_html( (string) ( $message[1] ?? '' ) )
			);
		}
	}

	/**
	 * Whether the given hook/screen ID is the CF7 form editor.
	 *
	 * @param string $id Hook suffix or screen ID.
	 * @return bool
	 */
	private static function is_editor_screen( string $id ): bool {
		if ( false === strpos( $id, 'wpcf7' ) ) {
			return false;
		}
		// Read-only routing check; no state changes.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 'wpcf7-new' === $page || ( 'wpcf7' === $page && isset( $_GET['post'] ) );
	}
}
