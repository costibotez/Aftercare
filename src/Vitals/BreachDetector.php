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

	private IncidentRepository $incidents;

	public function __construct( private SampleRepository $samples ) {
		$this->incidents = new IncidentRepository();
	}

	public function run(): void {
		$yesterday      = Util::days_ago( 1 );
		$baseline_start = Util::days_ago( 29 );

		foreach ( Options::tracked_urls() as $url ) {
			foreach ( Options::METRICS as $metric ) {
				$p75 = $this->samples->p75_for_day( $url, $metric, $yesterday );
				if ( null === $p75 ) {
					continue;
				}

				$budget   = Options::budget( $metric, $url );
				$baseline = $this->samples->baseline( $url, $metric, $baseline_start, $yesterday );

				$over_budget    = $budget > 0 && $p75 > $budget;
				$over_baseline  = null !== $baseline && $baseline > 0 && $p75 > $baseline * self::REGRESSION_FACTOR;
				$open_incident  = $this->incidents->find_open( $url, $metric );

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
