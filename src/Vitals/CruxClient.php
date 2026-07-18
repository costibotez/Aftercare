<?php
namespace Aftercare\Vitals;

use Aftercare\Core\Options;
use Aftercare\Core\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chrome UX Report API client. One request per tracked URL per day, with an
 * origin-level fallback when URL-level data is too thin. Requires the user's
 * own Google API key (no phoning home without opt-in).
 */
final class CruxClient {

	private const ENDPOINT = 'https://chromeuxreport.googleapis.com/v1/records:queryRecord';

	private const METRIC_MAP = array(
		'largest_contentful_paint'        => 'LCP',
		'interaction_to_next_paint'       => 'INP',
		'cumulative_layout_shift'         => 'CLS',
		'experimental_time_to_first_byte' => 'TTFB',
	);

	public function __construct( private SampleRepository $samples ) {}

	public function pull_all(): void {
		$api_key = (string) Options::get( 'api_key' );
		if ( '' === $api_key ) {
			return;
		}

		$today = gmdate( 'Y-m-d' );
		foreach ( Options::tracked_urls() as $url ) {
			// One CrUX sample per URL per day.
			if ( $this->samples->has_sample_for_day( $url, 'LCP', 'crux', $today ) ) {
				continue;
			}
			$metrics = $this->fetch( $url, $api_key );
			if ( null === $metrics ) {
				// URL-level record too thin: fall back to origin-level data.
				$metrics = $this->fetch( $url, $api_key, true );
			}
			if ( null === $metrics ) {
				continue;
			}
			$now = Util::now();
			foreach ( $metrics as $metric => $p75 ) {
				$this->samples->insert( $url, $metric, $p75, 'crux', $now );
			}
		}
	}

	/**
	 * @return array<string, float>|null Metric => p75, or null when no record.
	 */
	private function fetch( string $url, string $api_key, bool $origin_level = false ): ?array {
		$cache_key = 'aftercare_crux_' . md5( ( $origin_level ? 'o:' : 'u:' ) . $url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return empty( $cached ) ? null : $cached;
		}

		$body = array( 'metrics' => array_keys( self::METRIC_MAP ) );
		if ( $origin_level ) {
			$parts          = wp_parse_url( $url );
			$body['origin'] = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' );
		} else {
			$body['url'] = $url;
		}

		$response = wp_remote_post(
			add_query_arg( 'key', rawurlencode( $api_key ), self::ENDPOINT ),
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			// No data for this URL — cache the miss for a day to avoid re-hitting.
			set_transient( $cache_key, array(), 12 * HOUR_IN_SECONDS );
			return null;
		}
		if ( 200 !== $code ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['record']['metrics'] ) ) {
			return null;
		}

		$out = array();
		foreach ( self::METRIC_MAP as $api_key_name => $metric ) {
			$p75 = $data['record']['metrics'][ $api_key_name ]['percentiles']['p75'] ?? null;
			if ( null !== $p75 ) {
				$out[ $metric ] = (float) $p75; // CLS arrives as a string like "0.05".
			}
		}
		if ( empty( $out ) ) {
			return null;
		}

		set_transient( $cache_key, $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}
}
