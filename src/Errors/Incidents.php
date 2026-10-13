<?php
namespace Aftercare\Errors;

use Aftercare\Core\Plugin;
use Aftercare\Core\Util;
use Aftercare\Incidents\Attribution;
use Aftercare\Incidents\Repository as IncidentRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns fatal errors into incidents and resolves them once the error stops.
 *
 * An error incident is keyed by the error's location (file and line), which
 * is what the incident list shows in place of a URL.
 */
final class Incidents {

	/**
	 * Open an incident for a signature that is new or back after a quiet day.
	 * Runs inside the dying request, so it prepares whatever the alert needs
	 * that may not be loaded yet.
	 */
	public function on_error( string $signature ): void {
		$error = ( new Repository() )->find_by_signature( $signature );
		if ( ! $error ) {
			return;
		}

		$incidents = new IncidentRepository();
		$location  = (string) $error['location'];
		if ( $incidents->find_open( $location, 'ERROR', IncidentRepository::SOURCE_ERROR ) ) {
			return;
		}

		// A fatal while plugins load happens before plugins_loaded, so the
		// notifiers are not listening yet and wp_mail() may not exist.
		if ( ! function_exists( 'wp_mail' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}
		Plugin::instance()->register_notifiers();

		$incident_id = $incidents->open( $location, 'ERROR', null, (float) $error['occurrences'], 0.0, IncidentRepository::SOURCE_ERROR );
		if ( ! $incident_id ) {
			return;
		}

		if ( class_exists( Attribution::class ) ) {
			$causes = ( new Attribution() )->rank( $location, Util::now(), array(), (string) $error['culprit'] );
			$incidents->set_causes( $incident_id, $causes );
		}

		/** This action is documented in src/Vitals/BreachDetector.php */
		do_action( 'aftercare_incident_opened', $incidents->find( $incident_id ) );
	}

	/**
	 * Hourly: resolve error incidents whose error has not happened for a day.
	 */
	public function resolve_quiet(): void {
		$incidents = new IncidentRepository();
		$errors    = new Repository();
		foreach ( $incidents->open_for_source( IncidentRepository::SOURCE_ERROR ) as $incident ) {
			$last_seen = $errors->last_seen_at( (string) $incident['url'] );
			if ( null !== $last_seen && strtotime( $last_seen . ' UTC' ) > time() - Repository::QUIET_SECONDS ) {
				continue;
			}
			$incidents->set_status( (int) $incident['id'], 'resolved' );

			/** This action is documented in src/Vitals/BreachDetector.php */
			do_action( 'aftercare_incident_resolved', $incident );
		}
	}
}
