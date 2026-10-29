<?php
/**
 * Plugin admin screens: menu, credential upload/removal, log clearing, notices and
 * asset loading. Rendering lives in admin/views/*.php; this class only prepares
 * data and handles form posts (admin-post.php, nonce + capability checked).
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

/**
 * Admin controller.
 */
final class Admin {

	const MENU_SLUG       = 'skfss-dashboard';
	const PAGE_CONNECTION = 'skfss-connection';
	const PAGE_INTEGRATE  = 'skfss-integrations';
	const PAGE_LOGS       = 'skfss-logs';
	const PAGE_SETTINGS   = 'skfss-settings';
	const FLASH_TRANSIENT = 'skfss_flash_';
	const LOGS_PER_PAGE   = 25;

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Hook suffixes of the plugin screens.
	 *
	 * @var string[]
	 */
	private $hooks = array();

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
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_post_skfss_save_credentials', array( $this, 'handle_save_credentials' ) );
		add_action( 'admin_post_skfss_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_skfss_clear_logs', array( $this, 'handle_clear_logs' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SKFSS_FILE ), array( $this, 'action_links' ) );
		add_filter( 'option_page_capability_' . Settings::GROUP, array( Security::class, 'capability' ) );
	}

	/**
	 * Adds the "Google Sheets" menu and its screens.
	 */
	public function add_menu(): void {
		$cap = Security::capability();

		$this->hooks[] = (string) add_menu_page(
			__( 'FormSync', 'formsync-google-sheets-connector-contact-form-7' ),
			__( 'FormSync', 'formsync-google-sheets-connector-contact-form-7' ),
			$cap,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-media-spreadsheet',
			31
		);

		$pages = array(
			self::MENU_SLUG       => array( __( 'Dashboard', 'formsync-google-sheets-connector-contact-form-7' ), 'render_dashboard' ),
			self::PAGE_CONNECTION => array( __( 'Google Connection', 'formsync-google-sheets-connector-contact-form-7' ), 'render_connection' ),
			self::PAGE_INTEGRATE  => array( __( 'Integrations', 'formsync-google-sheets-connector-contact-form-7' ), 'render_integrations' ),
			self::PAGE_LOGS       => array( __( 'Error Logs', 'formsync-google-sheets-connector-contact-form-7' ), 'render_logs' ),
			self::PAGE_SETTINGS   => array( __( 'Settings', 'formsync-google-sheets-connector-contact-form-7' ), 'render_settings' ),
		);

		foreach ( $pages as $slug => $page ) {
			$this->hooks[] = (string) add_submenu_page( self::MENU_SLUG, $page[0], $page[0], $cap, $slug, array( $this, $page[1] ) );
		}
	}

	/**
	 * Loads assets on the plugin screens only.
	 *
	 * @param string $hook_suffix Hook suffix.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( ! in_array( (string) $hook_suffix, $this->hooks, true ) ) {
			return;
		}

		wp_enqueue_style( 'skfss-admin', SKFSS_URL . 'admin/css/admin.css', array(), SKFSS_VERSION );
		wp_enqueue_script( 'skfss-admin', SKFSS_URL . 'admin/js/admin.js', array(), SKFSS_VERSION, true );
		wp_localize_script(
			'skfss-admin',
			'SKFSS_ADMIN',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Security::AJAX_NONCE ),
				'i18n'    => array(
					'testing'           => __( 'Testing connection…', 'formsync-google-sheets-connector-contact-form-7' ),
					'testOk'            => __( 'Connection successful', 'formsync-google-sheets-connector-contact-form-7' ),
					'testFailed'        => __( 'Connection failed', 'formsync-google-sheets-connector-contact-form-7' ),
					'requestFailed'     => __( 'The request failed. Reload the page and try again.', 'formsync-google-sheets-connector-contact-form-7' ),
					'confirmClear'      => __( 'Delete all log entries? This cannot be undone.', 'formsync-google-sheets-connector-contact-form-7' ),
					'confirmDisconnect' => __( 'Remove the stored Google key? Forms will stop sending data to Google Sheets until a new key is uploaded.', 'formsync-google-sheets-connector-contact-form-7' ),
				),
			)
		);
	}

	/**
	 * Dashboard screen.
	 */
	public function render_dashboard(): void {
		$this->require_capability();

		$integrations = $this->plugin->integrations()->get_all();
		$spreadsheets = array();
		foreach ( $integrations as $integration ) {
			if ( $integration->is_enabled() && '' !== $integration->spreadsheet_id() ) {
				$spreadsheets[ $integration->spreadsheet_id() ] = array(
					'name' => '' !== $integration->spreadsheet_name() ? $integration->spreadsheet_name() : $integration->spreadsheet_id(),
					'url'  => Google_Sheets_Service::spreadsheet_url( $integration->spreadsheet_id() ),
				);
			}
		}

		$this->view(
			'dashboard',
			array(
				'connected'    => $this->plugin->auth()->is_configured(),
				'account'      => $this->plugin->auth()->get_account_label(),
				'auth_alert'   => false !== get_option( Google_Service_Account_Auth::ALERT_OPTION, false ),
				'counts'       => $this->plugin->integrations()->counts(),
				'recent'       => $this->plugin->logger()->get_logs( 1, 5, Error_Logger::LEVEL_ERROR ),
				'spreadsheets' => $spreadsheets,
				'cf7_active'   => $this->plugin->is_cf7_active(),
			)
		);
	}

	/**
	 * Google Connection screen.
	 */
	public function render_connection(): void {
		$this->require_capability();

		$this->view(
			'connection',
			array(
				'has_credentials' => $this->plugin->credentials()->has_credentials(),
				'summary'         => $this->plugin->credentials()->get_public_summary(),
				'crypto'          => Crypto::is_available(),
				'max_size'        => Credential_Store::MAX_FILE_SIZE,
				'auth_alert'      => false !== get_option( Google_Service_Account_Auth::ALERT_OPTION, false ),
			)
		);
	}

	/**
	 * Integrations screen.
	 */
	public function render_integrations(): void {
		$this->require_capability();

		$this->view(
			'integrations',
			array(
				'integrations' => $this->plugin->integrations()->get_all(),
				'cf7_active'   => $this->plugin->is_cf7_active(),
			)
		);
	}

	/**
	 * Error Logs screen.
	 */
	public function render_logs(): void {
		$this->require_capability();

		// Read-only filters and pagination; no state change.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$level = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
		$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$levels = self::log_levels();
		if ( ! array_key_exists( $level, $levels ) ) {
			$level = '';
		}

		$logger = $this->plugin->logger();
		$total  = $logger->count_logs( $level );
		$pages  = max( 1, (int) ceil( $total / self::LOGS_PER_PAGE ) );
		$paged  = min( max( 1, $paged ), $pages );

		$this->view(
			'logs',
			array(
				'logs'   => $logger->get_logs( $paged, self::LOGS_PER_PAGE, $level ),
				'total'  => $total,
				'pages'  => $pages,
				'paged'  => $paged,
				'level'  => $level,
				'levels' => $levels,
				'debug'  => (bool) $this->plugin->settings()->get( 'debug' ),
			)
		);
	}

	/**
	 * Settings screen.
	 */
	public function render_settings(): void {
		$this->require_capability();

		$this->view(
			'settings',
			array(
				'settings' => $this->plugin->settings()->all(),
			)
		);
	}

	/**
	 * Handles the service-account key upload.
	 */
	public function handle_save_credentials(): void {
		$this->require_capability();
		check_admin_referer( 'skfss_save_credentials' );

		$redirect = self::page_url( self::PAGE_CONNECTION );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated field by field below; the file itself is parsed as JSON and validated.
		$file = isset( $_FILES['skfss_key_file'] ) && is_array( $_FILES['skfss_key_file'] ) ? $_FILES['skfss_key_file'] : array();

		$contents = $this->read_uploaded_key( $file );
		if ( is_wp_error( $contents ) ) {
			$this->flash( 'error', $contents->get_error_message() );
			$this->redirect( $redirect );
		}

		$credentials = Credential_Store::validate_service_account_json( $contents );
		unset( $contents );
		if ( is_wp_error( $credentials ) ) {
			$this->flash( 'error', $credentials->get_error_message() );
			$this->redirect( $redirect );
		}

		$saved = $this->plugin->credentials()->save_service_account( $credentials );
		unset( $credentials );
		if ( is_wp_error( $saved ) ) {
			$this->flash( 'error', $saved->get_error_message() );
			$this->redirect( $redirect );
		}

		$this->reset_connection_state();

		$token = $this->plugin->auth()->get_access_token( true );
		if ( is_wp_error( $token ) ) {
			$this->flash( 'warning', __( 'The key was saved, but signing in to Google failed:', 'formsync-google-sheets-connector-contact-form-7' ) . ' ' . $token->get_error_message() );
		} else {
			$this->flash( 'success', __( 'The service-account key was saved and Google sign-in works. Now share your spreadsheets with the service-account email shown below.', 'formsync-google-sheets-connector-contact-form-7' ) );
		}

		$this->redirect( $redirect );
	}

	/**
	 * Removes the stored credentials.
	 */
	public function handle_disconnect(): void {
		$this->require_capability();
		check_admin_referer( 'skfss_disconnect' );

		$this->plugin->credentials()->delete();
		$this->reset_connection_state();

		$this->flash( 'success', __( 'The Google key was removed from this site. Remember to delete the key in Google Cloud as well if you no longer need it.', 'formsync-google-sheets-connector-contact-form-7' ) );
		$this->redirect( self::page_url( self::PAGE_CONNECTION ) );
	}

	/**
	 * Clears all logs.
	 */
	public function handle_clear_logs(): void {
		$this->require_capability();
		check_admin_referer( 'skfss_clear_logs' );

		$this->plugin->logger()->clear();

		$this->flash( 'success', __( 'All log entries were deleted.', 'formsync-google-sheets-connector-contact-form-7' ) );
		$this->redirect( self::page_url( self::PAGE_LOGS ) );
	}

	/**
	 * Admin notices: flash messages, authentication problems, failing integrations
	 * and a missing Contact Form 7.
	 *
	 * Notices are only shown where they are actionable: on this plugin's own
	 * screens and on Contact Form 7's screens. Nothing is printed on unrelated
	 * admin pages, in line with the WordPress.org plugin guidelines on admin notices.
	 */
	public function render_notices(): void {
		if ( ! Security::can_manage() ) {
			return;
		}

		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = $screen ? (string) $screen->id : '';
		$is_ours   = in_array( $screen_id, $this->hooks, true );
		$is_cf7    = false !== strpos( $screen_id, 'wpcf7' );

		if ( ! $is_ours && ! $is_cf7 ) {
			return;
		}

		if ( $is_ours ) {
			$this->render_flash();
		}

		if ( false !== get_option( Google_Service_Account_Auth::ALERT_OPTION, false ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'Google Sheets authentication needs attention.', 'formsync-google-sheets-connector-contact-form-7' ),
				esc_url( self::page_url( self::PAGE_CONNECTION ) ),
				esc_html__( 'Check the Google connection', 'formsync-google-sheets-connector-contact-form-7' )
			);
		}

		if ( $is_ours && ! $this->plugin->is_cf7_active() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Contact Form 7 is not active. FormSync needs it to send form submissions to Google Sheets.', 'formsync-google-sheets-connector-contact-form-7' )
			);
		}

		$failed = $this->plugin->integrations()->counts()['failed'];
		if ( $failed > 0 ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a> | <a href="%4$s">%5$s</a></p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of integrations. */
						_n( '%d Google Sheets integration failed on its last submission.', '%d Google Sheets integrations failed on their last submission.', $failed, 'formsync-google-sheets-connector-contact-form-7' ),
						$failed
					)
				),
				esc_url( self::page_url( self::PAGE_INTEGRATE ) ),
				esc_html__( 'View integrations', 'formsync-google-sheets-connector-contact-form-7' ),
				esc_url( self::page_url( self::PAGE_LOGS ) ),
				esc_html__( 'View Error Logs', 'formsync-google-sheets-connector-contact-form-7' )
			);
		}
	}

	/**
	 * Adds "Settings" and "Google Connection" links on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ): array {
		$links = (array) $links;
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::page_url( self::PAGE_CONNECTION ) ), esc_html__( 'Google Connection', 'formsync-google-sheets-connector-contact-form-7' ) ),
			sprintf( '<a href="%s">%s</a>', esc_url( self::page_url( self::PAGE_SETTINGS ) ), esc_html__( 'Settings', 'formsync-google-sheets-connector-contact-form-7' ) )
		);
		return $links;
	}

	/**
	 * URL of a plugin screen.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function page_url( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Formats a stored UTC datetime in the site's timezone and format.
	 *
	 * @param string $gmt UTC datetime (Y-m-d H:i:s).
	 * @return string '' when empty.
	 */
	public static function format_date( string $gmt ): string {
		if ( '' === $gmt || 0 === strpos( $gmt, '0000-00-00' ) ) {
			return '';
		}
		$timestamp = strtotime( $gmt . ' UTC' );
		if ( false === $timestamp ) {
			return '';
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Log level labels.
	 *
	 * @return array<string,string>
	 */
	public static function log_levels(): array {
		return array(
			''                          => __( 'All levels', 'formsync-google-sheets-connector-contact-form-7' ),
			Error_Logger::LEVEL_ERROR   => __( 'Errors', 'formsync-google-sheets-connector-contact-form-7' ),
			Error_Logger::LEVEL_WARNING => __( 'Warnings', 'formsync-google-sheets-connector-contact-form-7' ),
			Error_Logger::LEVEL_DEBUG   => __( 'Debug', 'formsync-google-sheets-connector-contact-form-7' ),
		);
	}

	/**
	 * CF7 edit URL for a form.
	 *
	 * @param int $form_id Form ID.
	 * @return string
	 */
	public static function form_edit_url( int $form_id ): string {
		return add_query_arg(
			array(
				'page'   => 'wpcf7',
				'post'   => $form_id,
				'action' => 'edit',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Validates and reads an uploaded key file. The temporary file is only read,
	 * never moved or kept; its contents are validated and then stored encrypted.
	 *
	 * @param array $file Entry from $_FILES.
	 * @return string|\WP_Error File contents.
	 */
	private function read_uploaded_key( array $file ) {
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return new \WP_Error( 'skfss_no_file', __( 'Choose the JSON key file to upload.', 'formsync-google-sheets-connector-contact-form-7' ) );
		}
		if ( UPLOAD_ERR_OK !== $error ) {
			return new \WP_Error( 'skfss_upload_error', __( 'The file could not be uploaded. Try again.', 'formsync-google-sheets-connector-contact-form-7' ) );
		}

		$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$name     = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$size     = isset( $file['size'] ) ? (int) $file['size'] : 0;

		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			return new \WP_Error( 'skfss_upload_error', __( 'The file could not be uploaded. Try again.', 'formsync-google-sheets-connector-contact-form-7' ) );
		}
		if ( 'json' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return new \WP_Error( 'skfss_bad_type', __( 'Upload the key as a .json file.', 'formsync-google-sheets-connector-contact-form-7' ) );
		}
		if ( $size <= 0 || $size > Credential_Store::MAX_FILE_SIZE ) {
			return new \WP_Error( 'skfss_bad_size', __( 'This file is too large to be a service-account key.', 'formsync-google-sheets-connector-contact-form-7' ) );
		}

		$contents = File_Handler::filesystem()->get_contents( $tmp_name );
		if ( ! is_string( $contents ) || '' === $contents || strlen( $contents ) > Credential_Store::MAX_FILE_SIZE ) {
			return new \WP_Error( 'skfss_unreadable', __( 'The uploaded file could not be read.', 'formsync-google-sheets-connector-contact-form-7' ) );
		}

		return $contents;
	}

	/**
	 * Clears tokens, caches and the auth alert after credentials change.
	 */
	private function reset_connection_state(): void {
		$this->plugin->auth()->invalidate_token();
		Google_Sheets_Service::flush_all_caches();
		delete_option( Google_Service_Account_Auth::ALERT_OPTION );
	}

	/**
	 * Stores a one-time notice for the current user.
	 *
	 * @param string $type    success|warning|error.
	 * @param string $message Message.
	 */
	private function flash( string $type, string $message ): void {
		$key      = self::FLASH_TRANSIENT . get_current_user_id();
		$messages = get_transient( $key );
		$messages = is_array( $messages ) ? $messages : array();

		$messages[] = array( $type, $message );
		set_transient( $key, $messages, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Prints and clears flash notices.
	 */
	private function render_flash(): void {
		$key      = self::FLASH_TRANSIENT . get_current_user_id();
		$messages = get_transient( $key );
		if ( ! is_array( $messages ) ) {
			return;
		}
		delete_transient( $key );

		foreach ( $messages as $message ) {
			$type = in_array( $message[0] ?? '', array( 'success', 'warning', 'error' ), true ) ? $message[0] : 'info';
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $type ),
				esc_html( (string) ( $message[1] ?? '' ) )
			);
		}
	}

	/**
	 * Includes a view. Views receive a single $view array.
	 *
	 * @param string $name View name.
	 * @param array  $view View data.
	 */
	private function view( string $name, array $view ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $view is read by the included template.
		$file = SKFSS_PATH . 'admin/views/' . $name . '.php';
		if ( is_readable( $file ) ) {
			include $file;
		}
	}

	/**
	 * Stops the request when the user lacks the capability.
	 */
	private function require_capability(): void {
		if ( ! Security::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'formsync-google-sheets-connector-contact-form-7' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Safe redirect and exit.
	 *
	 * @param string $url URL.
	 */
	private function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
