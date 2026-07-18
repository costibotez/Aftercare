<?php
namespace Aftercare\Core;

use Aftercare\Ledger\Listeners;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation / deactivation lifecycle.
 */
final class Activator {

	public static function activate(): void {
		Migrations::run();

		// Seed the settings row so autoload behaviour is deterministic.
		if ( false === get_option( Options::OPTION ) ) {
			add_option( Options::OPTION, array(), '', false );
		}

		Listeners::snapshot_plugin_versions();
		Cron::schedule();
	}

	public static function deactivate(): void {
		Cron::unschedule();
	}
}
