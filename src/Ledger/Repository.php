<?php
namespace Aftercare\Ledger;

use Aftercare\Core\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage and queries for the change ledger.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repository for the plugin's own custom table; direct queries are the point. Hot reads are cached at the Vitals\Status snapshot layer.
final class Repository {

	public const EVENT_TYPES = array(
		'plugin_update',
		'plugin_activate',
		'plugin_deactivate',
		'theme_update',
		'theme_switch',
		'core_update',
		'settings_change',
		'content_publish',
		'user_created',
	);

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aftercare_ledger_events';
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function record( string $event_type, string $summary, array $payload = array() ): void {
		global $wpdb;

		if ( ! in_array( $event_type, self::EVENT_TYPES, true ) ) {
			return;
		}

		$user        = wp_get_current_user();
		$actor_id    = $user && $user->exists() ? (int) $user->ID : 0;
		$actor_label = $actor_id ? $user->user_login : ( wp_doing_cron() ? 'system (cron)' : 'system' );

		$wpdb->insert(
			$this->table(),
			array(
				'event_type'  => $event_type,
				'summary'     => mb_substr( $summary, 0, 255 ),
				'actor_id'    => $actor_id,
				'actor_label' => mb_substr( $actor_label, 0, 100 ),
				'payload'     => wp_json_encode( $payload ),
				'occurred_at' => Util::now(),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * @param array{type?: string, from?: string, to?: string} $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function query( array $filters = array(), int $per_page = 50, int $page = 1 ): array {
		global $wpdb;

		[ $where, $params ] = $this->build_where( $filters );

		$params[] = $per_page;
		$params[] = max( 0, ( $page - 1 ) * $per_page );

		$rows = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is assembled from literal SQL fragments only; all values go through prepare().
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- additional placeholders live inside $where; counts always match by construction.
				"SELECT * FROM {$this->table()} {$where} ORDER BY occurred_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$params
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/**
	 * @param array{type?: string, from?: string, to?: string} $filters
	 */
	public function count( array $filters = array() ): int {
		global $wpdb;
		[ $where, $params ] = $this->build_where( $filters );
		if ( $params ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} {$where}", ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders live inside $where, which is assembled from literal SQL fragments; values go through prepare().
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix, no user input.
	}

	/**
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function build_where( array $filters ): array {
		$clauses = array();
		$params  = array();

		if ( ! empty( $filters['type'] ) && in_array( $filters['type'], self::EVENT_TYPES, true ) ) {
			$clauses[] = 'event_type = %s';
			$params[]  = $filters['type'];
		}
		if ( ! empty( $filters['from'] ) ) {
			$clauses[] = 'occurred_at >= %s';
			$params[]  = $filters['from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['to'] ) ) {
			$clauses[] = 'occurred_at <= %s';
			$params[]  = $filters['to'] . ' 23:59:59';
		}

		return array( $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '', $params );
	}

	/**
	 * Events inside a time window (used by the attribution engine).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function in_window( string $from_gmt, string $to_gmt ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE occurred_at >= %s AND occurred_at <= %s ORDER BY occurred_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from_gmt,
				$to_gmt
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/**
	 * Count per event type for a calendar month (report engine).
	 *
	 * @return array<string, int>
	 */
	public function monthly_counts( int $month, int $year ): array {
		global $wpdb;
		$start = sprintf( '%04d-%02d-01 00:00:00', $year, $month );
		$end   = gmdate( 'Y-m-01 00:00:00', strtotime( $start . ' +1 month' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_type, COUNT(*) AS total FROM {$this->table()} WHERE occurred_at >= %s AND occurred_at < %s GROUP BY event_type", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$start,
				$end
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows ?: array() as $row ) {
			$out[ (string) $row['event_type'] ] = (int) $row['total'];
		}
		return $out;
	}

	/**
	 * Count per event type inside an arbitrary GMT window (weekly digest).
	 *
	 * @return array<string, int>
	 */
	public function counts_between( string $from_gmt, string $to_gmt ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_type, COUNT(*) AS total FROM {$this->table()} WHERE occurred_at >= %s AND occurred_at <= %s GROUP BY event_type ORDER BY total DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from_gmt,
				$to_gmt
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows ?: array() as $row ) {
			$out[ (string) $row['event_type'] ] = (int) $row['total'];
		}
		return $out;
	}

	public function prune( int $days ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table()} WHERE occurred_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Util::days_ago( $days ) . ' 00:00:00'
			)
		);
	}
}
