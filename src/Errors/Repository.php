<?php
namespace Aftercare\Errors;

use Aftercare\Core\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage for PHP fatal errors, one row per signature (type, file and line).
 *
 * The write happens inside a dying request, so it is a single upsert behind
 * a single read, with database errors suppressed: a fatal on every request
 * must cost two queries, never a flood of rows or a second error.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repository for the plugin's own custom table; direct queries are the point.
final class Repository {

	/**
	 * A signature silent for this long counts as new again when it returns:
	 * it gets a fresh ledger entry and can open a fresh incident.
	 */
	public const QUIET_SECONDS = DAY_IN_SECONDS;

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aftercare_errors';
	}

	/**
	 * Count one occurrence.
	 *
	 * @return bool True when the signature is new, or back after QUIET_SECONDS.
	 */
	public function record( string $signature, int $type, string $file, int $line, string $message, string $culprit ): bool {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		$previous = $wpdb->get_var(
			$wpdb->prepare( 'SELECT last_seen FROM %i WHERE signature = %s', $this->table(), $signature )
		);
		$now      = Util::now();
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (signature, error_type, location, file, line, message, culprit, occurrences, first_seen, last_seen)
				VALUES (%s, %d, %s, %s, %d, %s, %s, 1, %s, %s)
				ON DUPLICATE KEY UPDATE occurrences = occurrences + 1, last_seen = VALUES(last_seen), message = VALUES(message)',
				$this->table(),
				$signature,
				$type,
				mb_substr( self::location( $file, $line ), 0, 255 ),
				mb_substr( $file, 0, 255 ),
				$line,
				mb_substr( $message, 0, 255 ),
				mb_substr( $culprit, 0, 191 ),
				$now,
				$now
			)
		);
		$wpdb->suppress_errors( $suppress );

		return null === $previous || strtotime( $previous . ' UTC' ) < time() - self::QUIET_SECONDS;
	}

	/**
	 * Human-readable place of an error: "plugins/acme/acme.php:12".
	 */
	public static function location( string $file, int $line ): string {
		return $file . ':' . $line;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_by_signature( string $signature ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE signature = %s', $this->table(), $signature ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Every signature, most recently seen first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all( int $limit = 200 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY last_seen DESC LIMIT %d', $this->table(), $limit ),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/**
	 * Signatures seen since a GMT datetime.
	 */
	public function count_seen_since( string $from_gmt ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE last_seen >= %s', $this->table(), $from_gmt )
		);
	}

	public function prune( int $days ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %s', $this->table(), Util::days_ago( $days ) . ' 00:00:00' )
		);
	}
}
