<?php
namespace Aftercare\Reports;

use Aftercare\Core\Options;
use Aftercare\Core\Util;
use Aftercare\Incidents\Repository as IncidentRepository;
use Aftercare\Ledger\Repository as LedgerRepository;
use Aftercare\Vitals\SampleRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro: builds the monthly white-label client report — vitals versus previous
 * month, work performed (ledger), incidents caught and resolved.
 */
final class Builder {

	public function generate_draft( int $month, int $year ): int {
		$samples   = new SampleRepository();
		$ledger    = new LedgerRepository();
		$incidents = new IncidentRepository();
		$reports   = new Repository();

		$prev_ts    = strtotime( sprintf( '%04d-%02d-01', $year, $month ) . ' -1 month' );
		$prev_month = (int) gmdate( 'n', $prev_ts );
		$prev_year  = (int) gmdate( 'Y', $prev_ts );

		$primary_url = Options::tracked_urls()[0];

		$stats = array(
			'primary_url'     => $primary_url,
			'vitals'          => $samples->monthly_averages( $primary_url, $month, $year ),
			'vitals_previous' => $samples->monthly_averages( $primary_url, $prev_month, $prev_year ),
			'ledger_counts'   => $ledger->monthly_counts( $month, $year ),
			'ledger_events'   => $this->month_events( $ledger, $month, $year ),
			'incidents'       => $incidents->for_month( $month, $year ),
		);

		$branding = (array) Options::get( 'branding' );
		$html     = $this->render( $month, $year, $stats, $branding );

		return $reports->save_draft( $month, $year, $branding, $stats, $html );
	}

	/**
	 * Re-render an existing report (e.g. after the personal note changes).
	 *
	 * @param array<string, mixed> $report
	 */
	public function render_report( array $report ): string {
		$stats    = json_decode( (string) $report['summary_stats'], true ) ?: array();
		$branding = json_decode( (string) $report['branding'], true ) ?: array();
		return $this->render(
			(int) $report['period_month'],
			(int) $report['period_year'],
			$stats,
			$branding,
			(string) ( $report['personal_note'] ?? '' )
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function month_events( LedgerRepository $ledger, int $month, int $year ): array {
		$start = sprintf( '%04d-%02d-01', $year, $month );
		$end   = gmdate( 'Y-m-d', strtotime( $start . ' +1 month -1 day' ) );
		return $ledger->query(
			array(
				'from' => $start,
				'to'   => $end,
			),
			500
		);
	}

	/**
	 * @param array<string, mixed> $stats
	 * @param array<string, mixed> $branding
	 */
	private function render( int $month, int $year, array $stats, array $branding, string $personal_note = '' ): string {
		$aftercare_report = array(
			'month'         => $month,
			'year'          => $year,
			'stats'         => $stats,
			'branding'      => $branding,
			'personal_note' => $personal_note,
			'site_name'     => get_bloginfo( 'name' ),
			'site_url'      => home_url(),
		);
		ob_start();
		include AFTERCARE_DIR . 'templates/report-default.php';
		return (string) ob_get_clean();
	}

	/**
	 * Send a report to the configured client recipients.
	 *
	 * @param array<string, mixed> $report
	 */
	public function send( array $report ): bool {
		$recipients = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) Options::get( 'client_recipients' ) ) ?: array() ) );
		if ( empty( $recipients ) ) {
			return false;
		}

		$branding = (array) Options::get( 'branding' );
		$subject  = sprintf(
			/* translators: 1: site name, 2: month name, 3: year */
			__( '%1$s — site care report for %2$s %3$s', 'aftercare' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			date_i18n( 'F', mktime( 0, 0, 0, (int) $report['period_month'], 1 ) ),
			(int) $report['period_year']
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$sender  = sanitize_text_field( (string) ( $branding['sender_name'] ?? '' ) );
		$reply   = sanitize_email( (string) ( $branding['reply_to'] ?? '' ) );
		if ( $reply ) {
			$headers[] = $sender ? "Reply-To: {$sender} <{$reply}>" : "Reply-To: {$reply}";
		}

		$html = $this->render_report( $report );
		$sent = true;
		foreach ( $recipients as $recipient ) {
			$sent = wp_mail( $recipient, $subject, $html, $headers ) && $sent;
		}

		if ( $sent ) {
			( new Repository() )->mark_sent( (int) $report['id'] );
		}
		return $sent;
	}
}
