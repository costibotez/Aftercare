<?php
namespace Aftercare\Core;

use Aftercare\Admin\Menu;
use Aftercare\Incidents\Repository as IncidentRepository;
use Aftercare\Ledger\Listeners;
use Aftercare\Ledger\Repository as LedgerRepository;
use Aftercare\Licensing\License;
use Aftercare\Notifications\Emailer;
use Aftercare\Notifications\Slack;
use Aftercare\Notifications\Webhook;
use Aftercare\Reports\Builder as ReportBuilder;
use Aftercare\Reports\Repository as ReportRepository;
use Aftercare\Vitals\RumController;
use Aftercare\Vitals\SampleRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight service container and hook bootstrapper.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	/** @var array<string, object> */
	private array $services = array();

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Fetch (and lazily build) a shared service instance.
	 *
	 * @param string $class Fully qualified class name.
	 */
	public function get( string $class ): object {
		if ( ! isset( $this->services[ $class ] ) ) {
			$this->services[ $class ] = new $class();
		}
		return $this->services[ $class ];
	}

	public function boot(): void {
		Migrations::maybe_upgrade();

		( new Listeners( new LedgerRepository() ) )->register();
		( new Cron() )->register();
		( new RumController( new SampleRepository() ) )->register();

		// Notifications subscribe to incident lifecycle actions.
		( new Emailer() )->register();
		if ( License::is_pro() ) {
			( new Slack() )->register();
			( new Webhook() )->register();
		}

		if ( is_admin() ) {
			( new Menu() )->register();
		}
	}

	public function incidents(): IncidentRepository {
		return $this->get( IncidentRepository::class );
	}

	public function ledger(): LedgerRepository {
		return $this->get( LedgerRepository::class );
	}

	public function samples(): SampleRepository {
		return $this->get( SampleRepository::class );
	}

	public function reports(): ReportRepository {
		return $this->get( ReportRepository::class );
	}

	public function report_builder(): ReportBuilder {
		return $this->get( ReportBuilder::class );
	}
}
