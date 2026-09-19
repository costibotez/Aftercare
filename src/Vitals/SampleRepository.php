<?php
namespace Aftercare\Vitals;

use Aftercare\Core\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage for daily p75 vitals samples.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repository for the plugin's own custom table; direct queries are the point. Hot reads are cached at the Vitals\Status snapshot layer.
final class SampleRepository {

	/** CrUX record for the exact tracked URL. */
	public const SOURCE_CRUX_URL = 'crux_url';

	/** CrUX record for the whole origin — every page pooled together. */
	public const SOURCE_CRUX_ORIGIN = 'crux_origin';

	/**
	 * Rows written before source levels were tracked. Could be either level;
	 * treated as its own source so it is never averaged with a known one.
	 */
	public const SOURCE_CRUX_LEGACY = 'crux';

	/** Aggregated real-user beacons for the exact tracked URL. */
	public const SOURCE_RUM = 'rum';

	/** Every CrUX-derived source, for "did we already pull today?" checks. */
	public const CRUX_SOURCES = array( self::SOURCE_CRUX_URL, self::SOURCE_CRUX_ORIGIN, self::SOURCE_CRUX_LEGACY );

	/**
	 * Preference when more than one source covers the same day: the most
	 * specific measurement of the tracked URL wins. Origin-level data sits
	 * below RUM's predecessor 'crux' only because legacy rows are usually
	 * URL-level, and above 'rum' to preserve "CrUX wins over RUM".
	 */
	private const SOURCE_PRIORITY = array(
		self::SOURCE_CRUX_URL,
		self::SOURCE_CRUX_LEGACY,
		self::SOURCE_CRUX_ORIGIN,
		self::SOURCE_RUM,
	);

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aftercare_vitals_samples';
	}

	/**
	 * Guards the literal placeholder runs written into the queries below.
	 *
	 * The SQL in this class is deliberately written as literal strings with no
	 * interpolation, so `FIELD(sample_source, %s, %s, %s, %s)` and
	 * `IN ( %s, %s, %s )` hard-code the lengths of SOURCE_PRIORITY and
	 * CRUX_SOURCES. Adding a source without updating those runs would shift
	 * every following argument, so fail loudly in development instead.
	 */
	private function assert_source_counts(): void {
		assert( 4 === count( self::SOURCE_PRIORITY ), 'SOURCE_PRIORITY changed: update the FIELD() placeholder runs in this class.' );
		assert( 3 === count( self::CRUX_SOURCES ), 'CRUX_SOURCES changed: update the IN () placeholder run in this class.' );
	}

	public function insert( string $url, string $metric, float $p75, string $source, string $recorded_at ): void {
		global $wpdb;
		$wpdb->insert(
			$this->table(),
			array(
				'url_hash'      => Util::url_hash( $url ),
				'url'           => $url,
				'metric'        => $metric,
				'p75_value'     => $p75,
				'sample_source' => $source,
				'recorded_at'   => $recorded_at,
			),
			array( '%s', '%s', '%s', '%f', '%s', '%s' )
		);
	}

	/**
	 * True when a sample from this source already exists for that GMT day.
	 */
	public function has_sample_for_day( string $url, string $metric, string $source, string $day ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE url_hash = %s AND metric = %s AND sample_source = %s AND recorded_at >= %s AND recorded_at < %s LIMIT 1',
				$this->table(),
				Util::url_hash( $url ),
				$metric,
				$source,
				$day . ' 00:00:00',
				$day . ' 23:59:59'
			)
		);
	}

	/**
	 * True when any CrUX-derived sample already exists for that GMT day,
	 * whatever level it came from. Keeps the daily pull to one API round trip
	 * even when the client falls back from URL- to origin-level.
	 */
	public function has_crux_sample_for_day( string $url, string $metric, string $day ): bool {
		global $wpdb;
		$this->assert_source_counts();
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE url_hash = %s AND metric = %s AND sample_source IN ( %s, %s, %s ) AND recorded_at >= %s AND recorded_at < %s LIMIT 1',
				$this->table(),
				Util::url_hash( $url ),
				$metric,
				self::SOURCE_CRUX_URL,
				self::SOURCE_CRUX_ORIGIN,
				self::SOURCE_CRUX_LEGACY,
				$day . ' 00:00:00',
				$day . ' 23:59:59'
			)
		);
	}

	/**
	 * Preferred p75 for a given GMT day, with the source it came from. The
	 * source matters: a URL-level and an origin-level reading describe
	 * different populations and must never be compared with one another.
	 *
	 * @return array{value: float, source: string}|null
	 */
	public function latest_for_day( string $url, string $metric, string $day ): ?array {
		global $wpdb;
		$this->assert_source_counts();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT p75_value, sample_source FROM %i
				WHERE url_hash = %s AND metric = %s AND recorded_at >= %s AND recorded_at < %s
				ORDER BY FIELD(sample_source, %s, %s, %s, %s), id DESC
				LIMIT 1',
				$this->table(),
				Util::url_hash( $url ),
				$metric,
				$day . ' 00:00:00',
				$day . ' 23:59:59',
				self::SOURCE_CRUX_URL,
				self::SOURCE_CRUX_LEGACY,
				self::SOURCE_CRUX_ORIGIN,
				self::SOURCE_RUM
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		return array(
			'value'  => (float) $row['p75_value'],
			'source' => (string) $row['sample_source'],
		);
	}

	/**
	 * Latest p75 for a given GMT day, without its source.
	 *
	 * @deprecated Use latest_for_day() — comparing values across source levels
	 *             is what this method makes easy to get wrong.
	 */
	public function p75_for_day( string $url, string $metric, string $day ): ?float {
		$row = $this->latest_for_day( $url, $metric, $day );
		return null === $row ? null : $row['value'];
	}

	/**
	 * Average of daily p75 values between two GMT dates (inclusive start,
	 * exclusive end), restricted to a single source. Used as the rolling
	 * baseline, so the day count comes back with it: a handful of days after a
	 * source switch is not a baseline worth comparing against.
	 *
	 * @return array{value: float, days: int}|null
	 */
	public function baseline( string $url, string $metric, string $from_day, string $to_day, string $source ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT AVG(daily.value) AS avg_value, COUNT(*) AS days FROM (
					SELECT DATE(recorded_at) AS day, MIN(p75_value) AS value
					FROM %i
					WHERE url_hash = %s AND metric = %s AND sample_source = %s AND recorded_at >= %s AND recorded_at < %s
					GROUP BY DATE(recorded_at)
				) AS daily',
				$this->table(),
				Util::url_hash( $url ),
				$metric,
				$source,
				$from_day . ' 00:00:00',
				$to_day . ' 00:00:00'
			),
			ARRAY_A
		);
		if ( ! $row || null === $row['avg_value'] ) {
			return null;
		}
		return array(
			'value' => (float) $row['avg_value'],
			'days'  => (int) $row['days'],
		);
	}

	/**
	 * Daily series for sparklines. One point per day, taken from the highest
	 * priority source available that day rather than blended across sources,
	 * so a switch between URL- and origin-level data shows as a real step in
	 * the chart instead of a smeared average.
	 *
	 * @return array<int, array{day: string, value: float, source: string}>
	 */
	public function series( string $url, string $metric, int $days ): array {
		global $wpdb;
		$this->assert_source_counts();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(recorded_at) AS day,
					SUBSTRING_INDEX( GROUP_CONCAT( p75_value ORDER BY FIELD(sample_source, %s, %s, %s, %s), id DESC ), ',', 1 ) AS value,
					SUBSTRING_INDEX( GROUP_CONCAT( sample_source ORDER BY FIELD(sample_source, %s, %s, %s, %s), id DESC ), ',', 1 ) AS source
				FROM %i
				WHERE url_hash = %s AND metric = %s AND recorded_at >= %s
				GROUP BY DATE(recorded_at)
				ORDER BY day ASC",
				self::SOURCE_CRUX_URL,
				self::SOURCE_CRUX_LEGACY,
				self::SOURCE_CRUX_ORIGIN,
				self::SOURCE_RUM,
				self::SOURCE_CRUX_URL,
				self::SOURCE_CRUX_LEGACY,
				self::SOURCE_CRUX_ORIGIN,
				self::SOURCE_RUM,
				$this->table(),
				Util::url_hash( $url ),
				$metric,
				Util::days_ago( $days ) . ' 00:00:00'
			),
			ARRAY_A
		);
		return array_map(
			static fn( $row ) => array(
				'day'    => (string) $row['day'],
				'value'  => (float) $row['value'],
				'source' => (string) $row['source'],
			),
			$rows ?: array()
		);
	}

	/**
	 * Monthly average p75 per metric for one URL. Feeds the report engine.
	 *
	 * @return array<string, float|null>
	 */
	public function monthly_averages( string $url, int $month, int $year ): array {
		global $wpdb;
		$start = sprintf( '%04d-%02d-01 00:00:00', $year, $month );
		$end   = gmdate( 'Y-m-01 00:00:00', strtotime( $start . ' +1 month' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT metric, AVG(p75_value) AS avg_value FROM %i WHERE url_hash = %s AND recorded_at >= %s AND recorded_at < %s GROUP BY metric',
				$this->table(),
				Util::url_hash( $url ),
				$start,
				$end
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows ?: array() as $row ) {
			$out[ (string) $row['metric'] ] = (float) $row['avg_value'];
		}
		return $out;
	}

	public function prune( int $days ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE recorded_at < %s',
				$this->table(),
				Util::days_ago( $days ) . ' 00:00:00'
			)
		);
	}
}
