<?php
namespace Aftercare\Vitals;

use Aftercare\Core\Cron;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Re-tests the site in the lab shortly after a change that can move
 * performance: a plugin or theme update, a plugin activation, a theme switch
 * or a core update.
 *
 * Changes are debounced. Every new change pushes the check back to
 * DELAY_SECONDS after it and joins the pending list, so a bulk update of a
 * dozen plugins produces one check that knows about all of them. The ledger
 * ids travel with the check into any incident it opens.
 */
final class LabScheduler {

	public const CHECK_HOOK  = 'aftercare_lab_change_check';
	public const PENDING_OPT = 'aftercare_lab_pending';
	public const COUNT_OPT   = 'aftercare_lab_change_count';

	/** Ledger event types that trigger a re-test. */
	public const TRIGGER_TYPES = array( 'plugin_update', 'plugin_activate', 'theme_update', 'theme_switch', 'core_update' );

	private const DELAY_SECONDS   = 15 * MINUTE_IN_SECONDS;
	private const STAGGER_SECONDS = 2 * MINUTE_IN_SECONDS;

	/** Above this many URLs, a change check takes one run per URL, not three. */
	private const FULL_RUN_MAX_URLS = 3;

	public function register(): void {
		add_action( 'aftercare_ledger_recorded', array( $this, 'on_ledger_recorded' ), 10, 2 );
		add_action( self::CHECK_HOOK, array( $this, 'run_check' ) );
	}

	/**
	 * @param int    $event_id
	 * @param string $event_type
	 */
	public function on_ledger_recorded( $event_id, $event_type ): void {
		if ( in_array( $event_type, self::TRIGGER_TYPES, true ) ) {
			self::queue( (int) $event_id );
		}
	}

	/**
	 * Add a change to the pending check and push the check back so it runs
	 * DELAY_SECONDS after the newest change.
	 */
	public static function queue( int $event_id ): void {
		if ( $event_id <= 0 || ! LabClient::enabled() ) {
			return;
		}

		$pending   = self::pending();
		$pending[] = $event_id;
		update_option( self::PENDING_OPT, array_values( array_unique( $pending ) ), false );

		Cron::unschedule_hook( self::CHECK_HOOK );
		Cron::schedule_single( time() + self::DELAY_SECONDS, self::CHECK_HOOK );
	}

	/**
	 * Ledger ids waiting for the next change check.
	 *
	 * @return int[]
	 */
	public static function pending(): array {
		$pending = get_option( self::PENDING_OPT, array() );
		return is_array( $pending ) ? array_map( 'intval', $pending ) : array();
	}

	/**
	 * Scheduled action: hand each URL to the lab client with the changes that
	 * prompted the check.
	 */
	public function run_check(): void {
		$event_ids = self::pending();
		delete_option( self::PENDING_OPT );
		if ( empty( $event_ids ) || ! LabClient::enabled() || ! self::take_daily_slot() ) {
			return;
		}

		$urls = LabClient::urls();
		$runs = count( $urls ) > self::FULL_RUN_MAX_URLS ? 1 : 0; // 0 = the client's default.
		$when = time();
		foreach ( $urls as $url ) {
			Cron::schedule_single( $when, LabClient::URL_HOOK, array( $url, $event_ids, $runs ) );
			$when += self::STAGGER_SECONDS;
		}
	}

	/**
	 * Count this check against the daily cap. A site that changes all day is
	 * still covered by the daily lab check, so going over the cap only drops
	 * the extra re-tests.
	 */
	private function take_daily_slot(): bool {
		/**
		 * Filter how many change-triggered lab checks may run per day.
		 *
		 * @param int $max Checks per GMT day.
		 */
		$max   = max( 0, (int) apply_filters( 'aftercare_lab_change_checks_per_day', 4 ) );
		$today = gmdate( 'Y-m-d' );
		$count = get_option( self::COUNT_OPT, array() );
		$used  = is_array( $count ) && ( $count['day'] ?? '' ) === $today ? (int) ( $count['count'] ?? 0 ) : 0;
		if ( $used >= $max ) {
			return false;
		}
		update_option(
			self::COUNT_OPT,
			array(
				'day'   => $today,
				'count' => $used + 1,
			),
			false
		);
		return true;
	}
}
