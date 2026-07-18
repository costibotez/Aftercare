<?php
namespace Aftercare\Core;

use Aftercare\Incidents\Repository as IncidentRepository;
use Aftercare\Vitals\BreachDetector;
use Aftercare\Vitals\CruxClient;
use Aftercare\Vitals\RumController;
use Aftercare\Vitals\SampleRepository;
use Aftercare\Vitals\Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI: `wp aftercare <pull|check|status|run>`.
 *
 * Lets agencies script vitals collection from a real server cron instead of
 * relying on WP-Cron:
 *
 *     0 6 * * * wp aftercare run --path=/var/www/site
 */
final class CliCommands {

	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'aftercare pull', array( self::class, 'pull' ), array( 'shortdesc' => 'Pull today\'s Core Web Vitals from CrUX and aggregate buffered RUM samples.' ) );
		\WP_CLI::add_command( 'aftercare check', array( self::class, 'check' ), array( 'shortdesc' => 'Run breach detection against budgets and the 28-day baseline.' ) );
		\WP_CLI::add_command( 'aftercare status', array( self::class, 'status' ), array( 'shortdesc' => 'Show the latest p75 per metric, budgets and open incidents.' ) );
		\WP_CLI::add_command( 'aftercare run', array( self::class, 'run' ), array( 'shortdesc' => 'Run the full daily pipeline: pull, aggregate, detect, prune.' ) );
	}

	public static function pull(): void {
		if ( '' === (string) Options::get( 'api_key' ) ) {
			\WP_CLI::warning( 'No Google API key configured; skipping CrUX pull. Set one under Aftercare → Settings.' );
		}
		$samples = new SampleRepository();
		( new CruxClient( $samples ) )->pull_all();
		RumController::aggregate_buffer( $samples );
		Status::flush_cache();
		\WP_CLI::success( 'Vitals pull complete.' );
	}

	public static function check(): void {
		( new BreachDetector( new SampleRepository() ) )->run();
		Status::flush_cache();
		$open = ( new IncidentRepository() )->count_open();
		if ( $open > 0 ) {
			\WP_CLI::warning( sprintf( 'Breach detection complete: %d open incident(s).', $open ) );
		} else {
			\WP_CLI::success( 'Breach detection complete: no open incidents.' );
		}
	}

	public static function status(): void {
		$rows = array();
		foreach ( Status::summary() as $row ) {
			$rows[] = array(
				'metric' => $row['metric'],
				'p75'    => null !== $row['value'] ? Util::format_metric( $row['metric'], (float) $row['value'] ) : '-',
				'budget' => Util::format_metric( $row['metric'], (float) $row['budget'] ),
				'status' => $row['status'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'metric', 'p75', 'budget', 'status' ) );

		$open = ( new IncidentRepository() )->count_open();
		\WP_CLI::log( sprintf( 'Open incidents: %d', $open ) );
	}

	public static function run(): void {
		( new Cron() )->run_daily();
		Status::flush_cache();
		\WP_CLI::success( 'Daily pipeline complete (pull, RUM aggregation, breach detection, retention).' );
	}
}
