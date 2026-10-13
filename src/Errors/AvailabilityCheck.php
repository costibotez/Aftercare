<?php
namespace Aftercare\Errors;

use Aftercare\Core\Cron;
use Aftercare\Core\Options;
use Aftercare\Incidents\Attribution;
use Aftercare\Incidents\Repository as IncidentRepository;
use Aftercare\Core\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Requests the homepage and tracked URLs from the site itself and opens an
 * incident when one keeps failing.
 *
 * A failure is a server error (5xx), a missing page (404 or 410) or an HTML
 * page cut off before </html>, which is what a fatal error after output has
 * started looks like from outside. No response at all is never a failure:
 * this code runs on the site, so a site that is down never gets to check
 * itself, and a request that cannot connect means the host blocks loopback
 * requests. That case is recorded for Site Health instead. 401 and 403 are
 * treated the same way, since they usually mean a staging password or a
 * firewall rather than a broken page.
 *
 * Two failures in a row open an incident; the first healthy response
 * resolves it. A first failure schedules a confirming check two minutes
 * later, so a broken page is reported in minutes rather than an hour.
 */
final class AvailabilityCheck {

	public const CONFIRM_HOOK = 'aftercare_availability_confirm';
	public const STATE_OPT    = 'aftercare_availability_state';
	public const LOOPBACK_OPT = 'aftercare_availability_loopback';

	private const FAILURES_TO_OPEN = 2;
	private const CONFIRM_SECONDS  = 2 * MINUTE_IN_SECONDS;

	public const OK           = 'ok';
	public const FAIL         = 'fail';
	public const INCONCLUSIVE = 'inconclusive';

	public function register(): void {
		add_action( self::CONFIRM_HOOK, array( $this, 'run' ) );
	}

	public static function enabled(): bool {
		return (bool) Options::get( 'availability_watch' );
	}

	/**
	 * @return string[]
	 */
	public static function urls(): array {
		/**
		 * Filter how many URLs one availability check requests.
		 *
		 * @param int $max Maximum URLs, homepage included.
		 */
		$max = max( 1, (int) apply_filters( 'aftercare_availability_max_urls', 6 ) );
		return array_slice( Options::tracked_urls(), 0, $max );
	}

	public function run(): void {
		if ( ! self::enabled() ) {
			return;
		}

		$state        = get_option( self::STATE_OPT, array() );
		$state        = is_array( $state ) ? $state : array();
		$incidents    = new IncidentRepository();
		$inconclusive = array();
		$confirm      = false;

		foreach ( self::urls() as $url ) {
			$key = Util::url_hash( $url );
			[ $verdict, $status, $detail ] = $this->check( $url );

			if ( self::INCONCLUSIVE === $verdict ) {
				$inconclusive[] = $url . ': ' . $detail;
				continue;
			}

			$open = $incidents->find_open( $url, 'HTTP', IncidentRepository::SOURCE_AVAILABILITY );

			if ( self::OK === $verdict ) {
				unset( $state[ $key ] );
				if ( $open ) {
					$incidents->set_status( (int) $open['id'], 'resolved' );
					/** This action is documented in src/Vitals/BreachDetector.php */
					do_action( 'aftercare_incident_resolved', $open );
				}
				continue;
			}

			$state[ $key ] = (int) ( $state[ $key ] ?? 0 ) + 1;
			if ( $state[ $key ] < self::FAILURES_TO_OPEN ) {
				$confirm = true;
				continue;
			}
			if ( ! $open ) {
				$this->open( $incidents, $url, $status );
			}
		}

		update_option( self::STATE_OPT, $state, false );

		// Every URL unreachable from the site itself: loopback is blocked.
		if ( $inconclusive && count( $inconclusive ) === count( self::urls() ) ) {
			update_option(
				self::LOOPBACK_OPT,
				array(
					'at'      => time(),
					'message' => implode( '; ', $inconclusive ),
				),
				false
			);
		} else {
			delete_option( self::LOOPBACK_OPT );
		}

		if ( $confirm ) {
			Cron::schedule_single( time() + self::CONFIRM_SECONDS, self::CONFIRM_HOOK );
		}
	}

	/**
	 * @return array{0: string, 1: int, 2: string} Verdict, HTTP status, detail.
	 */
	public function check( string $url ): array {
		$response = wp_remote_get(
			add_query_arg( 'aftercare_check', time(), $url ), // Past any page cache.
			array(
				'timeout'     => 10,
				'redirection' => 5,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
				'user-agent'  => 'Aftercare availability check; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( self::INCONCLUSIVE, 0, $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 500 || 404 === $status || 410 === $status ) {
			return array( self::FAIL, $status, '' );
		}
		if ( 200 !== $status ) {
			/* translators: %d: HTTP status code */
			return array( self::INCONCLUSIVE, $status, sprintf( __( 'HTTP %d', 'aftercare' ), $status ) );
		}

		$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( str_contains( $type, 'text/html' ) && '' !== $body && false === stripos( $body, '</html>' ) ) {
			return array( self::FAIL, 200, '' );
		}
		return array( self::OK, 200, '' );
	}

	private function open( IncidentRepository $incidents, string $url, int $status ): void {
		$incident_id = $incidents->open( $url, 'HTTP', null, (float) $status, 0.0, IncidentRepository::SOURCE_AVAILABILITY );
		if ( ! $incident_id ) {
			return;
		}
		if ( class_exists( Attribution::class ) ) {
			$incidents->set_causes( $incident_id, ( new Attribution() )->rank( $url, Util::now() ) );
		}
		/** This action is documented in src/Vitals/BreachDetector.php */
		do_action( 'aftercare_incident_opened', $incidents->find( $incident_id ) );
	}
}
