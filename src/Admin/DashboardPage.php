<?php
namespace Aftercare\Admin;

use Aftercare\Core\Options;
use Aftercare\Core\Util;
use Aftercare\Incidents\Repository as IncidentRepository;
use Aftercare\Ledger\Repository as LedgerRepository;
use Aftercare\Vitals\LabClient;
use Aftercare\Vitals\SampleRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vitals cards with sparklines and status pills, recent ledger events and the
 * open incidents count.
 */
final class DashboardPage {

	public function render(): void {
		$samples   = new SampleRepository();
		$ledger    = new LedgerRepository();
		$incidents = new IncidentRepository();

		$primary_url = Options::tracked_urls()[0];
		$open_count  = $incidents->count_open();

		Menu::header( __( 'Aftercare — Dashboard', 'aftercare' ) );

		if ( isset( $_GET['aftercare_ran'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Daily checks ran. Vitals, breach detection and retention are up to date.', 'aftercare' ) . '</p></div>';
		}

		if ( '' === (string) Options::get( 'api_key' ) ) {
			echo '<div class="notice notice-info"><p>';
			printf(
				/* translators: %s: settings page URL */
				wp_kses_post( __( 'Add your Google API key in <a href="%s">Aftercare Settings</a> to start pulling Core Web Vitals from the Chrome UX Report. First data appears within minutes.', 'aftercare' ) ),
				esc_url( admin_url( 'admin.php?page=aftercare-settings' ) )
			);
			echo '</p></div>';
		}

		echo '<p class="aftercare-subtle">';
		printf(
			/* translators: %s: URL being monitored */
			esc_html__( 'Monitoring %s (p75, field data)', 'aftercare' ),
			esc_html( $primary_url )
		);
		echo '</p>';

		echo '<div class="aftercare-cards">';
		foreach ( Options::METRICS as $metric ) {
			$this->render_metric_card( $samples->series( $primary_url, $metric, 30 ), $primary_url, $metric );
		}
		echo '</div>';

		if ( LabClient::enabled() ) {
			$this->render_lab_section( $samples, $primary_url );
		}

		echo '<div class="aftercare-columns">';

		// Open incidents summary.
		echo '<div class="aftercare-panel">';
		echo '<h2>' . esc_html__( 'Incidents', 'aftercare' ) . '</h2>';
		if ( $open_count > 0 ) {
			echo '<p class="aftercare-incident-count aftercare-fail">';
			printf(
				/* translators: %d: number of open incidents */
				esc_html( _n( '%d open incident needs attention.', '%d open incidents need attention.', $open_count, 'aftercare' ) ),
				(int) $open_count
			);
			echo '</p>';
		} else {
			echo '<p class="aftercare-incident-count aftercare-pass">' . esc_html__( 'No open incidents. All metrics within budget.', 'aftercare' ) . '</p>';
		}
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=aftercare-incidents' ) ) . '">' . esc_html__( 'View incidents', 'aftercare' ) . '</a></p>';

		echo '<h2>' . esc_html__( 'Maintenance', 'aftercare' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="aftercare_run_now" />';
		wp_nonce_field( 'aftercare_run_now' );
		echo '<button type="submit" class="button">' . esc_html__( 'Run daily checks now', 'aftercare' ) . '</button>';
		echo '</form>';
		echo '</div>';

		// Recent ledger events.
		echo '<div class="aftercare-panel">';
		echo '<h2>' . esc_html__( 'Recent changes', 'aftercare' ) . '</h2>';
		$events = $ledger->query( array(), 10 );
		if ( empty( $events ) ) {
			echo '<p class="aftercare-subtle">' . esc_html__( 'No changes recorded yet. Plugin updates, theme switches, publishes and settings changes will appear here within seconds of happening.', 'aftercare' ) . '</p>';
		} else {
			echo '<ul class="aftercare-timeline">';
			foreach ( $events as $event ) {
				LedgerPage::render_event_row( $event );
			}
			echo '</ul>';
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=aftercare-ledger' ) ) . '">' . esc_html__( 'Full ledger', 'aftercare' ) . '</a></p>';
		}
		echo '</div>';

		echo '</div>';

		Menu::footer();
	}

	/**
	 * Lab cards sit under their own heading and are never merged into the
	 * field cards above: a Lighthouse run is a synthetic load, not a visitor.
	 */
	private function render_lab_section( SampleRepository $samples, string $url ): void {
		echo '<h2>' . esc_html__( 'Lab checks', 'aftercare' ) . '</h2>';
		echo '<p class="aftercare-subtle">' . esc_html__( 'Lighthouse, mobile profile, median of 3 runs per day through the PageSpeed Insights API. Useful when field data is thin; not a measure of real visitors.', 'aftercare' ) . '</p>';

		$error = get_option( LabClient::ERROR_OPT );
		if ( is_array( $error ) && ! empty( $error['message'] ) ) {
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				/* translators: 1: URL, 2: error message from the PageSpeed Insights API */
				esc_html__( 'The last lab check of %1$s failed: %2$s', 'aftercare' ),
				esc_html( (string) $error['url'] ),
				esc_html( (string) $error['message'] )
			);
			echo '</p></div>';
		}

		echo '<div class="aftercare-cards">';
		foreach ( Options::LAB_METRICS as $metric ) {
			$this->render_metric_card( $samples->lab_series( $url, $metric, 30 ), $url, $metric );
		}
		echo '</div>';
	}

	/**
	 * @param array<int, array{day: string, value: float}> $series
	 */
	private function render_metric_card( array $series, string $url, string $metric ): void {
		$latest = ! empty( $series ) ? end( $series )['value'] : null;
		$budget = Options::budget( $metric, $url );
		$status = \Aftercare\Vitals\Status::evaluate( $latest, $budget );

		$labels = array(
			'pass'  => __( 'Pass', 'aftercare' ),
			'warn'  => __( 'Warn', 'aftercare' ),
			'fail'  => __( 'Fail', 'aftercare' ),
			'empty' => __( 'No data', 'aftercare' ),
		);

		echo '<div class="aftercare-card">';
		echo '<div class="aftercare-card-head">';
		echo '<span class="aftercare-metric-name">' . esc_html( $metric ) . '</span>';
		echo '<span class="aftercare-pill aftercare-pill-' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ) . '</span>';
		echo '</div>';
		echo '<div class="aftercare-card-value">' . ( null !== $latest ? esc_html( Util::format_metric( $metric, $latest ) ) : '&mdash;' ) . '</div>';
		echo '<div class="aftercare-card-budget">';
		printf(
			/* translators: %s: budget value */
			esc_html__( 'Budget %s', 'aftercare' ),
			esc_html( Util::format_metric( $metric, $budget ) )
		);
		echo '</div>';
		echo '<div class="aftercare-sparkline" data-sparkline="' . esc_attr( (string) wp_json_encode( array_column( $series, 'value' ) ) ) . '" data-budget="' . esc_attr( (string) $budget ) . '"></div>';
		echo '</div>';
	}
}
