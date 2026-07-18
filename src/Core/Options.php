<?php
namespace Aftercare\Core;

use Aftercare\Licensing\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings accessor. Everything lives in one option row: aftercare_settings.
 */
final class Options {

	public const OPTION = 'aftercare_settings';

	public const METRICS = array( 'LCP', 'INP', 'CLS', 'TTFB' );

	public const FREE_URL_LIMIT = 5; // Tracked URLs beyond the homepage.

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
			),
			'rum_enabled'            => false,
			'rum_sample_rate'        => 10, // percent of visits
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
	 * Tracked URLs: the homepage is always first, followed by the configured
	 * extras (capped in free mode).
	 *
	 * @return string[]
	 */
	public static function tracked_urls(): array {
		$urls  = array( home_url( '/' ) );
		$extra = self::get( 'tracked_urls' );
		if ( is_array( $extra ) ) {
			if ( ! License::is_pro() ) {
				$extra = array_slice( $extra, 0, self::FREE_URL_LIMIT );
			}
			foreach ( $extra as $url ) {
				$url = esc_url_raw( trim( (string) $url ) );
				if ( '' !== $url && ! in_array( $url, $urls, true ) ) {
					$urls[] = $url;
				}
			}
		}
		return $urls;
	}

	/**
	 * Budget for one metric, filterable per URL (Pro overrides hook in here).
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
	 * Vitals sample retention in days.
	 */
	public static function vitals_retention_days(): int {
		return License::is_pro() ? 396 : 30; // 13 months vs 30 days.
	}

	/**
	 * Ledger retention in days. 0 = unlimited.
	 */
	public static function ledger_retention_days(): int {
		return License::is_pro() ? 0 : 90;
	}
}
