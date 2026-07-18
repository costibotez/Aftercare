<?php
/**
 * White-label monthly client report (Pro). Print-friendly standalone HTML.
 * Expects $aftercare_report (array with month, year, stats, branding,
 * personal_note, site_name, site_url).
 *
 * @var array<string, mixed> $aftercare_report
 */

use Aftercare\Core\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aftercare_stats    = (array) $aftercare_report['stats'];
$aftercare_branding = (array) $aftercare_report['branding'];
$aftercare_accent   = sanitize_hex_color( (string) ( $aftercare_branding['accent'] ?? '' ) );
$aftercare_accent   = $aftercare_accent ? $aftercare_accent : '#0f766e';
$aftercare_period   = date_i18n( 'F Y', mktime( 0, 0, 0, (int) $aftercare_report['month'], 1, (int) $aftercare_report['year'] ) );
$aftercare_vitals   = (array) ( $aftercare_stats['vitals'] ?? array() );
$aftercare_previous = (array) ( $aftercare_stats['vitals_previous'] ?? array() );
$aftercare_events   = (array) ( $aftercare_stats['ledger_events'] ?? array() );
$aftercare_incs     = (array) ( $aftercare_stats['incidents'] ?? array() );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?php echo esc_html( $aftercare_report['site_name'] . ' — ' . $aftercare_period ); ?></title>
<style>
	body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1f2937; margin: 0; background: #f9fafb; }
	.page { max-width: 760px; margin: 24px auto; background: #fff; border-radius: 8px; padding: 40px; }
	header { border-bottom: 3px solid <?php echo esc_html( $aftercare_accent ); ?>; padding-bottom: 16px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; }
	header img { max-height: 48px; }
	h1 { font-size: 22px; margin: 0; }
	h2 { font-size: 16px; color: <?php echo esc_html( $aftercare_accent ); ?>; margin-top: 32px; text-transform: uppercase; letter-spacing: 0.05em; }
	.subtitle { color: #6b7280; margin: 4px 0 0; }
	table { width: 100%; border-collapse: collapse; margin-top: 12px; }
	th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #e5e7eb; font-size: 14px; }
	th { color: #6b7280; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; }
	.delta-good { color: #0f766e; font-weight: 600; }
	.delta-bad { color: #b91c1c; font-weight: 600; }
	.note { background: #f0fdfa; border-left: 3px solid <?php echo esc_html( $aftercare_accent ); ?>; padding: 12px 16px; border-radius: 0 6px 6px 0; }
	footer { margin-top: 40px; padding-top: 16px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 12px; }
	ul.events { padding-left: 18px; }
	ul.events li { margin-bottom: 6px; font-size: 14px; }
	.print-hint { text-align: center; color: #6b7280; font-size: 12px; }
	@media print { body { background: #fff; } .page { margin: 0; border-radius: 0; padding: 24px; } .print-hint { display: none; } }
</style>
</head>
<body>
<p class="print-hint"><?php esc_html_e( 'Use your browser\'s Print dialog to save this report as a PDF.', 'aftercare' ); ?></p>
<div class="page">
	<header>
		<div>
			<h1><?php echo esc_html( (string) $aftercare_report['site_name'] ); ?></h1>
			<p class="subtitle">
				<?php
				printf(
					/* translators: %s: month and year */
					esc_html__( 'Site care report — %s', 'aftercare' ),
					esc_html( $aftercare_period )
				);
				?>
			</p>
		</div>
		<?php if ( ! empty( $aftercare_branding['logo_url'] ) ) : ?>
			<img src="<?php echo esc_url( (string) $aftercare_branding['logo_url'] ); ?>" alt="" />
		<?php endif; ?>
	</header>

	<?php if ( ! empty( $aftercare_report['personal_note'] ) ) : ?>
		<div class="note"><?php echo wp_kses_post( wpautop( (string) $aftercare_report['personal_note'] ) ); ?></div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Core Web Vitals', 'aftercare' ); ?></h2>
	<table>
		<thead><tr>
			<th><?php esc_html_e( 'Metric', 'aftercare' ); ?></th>
			<th><?php esc_html_e( 'This month (avg p75)', 'aftercare' ); ?></th>
			<th><?php esc_html_e( 'Previous month', 'aftercare' ); ?></th>
			<th><?php esc_html_e( 'Change', 'aftercare' ); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ( array( 'LCP', 'INP', 'CLS', 'TTFB' ) as $aftercare_metric ) : ?>
			<?php
			$aftercare_now  = isset( $aftercare_vitals[ $aftercare_metric ] ) ? (float) $aftercare_vitals[ $aftercare_metric ] : null;
			$aftercare_prev = isset( $aftercare_previous[ $aftercare_metric ] ) ? (float) $aftercare_previous[ $aftercare_metric ] : null;
			?>
			<tr>
				<td><strong><?php echo esc_html( $aftercare_metric ); ?></strong></td>
				<td><?php echo null !== $aftercare_now ? esc_html( Util::format_metric( $aftercare_metric, $aftercare_now ) ) : '&mdash;'; ?></td>
				<td><?php echo null !== $aftercare_prev ? esc_html( Util::format_metric( $aftercare_metric, $aftercare_prev ) ) : '&mdash;'; ?></td>
				<td>
					<?php
					if ( null !== $aftercare_now && null !== $aftercare_prev && $aftercare_prev > 0 ) {
						$aftercare_delta = ( $aftercare_now - $aftercare_prev ) / $aftercare_prev * 100;
						$aftercare_class = $aftercare_delta <= 0 ? 'delta-good' : 'delta-bad';
						echo '<span class="' . esc_attr( $aftercare_class ) . '">' . esc_html( ( $aftercare_delta > 0 ? '+' : '' ) . number_format_i18n( $aftercare_delta, 1 ) . '%' ) . '</span>';
					} else {
						echo '&mdash;';
					}
					?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p class="subtitle" style="font-size:12px;">
		<?php
		printf(
			/* translators: %s: URL */
			esc_html__( 'Field data (p75) for %s. Lower is better.', 'aftercare' ),
			esc_html( (string) ( $aftercare_stats['primary_url'] ?? $aftercare_report['site_url'] ) )
		);
		?>
	</p>

	<h2><?php esc_html_e( 'Regressions caught this month', 'aftercare' ); ?></h2>
	<?php if ( empty( $aftercare_incs ) ) : ?>
		<p><?php esc_html_e( 'No performance regressions were detected. All metrics stayed within budget.', 'aftercare' ); ?></p>
	<?php else : ?>
		<table>
			<thead><tr>
				<th><?php esc_html_e( 'Metric', 'aftercare' ); ?></th>
				<th><?php esc_html_e( 'URL', 'aftercare' ); ?></th>
				<th><?php esc_html_e( 'Peak', 'aftercare' ); ?></th>
				<th><?php esc_html_e( 'Status', 'aftercare' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $aftercare_incs as $aftercare_incident ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $aftercare_incident['metric'] ); ?></td>
					<td><?php echo esc_html( (string) $aftercare_incident['url'] ); ?></td>
					<td><?php echo esc_html( Util::format_metric( (string) $aftercare_incident['metric'], (float) $aftercare_incident['breach_p75'] ) ); ?></td>
					<td><?php echo esc_html( ucfirst( (string) $aftercare_incident['status'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Work performed this month', 'aftercare' ); ?></h2>
	<?php if ( empty( $aftercare_events ) ) : ?>
		<p><?php esc_html_e( 'No changes were recorded this month.', 'aftercare' ); ?></p>
	<?php else : ?>
		<ul class="events">
			<?php foreach ( array_slice( $aftercare_events, 0, 100 ) as $aftercare_event ) : ?>
				<li>
					<?php echo esc_html( (string) $aftercare_event['summary'] ); ?>
					<span style="color:#6b7280;">— <?php echo esc_html( gmdate( 'j M', strtotime( (string) $aftercare_event['occurred_at'] ) ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<footer>
		<?php if ( ! empty( $aftercare_branding['footer_text'] ) ) : ?>
			<p><?php echo esc_html( (string) $aftercare_branding['footer_text'] ); ?></p>
		<?php endif; ?>
		<p><?php echo esc_html( (string) $aftercare_report['site_url'] ); ?></p>
	</footer>
</div>
</body>
</html>
