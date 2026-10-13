<?php
namespace Aftercare\Incidents;

use Aftercare\Core\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage for detected regressions.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repository for the plugin's own custom table; direct queries are the point. Hot reads are cached at the Vitals\Status snapshot layer.
final class Repository {

	public const STATUSES = array( 'open', 'acknowledged', 'resolved', 'dismissed' );

	/** Incident raised from field data (Chrome UX Report or real-user beacons). */
	public const SOURCE_FIELD = 'field';

	/**
	 * Incident raised from Lighthouse lab checks. Tracked apart from field
	 * incidents so a recovery in one never resolves the other.
	 */
	public const SOURCE_LAB = 'lab';

	/**
	 * Metric name for display: "LCP", or "LCP (lab)" for a lab incident.
	 *
	 * @param array<string, mixed> $incident
	 */
	public static function label( array $incident ): string {
		$metric = (string) ( $incident['metric'] ?? '' );
		if ( self::SOURCE_LAB === ( $incident['sample_source'] ?? '' ) ) {
			/* translators: %s: metric name such as LCP */
			return sprintf( __( '%s (lab)', 'aftercare' ), $metric );
		}
		return $metric;
	}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aftercare_incidents';
	}

	public function open( string $url, string $metric, ?float $baseline, float $breach, float $budget, string $source = self::SOURCE_FIELD ): int {
		global $wpdb;
		$wpdb->insert(
			$this->table(),
			array(
				'metric'        => $metric,
				'url_hash'      => Util::url_hash( $url ),
				'url'           => $url,
				'baseline_p75'  => $baseline,
				'breach_p75'    => $breach,
				'budget_value'  => $budget,
				'status'        => 'open',
				'opened_at'     => Util::now(),
				'sample_source' => $source,
			),
			array( '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Open or acknowledged incident for a URL, metric and source, if any.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_open( string $url, string $metric, string $source = self::SOURCE_FIELD ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE url_hash = %s AND metric = %s AND sample_source = %s AND status IN ('open','acknowledged') ORDER BY opened_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Util::url_hash( $url ),
				$metric,
				$source
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * @param array<int, array<string, mixed>> $causes
	 */
	public function set_causes( int $id, array $causes ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			array( 'causes' => wp_json_encode( $causes ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public function set_status( int $id, string $status ): void {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return;
		}
		$data   = array( 'status' => $status );
		$format = array( '%s' );
		if ( in_array( $status, array( 'resolved', 'dismissed' ), true ) ) {
			$data['resolved_at'] = Util::now();
			$format[]            = '%s';
		}
		$wpdb->update( $this->table(), $data, array( 'id' => $id ), $format, array( '%d' ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function all( ?string $status = null, int $limit = 100 ): array {
		global $wpdb;
		if ( $status && in_array( $status, self::STATUSES, true ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table()} WHERE status = %s ORDER BY opened_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$status,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$this->table()} ORDER BY opened_at DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
		}
		return $rows ?: array();
	}

	public function count_open(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()} WHERE status IN ('open','acknowledged')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix, no user input.
	}

	/**
	 * Incidents opened since a GMT datetime (weekly digest).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function opened_since( string $from_gmt ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE opened_at >= %s ORDER BY opened_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from_gmt
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/**
	 * Incidents opened inside one calendar month (report engine).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_month( int $month, int $year ): array {
		global $wpdb;
		$start = sprintf( '%04d-%02d-01 00:00:00', $year, $month );
		$end   = gmdate( 'Y-m-01 00:00:00', strtotime( $start . ' +1 month' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE opened_at >= %s AND opened_at < %s ORDER BY opened_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$start,
				$end
			),
			ARRAY_A
		);
		return $rows ?: array();
	}
}
