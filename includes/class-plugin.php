<?php
/**
 * Main plugin bootstrap. Builds the service graph and registers hooks.
 *
 * Dependency direction (important for future changes):
 *
 *   Submission_Handler (CF7-aware)
 *     -> Integration_Manager (storage)
 *     -> Sync_Service (CF7-agnostic, reusable by a future retry queue)
 *       -> Google_Sheets_Service (Sheets/Drive operations, caching)
 *         -> Google_Http_Client (HTTP, error mapping, token refresh)
 *           -> Google_Auth_Interface (service account today, OAuth later)
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin container.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Global settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Error logger.
	 *
	 * @var Error_Logger
	 */
	private $logger;

	/**
	 * Credential store.
	 *
	 * @var Credential_Store
	 */
	private $credentials;

	/**
	 * Google authentication provider.
	 *
	 * @var Google_Auth_Interface
	 */
	private $auth;

	/**
	 * Google Sheets service.
	 *
	 * @var Google_Sheets_Service
	 */
	private $sheets;

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
	 * Submission ledger.
	 *
	 * @var Submission_Tracker
	 */
	private $tracker;

	/**
	 * Sync service.
	 *
	 * @var Sync_Service
	 */
	private $sync;

	/**
	 * Returns (and on first call boots) the plugin.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Private constructor; use instance().
	 */
	private function __construct() {}

	/**
	 * Builds services and registers hooks.
	 */
	private function boot(): void {
		Installer::maybe_upgrade();
		Installer::register_hooks();

		$crypto             = new Crypto();
		$this->settings     = new Settings();
		$this->logger       = new Error_Logger( $this->settings );
		$this->credentials  = new Credential_Store( $crypto );
		$this->auth         = $this->create_auth( $crypto );
		$this->sheets       = new Google_Sheets_Service( new Google_Http_Client( $this->auth ), $this->auth );
		$this->integrations = new Integration_Manager();
		$this->mapper       = new Field_Mapper();
		$this->tracker      = new Submission_Tracker();
		$this->sync         = new Sync_Service( $this->sheets, $this->auth, $this->integrations, $this->mapper, $this->tracker, $this->logger );

		add_action( Installer::CRON_HOOK, array( $this, 'run_maintenance' ) );
		add_action( 'deleted_post', array( $this, 'on_post_deleted' ), 10, 2 );

		if ( $this->is_cf7_active() ) {
			$handler = new Submission_Handler( $this->integrations, $this->mapper, $this->sync, $this->logger, new Metadata_Collector(), new File_Handler( $this->logger ) );
			$handler->register( (bool) $this->settings->get( 'sync_on_mail_failed' ) );
		}

		if ( is_admin() ) {
			$this->settings->register();

			$admin = new Admin( $this );
			$admin->register();

			$ajax = new Ajax( $this->sheets, $this->mapper );
			$ajax->register();

			if ( $this->is_cf7_active() ) {
				$panel = new CF7_Integration( $this );
				$panel->register();
			}
		}
	}

	/**
	 * Creates the authentication provider.
	 *
	 * Third parties (or a future OAuth module) can supply their own provider through
	 * the `skfss_auth_provider` filter without touching the Sheets service layer.
	 *
	 * @param Crypto $crypto Crypto helper.
	 * @return Google_Auth_Interface
	 */
	private function create_auth( Crypto $crypto ): Google_Auth_Interface {
		$method   = (string) $this->settings->get( 'auth_method' );
		$provider = apply_filters( 'skfss_auth_provider', null, $method, $this->credentials, $crypto );

		if ( $provider instanceof Google_Auth_Interface ) {
			return $provider;
		}

		return new Google_Service_Account_Auth( $this->credentials, $crypto );
	}

	/**
	 * Whether Contact Form 7 is loaded.
	 *
	 * @return bool
	 */
	public function is_cf7_active(): bool {
		return defined( 'WPCF7_VERSION' ) && class_exists( '\WPCF7_ContactForm' );
	}

	/**
	 * Daily maintenance: log retention and ledger cleanup.
	 */
	public function run_maintenance(): void {
		$days = max( 1, (int) $this->settings->get( 'log_retention_days' ) );
		$this->logger->purge_older_than( $days );
		$this->tracker->purge_older_than( (int) apply_filters( 'skfss_submission_ledger_days', 90 ) );
	}

	/**
	 * Removes integrations that belonged to a deleted Contact Form 7 form.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object (WP 5.5+).
	 */
	public function on_post_deleted( $post_id, $post = null ): void {
		if ( $post instanceof \WP_Post && 'wpcf7_contact_form' === $post->post_type ) {
			$this->integrations->delete_for_form( (int) $post_id );
		}
	}

	/**
	 * Global settings service.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Error logger service.
	 *
	 * @return Error_Logger
	 */
	public function logger(): Error_Logger {
		return $this->logger;
	}

	/**
	 * Credential store service.
	 *
	 * @return Credential_Store
	 */
	public function credentials(): Credential_Store {
		return $this->credentials;
	}

	/**
	 * Active Google authentication provider.
	 *
	 * @return Google_Auth_Interface
	 */
	public function auth(): Google_Auth_Interface {
		return $this->auth;
	}

	/**
	 * Google Sheets service.
	 *
	 * @return Google_Sheets_Service
	 */
	public function sheets(): Google_Sheets_Service {
		return $this->sheets;
	}

	/**
	 * Integration storage service.
	 *
	 * @return Integration_Manager
	 */
	public function integrations(): Integration_Manager {
		return $this->integrations;
	}

	/**
	 * Field mapper service.
	 *
	 * @return Field_Mapper
	 */
	public function mapper(): Field_Mapper {
		return $this->mapper;
	}
}
