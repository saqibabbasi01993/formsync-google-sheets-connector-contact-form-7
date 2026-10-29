<?php
/**
 * "Google Sheets" panel inside the Contact Form 7 editor.
 *
 * Rendered inside CF7's own <form>, so it contains no <form> element; every
 * button is type="button" except the Save button, which submits CF7's form.
 * Settings are saved by CF7_Integration::save() on `wpcf7_after_save`.
 *
 * @package SKFreelancers\SheetsSync
 *
 * @var array $view View data prepared by CF7_Integration::render_panel().
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

$skfss_config      = $view['config'];
$skfss_integration = $view['integration'];
$skfss_mapper      = $view['mapper'];
$skfss_is_manual   = 'manual' === $skfss_config['config_mode'];
$skfss_open_url    = '' !== $skfss_config['spreadsheet_id'] ? Google_Sheets_Service::spreadsheet_url( $skfss_config['spreadsheet_id'], $skfss_config['worksheet_id'] ) : '';
$skfss_status      = $skfss_integration ? $skfss_integration->last_sync_status() : '';
?>
<div class="skfss-panel" id="skfss-panel" data-form-id="<?php echo esc_attr( (string) $view['form_id'] ); ?>">
	<h2><?php esc_html_e( 'Google Sheets', 'formsync-google-sheets-connector-contact-form-7' ); ?></h2>

	<?php wp_nonce_field( Security::PANEL_NONCE, 'skfss_panel_nonce', false ); ?>
	<input type="hidden" name="skfss[form_id]" value="<?php echo esc_attr( (string) $view['form_id'] ); ?>" />

	<?php if ( $view['form_id'] <= 0 ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'Save the form once first. After that you can choose a spreadsheet for it here.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p></div>
	<?php endif; ?>

	<table class="skfss-status widefat striped">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Google Connection', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
				<td>
					<?php if ( $view['connected'] ) : ?>
						<span class="skfss-ok">&#10003; <?php esc_html_e( 'Connected', 'formsync-google-sheets-connector-contact-form-7' ); ?></span>
						<code><?php echo esc_html( $view['account'] ); ?></code>
					<?php else : ?>
						<span class="skfss-bad">&#10005; <?php esc_html_e( 'Not Connected', 'formsync-google-sheets-connector-contact-form-7' ); ?></span>
						<a href="<?php echo esc_url( Admin::page_url( Admin::PAGE_CONNECTION ) ); ?>"><?php esc_html_e( 'Connect Google', 'formsync-google-sheets-connector-contact-form-7' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'CF7 Form', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
				<td>
					<?php if ( $skfss_config['enabled'] ) : ?>
						<span class="skfss-ok">&#10003; <?php esc_html_e( 'Integration Enabled', 'formsync-google-sheets-connector-contact-form-7' ); ?></span>
					<?php else : ?>
						<span class="skfss-muted"><?php esc_html_e( 'Integration Disabled', 'formsync-google-sheets-connector-contact-form-7' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $skfss_integration && '' !== $skfss_integration->last_submission_at() ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Last Submission', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
					<td><?php echo esc_html( Admin::format_date( $skfss_integration->last_submission_at() ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Last Sync', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
					<td>
						<?php if ( 'success' === $skfss_status ) : ?>
							<span class="skfss-ok">&#10003; <?php esc_html_e( 'Successful', 'formsync-google-sheets-connector-contact-form-7' ); ?></span>
						<?php else : ?>
							<span class="skfss-bad">&#10005; <?php esc_html_e( 'Failed', 'formsync-google-sheets-connector-contact-form-7' ); ?></span>
							<?php if ( '' !== $skfss_integration->last_error() ) : ?>
								<span class="skfss-error-text"><?php echo esc_html( $skfss_integration->last_error() ); ?></span>
							<?php endif; ?>
							<a href="<?php echo esc_url( Admin::page_url( Admin::PAGE_LOGS ) ); ?>"><?php esc_html_e( 'View Error Logs', 'formsync-google-sheets-connector-contact-form-7' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>

	<fieldset class="skfss-section">
		<legend class="screen-reader-text"><?php esc_html_e( 'Integration', 'formsync-google-sheets-connector-contact-form-7' ); ?></legend>
		<p>
			<label>
				<input type="checkbox" name="skfss[enabled]" value="1" <?php checked( $skfss_config['enabled'] ); ?> />
				<strong><?php esc_html_e( 'Enable Google Sheets Integration', 'formsync-google-sheets-connector-contact-form-7' ); ?></strong>
			</label>
		</p>
		<p class="description"><?php esc_html_e( 'When enabled, each successful submission of this form is added as a new row. If Google Sheets is unavailable, visitors still see the normal success message and the problem is recorded in the Error Logs.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
	</fieldset>

	<h3><?php esc_html_e( 'Spreadsheet', 'formsync-google-sheets-connector-contact-form-7' ); ?></h3>
	<p class="skfss-mode-switch">
		<label><input type="radio" name="skfss[config_mode]" value="select" <?php checked( ! $skfss_is_manual ); ?> /> <?php esc_html_e( 'Choose from my spreadsheets', 'formsync-google-sheets-connector-contact-form-7' ); ?></label>
		<label><input type="radio" name="skfss[config_mode]" value="manual" <?php checked( $skfss_is_manual ); ?> /> <?php esc_html_e( 'Enter spreadsheet ID manually', 'formsync-google-sheets-connector-contact-form-7' ); ?></label>
	</p>

	<table class="form-table skfss-config skfss-config-select" <?php echo $skfss_is_manual ? 'hidden' : ''; ?>>
		<tbody>
			<tr>
				<th scope="row"><label for="skfss-spreadsheet"><?php esc_html_e( 'Google Spreadsheet', 'formsync-google-sheets-connector-contact-form-7' ); ?></label></th>
				<td>
					<select id="skfss-spreadsheet" name="skfss[spreadsheet_id]" class="skfss-select">
						<option value=""><?php esc_html_e( '— Select a spreadsheet —', 'formsync-google-sheets-connector-contact-form-7' ); ?></option>
						<?php if ( ! $skfss_is_manual && '' !== $skfss_config['spreadsheet_id'] ) : ?>
							<option value="<?php echo esc_attr( $skfss_config['spreadsheet_id'] ); ?>" selected="selected"><?php echo esc_html( '' !== $skfss_config['spreadsheet_name'] ? $skfss_config['spreadsheet_name'] : $skfss_config['spreadsheet_id'] ); ?></option>
						<?php endif; ?>
					</select>
					<button type="button" class="button skfss-refresh"><?php esc_html_e( 'Refresh', 'formsync-google-sheets-connector-contact-form-7' ); ?></button>
					<input type="hidden" name="skfss[spreadsheet_name]" class="skfss-spreadsheet-name" value="<?php echo esc_attr( $skfss_config['spreadsheet_name'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Only spreadsheets shared with the service-account email are listed.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
					<p class="skfss-id-line"><?php esc_html_e( 'Spreadsheet ID:', 'formsync-google-sheets-connector-contact-form-7' ); ?> <code class="skfss-spreadsheet-id"><?php echo esc_html( $skfss_is_manual ? '' : $skfss_config['spreadsheet_id'] ); ?></code></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="skfss-worksheet"><?php esc_html_e( 'Worksheet', 'formsync-google-sheets-connector-contact-form-7' ); ?></label></th>
				<td>
					<select id="skfss-worksheet" name="skfss[worksheet_id]" class="skfss-select">
						<option value=""><?php esc_html_e( '— Select a worksheet —', 'formsync-google-sheets-connector-contact-form-7' ); ?></option>
						<?php if ( ! $skfss_is_manual && '' !== $skfss_config['worksheet_id'] ) : ?>
							<option value="<?php echo esc_attr( $skfss_config['worksheet_id'] ); ?>" data-title="<?php echo esc_attr( $skfss_config['worksheet_name'] ); ?>" selected="selected"><?php echo esc_html( $skfss_config['worksheet_name'] ); ?></option>
						<?php endif; ?>
					</select>
					<input type="hidden" name="skfss[worksheet_name]" class="skfss-worksheet-name" value="<?php echo esc_attr( $skfss_is_manual ? '' : $skfss_config['worksheet_name'] ); ?>" />
					<p class="skfss-id-line"><?php esc_html_e( 'Worksheet ID:', 'formsync-google-sheets-connector-contact-form-7' ); ?> <code class="skfss-worksheet-id"><?php echo esc_html( $skfss_is_manual ? '' : $skfss_config['worksheet_id'] ); ?></code></p>
				</td>
			</tr>
		</tbody>
	</table>

	<table class="form-table skfss-config skfss-config-manual" <?php echo $skfss_is_manual ? '' : 'hidden'; ?>>
		<tbody>
			<tr>
				<th scope="row"><label for="skfss-manual-spreadsheet"><?php esc_html_e( 'Spreadsheet ID or link', 'formsync-google-sheets-connector-contact-form-7' ); ?></label></th>
				<td>
					<input type="text" id="skfss-manual-spreadsheet" class="regular-text" name="skfss[manual_spreadsheet]" value="<?php echo esc_attr( $skfss_is_manual ? $skfss_config['spreadsheet_id'] : '' ); ?>" autocomplete="off" />
					<p class="description"><?php esc_html_e( 'Paste the spreadsheet link from your browser, or just the ID between /d/ and /edit.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="skfss-manual-worksheet"><?php esc_html_e( 'Worksheet name', 'formsync-google-sheets-connector-contact-form-7' ); ?></label></th>
				<td>
					<input type="text" id="skfss-manual-worksheet" class="regular-text" name="skfss[manual_worksheet]" value="<?php echo esc_attr( $skfss_is_manual ? $skfss_config['worksheet_name'] : '' ); ?>" autocomplete="off" />
					<p class="description"><?php esc_html_e( 'The name of the tab at the bottom of the spreadsheet, for example "Sheet1".', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
				</td>
			</tr>
		</tbody>
	</table>

	<p class="skfss-actions">
		<button type="button" class="button skfss-test"><?php esc_html_e( 'Test Connection', 'formsync-google-sheets-connector-contact-form-7' ); ?></button>
		<a class="button skfss-open" href="<?php echo esc_url( $skfss_open_url ); ?>" target="_blank" rel="noopener noreferrer" <?php echo '' === $skfss_open_url ? 'hidden' : ''; ?>><?php esc_html_e( 'Open Spreadsheet', 'formsync-google-sheets-connector-contact-form-7' ); ?></a>
		<input type="submit" class="button button-primary" name="wpcf7-save" value="<?php esc_attr_e( 'Save Settings', 'formsync-google-sheets-connector-contact-form-7' ); ?>" />
	</p>
	<div class="skfss-message" role="status" aria-live="polite"></div>

	<h3><?php esc_html_e( 'Headers', 'formsync-google-sheets-connector-contact-form-7' ); ?></h3>
	<p>
		<label>
			<input type="checkbox" name="skfss[auto_headers]" value="1" <?php checked( $skfss_config['auto_headers'] ); ?> />
			<?php esc_html_e( 'Automatically create missing headers', 'formsync-google-sheets-connector-contact-form-7' ); ?>
		</label>
	</p>
	<p class="description"><?php esc_html_e( 'Adds the column names below to the first row of the worksheet when they are missing. Existing headers are never changed or reordered.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>

	<h3><?php esc_html_e( 'Row order', 'formsync-google-sheets-connector-contact-form-7' ); ?></h3>
	<fieldset>
		<legend class="screen-reader-text"><?php esc_html_e( 'Where new submissions are added', 'formsync-google-sheets-connector-contact-form-7' ); ?></legend>
		<?php foreach ( Settings::row_orders() as $skfss_order => $skfss_label ) : ?>
			<label class="skfss-block-label"><input type="radio" name="skfss[row_order]" value="<?php echo esc_attr( $skfss_order ); ?>" <?php checked( $skfss_config['row_order'], $skfss_order ); ?> /> <?php echo esc_html( $skfss_label ); ?></label>
		<?php endforeach; ?>
	</fieldset>
	<div class="skfss-top-rows" <?php echo 'prepend' === $skfss_config['row_order'] ? '' : 'hidden'; ?>>
		<p>
			<label for="skfss-top-rows"><strong><?php esc_html_e( 'Rows to keep at the top', 'formsync-google-sheets-connector-contact-form-7' ); ?></strong></label><br />
			<select id="skfss-top-rows" name="skfss[top_rows]">
				<option value="0" <?php selected( 0, $skfss_config['top_rows'] ); ?>><?php esc_html_e( 'Same as the sheet\'s frozen rows', 'formsync-google-sheets-connector-contact-form-7' ); ?></option>
				<?php for ( $skfss_n = 1; $skfss_n <= Settings::MAX_TOP_ROWS; $skfss_n++ ) : ?>
					<option value="<?php echo esc_attr( (string) $skfss_n ); ?>" <?php selected( $skfss_n, $skfss_config['top_rows'] ); ?>>
						<?php
						echo esc_html(
							1 === $skfss_n
								? __( '1 row (header row only)', 'formsync-google-sheets-connector-contact-form-7' )
								/* translators: %d: number of rows. */
								: sprintf( _n( '%d row', '%d rows', $skfss_n, 'formsync-google-sheets-connector-contact-form-7' ), $skfss_n )
						);
						?>
					</option>
				<?php endfor; ?>
			</select>
		</p>
		<p class="description"><?php esc_html_e( 'New submissions are inserted directly below these rows, and the rows themselves are never moved or changed. Use this if you have title or note rows under the header. Example: 3 keeps rows 1–3 in place and puts each new submission in row 4. With "Same as the sheet\'s frozen rows", freeze the rows in Google Sheets (View → Freeze); if nothing is frozen, only row 1 is kept. Changes to frozen rows can take up to 10 minutes to be noticed, or select Refresh above.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
	</div>
	<p class="description"><?php esc_html_e( 'With "newest first", older rows move down when a submission is added. Row 1 always holds the column headers used for field matching.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>

	<?php if ( $view['has_files'] ) : ?>
		<h3><?php esc_html_e( 'File uploads', 'formsync-google-sheets-connector-contact-form-7' ); ?></h3>
		<fieldset>
			<legend class="screen-reader-text"><?php esc_html_e( 'File field behavior', 'formsync-google-sheets-connector-contact-form-7' ); ?></legend>
			<?php foreach ( Settings::file_modes() as $skfss_mode => $skfss_label ) : ?>
				<label class="skfss-block-label"><input type="radio" name="skfss[file_mode]" value="<?php echo esc_attr( $skfss_mode ); ?>" <?php checked( $skfss_config['file_mode'], $skfss_mode ); ?> /> <?php echo esc_html( $skfss_label ); ?></label>
			<?php endforeach; ?>
		</fieldset>
		<p class="description"><?php esc_html_e( 'Files are never placed in the sheet itself. With a link option, a copy of each uploaded file is kept in a folder with an unguessable name inside your uploads directory, and anyone who has the link (for example, anyone with access to the sheet) can open it. Use "File name only" for sensitive documents.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
	<?php else : ?>
		<input type="hidden" name="skfss[file_mode]" value="<?php echo esc_attr( $skfss_config['file_mode'] ); ?>" />
	<?php endif; ?>

	<h3><?php esc_html_e( 'Field mapping', 'formsync-google-sheets-connector-contact-form-7' ); ?></h3>
	<p class="skfss-mode-switch">
		<label><input type="radio" name="skfss[mapping_mode]" value="auto" <?php checked( 'auto', $skfss_config['mapping_mode'] ); ?> /> <?php esc_html_e( 'Automatic: match form fields to sheet headers by name', 'formsync-google-sheets-connector-contact-form-7' ); ?></label>
		<label><input type="radio" name="skfss[mapping_mode]" value="manual" <?php checked( 'manual', $skfss_config['mapping_mode'] ); ?> /> <?php esc_html_e( 'Manual: use the column names below exactly', 'formsync-google-sheets-connector-contact-form-7' ); ?></label>
	</p>
	<p class="description skfss-auto-help"><?php esc_html_e( 'In automatic mode, each field goes to the existing column whose header best matches its name (for example "your-email" and "Email"), or to a new column named after the field. Fields added to the form later are included automatically. Metadata columns always use the names below.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
	<p>
		<button type="button" class="button skfss-match"><?php esc_html_e( 'Match with sheet headers', 'formsync-google-sheets-connector-contact-form-7' ); ?></button>
	</p>

	<?php if ( empty( $view['fields'] ) ) : ?>
		<p class="description"><?php esc_html_e( 'This form has no fields yet. Add fields on the Form tab and save to map them.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
	<?php endif; ?>

	<table class="widefat striped skfss-mapping">
		<thead>
			<tr>
				<th scope="col" class="skfss-col-order"><?php esc_html_e( 'Order', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
				<th scope="col" class="skfss-col-send"><?php esc_html_e( 'Send', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
				<th scope="col"><?php esc_html_e( 'CF7 Field', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Google Sheet Column', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $view['mapping'] as $skfss_index => $skfss_row ) : ?>
				<?php
				$skfss_is_meta = 0 === strpos( $skfss_row['source'], 'meta:' );
				$skfss_field   = $skfss_is_meta ? '' : substr( $skfss_row['source'], 6 );
				$skfss_type    = $skfss_is_meta ? '' : (string) ( $view['field_types'][ $skfss_field ] ?? '' );
				$skfss_prefix  = 'skfss[mapping][' . (int) $skfss_index . ']';
				?>
				<tr class="skfss-map-row<?php echo $skfss_is_meta ? ' skfss-map-meta' : ' skfss-map-field'; ?>" data-field="<?php echo esc_attr( $skfss_field ); ?>">
					<td class="skfss-col-order">
						<button type="button" class="button-link skfss-move" data-dir="up" aria-label="<?php esc_attr_e( 'Move up', 'formsync-google-sheets-connector-contact-form-7' ); ?>">&#9650;</button>
						<button type="button" class="button-link skfss-move" data-dir="down" aria-label="<?php esc_attr_e( 'Move down', 'formsync-google-sheets-connector-contact-form-7' ); ?>">&#9660;</button>
					</td>
					<td class="skfss-col-send">
						<input type="hidden" data-key="source" name="<?php echo esc_attr( $skfss_prefix . '[source]' ); ?>" value="<?php echo esc_attr( $skfss_row['source'] ); ?>" />
						<input type="checkbox" data-key="enabled" name="<?php echo esc_attr( $skfss_prefix . '[enabled]' ); ?>" value="1" <?php checked( $skfss_row['enabled'] ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: field name. */ __( 'Send %s', 'formsync-google-sheets-connector-contact-form-7' ), $skfss_mapper->source_label( $skfss_row['source'] ) ) ); ?>" />
					</td>
					<td>
						<?php if ( $skfss_is_meta ) : ?>
							<?php echo esc_html( $skfss_mapper->source_label( $skfss_row['source'] ) ); ?>
							<span class="skfss-badge"><?php esc_html_e( 'Metadata', 'formsync-google-sheets-connector-contact-form-7' ); ?></span>
						<?php else : ?>
							<code><?php echo esc_html( $skfss_field ); ?></code>
							<?php if ( '' !== $skfss_type ) : ?>
								<span class="skfss-muted"><?php echo esc_html( $skfss_type ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</td>
					<td>
						<input type="text" class="regular-text skfss-column" data-key="column" name="<?php echo esc_attr( $skfss_prefix . '[column]' ); ?>" value="<?php echo esc_attr( $skfss_row['column'] ); ?>" maxlength="191" />
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="description"><?php esc_html_e( 'Unchecked rows are not sent. Metadata such as IP address or user details is only collected when you check it. New columns are added to the right of existing ones in the order shown.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
</div>
