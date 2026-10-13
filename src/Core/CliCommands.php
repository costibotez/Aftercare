<?php
namespace Aftercare\Core;

use Aftercare\Incidents\Repository as IncidentRepository;
use Aftercare\Vitals\BreachDetector;
use Aftercare\Vitals\CruxClient;
use Aftercare\Vitals\LabClient;
use Aftercare\Vitals\RumController;
use Aftercare\Vitals\SampleRepository;
use Aftercare\Vitals\Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI: `wp aftercare <pull|check|status|run|lab>`.
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
		\WP_CLI::add_command(
			'aftercare lab',
			array( self::class, 'lab' ),
			array(
				'shortdesc' => 'Run a Lighthouse lab check now, store the result and judge it against the lab baseline.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'page',
						'description' => 'Tracked URL to test. Defaults to every URL the lab checks cover. (Named page because --url is a global WP-CLI option.)',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'runs',
						'description' => 'Lighthouse runs per URL, 1 to 5. The median is stored.',
						'optional'    => true,
						'default'     => 3,
					),
				),
			)
		);
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

	/**
	 * Runs synchronously, one URL after another; each Lighthouse run takes 10
	 * to 30 seconds.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public static function lab( array $args, array $assoc_args ): void {
		if ( '' === (string) Options::get( 'api_key' ) ) {
			\WP_CLI::error( 'No Google API key configured. Set one under Aftercare → Settings.' );
		}
		if ( ! LabClient::enabled() ) {
			\WP_CLI::error( 'Lab checks are turned off. Enable them under Aftercare → Settings.' );
		}

		$urls = LabClient::urls();
		if ( isset( $assoc_args['page'] ) ) {
			$wanted = Util::url_hash( (string) $assoc_args['page'] );
			$urls   = array_values( array_filter( Options::tracked_urls(), static fn( $url ) => Util::url_hash( $url ) === $wanted ) );
			if ( empty( $urls ) ) {
				\WP_CLI::error( 'That URL is not tracked. Add it under Aftercare → Settings first, so its lab history has a home.' );
			}
		}
		$runs = max( 1, min( 5, (int) ( $assoc_args['runs'] ?? 3 ) ) );

		$samples = new SampleRepository();
		$client  = new LabClient( $samples );
		$rows    = array();
		$failed  = 0;
		foreach ( $urls as $url ) {
			\WP_CLI::log( sprintf( 'Testing %s (%d run%s)...', $url, $runs, 1 === $runs ? '' : 's' ) );
			delete_option( LabClient::ERROR_OPT );
			$client->run_url( $url, array(), $runs );

			$error = LabClient::last_error();
			if ( $error ) {
				++$failed;
				\WP_CLI::warning( sprintf( '%s: %s', $url, $error['message'] ) );
				continue;
			}
			$row = array( 'url' => $url );
			foreach ( Options::LAB_METRICS as $metric ) {
				$series         = $samples->lab_series( $url, $metric, 0 );
				$row[ $metric ] = $series ? Util::format_metric( $metric, (float) end( $series )['value'] ) : '-';
			}
			$rows[] = $row;
		}

		if ( $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array_merge( array( 'url' ), Options::LAB_METRICS ) );
		}
		\WP_CLI::log( sprintf( 'Open incidents: %d', ( new IncidentRepository() )->count_open() ) );

		if ( count( $urls ) === $failed ) {
			\WP_CLI::error( 'Every lab check failed.' );
		}
		\WP_CLI::success( sprintf( 'Lab check complete for %d of %d URLs.', count( $urls ) - $failed, count( $urls ) ) );
	}

	public static function run(): void {
		( new Cron() )->run_daily();
		Status::flush_cache();
		\WP_CLI::success( 'Daily pipeline complete (pull, RUM aggregation, breach detection, retention).' );
	}
}
