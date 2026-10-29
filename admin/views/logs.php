<?php
/**
 * Error Logs screen. Entries contain diagnostic details only (form, spreadsheet,
 * error type, HTTP status, field names); submitted values and credentials are
 * never logged.
 *
 * @package SKFreelancers\SheetsSync
 *
 * @var array $view View data prepared by Admin::render_logs().
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

$skfss_base_args = '' !== $view['level'] ? array( 'level' => $view['level'] ) : array();
?>
<div class="wrap skfss-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Error Logs', 'formsync-google-sheets-connector-contact-form-7' ); ?></h1>
	<hr class="wp-header-end" />

	<?php if ( $view['debug'] ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'Debug logging is on, so technical events are recorded too. Turn it off in Settings when you are done troubleshooting.', 'formsync-google-sheets-connector-contact-form-7' ); ?></p></div>
	<?php endif; ?>

	<div class="tablenav top">
		<form method="get" class="alignleft actions">
			<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE_LOGS ); ?>" />
			<label for="skfss-level" class="screen-reader-text"><?php esc_html_e( 'Filter by level', 'formsync-google-sheets-connector-contact-form-7' ); ?></label>
			<select id="skfss-level" name="level">
				<?php foreach ( $view['levels'] as $skfss_value => $skfss_label ) : ?>
					<option value="<?php echo esc_attr( $skfss_value ); ?>" <?php selected( $view['level'], $skfss_value ); ?>><?php echo esc_html( $skfss_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'formsync-google-sheets-connector-contact-form-7' ); ?></button>
		</form>

		<?php if ( $view['total'] > 0 ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="alignleft actions skfss-clear-form">
				<input type="hidden" name="action" value="skfss_clear_logs" />
				<?php wp_nonce_field( 'skfss_clear_logs' ); ?>
				<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Clear Logs', 'formsync-google-sheets-connector-contact-form-7' ); ?></button>
			</form>
		<?php endif; ?>

		<div class="tablenav-pages">
			<span class="displaying-num">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of entries. */
						_n( '%s entry', '%s entries', $view['total'], 'formsync-google-sheets-connector-contact-form-7' ),
						number_format_i18n( $view['total'] )
					)
				);
				?>
			</span>
			<?php
			if ( $view['pages'] > 1 ) {
				echo wp_kses_post(
					(string) paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%', Admin::page_url( Admin::PAGE_LOGS, $skfss_base_args ) ),
							'format'    => '',
							'current'   => $view['paged'],
							'total'     => $view['pages'],
							'prev_text' => '&lsaquo;',
							'next_text' => '&rsaquo;',
						)
					)
				);
			}
			?>
		</div>
		<br class="clear" />
	</div>

	<table class="widefat striped skfss-logs">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Date', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Level', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Form', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Spreadsheet / Worksheet', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Error type', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
				<th scope="col"><?php esc_html_e( 'HTTP', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Message', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Details', 'formsync-google-sheets-connector-contact-form-7' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $view['logs'] ) ) : ?>
				<tr><td colspan="8"><?php esc_html_e( 'No log entries.', 'formsync-google-sheets-connector-contact-form-7' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $view['logs'] as $skfss_log ) : ?>
				<?php
				$skfss_context = json_decode( (string) $skfss_log->context, true );
				$skfss_context = is_array( $skfss_context ) ? $skfss_context : array();
				unset( $skfss_context['form_id'], $skfss_context['form_title'], $skfss_context['spreadsheet_id'], $skfss_context['worksheet'], $skfss_context['http_status'] );
				$skfss_form_id = (int) $skfss_log->form_id;
				?>
				<tr class="skfss-level-<?php echo esc_attr( (string) $skfss_log->level ); ?>">
					<td><?php echo esc_html( Admin::format_date( (string) $skfss_log->created_at ) ); ?></td>
					<td><span class="skfss-level"><?php echo esc_html( (string) $skfss_log->level ); ?></span></td>
					<td>
						<?php if ( $skfss_form_id > 0 ) : ?>
							<?php echo esc_html( '' !== (string) $skfss_log->form_title ? (string) $skfss_log->form_title : '#' . $skfss_form_id ); ?>
							<br /><span class="skfss-muted skfss-small">
							<?php
							/* translators: %d: form ID. */
							echo esc_html( sprintf( __( 'Form ID %d', 'formsync-google-sheets-connector-contact-form-7' ), $skfss_form_id ) );
							?>
							</span>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td>
						<?php if ( '' !== (string) $skfss_log->spreadsheet_id ) : ?>
							<code class="skfss-small"><?php echo esc_html( (string) $skfss_log->spreadsheet_id ); ?></code>
						<?php endif; ?>
						<?php if ( '' !== (string) $skfss_log->worksheet ) : ?>
							<br /><?php echo esc_html( (string) $skfss_log->worksheet ); ?>
						<?php endif; ?>
					</td>
					<td><code><?php echo esc_html( (string) $skfss_log->error_type ); ?></code></td>
					<td><?php echo (int) $skfss_log->http_status > 0 ? esc_html( (string) (int) $skfss_log->http_status ) : '&mdash;'; ?></td>
					<td><?php echo esc_html( (string) $skfss_log->message ); ?></td>
					<td class="skfss-context">
						<?php foreach ( $skfss_context as $skfss_key => $skfss_value ) : ?>
							<div><strong><?php echo esc_html( (string) $skfss_key ); ?>:</strong> <?php echo esc_html( is_array( $skfss_value ) ? implode( ', ', array_map( 'strval', $skfss_value ) ) : (string) $skfss_value ); ?></div>
						<?php endforeach; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
