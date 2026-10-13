<?php
namespace Aftercare\Vitals;

use Aftercare\Core\Cron;
use Aftercare\Core\Options;
use Aftercare\Core\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lighthouse lab checks through the PageSpeed Insights API, with the same
 * Google API key as the Chrome UX Report pull.
 *
 * Field data needs traffic and moves slowly (a rolling 28-day window); a lab
 * run works on any public page and reflects today's build. Each URL is tested
 * in its own scheduled action because one PageSpeed Insights call takes 10 to
 * 30 seconds, and the median of a few runs is stored to damp Lighthouse's
 * run-to-run noise.
 */
final class LabClient {

	public const URL_HOOK  = 'aftercare_lab_url';
	public const ERROR_OPT = 'aftercare_lab_last_error';

	private const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/** Lighthouse audit id => Aftercare metric. */
	private const AUDIT_MAP = array(
		'largest-contentful-paint' => 'LCP',
		'cumulative-layout-shift'  => 'CLS',
		'server-response-time'     => 'TTFB',
		'total-blocking-time'      => 'TBT',
	);

	/** Gap between queued URL checks, so they never pile into one cron request. */
	private const STAGGER_SECONDS = 2 * MINUTE_IN_SECONDS;

	public function __construct( private SampleRepository $samples ) {}

	public function register(): void {
		add_action( self::URL_HOOK, array( $this, 'run_url' ) );
	}

	public static function enabled(): bool {
		return (bool) Options::get( 'lab_enabled' ) && '' !== (string) Options::get( 'api_key' );
	}

	/**
	 * URLs a lab check covers: the homepage first, then tracked URLs, capped
	 * so one check stays well inside the API quota and cron time.
	 *
	 * @return string[]
	 */
	public static function urls(): array {
		/**
		 * Filter how many URLs one lab check tests.
		 *
		 * @param int $max Maximum URLs, homepage included.
		 */
		$max = max( 1, (int) apply_filters( 'aftercare_lab_max_urls', 6 ) );
		return array_slice( Options::tracked_urls(), 0, $max );
	}

	/**
	 * Daily pipeline step: queue one action per URL not yet tested today.
	 */
	public function queue_daily(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$today = gmdate( 'Y-m-d' );
		$when  = time();
		foreach ( self::urls() as $url ) {
			if ( $this->samples->has_sample_for_day( $url, 'LCP', SampleRepository::SOURCE_LAB, $today ) ) {
				continue;
			}
			Cron::schedule_single( $when, self::URL_HOOK, array( $url ) );
			$when += self::STAGGER_SECONDS;
		}
	}

	/**
	 * Scheduled action: test one URL and store the median of each metric.
	 *
	 * @param string $url URL to test.
	 */
	public function run_url( $url ): void {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url || ! self::enabled() ) {
			return;
		}

		/**
		 * Filter how many Lighthouse runs make up one lab reading. The median
		 * of the runs is stored.
		 *
		 * @param int $runs Runs per URL.
		 */
		$runs    = max( 1, min( 5, (int) apply_filters( 'aftercare_lab_runs', 3 ) ) );
		$api_key = (string) Options::get( 'api_key' );
		$values  = array();
		$error   = '';

		for ( $i = 0; $i < $runs; $i++ ) {
			$result = $this->fetch( $url, $api_key );
			if ( is_string( $result ) ) {
				$error = $result;
				break; // An API error repeats on every run; do not burn quota on it.
			}
			foreach ( $result as $metric => $value ) {
				$values[ $metric ][] = $value;
			}
		}

		if ( empty( $values ) ) {
			update_option(
				self::ERROR_OPT,
				array(
					'url'     => $url,
					'message' => $error,
					'at'      => Util::now(),
				),
				false
			);
			return;
		}

		delete_option( self::ERROR_OPT );
		$now = Util::now();
		foreach ( $values as $metric => $readings ) {
			$this->samples->insert( $url, $metric, Util::percentile( $readings, 50.0 ), SampleRepository::SOURCE_LAB, $now );
		}
		( new BreachDetector( $this->samples ) )->run_lab( $url );
		Status::flush_cache();
	}

	/**
	 * One Lighthouse run.
	 *
	 * @return array<string, float>|string Metric => value, or an error message.
	 */
	private function fetch( string $url, string $api_key ) {
		$request = add_query_arg(
			array(
				'url'      => rawurlencode( $url ),
				'strategy' => 'mobile',
				'category' => 'performance',
				'key'      => rawurlencode( $api_key ),
			),
			self::ENDPOINT
		);

		$response = wp_remote_get( $request, array( 'timeout' => 60 ) );
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$message = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';
			/* translators: %d: HTTP status code */
			return '' !== $message ? $message : sprintf( __( 'PageSpeed Insights returned HTTP %d.', 'aftercare' ), $code );
		}

		// Lighthouse reports a failed load (no paint, page unreachable from
		// Google) as a 200 with a runtime error and empty audits.
		if ( is_array( $data ) && ! empty( $data['lighthouseResult']['runtimeError']['message'] ) ) {
			return (string) $data['lighthouseResult']['runtimeError']['message'];
		}
		if ( ! is_array( $data ) || empty( $data['lighthouseResult']['audits'] ) ) {
			return __( 'PageSpeed Insights returned no Lighthouse result.', 'aftercare' );
		}

		$out = array();
		foreach ( self::AUDIT_MAP as $audit => $metric ) {
			$value = $data['lighthouseResult']['audits'][ $audit ]['numericValue'] ?? null;
			if ( is_numeric( $value ) ) {
				$out[ $metric ] = (float) $value;
			}
		}
		return empty( $out ) ? __( 'PageSpeed Insights returned no metric values.', 'aftercare' ) : $out;
	}
}
