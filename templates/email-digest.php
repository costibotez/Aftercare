<?php
/**
 * Weekly digest email. Expects $aftercare_digest (array with vitals,
 * ledger_counts, incidents, site_name).
 *
 * @var array<string, mixed> $aftercare_digest
 */

use Aftercare\Admin\LedgerPage;
use Aftercare\Core\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aftercare_status_colors = array(
	'pass'  => '#0f766e',
	'warn'  => '#d97706',
	'fail'  => '#b91c1c',
	'empty' => '#6b7280',
);
$aftercare_status_labels = array(
	'pass'  => __( 'Pass', 'aftercare' ),
	'warn'  => __( 'Warn', 'aftercare' ),
	'fail'  => __( 'Fail', 'aftercare' ),
	'empty' => __( 'No data', 'aftercare' ),
);
$aftercare_total_changes = array_sum( (array) $aftercare_digest['ledger_counts'] );
?>
<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:560px;margin:0 auto;color:#1f2937;">
	<div style="background:#0f766e;color:#ffffff;padding:16px 24px;border-radius:8px 8px 0 0;">
		<h1 style="margin:0;font-size:18px;">
			<?php
			printf(
				/* translators: %s: site name */
				esc_html__( '%s — weekly site care digest', 'aftercare' ),
				esc_html( (string) $aftercare_digest['site_name'] )
			);
			?>
		</h1>
	</div>
	<div style="border:1px solid #e5e7eb;border-top:none;padding:24px;border-radius:0 0 8px 8px;">

		<h2 style="font-size:14px;text-transform:uppercase;letter-spacing:0.05em;color:#0f766e;margin-top:0;"><?php esc_html_e( 'Core Web Vitals', 'aftercare' ); ?></h2>
		<table style="width:100%;border-collapse:collapse;" role="presentation">
			<?php foreach ( (array) $aftercare_digest['vitals'] as $aftercare_row ) : ?>
				<tr>
					<td style="padding:6px 0;border-bottom:1px solid #f3f4f6;"><strong><?php echo esc_html( (string) $aftercare_row['metric'] ); ?></strong></td>
					<td style="padding:6px 0;border-bottom:1px solid #f3f4f6;">
						<?php echo null !== $aftercare_row['value'] ? esc_html( Util::format_metric( (string) $aftercare_row['metric'], (float) $aftercare_row['value'] ) ) : '&mdash;'; ?>
					</td>
					<td style="padding:6px 0;border-bottom:1px solid #f3f4f6;text-align:right;">
						<span style="color:<?php echo esc_attr( $aftercare_status_colors[ $aftercare_row['status'] ] ); ?>;font-weight:700;font-size:12px;text-transform:uppercase;">
							<?php echo esc_html( $aftercare_status_labels[ $aftercare_row['status'] ] ); ?>
						</span>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>

		<h2 style="font-size:14px;text-transform:uppercase;letter-spacing:0.05em;color:#0f766e;"><?php esc_html_e( 'Changes this week', 'aftercare' ); ?></h2>
		<?php if ( 0 === $aftercare_total_changes ) : ?>
			<p style="color:#6b7280;"><?php esc_html_e( 'No changes were recorded this week.', 'aftercare' ); ?></p>
		<?php else : ?>
			<table style="width:100%;border-collapse:collapse;" role="presentation">
				<?php foreach ( (array) $aftercare_digest['ledger_counts'] as $aftercare_type => $aftercare_count ) : ?>
					<tr>
						<td style="padding:4px 0;"><?php echo esc_html( LedgerPage::type_label( (string) $aftercare_type ) ); ?></td>
						<td style="padding:4px 0;text-align:right;font-weight:700;"><?php echo (int) $aftercare_count; ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		<?php endif; ?>

		<h2 style="font-size:14px;text-transform:uppercase;letter-spacing:0.05em;color:#0f766e;"><?php esc_html_e( 'Incidents this week', 'aftercare' ); ?></h2>
		<?php if ( empty( $aftercare_digest['incidents'] ) ) : ?>
			<p style="color:#0f766e;font-weight:600;"><?php esc_html_e( 'No performance regressions this week.', 'aftercare' ); ?></p>
		<?php else : ?>
			<ul style="padding-left:18px;">
				<?php foreach ( (array) $aftercare_digest['incidents'] as $aftercare_incident ) : ?>
					<li style="margin-bottom:6px;">
						<strong><?php echo esc_html( (string) $aftercare_incident['metric'] ); ?></strong>
						— <?php echo esc_html( Util::format_metric( (string) $aftercare_incident['metric'], (float) $aftercare_incident['breach_p75'] ) ); ?>
						<span style="color:#6b7280;">(<?php echo esc_html( (string) $aftercare_incident['status'] ); ?>)</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<p style="margin-bottom:0;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=aftercare' ) ); ?>" style="display:inline-block;background:#0f766e;color:#ffffff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600;">
				<?php esc_html_e( 'Open Aftercare', 'aftercare' ); ?>
			</a>
		</p>
		<p style="color:#6b7280;font-size:12px;margin-bottom:0;">
			<?php esc_html_e( 'You receive this weekly digest because Aftercare is active on your site. Disable it under Aftercare → Settings → Notifications.', 'aftercare' ); ?>
		</p>
	</div>
</div>
