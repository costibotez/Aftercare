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
	 * The part of a URL that identifies a page: lowercase scheme and host,
	 * the port when one is given and the path without a trailing slash. The
	 * query string and fragment are dropped, so "https://Example.com/page/?a=1"
	 * and "https://example.com/page" normalise to the same value.
	 *
	 * @param string $url Any URL.
	 * @return string Normalised URL, or '' for anything that is not an absolute
	 *                http(s) URL.
	 */
	public static function normalize_url( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return $scheme . '://' . strtolower( (string) $parts['host'] ) . $port . untrailingslashit( (string) ( $parts['path'] ?? '' ) );
	}

	/**
	 * Whether a URL's host is, or resolves to, an address that is not on the
	 * public internet: loopback, private, link-local (which includes cloud
	 * metadata at 169.254.169.254), carrier-grade NAT or reserved.
	 *
	 * WordPress makes the same check in wp_safe_remote_get(), but versions
	 * before 7.1 do not treat link-local or carrier-grade NAT addresses as
	 * internal, so this check is needed on every version Aftercare supports.
	 * A host that does not resolve is left to core, which refuses it.
	 *
	 * @param string $url URL to test.
	 */
	public static function is_internal_host( string $url ): bool {
		$host = trim( (string) wp_parse_url( $url, PHP_URL_HOST ), '[]' );
		if ( '' === $host ) {
			return true;
		}
		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : gethostbynamel( $host );
		foreach ( is_array( $ips ) ? $ips : array() as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return true;
			}
			$parts = array_map( 'intval', explode( '.', $ip ) );
			if ( 4 === count( $parts ) && 100 === $parts[0] && $parts[1] >= 64 && $parts[1] <= 127 ) {
				return true; // 100.64.0.0/10, carrier-grade NAT.
			}
		}
		return false;
	}

	/**
	 * A CSV cell that spreadsheet apps show as text rather than run as a
	 * formula. Cells starting with =, +, -, @, a tab or a carriage return
	 * get a leading apostrophe, so a post titled =HYPERLINK(...) exports as
	 * '=HYPERLINK(...).
	 *
	 * @param mixed $value Cell value.
	 */
	public static function csv_safe( $value ): string {
		$value = (string) $value;
		if ( '' !== $value && str_contains( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}
		return $value;
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
			case 'ERROR':
				/* translators: %s: number of occurrences */
				return sprintf( _n( '%s time', '%s times', (int) $value, 'aftercare' ), number_format_i18n( $value ) );
			case 'HTTP':
				/* translators: %d: HTTP status code */
				if ( 200 === (int) $value ) {
					// Only stored for a 200 page that was cut off mid-render.
					return __( 'page cut off', 'aftercare' );
				}
				return $value > 0 ? sprintf( __( 'HTTP %d', 'aftercare' ), (int) $value ) : __( 'no response', 'aftercare' );
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
