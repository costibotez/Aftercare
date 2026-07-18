<?php
/**
 * Incident alert email. Expects $aftercare_incident (array).
 *
 * @var array<string, mixed> $aftercare_incident
 */

use Aftercare\Core\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aftercare_metric   = (string) $aftercare_incident['metric'];
$aftercare_site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
$aftercare_detail   = admin_url( 'admin.php?page=aftercare-incidents&incident=' . (int) $aftercare_incident['id'] );
$aftercare_baseline = null !== $aftercare_incident['baseline_p75']
	? Util::format_metric( $aftercare_metric, (float) $aftercare_incident['baseline_p75'] )
	: __( 'n/a', 'aftercare' );
?>
<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:560px;margin:0 auto;color:#1f2937;">
	<div style="background:#0f766e;color:#ffffff;padding:16px 24px;border-radius:8px 8px 0 0;">
		<h1 style="margin:0;font-size:18px;"><?php esc_html_e( 'Performance regression detected', 'aftercare' ); ?></h1>
	</div>
	<div style="border:1px solid #e5e7eb;border-top:none;padding:24px;border-radius:0 0 8px 8px;">
		<p style="margin-top:0;">
			<?php
			printf(
				/* translators: 1: metric, 2: URL, 3: site name */
				esc_html__( '%1$s breached its performance budget on %2$s (%3$s).', 'aftercare' ),
				'<strong>' . esc_html( $aftercare_metric ) . '</strong>',
				'<a href="' . esc_url( (string) $aftercare_incident['url'] ) . '">' . esc_html( (string) $aftercare_incident['url'] ) . '</a>',
				esc_html( $aftercare_site )
			);
			?>
		</p>
		<table style="width:100%;border-collapse:collapse;margin:16px 0;" role="presentation">
			<tr>
				<td style="padding:8px 12px;background:#fee2e2;border-radius:6px 0 0 6px;">
					<div style="font-size:11px;color:#6b7280;text-transform:uppercase;"><?php esc_html_e( 'Measured p75', 'aftercare' ); ?></div>
					<div style="font-size:20px;font-weight:700;color:#b91c1c;"><?php echo esc_html( Util::format_metric( $aftercare_metric, (float) $aftercare_incident['breach_p75'] ) ); ?></div>
				</td>
				<td style="padding:8px 12px;background:#f9fafb;">
					<div style="font-size:11px;color:#6b7280;text-transform:uppercase;"><?php esc_html_e( 'Budget', 'aftercare' ); ?></div>
					<div style="font-size:20px;font-weight:700;"><?php echo esc_html( Util::format_metric( $aftercare_metric, (float) $aftercare_incident['budget_value'] ) ); ?></div>
				</td>
				<td style="padding:8px 12px;background:#f9fafb;border-radius:0 6px 6px 0;">
					<div style="font-size:11px;color:#6b7280;text-transform:uppercase;"><?php esc_html_e( '28-day baseline', 'aftercare' ); ?></div>
					<div style="font-size:20px;font-weight:700;"><?php echo esc_html( $aftercare_baseline ); ?></div>
				</td>
			</tr>
		</table>
		<p>
			<a href="<?php echo esc_url( $aftercare_detail ); ?>" style="display:inline-block;background:#0f766e;color:#ffffff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600;">
				<?php esc_html_e( 'View incident and recent changes', 'aftercare' ); ?>
			</a>
		</p>
		<p style="color:#6b7280;font-size:12px;margin-bottom:0;">
			<?php esc_html_e( 'Sent by Aftercare. The incident page lists every site change from the 72 hours before the regression.', 'aftercare' ); ?>
		</p>
	</div>
</div>
