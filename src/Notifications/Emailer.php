<?php
namespace Aftercare\Notifications;

use Aftercare\Core\Options;
use Aftercare\Core\Util;

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

		$subject = sprintf(
			/* translators: 1: site name, 2: metric */
			__( '[%1$s] Performance regression detected: %2$s', 'aftercare' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			(string) $incident['metric']
		);

		ob_start();
		$aftercare_incident = $incident; // Available to the template.
		include AFTERCARE_DIR . 'templates/email-incident.php';
		$body = (string) ob_get_clean();

		wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
