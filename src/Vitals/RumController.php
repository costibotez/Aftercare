<?php
namespace Aftercare\Vitals;

use Aftercare\Core\Options;
use Aftercare\Core\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the buffer is the plugin's own custom table; caching a write-heavy buffer is meaningless.

/**
 * Real-user monitoring: a ~2 KB beacon (native PerformanceObserver, no
 * external library) loaded for a sampled percentage of visits posts metric
 * values to a REST endpoint. Values are buffered per GMT day in their own
 * table and folded into daily p75 samples by the cron.
 *
 * The endpoint is public, so everything it accepts is bounded: only pages
 * Aftercare tracks are buffered, each page and metric keeps at most
 * MAX_VALUES values a day, every beacon is one single-row INSERT, and a
 * visitor's IP address can send at most a few beacons a minute.
 */
final class RumController {

	/**
	 * Option that held the buffer up to 1.1.0. It is read once more by
	 * aggregate_buffer() so values buffered before an upgrade are not lost,
	 * then deleted. Remove the read path in 1.2.
	 */
	public const BUFFER_OPTION = 'aftercare_rum_buffer';

	/** Transient prefix for the per-IP rate limit. */
	public const RATE_PREFIX = 'aftercare_rum_rate_';

	private const MAX_VALUES     = 500; // Per URL+metric+day cap.
	private const MAX_BODY_BYTES = 1024;
	private const RATE_LIMIT     = 30; // Beacons per IP address per minute.

	public function __construct( private SampleRepository $samples ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_beacon' ) );
	}

	/**
	 * Fully prefixed name of the buffer table.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aftercare_rum_buffer';
	}

	public function register_routes(): void {
		register_rest_route(
			'aftercare/v1',
			'/rum',
			array(
				'methods'             => 'POST',
				// Public by design: RUM beacons come from anonymous visitors on
				// cached pages, so nonces are not viable. Input is strictly
				// validated, limited to tracked pages, rate limited per IP and
				// buffered with a hard cap.
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'collect' ),
				'args'                => array(
					'metric' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => Options::METRICS,
					),
					'value'  => array(
						'type'     => 'number',
						'required' => true,
					),
					'url'    => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	public function collect( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Options::get( 'rum_enabled' ) ) {
			return new \WP_REST_Response( null, 204 );
		}

		// A beacon is well under 200 bytes plus the page address.
		if ( strlen( $request->get_body() ) > self::MAX_BODY_BYTES ) {
			return new \WP_REST_Response( null, 413 );
		}

		$metric = (string) $request['metric'];
		$value  = (float) $request['value'];

		$max = 'CLS' === $metric ? 10.0 : 120000.0;
		if ( $value < 0 || $value > $max ) {
			return new \WP_REST_Response( null, 204 );
		}

		// Only pages Aftercare monitors are buffered. Anything else is
		// dropped before any write, so a script posting endless made-up
		// addresses on this host costs one settings read per request.
		$hash = Util::url_hash( Util::normalize_url( (string) $request['url'] ) );
		if ( ! isset( self::tracked_map()[ $hash ] ) ) {
			return new \WP_REST_Response( null, 204 );
		}

		if ( self::rate_limited() ) {
			return new \WP_REST_Response( null, 429 );
		}

		global $wpdb;
		$day   = gmdate( 'Y-m-d' );
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE day = %s AND url_hash = %s AND metric = %s',
				self::table(),
				$day,
				$hash,
				$metric
			)
		);
		if ( $count < self::MAX_VALUES ) {
			$wpdb->insert(
				self::table(),
				array(
					'day'      => $day,
					'url_hash' => $hash,
					'metric'   => $metric,
					'value'    => round( $value, 3 ),
				),
				array( '%s', '%s', '%s', '%f' )
			);
		}

		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * Tracked URLs keyed by the hash of their normalised form, so a beacon
	 * from "/page", "/page/" or "/page/?utm_source=x" finds the tracked
	 * "/page/". The first tracked URL wins when two normalise alike.
	 *
	 * @return array<string, string>
	 */
	private static function tracked_map(): array {
		$map = array();
		foreach ( Options::tracked_urls() as $url ) {
			$key = Util::url_hash( Util::normalize_url( $url ) );
			if ( ! isset( $map[ $key ] ) ) {
				$map[ $key ] = $url;
			}
		}
		return $map;
	}

	/**
	 * Counts this beacon against its IP address and reports whether the
	 * address is over the limit for the current minute. The address itself
	 * is never stored: the transient is keyed on a salted hash of it.
	 */
	private static function rate_limited(): bool {
		/**
		 * Filter how many RUM beacons one IP address may send per minute.
		 * Each page view sends up to four, one per metric. Return 0 to turn
		 * the limit off.
		 *
		 * @param int $limit Beacons per minute.
		 */
		$limit = (int) apply_filters( 'aftercare_rum_rate_limit', self::RATE_LIMIT );
		if ( $limit <= 0 ) {
			return false;
		}

		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key   = self::RATE_PREFIX . substr( wp_hash( $ip . '|' . intdiv( time(), MINUTE_IN_SECONDS ) ), 0, 32 );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return true;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * Cron entry point: aggregate every buffered day before today into daily
	 * p75 samples, then drop those buffer rows.
	 *
	 * @param SampleRepository $samples Where the daily samples go.
	 */
	public static function aggregate_buffer( SampleRepository $samples ): void {
		self::drain_legacy_buffer( $samples );

		global $wpdb;
		$today  = gmdate( 'Y-m-d' );
		$groups = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT day, url_hash, metric FROM %i WHERE day < %s GROUP BY day, url_hash, metric',
				self::table(),
				$today
			),
			ARRAY_A
		);
		if ( ! $groups ) {
			return;
		}

		$urls = self::tracked_map();
		foreach ( $groups as $group ) {
			$url    = $urls[ (string) $group['url_hash'] ] ?? '';
			$day    = (string) $group['day'];
			$metric = (string) $group['metric'];
			// A URL no longer tracked has nowhere to be shown.
			if ( '' === $url || $samples->has_sample_for_day( $url, $metric, 'rum', $day ) ) {
				continue;
			}
			$values = array_map(
				'floatval',
				$wpdb->get_col(
					$wpdb->prepare(
						'SELECT value FROM %i WHERE day = %s AND url_hash = %s AND metric = %s',
						self::table(),
						$day,
						(string) $group['url_hash'],
						$metric
					)
				)
			);
			if ( count( $values ) < 4 ) {
				continue; // Too few samples for a meaningful p75.
			}
			$samples->insert( $url, $metric, Util::percentile( $values ), 'rum', $day . ' 23:59:00' );
		}

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s', self::table(), $today ) );
	}

	/**
	 * Reads the buffer option used up to 1.1.0 one last time, then deletes
	 * it. Past days are aggregated exactly as 1.1.0 did; values for today
	 * move into the buffer table when their page is tracked, so today's
	 * aggregate tomorrow includes them.
	 *
	 * @param SampleRepository $samples Where the daily samples go.
	 */
	private static function drain_legacy_buffer( SampleRepository $samples ): void {
		$buffer = get_option( self::BUFFER_OPTION, false );
		if ( false === $buffer ) {
			return;
		}

		global $wpdb;
		$today = gmdate( 'Y-m-d' );
		$urls  = self::tracked_map();
		foreach ( is_array( $buffer ) ? $buffer : array() as $day => $by_url ) {
			$day = (string) $day;
			foreach ( is_array( $by_url ) ? $by_url : array() as $entries ) {
				foreach ( (array) $entries as $metric => $entry ) {
					$metric = (string) $metric;
					$url    = (string) ( $entry['url'] ?? '' );
					$values = array_map( 'floatval', (array) ( $entry['values'] ?? array() ) );
					if ( '' === $url || ! in_array( $metric, Options::METRICS, true ) ) {
						continue;
					}

					if ( $day < $today ) {
						if ( count( $values ) >= 4 && ! $samples->has_sample_for_day( $url, $metric, 'rum', $day ) ) {
							$samples->insert( $url, $metric, Util::percentile( $values ), 'rum', $day . ' 23:59:00' );
						}
						continue;
					}

					$hash = Util::url_hash( Util::normalize_url( $url ) );
					if ( ! isset( $urls[ $hash ] ) ) {
						continue;
					}
					foreach ( array_slice( $values, 0, self::MAX_VALUES ) as $value ) {
						$wpdb->insert(
							self::table(),
							array(
								'day'      => $day,
								'url_hash' => $hash,
								'metric'   => $metric,
								'value'    => $value,
							),
							array( '%s', '%s', '%s', '%f' )
						);
					}
				}
			}
		}
		delete_option( self::BUFFER_OPTION );
	}

	public function enqueue_beacon(): void {
		if ( ! Options::get( 'rum_enabled' ) || is_admin() ) {
			return;
		}
		wp_enqueue_script( 'aftercare-rum', AFTERCARE_URL . 'assets/js/rum.js', array(), AFTERCARE_VERSION, array( 'strategy' => 'defer' ) );
		wp_localize_script(
			'aftercare-rum',
			'aftercareRum',
			array(
				'endpoint'   => rest_url( 'aftercare/v1/rum' ),
				'sampleRate' => max( 1, min( 100, (int) Options::get( 'rum_sample_rate' ) ) ),
			)
		);
	}
}
