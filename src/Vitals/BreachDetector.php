<?php
namespace Aftercare\Vitals;

use Aftercare\Core\Options;
use Aftercare\Core\Util;
use Aftercare\Incidents\Attribution;
use Aftercare\Incidents\Repository as IncidentRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field data: compares yesterday's p75 against the budget and the 28-day
 * baseline. A budget breach or a 20% regression against baseline opens an
 * incident; recovery auto-resolves it.
 *
 * Lab data: a fresh Lighthouse reading is judged against the lab baseline
 * only, and must clear both the 20% factor and a minimum absolute change. A
 * throttled mobile Lighthouse run is routinely slower than the field budgets,
 * so a budget rule would keep lab incidents open on most sites for good;
 * what lab data shows reliably is change.
 */
final class BreachDetector {

	private const REGRESSION_FACTOR = 1.2;

	/**
	 * Days of same-source history required before the baseline comparison is
	 * trusted. When CrUX flips a thin URL-level record to origin-level (or
	 * back), the new source starts with no history of its own; comparing the
	 * first origin-level reading against a URL-level baseline reports a
	 * regression that never happened. Budget breaches are absolute and still
	 * fire from day one.
	 */
	private const MIN_BASELINE_DAYS = 7;

	/**
	 * Smallest lab change worth an incident, per metric. Below these a 20%
	 * swing is ordinary Lighthouse noise (a 100 ms TBT page reading 130 ms).
	 */
	private const LAB_MIN_DELTA = array(
		'LCP'  => 300.0,  // ms
		'TBT'  => 100.0,  // ms
		'CLS'  => 0.05,   // score
		'TTFB' => 150.0,  // ms
	);

	private IncidentRepository $incidents;

	public function __construct( private SampleRepository $samples ) {
		$this->incidents = new IncidentRepository();
	}

	public function run(): void {
		$yesterday      = Util::days_ago( 1 );
		$baseline_start = Util::days_ago( 29 );

		foreach ( Options::tracked_urls() as $url ) {
			foreach ( Options::METRICS as $metric ) {
				$sample = $this->samples->latest_for_day( $url, $metric, $yesterday );
				if ( null === $sample ) {
					continue;
				}
				$p75 = $sample['value'];

				$budget = Options::budget( $metric, $url );

				// Baseline is built from the same source as the reading, so a
				// URL-level p75 is never held against an origin-level average.
				$history       = $this->samples->baseline( $url, $metric, $baseline_start, $yesterday, $sample['source'] );
				$comparable    = null !== $history && $history['days'] >= self::MIN_BASELINE_DAYS;
				$baseline      = $comparable ? $history['value'] : null;

				$over_budget   = $budget > 0 && $p75 > $budget;
				$over_baseline = null !== $baseline && $baseline > 0 && $p75 > $baseline * self::REGRESSION_FACTOR;

				$this->apply( $url, $metric, $p75, $baseline, $budget, $over_budget || $over_baseline, IncidentRepository::SOURCE_FIELD );
			}
		}
	}

	/**
	 * Judge the lab reading just stored for one URL. Called right after a lab
	 * check, so a regression opens an incident the same day it is measured.
	 * The baseline stops at the start of today: today's readings are what is
	 * being judged.
	 */
	public function run_lab( string $url ): void {
		$today          = gmdate( 'Y-m-d' );
		$baseline_start = Util::days_ago( 28 );

		foreach ( Options::LAB_METRICS as $metric ) {
			$series = $this->samples->lab_series( $url, $metric, 0 );
			if ( empty( $series ) ) {
				continue;
			}
			$value = end( $series )['value'];

			$history = $this->samples->baseline( $url, $metric, $baseline_start, $today, SampleRepository::SOURCE_LAB );
			if ( null === $history || $history['days'] < self::MIN_BASELINE_DAYS || $history['value'] <= 0 ) {
				continue;
			}
			$baseline  = $history['value'];
			$regressed = $value > $baseline * self::REGRESSION_FACTOR
				&& ( $value - $baseline ) >= self::LAB_MIN_DELTA[ $metric ];

			$this->apply( $url, $metric, $value, $baseline, Options::budget( $metric, $url ), $regressed, IncidentRepository::SOURCE_LAB );
		}
	}

	/**
	 * Open an incident for a new breach, or resolve the open one on recovery.
	 */
	private function apply( string $url, string $metric, float $value, ?float $baseline, float $budget, bool $breached, string $source ): void {
		$open_incident = $this->incidents->find_open( $url, $metric, $source );

		if ( $breached ) {
			if ( $open_incident ) {
				return; // Already tracking this regression.
			}
			$incident_id = $this->incidents->open( $url, $metric, $baseline, $value, $budget, $source );
			if ( $incident_id ) {
				// The attribution engine ships only in the premium build; the
				// free build stores no ranked causes.
				if ( class_exists( Attribution::class ) ) {
					$causes = ( new Attribution() )->rank( $url, Util::now() );
					$this->incidents->set_causes( $incident_id, $causes );
				}

				$incident = $this->incidents->find( $incident_id );

				/**
				 * Fires when a new performance incident is opened.
				 *
				 * @param array $incident Incident row as an associative array.
				 */
				do_action( 'aftercare_incident_opened', $incident );
			}
		} elseif ( $open_incident ) {
			// Back under budget and baseline: auto-resolve.
			$this->incidents->set_status( (int) $open_incident['id'], 'resolved' );

			/**
			 * Fires when an incident recovers and is auto-resolved.
			 *
			 * @param array $incident Incident row before resolution.
			 */
			do_action( 'aftercare_incident_resolved', $open_incident );
		}
	}
}
