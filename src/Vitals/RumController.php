<?php
namespace Aftercare\Vitals;

use Aftercare\Core\Options;
use Aftercare\Core\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Real-user monitoring: a ~2 KB beacon (native PerformanceObserver, no
 * external library) loaded for a sampled percentage of visits posts metric
 * values to a REST endpoint. Values are buffered per GMT day and folded into
 * daily p75 samples by the cron.
 */
final class RumController {

	public const BUFFER_OPTION = 'aftercare_rum_buffer';
	private const MAX_VALUES   = 500; // Per URL+metric+day cap.

	public function __construct( private SampleRepository $samples ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_beacon' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'aftercare/v1',
			'/rum',
			array(
				'methods'             => 'POST',
				// Public by design: RUM beacons come from anonymous visitors on
				// cached pages, so nonces are not viable. Input is strictly
				// validated, same-host only, and buffered with a hard cap.
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

		$metric = (string) $request['metric'];
		$value  = (float) $request['value'];
		$url    = esc_url_raw( (string) $request['url'] );

		// Same-host only, sane value ranges.
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== $site_host ) {
			return new \WP_REST_Response( null, 204 );
		}
		$max = 'CLS' === $metric ? 10.0 : 120000.0;
		if ( $value < 0 || $value > $max ) {
			return new \WP_REST_Response( null, 204 );
		}

		// Strip query strings/fragments so variants aggregate together.
		$url = strtok( $url, '?#' );

		$day    = gmdate( 'Y-m-d' );
		$hash   = Util::url_hash( $url );
		$buffer = get_option( self::BUFFER_OPTION, array() );
		if ( ! is_array( $buffer ) ) {
			$buffer = array();
		}

		if ( ! isset( $buffer[ $day ][ $hash ][ $metric ] ) ) {
			$buffer[ $day ][ $hash ][ $metric ] = array(
				'url'    => $url,
				'values' => array(),
			);
		}
		if ( count( $buffer[ $day ][ $hash ][ $metric ]['values'] ) < self::MAX_VALUES ) {
			$buffer[ $day ][ $hash ][ $metric ]['values'][] = round( $value, 3 );
			update_option( self::BUFFER_OPTION, $buffer, false );
		}

		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * Cron entry point: aggregate every buffered day before today into daily
	 * p75 samples, then drop those buffer entries.
	 */
	public static function aggregate_buffer( SampleRepository $samples ): void {
		$buffer = get_option( self::BUFFER_OPTION, array() );
		if ( ! is_array( $buffer ) || empty( $buffer ) ) {
			return;
		}

		$today = gmdate( 'Y-m-d' );
		foreach ( $buffer as $day => $urls ) {
			if ( $day >= $today || ! is_array( $urls ) ) {
				continue; // Today is still collecting.
			}
			foreach ( $urls as $entries ) {
				foreach ( (array) $entries as $metric => $entry ) {
					$values = array_map( 'floatval', (array) ( $entry['values'] ?? array() ) );
					if ( count( $values ) < 4 ) {
						continue; // Too few samples for a meaningful p75.
					}
					$url = (string) ( $entry['url'] ?? '' );
					if ( '' === $url || $samples->has_sample_for_day( $url, (string) $metric, 'rum', $day ) ) {
						continue;
					}
					$samples->insert( $url, (string) $metric, Util::percentile( $values ), 'rum', $day . ' 23:59:00' );
				}
			}
			unset( $buffer[ $day ] );
		}
		update_option( self::BUFFER_OPTION, $buffer, false );
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
