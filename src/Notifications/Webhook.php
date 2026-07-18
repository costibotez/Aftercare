<?php
namespace Aftercare\Notifications;

use Aftercare\Core\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro: generic webhook notifications. Posts the incident as JSON.
 */
final class Webhook {

	public function register(): void {
		add_action( 'aftercare_incident_opened', array( $this, 'notify' ) );
		add_action(
			'aftercare_incident_resolved',
			function ( $incident ) {
				$this->notify( $incident, 'incident.resolved' );
			}
		);
	}

	/**
	 * @param array<string, mixed>|null $incident
	 */
	public function notify( $incident, string $event = 'incident.opened' ): void {
		$url = esc_url_raw( (string) Options::get( 'webhook_url' ) );
		if ( '' === $url || ! is_array( $incident ) ) {
			return;
		}
		wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'event'    => $event,
						'site'     => home_url(),
						'incident' => array(
							'id'           => (int) $incident['id'],
							'metric'       => (string) $incident['metric'],
							'url'          => (string) $incident['url'],
							'baseline_p75' => null !== $incident['baseline_p75'] ? (float) $incident['baseline_p75'] : null,
							'breach_p75'   => (float) $incident['breach_p75'],
							'budget_value' => (float) $incident['budget_value'],
							'status'       => (string) $incident['status'],
							'opened_at'    => (string) $incident['opened_at'],
						),
					)
				),
			)
		);
	}
}
