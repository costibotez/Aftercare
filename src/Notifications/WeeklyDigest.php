<?php
namespace Aftercare\Notifications;

use Aftercare\Core\Cron;
use Aftercare\Core\Options;
use Aftercare\Core\Util;
use Aftercare\Incidents\Repository as IncidentRepository;
use Aftercare\Ledger\Repository as LedgerRepository;
use Aftercare\Vitals\Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Free weekly summary email: vitals status, changes made and incidents from
 * the past 7 days. Opt-out via settings.
 */
final class WeeklyDigest {

	public function register(): void {
		add_action( Cron::WEEKLY_HOOK, array( $this, 'send' ) );
	}

	public function send(): void {
		if ( ! Options::get( 'weekly_digest' ) ) {
			return;
		}
		$to = sanitize_email( (string) Options::get( 'alert_email' ) );
		if ( '' === $to ) {
			return;
		}

		$from = Util::days_ago( 7 ) . ' 00:00:00';
		$now  = Util::now();

		$aftercare_digest = array(
			'vitals'        => Status::summary(),
			'ledger_counts' => ( new LedgerRepository() )->counts_between( $from, $now ),
			'incidents'     => ( new IncidentRepository() )->opened_since( $from ),
			'site_name'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Weekly site care digest', 'aftercare' ),
			$aftercare_digest['site_name']
		);

		ob_start();
		include AFTERCARE_DIR . 'templates/email-digest.php';
		$body = (string) ob_get_clean();

		wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
