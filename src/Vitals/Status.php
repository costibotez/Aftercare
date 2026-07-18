<?php
namespace Aftercare\Vitals;

use Aftercare\Core\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared vitals status snapshot for the primary URL. Cached for 15 minutes so
 * the admin bar and dashboard widget stay cheap on every page load.
 */
final class Status {

	private const CACHE = 'aftercare_status_summary';

	/**
	 * Pass/warn/fail/empty verdict for a value against its budget.
	 */
	public static function evaluate( ?float $latest, float $budget ): string {
		if ( null === $latest ) {
			return 'empty';
		}
		if ( $budget > 0 && $latest > $budget ) {
			return 'fail';
		}
		if ( $budget > 0 && $latest > 0.8 * $budget ) {
			return 'warn';
		}
		return 'pass';
	}

	/**
	 * Per-metric snapshot for the primary URL.
	 *
	 * @return array<int, array{metric: string, value: float|null, budget: float, status: string}>
	 */
	public static function summary(): array {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$samples = new SampleRepository();
		$url     = Options::tracked_urls()[0];
		$out     = array();

		foreach ( Options::METRICS as $metric ) {
			$series = $samples->series( $url, $metric, 30 );
			$latest = ! empty( $series ) ? end( $series )['value'] : null;
			$budget = Options::budget( $metric, $url );
			$out[]  = array(
				'metric' => $metric,
				'value'  => $latest,
				'budget' => $budget,
				'status' => self::evaluate( $latest, $budget ),
			);
		}

		set_transient( self::CACHE, $out, 15 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Worst status across all metrics: fail > warn > pass > empty.
	 */
	public static function overall(): string {
		$rank  = array(
			'empty' => 0,
			'pass'  => 1,
			'warn'  => 2,
			'fail'  => 3,
		);
		$worst = 'empty';
		foreach ( self::summary() as $row ) {
			if ( $rank[ $row['status'] ] > $rank[ $worst ] ) {
				$worst = $row['status'];
			}
		}
		return $worst;
	}

	public static function flush_cache(): void {
		delete_transient( self::CACHE );
	}
}
