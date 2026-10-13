<?php
namespace Aftercare\Notifications;

use Aftercare\Core\Options;
use Aftercare\Core\Util;
use Aftercare\Incidents\Repository as IncidentRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro: Slack incoming-webhook notifications for incidents.
 */
final class Slack {

	public function register(): void {
		add_action( 'aftercare_incident_opened', array( $this, 'notify_opened' ) );
		add_action( 'aftercare_incident_resolved', array( $this, 'notify_resolved' ) );
	}

	/**
	 * @param array<string, mixed>|null $incident
	 */
	public function notify_opened( $incident ): void {
		if ( is_array( $incident ) && ! IncidentRepository::is_vitals( $incident ) ) {
			$this->post(
				sprintf(
					/* translators: 1: site name, 2: "PHP fatal error" or "Page unavailable", 3: file and line, or URL, 4: occurrences or HTTP status */
					__( ':rotating_light: %1$s: %2$s at %3$s (%4$s)', 'aftercare' ),
					wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					IncidentRepository::label( $incident ),
					(string) $incident['url'],
					Util::format_metric( (string) $incident['metric'], (float) $incident['breach_p75'] )
				)
			);
			return;
		}
		if ( is_array( $incident ) ) {
			$this->post(
				sprintf(
					/* translators: 1: site name, 2: metric, 3: breach value, 4: budget value, 5: URL */
					__( ':rotating_light: %1$s — %2$s regression: %3$s (budget %4$s) on %5$s', 'aftercare' ),
					wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					IncidentRepository::label( $incident ),
					Util::format_metric( (string) $incident['metric'], (float) $incident['breach_p75'] ),
					Util::format_metric( (string) $incident['metric'], (float) $incident['budget_value'] ),
					(string) $incident['url']
				)
			);
		}
	}

	/**
	 * @param array<string, mixed>|null $incident
	 */
	public function notify_resolved( $incident ): void {
		if ( is_array( $incident ) ) {
			$this->post(
				sprintf(
					/* translators: 1: site name, 2: metric, 3: URL */
					__( ':white_check_mark: %1$s — %2$s recovered on %3$s', 'aftercare' ),
					wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					IncidentRepository::label( $incident ),
					(string) $incident['url']
				)
			);
		}
	}

	private function post( string $text ): void {
		$webhook = esc_url_raw( (string) Options::get( 'slack_webhook' ) );
		if ( '' === $webhook ) {
			return;
		}
		wp_remote_post(
			$webhook,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'text' => $text ) ),
			)
		);
	}
}
