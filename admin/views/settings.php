<?php
/**
 * Global settings (Settings API). Per-form options live in each form's
 * "Google Sheets" tab; the defaults below only pre-fill new integrations.
 *
 * @package SKFreelancers\SheetsSync
 *
 * @var array $view View data prepared by Admin::render_settings().
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

$skfss_settings = $view['settings'];
$skfss_name     = Settings::OPTION;
?>
<div class="wrap skfss-wrap">
	<h1><?php esc_html_e( 'Settings', 'formsync-google-sheets-connector-contact-form-7' ); ?></h1>
	<?php settings_errors(); ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
		<?php settings_fields( Settings::GROUP ); ?>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="skfss-auth-method"><?php esc_html_e( 'Authentication method', 'formsync-google-sheets-connector-contact-form-7' ); ?></label></th>
					<td>
						<select id="skfss-auth-method" name="<?php echo esc_attr( $skfss_name . '[auth_method]' ); ?>">
							<?php foreach ( Settings::auth_methods() as $skfss_key => $skfss_label ) : ?>
								<option value="<?php echo esc_attr( $skfss_key ); ?>" <?php selected( $skfss_settings['auth_method'], $skfss_key ); ?>><?php echo esc_html( $skfss_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default file handling', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Default file handling', 'formsync-google-sheets-connector-contact-form-7' ); ?></legend>
							<?php foreach ( Settings::file_modes() as $skfss_key => $skfss_label ) : ?>
								<label class="skfss-block-label"><input type="radio" name="<?php echo esc_attr( $skfss_name . '[default_file_mode]' ); ?>" value="<?php echo esc_attr( $skfss_key ); ?>" <?php checked( $skfss_settings['default_file_mode'], $skfss_key ); ?> /> <?php echo esc_html( $skfss_label ); ?></label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Used for new integrations. Each form can override it.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default row order', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Default row order', 'formsync-google-sheets-connector-contact-form-7' ); ?></legend>
							<?php foreach ( Settings::row_orders() as $skfss_key => $skfss_label ) : ?>
								<label class="skfss-block-label"><input type="radio" name="<?php echo esc_attr( $skfss_name . '[default_row_order]' ); ?>" value="<?php echo esc_attr( $skfss_key ); ?>" <?php checked( $skfss_settings['default_row_order'], $skfss_key ); ?> /> <?php echo esc_html( $skfss_label ); ?></label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Used when a form is set up for the first time. Forms that are already connected keep their own setting; change it in the form\'s Google Sheets tab.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default metadata', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
					<td>
						<fieldset class="skfss-columns">
							<legend class="screen-reader-text"><?php esc_html_e( 'Default metadata', 'formsync-google-sheets-connector-contact-form-7' ); ?></legend>
							<?php foreach ( Field_Mapper::metadata_definitions() as $skfss_key => $skfss_label ) : ?>
								<label class="skfss-block-label"><input type="checkbox" name="<?php echo esc_attr( $skfss_name . '[default_metadata][]' ); ?>" value="<?php echo esc_attr( $skfss_key ); ?>" <?php checked( in_array( $skfss_key, (array) $skfss_settings['default_metadata'], true ) ); ?> /> <?php echo esc_html( $skfss_label ); ?></label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Metadata columns checked by default when a form is set up. Personal data such as IP address or user details is only collected when checked for a form.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'When mail fails', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $skfss_name . '[sync_on_mail_failed]' ); ?>" value="1" <?php checked( ! empty( $skfss_settings['sync_on_mail_failed'] ) ); ?> /> <?php esc_html_e( 'Also send submissions to Google Sheets when Contact Form 7 could not send its email', 'formsync-google-sheets-connector-contact-form-7' ); ?></label>
						<p class="description"><?php esc_html_e( 'Useful as a backup when the site cannot send email. Submissions rejected as spam or invalid are never sent.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Debug logging', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $skfss_name . '[debug]' ); ?>" value="1" <?php checked( ! empty( $skfss_settings['debug'] ) ); ?> /> <?php esc_html_e( 'Record technical events in the Error Logs', 'formsync-google-sheets-connector-contact-form-7' ); ?></label>
						<p class="description"><?php esc_html_e( 'Logs submissions detected, headers read and rows appended. Submitted values and credentials are never logged.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="skfss-retention"><?php esc_html_e( 'Keep logs for', 'formsync-google-sheets-connector-contact-form-7' ); ?></label></th>
					<td>
						<input type="number" id="skfss-retention" class="small-text" min="1" max="365" name="<?php echo esc_attr( $skfss_name . '[log_retention_days]' ); ?>" value="<?php echo esc_attr( (string) (int) $skfss_settings['log_retention_days'] ); ?>" />
						<?php esc_html_e( 'days', 'formsync-google-sheets-connector-contact-form-7' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstall', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $skfss_name . '[delete_on_uninstall]' ); ?>" value="1" <?php checked( ! empty( $skfss_settings['delete_on_uninstall'] ) ); ?> /> <?php esc_html_e( 'Delete plugin data when plugin is uninstalled', 'formsync-google-sheets-connector-contact-form-7' ); ?></label>
						<p class="description"><?php esc_html_e( 'Removes this plugin\'s settings, stored Google key, integrations, logs and copied upload files. Contact forms and your Google spreadsheets are never touched.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
