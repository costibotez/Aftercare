<?php
namespace Aftercare\Reports;

use Aftercare\Core\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage for generated client reports (Pro).
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repository for the plugin's own custom table; direct queries are the point.
final class Repository {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aftercare_reports';
	}

	/**
	 * @param array<string, mixed> $branding
	 * @param array<string, mixed> $stats
	 */
	public function save_draft( int $month, int $year, array $branding, array $stats, string $html ): int {
		global $wpdb;
		$existing = $this->find_by_period( $month, $year );
		$data     = array(
			'period_month'  => $month,
			'period_year'   => $year,
			'branding'      => wp_json_encode( $branding ),
			'summary_stats' => wp_json_encode( $stats ),
			'content_html'  => $html,
			'generated_at'  => Util::now(),
		);
		if ( $existing ) {
			if ( 'sent' === $existing['status'] ) {
				return (int) $existing['id']; // Never overwrite a sent report.
			}
			$wpdb->update( $this->table(), $data, array( 'id' => (int) $existing['id'] ) );
			return (int) $existing['id'];
		}
		$data['status'] = 'draft';
		$wpdb->insert( $this->table(), $data );
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
	 * @return array<string, mixed>|null
	 */
	public function find_by_period( int $month, int $year ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE period_month = %d AND period_year = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$month,
				$year
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT * FROM {$this->table()} ORDER BY period_year DESC, period_month DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return $rows ?: array();
	}

	public function set_personal_note( int $id, string $note ): void {
		global $wpdb;
		$wpdb->update( $this->table(), array( 'personal_note' => $note ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	public function mark_sent( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			array(
				'status'  => 'sent',
				'sent_at' => Util::now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}
}
