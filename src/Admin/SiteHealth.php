<?php
namespace Aftercare\Admin;

use Aftercare\Core\Cron;
use Aftercare\Core\Options;
use Aftercare\Incidents\Repository as IncidentRepository;
use Aftercare\Vitals\LabClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site Health tests: is vitals collection configured, is the daily pipeline
 * actually running, are lab checks reaching PageSpeed Insights and are any
 * budgets currently breached.
 */
final class SiteHealth {

	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
	}

	/**
	 * @param array<string, mixed> $tests
	 * @return array<string, mixed>
	 */
	public function add_tests( array $tests ): array {
		$tests['direct']['aftercare_api_key']   = array(
			'label' => __( 'Aftercare can collect Core Web Vitals', 'aftercare' ),
			'test'  => array( $this, 'test_api_key' ),
		);
		$tests['direct']['aftercare_cron']      = array(
			'label' => __( 'Aftercare daily checks are running', 'aftercare' ),
			'test'  => array( $this, 'test_cron' ),
		);
		// Only when lab checks are on: a disabled feature is not a problem.
		// The test reads the outcome of the last real check rather than making
		// a Lighthouse call, which takes up to 30 seconds per run.
		if ( LabClient::enabled() ) {
			$tests['direct']['aftercare_lab'] = array(
				'label' => __( 'Aftercare lab checks reach PageSpeed Insights', 'aftercare' ),
				'test'  => array( $this, 'test_lab' ),
			);
		}
		$tests['direct']['aftercare_incidents'] = array(
			'label' => __( 'Site performance is within budget', 'aftercare' ),
			'test'  => array( $this, 'test_incidents' ),
		);
		return $tests;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function test_api_key(): array {
		$configured = '' !== (string) Options::get( 'api_key' );
		return $this->result(
			'aftercare_api_key',
			$configured ? 'good' : 'recommended',
			$configured
				? __( 'Aftercare has a Google API key and can pull Core Web Vitals', 'aftercare' )
				: __( 'Aftercare has no Google API key configured', 'aftercare' ),
			$configured
				? __( 'Daily p75 vitals are collected from the Chrome UX Report.', 'aftercare' )
				: __( 'Without a Chrome UX Report API key, Aftercare cannot collect Core Web Vitals or detect regressions. The key is free.', 'aftercare' ),
			$configured ? '' : $this->settings_action()
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function test_cron(): array {
		$last  = (int) get_option( Cron::LAST_RUN_OPT, 0 );
		$stale = $last > 0 && ( time() - $last ) > 2 * DAY_IN_SECONDS;
		$never = 0 === $last;

		if ( ! $stale && ! $never ) {
			return $this->result(
				'aftercare_cron',
				'good',
				__( 'Aftercare daily checks are running', 'aftercare' ),
				sprintf(
					/* translators: %s: human time diff */
					__( 'The daily vitals pull and breach detection last ran %s ago.', 'aftercare' ),
					human_time_diff( $last )
				)
			);
		}

		return $this->result(
			'aftercare_cron',
			'recommended',
			$never
				? __( 'Aftercare daily checks have not run yet', 'aftercare' )
				: __( 'Aftercare daily checks look stalled', 'aftercare' ),
			__( 'Vitals collection and breach detection run via WP-Cron (or Action Scheduler when available). If this persists, configure a real server cron job that calls wp-cron.php, or run the checks manually from the Aftercare dashboard.', 'aftercare' ),
			$this->dashboard_action()
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function test_lab(): array {
		$error   = LabClient::last_error();
		$success = (int) get_option( LabClient::SUCCESS_OPT, 0 );

		if ( $error && 403 === $error['status'] ) {
			return $this->result(
				'aftercare_lab',
				'recommended',
				__( 'The PageSpeed Insights API is not enabled for Aftercare\'s Google API key', 'aftercare' ),
				sprintf(
					/* translators: %s: error message from Google */
					__( 'Lab checks use the same key as the Chrome UX Report, but the PageSpeed Insights API has to be enabled for it separately, and an API restriction on the key must allow it. Google said: %s', 'aftercare' ),
					$error['message']
				),
				'<a href="https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com" target="_blank" rel="noopener">' . esc_html__( 'Enable the PageSpeed Insights API', 'aftercare' ) . '</a>'
			);
		}

		if ( $error ) {
			return $this->result(
				'aftercare_lab',
				'recommended',
				__( 'Aftercare lab checks are failing', 'aftercare' ),
				sprintf(
					/* translators: 1: URL, 2: error message */
					__( 'The last lab check of %1$s failed: %2$s Google loads the page from its own servers, so it must be publicly reachable (not behind a login, a maintenance page or a firewall).', 'aftercare' ),
					$error['url'],
					$error['message']
				),
				$this->dashboard_action()
			);
		}

		if ( 0 === $success ) {
			return $this->result(
				'aftercare_lab',
				'good',
				__( 'Aftercare lab checks are set up', 'aftercare' ),
				__( 'No lab check has completed yet. The first one runs with the next daily checks, or right away with the wp aftercare lab command.', 'aftercare' )
			);
		}

		if ( time() - $success > 2 * DAY_IN_SECONDS ) {
			return $this->result(
				'aftercare_lab',
				'recommended',
				__( 'Aftercare lab checks have stopped completing', 'aftercare' ),
				sprintf(
					/* translators: %s: human time diff */
					__( 'The last lab check completed %s ago. Lab checks run as scheduled tasks, so check that WP-Cron or Action Scheduler is running.', 'aftercare' ),
					human_time_diff( $success )
				),
				$this->dashboard_action()
			);
		}

		return $this->result(
			'aftercare_lab',
			'good',
			__( 'Aftercare lab checks reach PageSpeed Insights', 'aftercare' ),
			sprintf(
				/* translators: %s: human time diff */
				__( 'The last lab check completed %s ago.', 'aftercare' ),
				human_time_diff( $success )
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function test_incidents(): array {
		$open = ( new IncidentRepository() )->count_open();
		if ( 0 === $open ) {
			return $this->result(
				'aftercare_incidents',
				'good',
				__( 'No open performance incidents', 'aftercare' ),
				__( 'All monitored Core Web Vitals are within their performance budgets.', 'aftercare' )
			);
		}
		return $this->result(
			'aftercare_incidents',
			'recommended',
			sprintf(
				/* translators: %d: number of open incidents */
				_n( '%d performance budget is currently breached', '%d performance budgets are currently breached', $open, 'aftercare' ),
				$open
			),
			__( 'One or more Core Web Vitals metrics regressed past their budget or baseline. Open the incident to see every site change from the 72 hours before the regression.', 'aftercare' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=aftercare-incidents' ) ) . '">' . esc_html__( 'View incidents', 'aftercare' ) . '</a>'
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function result( string $test, string $status, string $label, string $description, string $actions = '' ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Performance', 'aftercare' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $actions,
			'test'        => $test,
		);
	}

	private function settings_action(): string {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=aftercare-settings' ) ) . '">' . esc_html__( 'Open Aftercare Settings', 'aftercare' ) . '</a>';
	}

	private function dashboard_action(): string {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=aftercare' ) ) . '">' . esc_html__( 'Open the Aftercare dashboard', 'aftercare' ) . '</a>';
	}
}
