<?php
/**
 * Integrations overview. Each integration is edited in its form's
 * "Google Sheets" tab so that form fields and mapping stay together.
 *
 * @package SKFreelancers\SheetsSync
 *
 * @var array $view View data prepared by Admin::render_integrations().
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap skfss-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Integrations', 'formsync-google-sheets-connector-contact-form-7' ); ?></h1>
	<?php if ( $view['cf7_active'] ) : ?>
		<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=wpcf7' ) ); ?>"><?php esc_html_e( 'Configure a form', 'formsync-google-sheets-connector-contact-form-7' ); ?></a>
	<?php endif; ?>
	<hr class="wp-header-end" />

	<p><?php esc_html_e( 'Each contact form has its own spreadsheet, worksheet and field mapping. To change them, edit the form and open its "Google Sheets" tab.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>

	<?php if ( empty( $view['integrations'] ) ) : ?>
		<p><?php esc_html_e( 'No integrations yet.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
	<?php else : ?>
		<table class="widefat striped skfss-integrations">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Form', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Spreadsheet', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Worksheet', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last Submission', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last Sync', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $view['integrations'] as $skfss_item ) : ?>
					<?php
					$skfss_form_id = $skfss_item->form_id();
					$skfss_title   = (string) get_post_field( 'post_title', $skfss_form_id, 'raw' );
					$skfss_title   = '' !== $skfss_title ? $skfss_title : $skfss_item->title();
					$skfss_status  = $skfss_item->last_sync_status();
					?>
					<tr>
						<td>
							<strong>
								<?php if ( $view['cf7_active'] && Security::can_edit_form( $skfss_form_id ) ) : ?>
									<a href="<?php echo esc_url( Admin::form_edit_url( $skfss_form_id ) ); ?>"><?php echo esc_html( '' !== $skfss_title ? $skfss_title : '#' . $skfss_form_id ); ?></a>
								<?php else : ?>
									<?php echo esc_html( '' !== $skfss_title ? $skfss_title : '#' . $skfss_form_id ); ?>
								<?php endif; ?>
							</strong>
						</td>
						<td>
							<?php if ( $skfss_item->is_enabled() ) : ?>
								<span class="skfss-ok">&#10003; <?php esc_html_e( 'Enabled', 'formsync-google-sheets-connector-contact-form-7' ); ?></span>
							<?php else : ?>
								<span class="skfss-muted"><?php esc_html_e( 'Disabled', 'formsync-google-sheets-connector-contact-form-7' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( '' !== $skfss_item->spreadsheet_id() ) : ?>
								<a href="<?php echo esc_url( $skfss_item->spreadsheet_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( '' !== $skfss_item->spreadsheet_name() ? $skfss_item->spreadsheet_name() : __( 'Open spreadsheet', 'formsync-google-sheets-connector-contact-form-7' ) ); ?></a>
								<br /><code class="skfss-small"><?php echo esc_html( $skfss_item->spreadsheet_id() ); ?></code>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( '' !== $skfss_item->worksheet_name() ? $skfss_item->worksheet_name() : '—' ); ?>
							<?php if ( $skfss_item->is_newest_first() ) : ?>
								<br /><span class="skfss-muted skfss-small"><?php esc_html_e( 'Newest first', 'formsync-google-sheets-connector-contact-form-7' ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $skfss_item->worksheet_id() ) : ?>
								<br /><span class="skfss-muted skfss-small">
								<?php
								/* translators: %s: worksheet ID. */
								echo esc_html( sprintf( __( 'ID %s', 'formsync-google-sheets-connector-contact-form-7' ), $skfss_item->worksheet_id() ) );
								?>
								</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( '' !== $skfss_item->last_submission_at() ? Admin::format_date( $skfss_item->last_submission_at() ) : '—' ); ?></td>
						<td>
							<?php if ( 'success' === $skfss_status ) : ?>
								<span class="skfss-ok">&#10003; <?php esc_html_e( 'Successful', 'formsync-google-sheets-connector-contact-form-7' ); ?></span>
							<?php elseif ( 'failed' === $skfss_status ) : ?>
								<span class="skfss-bad">&#10005; <?php esc_html_e( 'Failed', 'formsync-google-sheets-connector-contact-form-7' ); ?></span>
								<?php if ( '' !== $skfss_item->last_error() ) : ?>
									<br /><span class="skfss-error-text"><?php echo esc_html( $skfss_item->last_error() ); ?></span>
								<?php endif; ?>
								<br /><a href="<?php echo esc_url( Admin::page_url( Admin::PAGE_LOGS ) ); ?>"><?php esc_html_e( 'View Error Logs', 'formsync-google-sheets-connector-contact-form-7' ); ?></a>
							<?php else : ?>
								<span class="skfss-muted"><?php esc_html_e( 'No submissions yet', 'formsync-google-sheets-connector-contact-form-7' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
