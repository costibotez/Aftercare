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
 * Compares yesterday's p75 against the budget and the 28-day baseline.
 * A budget breach or a 20% regression against baseline opens an incident;
 * recovery auto-resolves it.
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
				$open_incident = $this->incidents->find_open( $url, $metric );

				if ( $over_budget || $over_baseline ) {
					if ( $open_incident ) {
						continue; // Already tracking this regression.
					}
					$incident_id = $this->incidents->open( $url, $metric, $baseline, $p75, $budget );
					if ( $incident_id ) {
						// The attribution engine ships only in the premium
						// build; the free build stores no ranked causes.
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
	}
}
