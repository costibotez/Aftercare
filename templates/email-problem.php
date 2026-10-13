<?php
/**
 * Alert email for incidents that are not about vitals: a PHP fatal error or
 * a page that failed the availability check. Expects $aftercare_incident.
 *
 * @var array<string, mixed> $aftercare_incident
 */

use Aftercare\Core\Util;
use Aftercare\Errors\Repository as ErrorRepository;
use Aftercare\Incidents\Repository as IncidentRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aftercare_is_error = IncidentRepository::SOURCE_ERROR === ( $aftercare_incident['sample_source'] ?? '' );
$aftercare_site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
$aftercare_detail   = admin_url( 'admin.php?page=aftercare-incidents&incident=' . (int) $aftercare_incident['id'] );
$aftercare_error    = $aftercare_is_error ? ( new ErrorRepository() )->find_by_location( (string) $aftercare_incident['url'] ) : null;
?>
<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:560px;margin:0 auto;color:#1f2937;">
	<div style="background:#b91c1c;color:#ffffff;padding:16px 24px;border-radius:8px 8px 0 0;">
		<h1 style="margin:0;font-size:18px;">
			<?php $aftercare_is_error ? esc_html_e( 'PHP fatal error detected', 'aftercare' ) : esc_html_e( 'Page unavailable', 'aftercare' ); ?>
		</h1>
	</div>
	<div style="border:1px solid #e5e7eb;border-top:none;padding:24px;border-radius:0 0 8px 8px;">
		<?php if ( $aftercare_is_error ) : ?>
			<p style="margin-top:0;">
				<?php
				printf(
					/* translators: 1: site name, 2: file and line */
					esc_html__( 'A PHP fatal error broke a request on %1$s, in %2$s:', 'aftercare' ),
					esc_html( $aftercare_site ),
					'<code>' . esc_html( (string) $aftercare_incident['url'] ) . '</code>'
				);
				?>
			</p>
			<?php if ( $aftercare_error ) : ?>
				<p style="background:#fef2f2;padding:12px;border-radius:6px;font-family:monospace;font-size:13px;"><?php echo esc_html( (string) $aftercare_error['message'] ); ?></p>
				<p>
					<?php
					printf(
						/* translators: %s: plugin, theme or core */
						esc_html__( 'Likely source: %s', 'aftercare' ),
						'<strong>' . esc_html( ErrorRepository::culprit_label( (string) $aftercare_error['culprit'] ) ) . '</strong>'
					);
					?>
				</p>
			<?php endif; ?>
		<?php else : ?>
			<p style="margin-top:0;">
				<?php
				printf(
					/* translators: 1: URL, 2: site name, 3: HTTP status or "no response" */
					esc_html__( '%1$s on %2$s failed two availability checks in a row (%3$s).', 'aftercare' ),
					'<a href="' . esc_url( (string) $aftercare_incident['url'] ) . '">' . esc_html( (string) $aftercare_incident['url'] ) . '</a>',
					esc_html( $aftercare_site ),
					esc_html( Util::format_metric( 'HTTP', (float) $aftercare_incident['breach_p75'] ) )
				);
				?>
			</p>
		<?php endif; ?>
		<p>
			<a href="<?php echo esc_url( $aftercare_detail ); ?>" style="display:inline-block;background:#0f766e;color:#ffffff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600;">
				<?php esc_html_e( 'View incident and recent changes', 'aftercare' ); ?>
			</a>
		</p>
		<p style="color:#6b7280;font-size:12px;margin-bottom:0;">
			<?php esc_html_e( 'Sent by Aftercare. The incident page lists every site change from the 72 hours before the problem started.', 'aftercare' ); ?>
		</p>
	</div>
</div>
