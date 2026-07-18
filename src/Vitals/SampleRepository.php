<?php
namespace Aftercare\Vitals;

use Aftercare\Core\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage for daily p75 vitals samples.
 */
final class SampleRepository {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aftercare_vitals_samples';
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
				"SELECT id FROM {$this->table()} WHERE url_hash = %s AND metric = %s AND sample_source = %s AND recorded_at >= %s AND recorded_at < %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Util::url_hash( $url ),
				$metric,
				$source,
				$day . ' 00:00:00',
				$day . ' 23:59:59'
			)
		);
	}

	/**
	 * Latest p75 for a given GMT day. CrUX wins over RUM when both exist.
	 */
	public function p75_for_day( string $url, string $metric, string $day ): ?float {
		global $wpdb;
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p75_value FROM {$this->table()} WHERE url_hash = %s AND metric = %s AND recorded_at >= %s AND recorded_at < %s ORDER BY FIELD(sample_source, 'crux', 'rum'), id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Util::url_hash( $url ),
				$metric,
				$day . ' 00:00:00',
				$day . ' 23:59:59'
			)
		);
		return null === $value ? null : (float) $value;
	}

	/**
	 * Average of daily p75 values between two GMT dates (inclusive start,
	 * exclusive end). Used as the rolling baseline.
	 */
	public function baseline( string $url, string $metric, string $from_day, string $to_day ): ?float {
		global $wpdb;
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(p75_value) FROM {$this->table()} WHERE url_hash = %s AND metric = %s AND recorded_at >= %s AND recorded_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Util::url_hash( $url ),
				$metric,
				$from_day . ' 00:00:00',
				$to_day . ' 00:00:00'
			)
		);
		return null === $value ? null : (float) $value;
	}

	/**
	 * Daily series for sparklines: [ [ 'day' => 'Y-m-d', 'value' => float ], ... ].
	 *
	 * @return array<int, array{day: string, value: float}>
	 */
	public function series( string $url, string $metric, int $days ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(recorded_at) AS day, MIN(p75_value) AS value FROM {$this->table()} WHERE url_hash = %s AND metric = %s AND recorded_at >= %s GROUP BY DATE(recorded_at) ORDER BY day ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Util::url_hash( $url ),
				$metric,
				Util::days_ago( $days ) . ' 00:00:00'
			),
			ARRAY_A
		);
		return array_map(
			static fn( $row ) => array(
				'day'   => (string) $row['day'],
				'value' => (float) $row['value'],
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
				"SELECT metric, AVG(p75_value) AS avg_value FROM {$this->table()} WHERE url_hash = %s AND recorded_at >= %s AND recorded_at < %s GROUP BY metric", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
				"DELETE FROM {$this->table()} WHERE recorded_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Util::days_ago( $days ) . ' 00:00:00'
			)
		);
	}
}
