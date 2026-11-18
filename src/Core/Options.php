<?php
namespace Aftercare\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings accessor. Everything lives in one option row: aftercare_settings.
 */
final class Options {

	public const OPTION = 'aftercare_settings';

	public const METRICS = array( 'LCP', 'INP', 'CLS', 'TTFB' );

	/**
	 * Metrics a Lighthouse lab run measures. INP needs a real interaction and
	 * has no lab value; Total Blocking Time is the accepted lab stand-in.
	 */
	public const LAB_METRICS = array( 'LCP', 'CLS', 'TTFB', 'TBT' );

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'api_key'                => '',
			'tracked_urls'           => array(),
			'budgets'                => array(
				'LCP'  => 2500.0, // ms
				'INP'  => 200.0,  // ms
				'CLS'  => 0.1,    // score
				'TTFB' => 800.0,  // ms
				'TBT'  => 200.0,  // ms, lab only
			),
			'rum_enabled'            => false,
			'rum_sample_rate'        => 10, // percent of visits
			'lab_enabled'            => true,
			'error_watch'            => true,
			'availability_watch'     => true,
			'alert_email'            => get_option( 'admin_email' ),
			'weekly_digest'          => true,
			'slack_webhook'          => '',
			'webhook_url'            => '',
			'client_recipients'      => '',
			'branding'               => array(
				'logo_url'    => '',
				'accent'      => '#0f766e',
				'footer_text' => '',
				'sender_name' => '',
				'reply_to'    => '',
			),
			'keep_data_on_uninstall' => false,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$merged            = array_merge( self::defaults(), $stored );
		$merged['budgets'] = array_merge( self::defaults()['budgets'], is_array( $merged['budgets'] ) ? $merged['budgets'] : array() );
		$merged['branding'] = array_merge( self::defaults()['branding'], is_array( $merged['branding'] ) ? $merged['branding'] : array() );
		return $merged;
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public static function update( array $values ): void {
		update_option( self::OPTION, array_merge( self::all(), $values ), false );
	}

	/**
	 * Tracked URLs: the homepage is always first, followed by every URL the
	 * site owner configured.
	 *
	 * @return string[]
	 */
	public static function tracked_urls(): array {
		$urls  = array( home_url( '/' ) );
		$extra = self::get( 'tracked_urls' );
		if ( is_array( $extra ) ) {
			foreach ( $extra as $url ) {
				$url = esc_url_raw( trim( (string) $url ) );
				// Settings saved before 1.1.1 accepted any URL.
				if ( '' !== $url && self::is_trackable_url( $url ) && ! in_array( $url, $urls, true ) ) {
					$urls[] = $url;
				}
			}
		}
		return $urls;
	}

	/**
	 * Hosts a tracked URL may point at: the hosts of home_url() and
	 * site_url(), plus any added through the aftercare_allowed_hosts filter.
	 *
	 * @return string[] Lowercase host names.
	 */
	public static function allowed_hosts(): array {
		$hosts = array(
			(string) wp_parse_url( home_url(), PHP_URL_HOST ),
			(string) wp_parse_url( site_url(), PHP_URL_HOST ),
		);
		/**
		 * Filter the hosts tracked URLs may point at. Aftercare requests
		 * tracked URLs from the server every hour, so only add domains this
		 * site serves, such as a separate shop or language domain.
		 *
		 * @param string[] $hosts Host names, by default those of home_url() and site_url().
		 */
		$hosts = (array) apply_filters( 'aftercare_allowed_hosts', $hosts );
		return array_values( array_unique( array_filter( array_map( static fn( $host ) => strtolower( trim( (string) $host ) ), $hosts ) ) ) );
	}

	/**
	 * Whether a URL may be tracked: an http(s) URL without credentials on an
	 * allowed host, on the standard port or the port this site itself uses.
	 * Keeps the hourly availability check and the lab checks from being
	 * pointed at other servers or internal addresses.
	 *
	 * @param string $url URL to test.
	 */
	public static function is_trackable_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return false;
		}
		if ( ! in_array( strtolower( $parts['host'] ), self::allowed_hosts(), true ) ) {
			return false;
		}
		$ports = array( 80, 443, self::port( home_url() ), self::port( site_url() ) );
		return in_array( self::port( $url ), $ports, true );
	}

	/**
	 * Whether a URL is on this site's own host and port, as home_url()
	 * gives them. Only those requests may skip TLS verification through
	 * the https_local_ssl_verify filter.
	 *
	 * @param string $url URL to test.
	 */
	public static function is_home_url( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host )
			&& strtolower( $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) )
			&& self::port( $url ) === self::port( home_url() );
	}

	/**
	 * Port a URL connects to, with the scheme's default filled in.
	 *
	 * @param string $url URL.
	 */
	private static function port( string $url ): int {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return 0;
		}
		if ( isset( $parts['port'] ) ) {
			return (int) $parts['port'];
		}
		return 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) ? 443 : 80;
	}

	/**
	 * Whether a URL is fit for a Slack or webhook endpoint: an absolute
	 * https URL with a host.
	 *
	 * @param string $url URL to test.
	 */
	public static function is_https_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && ! empty( $parts['host'] ) && 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) );
	}

	/**
	 * Budget for one metric, filterable per URL.
	 */
	public static function budget( string $metric, string $url = '' ): float {
		$budgets = self::get( 'budgets' );
		$value   = isset( $budgets[ $metric ] ) ? (float) $budgets[ $metric ] : 0.0;

		/**
		 * Filter the performance budget for a metric.
		 *
		 * @param float  $value  Budget value (ms, or score for CLS).
		 * @param string $metric Metric key: LCP, INP, CLS, TTFB.
		 * @param string $url    URL being evaluated ('' for global).
		 */
		return (float) apply_filters( 'aftercare_budget', $value, $metric, $url );
	}

	/**
	 * Vitals sample retention in days. Thirteen months, so a report can always
	 * compare a month against the same month a year earlier.
	 */
	public static function vitals_retention_days(): int {
		/**
		 * Filter how many days of vitals samples to keep.
		 *
		 * @param int $days Retention window in days.
		 */
		return max( 1, (int) apply_filters( 'aftercare_vitals_retention_days', 396 ) );
	}

	/**
	 * Ledger retention in days. 0 = keep everything.
	 */
	public static function ledger_retention_days(): int {
		/**
		 * Filter how many days of ledger history to keep. Return 0 to keep
		 * every event, which is the default.
		 *
		 * @param int $days Retention window in days, or 0 for unlimited.
		 */
		return max( 0, (int) apply_filters( 'aftercare_ledger_retention_days', 0 ) );
	}
}
