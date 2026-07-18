<?php
namespace Aftercare\Core;

use Aftercare\Ledger\Listeners;
use Aftercare\Ledger\Repository as LedgerRepository;
use Aftercare\Licensing\License;
use Aftercare\Reports\Builder as ReportBuilder;
use Aftercare\Vitals\BreachDetector;
use Aftercare\Vitals\CruxClient;
use Aftercare\Vitals\RumController;
use Aftercare\Vitals\SampleRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily pipeline: pull CrUX, aggregate RUM, detect breaches, prune old data,
 * and (Pro) generate the monthly report draft on the 1st.
 *
 * Uses Action Scheduler when present (e.g. WooCommerce installs), WP-Cron
 * otherwise.
 */
final class Cron {

	public const DAILY_HOOK    = 'aftercare_daily_tasks';
	public const LAST_RUN_OPT  = 'aftercare_cron_last_run';

	public function register(): void {
		add_action( self::DAILY_HOOK, array( $this, 'run_daily' ) );
		add_action( 'admin_notices', array( $this, 'maybe_warn_cron_health' ) );

		// Self-heal: if the recurring event vanished (e.g. after a cron reset), re-add it.
		if ( ! self::is_scheduled() ) {
			self::schedule();
		}
	}

	public static function schedule(): void {
		if ( self::uses_action_scheduler() ) {
			if ( false === as_next_scheduled_action( self::DAILY_HOOK ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::DAILY_HOOK, array(), 'aftercare' );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
	}

	public static function unschedule(): void {
		if ( self::uses_action_scheduler() ) {
			as_unschedule_all_actions( self::DAILY_HOOK, array(), 'aftercare' );
		}
		wp_clear_scheduled_hook( self::DAILY_HOOK );
	}

	private static function is_scheduled(): bool {
		if ( self::uses_action_scheduler() ) {
			return false !== as_next_scheduled_action( self::DAILY_HOOK );
		}
		return (bool) wp_next_scheduled( self::DAILY_HOOK );
	}

	private static function uses_action_scheduler(): bool {
		return function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_next_scheduled_action' )
			&& function_exists( 'as_unschedule_all_actions' );
	}

	public function run_daily(): void {
		update_option( self::LAST_RUN_OPT, time(), false );

		$samples = new SampleRepository();

		// 1. Pull yesterday's CrUX p75 values for every tracked URL.
		( new CruxClient( $samples ) )->pull_all();

		// 2. Fold buffered RUM beacons into daily p75 samples.
		RumController::aggregate_buffer( $samples );

		// 3. Compare against budgets and baseline; open/resolve incidents.
		( new BreachDetector( $samples ) )->run();

		// 4. Retention.
		$samples->prune( Options::vitals_retention_days() );
		$ledger_days = Options::ledger_retention_days();
		if ( $ledger_days > 0 ) {
			( new LedgerRepository() )->prune( $ledger_days );
		}

		// 5. Keep the plugin version snapshot fresh for update attribution.
		Listeners::snapshot_plugin_versions();

		// 6. Pro: draft last month's client report on the 1st of each month.
		if ( License::is_pro() && '1' === gmdate( 'j' ) ) {
			$prev_month = (int) gmdate( 'n', strtotime( 'first day of last month' ) );
			$prev_year  = (int) gmdate( 'Y', strtotime( 'first day of last month' ) );
			( new ReportBuilder() )->generate_draft( $prev_month, $prev_year );
		}
	}

	/**
	 * Warn when WP-Cron looks unreliable (disabled constant, or the daily task
	 * has not fired for over 48 hours).
	 */
	public function maybe_warn_cron_health(): void {
		if ( ! current_user_can( 'manage_options' ) || self::uses_action_scheduler() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! str_contains( (string) $screen->id, 'aftercare' ) ) {
			return;
		}

		$last     = (int) get_option( self::LAST_RUN_OPT, 0 );
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && ! self::real_cron_hint();
		$stale    = $last > 0 && ( time() - $last ) > 2 * DAY_IN_SECONDS;

		if ( $disabled || $stale ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Aftercare:', 'aftercare' ) . '</strong> ';
			echo esc_html__( 'WP-Cron does not appear to be running reliably on this site, so daily vitals collection and breach detection may be delayed. Configure a real server cron job that calls wp-cron.php, or install a plugin that provides Action Scheduler.', 'aftercare' );
			echo '</p></div>';
		}
	}

	private static function real_cron_hint(): bool {
		// If the daily task ran within the last 36h, some external cron is doing its job.
		$last = (int) get_option( self::LAST_RUN_OPT, 0 );
		return $last > 0 && ( time() - $last ) < 1.5 * DAY_IN_SECONDS;
	}
}
