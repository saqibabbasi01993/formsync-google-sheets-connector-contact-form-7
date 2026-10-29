<?php
/**
 * Handles CF7 file-upload fields.
 *
 * Binary files are never sent to Google. Depending on the per-form setting, the
 * sheet receives the file name, a link, or both. For links, the file is copied out of
 * CF7's temporary folder (CF7 deletes it right after the submission) into
 * uploads/skfss-uploads/YYYY/MM/<random 24 chars>/ :
 * - Only paths CF7 itself reports in WPCF7_Submission::uploaded_files() are used, and
 *   each must resolve (realpath) inside CF7's temporary upload directory, so no
 *   visitor-supplied path can reach the filesystem.
 * - The file type must be allowed by WordPress (wp_check_filetype_and_ext).
 * - The random folder makes URLs unguessable; directory listing and script
 *   execution are disabled with index.php files and an .htaccess file (Apache).
 * Links are public to anyone who has them; administrators are told this in the UI.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * File handler.
 */
final class File_Handler {

	const DIR = 'skfss-uploads';

	/**
	 * Error logger.
	 *
	 * @var Error_Logger
	 */
	private $logger;

	/**
	 * Per-request cache so several integrations share one copy.
	 *
	 * @var array<string,array{names:string[],urls:string[],with_urls:bool}>
	 */
	private $cache = array();

	/**
	 * Constructor.
	 *
	 * @param Error_Logger $logger Logger.
	 */
	public function __construct( Error_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Resolves file fields to names and (optionally) public URLs.
	 *
	 * @param \WPCF7_Submission $submission Submission.
	 * @param string[]          $file_fields File field names.
	 * @param bool              $need_urls  Whether links are needed.
	 * @return array<string,array{names:string[],urls:string[]}>
	 */
	public function get_files( $submission, array $file_fields, bool $need_urls ): array {
		$uploaded = (array) $submission->uploaded_files();
		$tmp_root = function_exists( 'wpcf7_upload_tmp_dir' ) ? realpath( wpcf7_upload_tmp_dir() ) : false;
		$tmp_root = false !== $tmp_root ? trailingslashit( wp_normalize_path( $tmp_root ) ) : '';
		$result   = array();

		foreach ( $file_fields as $name ) {
			if ( isset( $this->cache[ $name ] ) && ( ! $need_urls || $this->cache[ $name ]['with_urls'] ) ) {
				$result[ $name ] = $this->cache[ $name ];
				continue;
			}

			$names = array();
			$urls  = array();
			foreach ( (array) ( $uploaded[ $name ] ?? array() ) as $path ) {
				$real = is_string( $path ) ? realpath( $path ) : false;
				if ( false === $real || '' === $tmp_root || 0 !== strpos( wp_normalize_path( $real ), $tmp_root ) || ! is_file( $real ) ) {
					continue;
				}
				$names[] = sanitize_file_name( wp_basename( $real ) );
				if ( $need_urls ) {
					$url = $this->persist( $real );
					if ( '' !== $url ) {
						$urls[] = $url;
					}
				}
			}

			$this->cache[ $name ] = array(
				'names'     => $names,
				'urls'      => $urls,
				'with_urls' => $need_urls,
			);
			$result[ $name ]      = $this->cache[ $name ];
		}

		return $result;
	}

	/**
	 * Formats one field's files for a cell.
	 *
	 * @param array  $entry Entry from get_files().
	 * @param string $mode  filename|url|both.
	 * @return string
	 */
	public static function format( array $entry, string $mode ): string {
		$names = (array) ( $entry['names'] ?? array() );
		$urls  = (array) ( $entry['urls'] ?? array() );

		if ( 'url' === $mode ) {
			return implode( ', ', ! empty( $urls ) ? $urls : $names );
		}
		if ( 'both' === $mode ) {
			$parts = array();
			foreach ( $names as $index => $file_name ) {
				$parts[] = isset( $urls[ $index ] ) ? $file_name . ' (' . $urls[ $index ] . ')' : $file_name;
			}
			return implode( ', ', $parts );
		}
		return implode( ', ', $names );
	}

	/**
	 * Base directory for copied files.
	 *
	 * @return array{path:string,url:string}|WP_Error
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'skfss_uploads_unavailable', (string) $uploads['error'] );
		}
		return array(
			'path' => wp_normalize_path( $uploads['basedir'] ) . '/' . self::DIR,
			'url'  => set_url_scheme( $uploads['baseurl'] ) . '/' . self::DIR,
		);
	}

	/**
	 * Direct filesystem instance (safe here: only WordPress' own uploads dir is used).
	 *
	 * @return \WP_Filesystem_Direct
	 */
	public static function filesystem(): \WP_Filesystem_Direct {
		if ( ! class_exists( '\WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}
		return new \WP_Filesystem_Direct( null );
	}

	/**
	 * Copies a file to the public folder and returns its URL.
	 *
	 * @param string $real Real path inside CF7's temp dir.
	 * @return string URL or ''.
	 */
	private function persist( string $real ): string {
		$file_name = sanitize_file_name( wp_basename( $real ) );
		$check     = wp_check_filetype_and_ext( $real, $file_name );
		if ( empty( $check['ext'] ) ) {
			$this->logger->debug( 'file_type_rejected', 'An uploaded file type is not allowed by WordPress, so no link was created.', array( 'detail' => pathinfo( $file_name, PATHINFO_EXTENSION ) ) );
			return '';
		}

		$base = $this->prepare_base();
		if ( is_wp_error( $base ) ) {
			$this->logger->warning( 'file_storage_unavailable', 'The uploads folder is not writable, so no file link was created.' );
			return '';
		}

		$fs       = self::filesystem();
		$relative = gmdate( 'Y/m' ) . '/' . wp_generate_password( 24, false, false );
		$dir      = $base['path'] . '/' . $relative;
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$this->protect_directory( $base['path'] . '/' . gmdate( 'Y' ) );
		$this->protect_directory( $base['path'] . '/' . gmdate( 'Y/m' ) );
		$this->protect_directory( $dir );

		$mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
		if ( ! $fs->copy( $real, $dir . '/' . $file_name, false, $mode ) ) {
			return '';
		}

		return $base['url'] . '/' . $relative . '/' . rawurlencode( $file_name );
	}

	/**
	 * Creates the base folder with protection files.
	 *
	 * @return array{path:string,url:string}|WP_Error
	 */
	private function prepare_base() {
		$base = self::base_dir();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		if ( ! wp_mkdir_p( $base['path'] ) ) {
			return new WP_Error( 'skfss_uploads_unwritable', 'Uploads folder is not writable.' );
		}

		$fs       = self::filesystem();
		$mode     = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
		$htaccess = $base['path'] . '/.htaccess';
		if ( ! $fs->exists( $htaccess ) ) {
			$rules = "Options -Indexes\n"
				. "<FilesMatch \"\\.(php[0-9]?|phtml|phar|pl|py|cgi|asp|aspx|jsp|sh|shtml)$\">\n"
				. "\t<IfModule mod_authz_core.c>\n\t\tRequire all denied\n\t</IfModule>\n"
				. "\t<IfModule !mod_authz_core.c>\n\t\tOrder allow,deny\n\t\tDeny from all\n\t</IfModule>\n"
				. "</FilesMatch>\n";
			$fs->put_contents( $htaccess, $rules, $mode );
		}
		$this->protect_directory( $base['path'] );

		return $base;
	}

	/**
	 * Drops an index.php into a directory to block listings on servers without .htaccess.
	 *
	 * @param string $dir Directory.
	 */
	private function protect_directory( string $dir ): void {
		$fs    = self::filesystem();
		$index = $dir . '/index.php';
		if ( is_dir( $dir ) && ! $fs->exists( $index ) ) {
			$fs->put_contents( $index, "<?php\n// Silence is golden.\n", defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
		}
	}
}
