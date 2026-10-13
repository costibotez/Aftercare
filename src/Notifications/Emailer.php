<?php
namespace Aftercare\Notifications;

use Aftercare\Core\Options;
use Aftercare\Core\Util;
use Aftercare\Incidents\Repository as IncidentRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Free-tier email alerts for new incidents.
 */
final class Emailer {

	public function register(): void {
		add_action( 'aftercare_incident_opened', array( $this, 'send_incident_alert' ) );
	}

	/**
	 * @param array<string, mixed>|null $incident
	 */
	public function send_incident_alert( $incident ): void {
		if ( ! is_array( $incident ) ) {
			return;
		}
		$to = sanitize_email( (string) Options::get( 'alert_email' ) );
		if ( '' === $to ) {
			return;
		}

		$site      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$is_vitals = IncidentRepository::is_vitals( $incident );
		$subject   = $is_vitals
			? sprintf(
				/* translators: 1: site name, 2: metric */
				__( '[%1$s] Performance regression detected: %2$s', 'aftercare' ),
				$site,
				IncidentRepository::label( $incident )
			)
			: sprintf(
				/* translators: 1: site name, 2: "PHP fatal error" or "Page unavailable", 3: file and line, or URL */
				__( '[%1$s] %2$s: %3$s', 'aftercare' ),
				$site,
				IncidentRepository::label( $incident ),
				(string) $incident['url']
			);

		ob_start();
		$aftercare_incident = $incident; // Available to the template.
		include AFTERCARE_DIR . ( $is_vitals ? 'templates/email-incident.php' : 'templates/email-problem.php' );
		$body = (string) ob_get_clean();

		wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
