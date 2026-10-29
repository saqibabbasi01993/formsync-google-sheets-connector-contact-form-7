<?php
/**
 * Dashboard screen.
 *
 * @package SKFreelancers\SheetsSync
 *
 * @var array $view View data prepared by Admin::render_dashboard().
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

$skfss_counts = $view['counts'];
?>
<div class="wrap skfss-wrap">
	<h1><?php esc_html_e( 'FormSync', 'formsync-google-sheets-connector-contact-form-7' ); ?></h1>

	<div class="skfss-cards">
		<div class="skfss-card">
			<h2><?php esc_html_e( 'Google Connection', 'formsync-google-sheets-connector-contact-form-7' ); ?></h2>
			<?php if ( $view['connected'] && ! $view['auth_alert'] ) : ?>
				<p class="skfss-ok">&#10003; <?php esc_html_e( 'Connected', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
				<p><code><?php echo esc_html( $view['account'] ); ?></code></p>
			<?php elseif ( $view['connected'] ) : ?>
				<p class="skfss-bad">&#10005; <?php esc_html_e( 'Google Sheets authentication needs attention.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
			<?php else : ?>
				<p class="skfss-bad">&#10005; <?php esc_html_e( 'Not Connected', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
			<?php endif; ?>
			<p><a class="button" href="<?php echo esc_url( Admin::page_url( Admin::PAGE_CONNECTION ) ); ?>"><?php esc_html_e( 'Manage connection', 'formsync-google-sheets-connector-contact-form-7' ); ?></a></p>
		</div>

		<div class="skfss-card">
			<h2><?php esc_html_e( 'Connected forms', 'formsync-google-sheets-connector-contact-form-7' ); ?></h2>
			<p class="skfss-big-number"><?php echo esc_html( number_format_i18n( $skfss_counts['forms'] ) ); ?></p>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of integrations. */
						_n( '%d enabled integration', '%d enabled integrations', $skfss_counts['integrations'], 'formsync-google-sheets-connector-contact-form-7' ),
						$skfss_counts['integrations']
					)
				);
				?>
				<?php if ( $skfss_counts['failed'] > 0 ) : ?>
					&middot; <span class="skfss-bad">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of failed integrations. */
							_n( '%d failing', '%d failing', $skfss_counts['failed'], 'formsync-google-sheets-connector-contact-form-7' ),
							$skfss_counts['failed']
						)
					);
					?>
					</span>
				<?php endif; ?>
			</p>
			<p><a class="button" href="<?php echo esc_url( Admin::page_url( Admin::PAGE_INTEGRATE ) ); ?>"><?php esc_html_e( 'View integrations', 'formsync-google-sheets-connector-contact-form-7' ); ?></a></p>
		</div>
	</div>

	<?php if ( ! $view['connected'] || 0 === $skfss_counts['integrations'] ) : ?>
		<h2><?php esc_html_e( 'Getting started', 'formsync-google-sheets-connector-contact-form-7' ); ?></h2>
		<ol class="skfss-steps">
			<li>
				<?php esc_html_e( 'Connect Google on the Google Connection screen.', 'formsync-google-sheets-connector-contact-form-7' ); ?>
				<?php if ( $view['connected'] ) : ?>
					<span class="skfss-ok">&#10003;</span>
				<?php endif; ?>
			</li>
			<li><?php esc_html_e( 'Share your spreadsheet with the service-account email (as Editor).', 'formsync-google-sheets-connector-contact-form-7' ); ?></li>
			<li><?php esc_html_e( 'Open a contact form, go to its "Google Sheets" tab, choose the spreadsheet and worksheet, and save the form.', 'formsync-google-sheets-connector-contact-form-7' ); ?></li>
		</ol>
		<?php if ( $view['cf7_active'] ) : ?>
			<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wpcf7' ) ); ?>"><?php esc_html_e( 'Go to Contact Forms', 'formsync-google-sheets-connector-contact-form-7' ); ?></a></p>
		<?php endif; ?>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Configured spreadsheets', 'formsync-google-sheets-connector-contact-form-7' ); ?></h2>
	<?php if ( empty( $view['spreadsheets'] ) ) : ?>
		<p><?php esc_html_e( 'No enabled form sends data to a spreadsheet yet.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
	<?php else : ?>
		<ul class="skfss-list">
			<?php foreach ( $view['spreadsheets'] as $skfss_sheet ) : ?>
				<li><a href="<?php echo esc_url( $skfss_sheet['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $skfss_sheet['name'] ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Recent synchronization errors', 'formsync-google-sheets-connector-contact-form-7' ); ?></h2>
	<?php if ( empty( $view['recent'] ) ) : ?>
		<p class="skfss-ok">&#10003; <?php esc_html_e( 'No errors recorded.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Form', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Message', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $view['recent'] as $skfss_log ) : ?>
					<tr>
						<td><?php echo esc_html( Admin::format_date( (string) $skfss_log->created_at ) ); ?></td>
						<td><?php echo esc_html( '' !== (string) $skfss_log->form_title ? (string) $skfss_log->form_title : ( (int) $skfss_log->form_id > 0 ? '#' . (int) $skfss_log->form_id : '—' ) ); ?></td>
						<td><?php echo esc_html( (string) $skfss_log->message ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><a href="<?php echo esc_url( Admin::page_url( Admin::PAGE_LOGS ) ); ?>"><?php esc_html_e( 'View Error Logs', 'formsync-google-sheets-connector-contact-form-7' ); ?></a></p>
	<?php endif; ?>
</div>
