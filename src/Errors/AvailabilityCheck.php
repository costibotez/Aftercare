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
	private const MAX_REDIRECTS    = 5;

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
		// The query argument gets past page caches and CDNs, so every check
		// reaches the origin server. The readme documents this.
		$response = self::fetch( add_query_arg( 'aftercare_check', time(), $url ) );

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

	/**
	 * GETs a URL and follows up to MAX_REDIRECTS redirects one hop at a time,
	 * so each hop is judged on its own host. Only this site's own host may
	 * skip TLS verification through https_local_ssl_verify, as core's
	 * loopback requests do; any other host goes through
	 * wp_safe_remote_get(), which refuses internal addresses, with TLS
	 * always verified.
	 *
	 * @param string $url URL to request.
	 * @return array<string, mixed>|\WP_Error Response, or an error.
	 */
	private static function fetch( string $url ) {
		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$args = array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
				'user-agent'  => 'Aftercare availability check; ' . home_url( '/' ),
			);
			if ( Options::is_home_url( $url ) ) {
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				$args['sslverify'] = apply_filters( 'https_local_ssl_verify', false );
				$response          = wp_remote_get( $url, $args );
			} elseif ( Util::is_internal_host( $url ) ) {
				return new \WP_Error( 'http_request_failed', __( 'Refused a request to an internal address.', 'aftercare' ) );
			} else {
				$args['sslverify'] = true;
				$response          = wp_safe_remote_get( $url, $args );
			}

			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$status   = (int) wp_remote_retrieve_response_code( $response );
			$location = wp_remote_retrieve_header( $response, 'location' );
			$location = is_array( $location ) ? (string) end( $location ) : (string) $location;
			if ( $status < 300 || $status >= 400 || '' === $location ) {
				return $response;
			}
			$url = \WP_Http::make_absolute_url( $location, $url );
		}
		return new \WP_Error( 'http_request_failed', __( 'Too many redirects.', 'aftercare' ) );
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
