<?php
namespace Aftercare\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small shared helpers.
 */
final class Util {

	public static function url_hash( string $url ): string {
		return md5( untrailingslashit( strtolower( $url ) ) );
	}

	/**
	 * @param float[] $values
	 */
	public static function percentile( array $values, float $percentile = 75.0 ): float {
		if ( empty( $values ) ) {
			return 0.0;
		}
		sort( $values );
		$index = ( $percentile / 100 ) * ( count( $values ) - 1 );
		$lower = (int) floor( $index );
		$upper = (int) ceil( $index );
		if ( $lower === $upper ) {
			return (float) $values[ $lower ];
		}
		$fraction = $index - $lower;
		return (float) ( $values[ $lower ] + ( $values[ $upper ] - $values[ $lower ] ) * $fraction );
	}

	/**
	 * Human readable metric value: seconds for LCP/TTFB, ms for INP, raw for CLS.
	 */
	public static function format_metric( string $metric, float $value ): string {
		switch ( $metric ) {
			case 'CLS':
				return number_format_i18n( $value, 3 );
			case 'LCP':
			case 'TTFB':
				return number_format_i18n( $value / 1000, 2 ) . ' s';
			default:
				return number_format_i18n( $value, 0 ) . ' ms';
		}
	}

	/**
	 * Current time in GMT, MySQL format.
	 */
	public static function now(): string {
		return current_time( 'mysql', true );
	}

	/**
	 * GMT date string N days ago (Y-m-d).
	 */
	public static function days_ago( int $days ): string {
		return gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
	}
}
