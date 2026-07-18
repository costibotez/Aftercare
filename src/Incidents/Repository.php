<?php
namespace Aftercare\Incidents;

use Aftercare\Core\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage for detected regressions.
 */
final class Repository {

	public const STATUSES = array( 'open', 'acknowledged', 'resolved', 'dismissed' );

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aftercare_incidents';
	}

	public function open( string $url, string $metric, ?float $baseline, float $breach, float $budget ): int {
		global $wpdb;
		$wpdb->insert(
			$this->table(),
			array(
				'metric'       => $metric,
				'url_hash'     => Util::url_hash( $url ),
				'url'          => $url,
				'baseline_p75' => $baseline,
				'breach_p75'   => $breach,
				'budget_value' => $budget,
				'status'       => 'open',
				'opened_at'    => Util::now(),
			),
			array( '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s' )
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
	 * Open or acknowledged incident for a URL+metric pair, if any.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_open( string $url, string $metric ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE url_hash = %s AND metric = %s AND status IN ('open','acknowledged') ORDER BY opened_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Util::url_hash( $url ),
				$metric
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
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()} WHERE status IN ('open','acknowledged')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
