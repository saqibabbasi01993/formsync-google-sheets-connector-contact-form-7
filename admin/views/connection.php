<?php
/**
 * Google Connection screen: service-account instructions, key upload, status,
 * connection test and disconnect. The private key is never shown again after upload.
 *
 * @package SKFreelancers\SheetsSync
 *
 * @var array $view View data prepared by Admin::render_connection().
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

$skfss_summary  = $view['summary'];
$skfss_readable = $view['has_credentials'] && $skfss_summary['readable'];
?>
<div class="wrap skfss-wrap">
	<h1><?php esc_html_e( 'Google Connection', 'formsync-google-sheets-connector-contact-form-7' ); ?></h1>

	<?php if ( ! $view['crypto'] ) : ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'This server does not provide the OpenSSL PHP extension with AES-256-GCM, so the key cannot be stored securely. Ask your host to enable OpenSSL.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $skfss_readable ) : ?>
		<div class="skfss-card skfss-card-wide">
			<h2><?php esc_html_e( 'Status', 'formsync-google-sheets-connector-contact-form-7' ); ?></h2>
			<?php if ( $view['auth_alert'] ) : ?>
				<p class="skfss-bad">&#10005; <?php esc_html_e( 'Google Sheets authentication needs attention.', 'formsync-google-sheets-connector-contact-form-7' ); ?> <?php esc_html_e( 'Run the test below. If it fails, create a new key in Google Cloud and upload it.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
			<?php else : ?>
				<p class="skfss-ok">&#10003; <?php esc_html_e( 'Connected with a service account', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
			<?php endif; ?>

			<table class="form-table skfss-summary">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Service-account email', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
						<td>
							<code class="skfss-sa-email"><?php echo esc_html( $skfss_summary['client_email'] ); ?></code>
							<p class="description"><?php esc_html_e( 'Share each spreadsheet with this email address as an Editor (Share button in Google Sheets). The plugin can only see spreadsheets shared with it.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
						</td>
					</tr>
					<?php if ( '' !== $skfss_summary['project_id'] ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Google Cloud project', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
							<td><code><?php echo esc_html( $skfss_summary['project_id'] ); ?></code></td>
						</tr>
					<?php endif; ?>
					<?php if ( '' !== $skfss_summary['key_hint'] ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Key ID', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
							<td><code><?php echo esc_html( $skfss_summary['key_hint'] ); ?></code></td>
						</tr>
					<?php endif; ?>
					<?php if ( $skfss_summary['stored_at'] > 0 ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Uploaded', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $skfss_summary['stored_at'] ) ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<p>
				<button type="button" class="button button-primary skfss-connection-test"><?php esc_html_e( 'Test Connection', 'formsync-google-sheets-connector-contact-form-7' ); ?></button>
			</p>
			<div class="skfss-message" role="status" aria-live="polite"></div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="skfss-disconnect-form">
				<input type="hidden" name="action" value="skfss_disconnect" />
				<?php wp_nonce_field( 'skfss_disconnect' ); ?>
				<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Disconnect (remove key from this site)', 'formsync-google-sheets-connector-contact-form-7' ); ?></button>
			</form>
		</div>
	<?php elseif ( $view['has_credentials'] ) : ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'A key is stored but can no longer be read. This happens when the site\'s security keys (salts) in wp-config.php change. Upload the key file again.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p></div>
	<?php endif; ?>

	<div class="skfss-card skfss-card-wide">
		<h2><?php echo $skfss_readable ? esc_html__( 'Replace the key', 'formsync-google-sheets-connector-contact-form-7' ) : esc_html__( 'Connect Google', 'formsync-google-sheets-connector-contact-form-7' ); ?></h2>

		<p><?php esc_html_e( 'This plugin signs in to Google with a service account: a robot Google account that belongs to your own Google Cloud project. Setup takes about five minutes and is free.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>

		<ol class="skfss-steps">
			<li>
				<?php
				printf(
					/* translators: %s: link to Google Cloud Console. */
					esc_html__( 'Open the %s and create a project (or select an existing one).', 'formsync-google-sheets-connector-contact-form-7' ),
					'<a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Google Cloud Console', 'formsync-google-sheets-connector-contact-form-7' ) . '</a>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: 1: link to Google Sheets API page, 2: link to Google Drive API page. */
					esc_html__( 'Enable the %1$s and the %2$s for that project (the Drive API is only used to list your spreadsheets).', 'formsync-google-sheets-connector-contact-form-7' ),
					'<a href="https://console.cloud.google.com/apis/library/sheets.googleapis.com" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Google Sheets API', 'formsync-google-sheets-connector-contact-form-7' ) . '</a>',
					'<a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Google Drive API', 'formsync-google-sheets-connector-contact-form-7' ) . '</a>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: link to the service accounts page. */
					esc_html__( 'Go to %s and create a service account. No roles are needed.', 'formsync-google-sheets-connector-contact-form-7' ),
					'<a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener noreferrer">' . esc_html__( 'IAM & Admin → Service Accounts', 'formsync-google-sheets-connector-contact-form-7' ) . '</a>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'Open the new service account, go to Keys → Add key → Create new key, choose JSON, and download the file.', 'formsync-google-sheets-connector-contact-form-7' ); ?></li>
			<li><?php esc_html_e( 'Upload that JSON file below. It is validated and stored encrypted; the private key is never displayed again.', 'formsync-google-sheets-connector-contact-form-7' ); ?></li>
			<li><?php esc_html_e( 'Share each spreadsheet you want to use with the service-account email shown after upload, with Editor access.', 'formsync-google-sheets-connector-contact-form-7' ); ?></li>
			<li><?php esc_html_e( 'Select Test Connection, then configure your forms in Contact → Contact Forms → (your form) → Google Sheets.', 'formsync-google-sheets-connector-contact-form-7' ); ?></li>
		</ol>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="skfss-upload-form">
			<input type="hidden" name="action" value="skfss_save_credentials" />
			<?php wp_nonce_field( 'skfss_save_credentials' ); ?>
			<p>
				<label for="skfss-key-file"><strong><?php esc_html_e( 'Service-account JSON key file', 'formsync-google-sheets-connector-contact-form-7' ); ?></strong></label><br />
				<input type="file" id="skfss-key-file" name="skfss_key_file" accept=".json,application/json" required />
			</p>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: maximum file size. */
						__( 'Maximum size: %s. Keep the downloaded file somewhere safe or delete it after uploading.', 'formsync-google-sheets-connector-contact-form-7' ),
						size_format( $view['max_size'] )
					)
				);
				?>
			</p>
			<?php submit_button( $skfss_readable ? __( 'Upload new key', 'formsync-google-sheets-connector-contact-form-7' ) : __( 'Upload and connect', 'formsync-google-sheets-connector-contact-form-7' ), 'primary', 'submit', true, $view['crypto'] ? array() : array( 'disabled' => 'disabled' ) ); ?>
		</form>
	</div>
</div>
