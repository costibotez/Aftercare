<?php
/**
 * Compatibility checks run by bin/compat-test.sh against a real WordPress
 * install. Each step loads WordPress, exercises the plugin and exits non-zero
 * on the first failed assertion.
 *
 * Usage: php checks.php <wordpress-dir> <install|activate|flow|teardown>
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$wp_dir = rtrim( $argv[1] ?? '', '/' );
$step   = $argv[2] ?? '';
$home   = getenv( 'AFTERCARE_TEST_URL' ) ?: 'http://localhost:8899';

$_SERVER['HTTP_HOST']   = (string) compat_host( $home );
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = 'localhost';

if ( 'install' === $step ) {
	define( 'WP_INSTALLING', true );
}

require $wp_dir . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

function compat_host( string $url ): string {
	$parts = parse_url( $url );
	return $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
}

function check( bool $ok, string $label ): void {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		exit( 1 );
	}
}

function aftercare_tables(): array {
	global $wpdb;
	return $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'aftercare_' ) . '%' ) );
}

function aftercare_cron_hooks(): array {
	$hooks = array();
	foreach ( (array) _get_cron_array() as $events ) {
		foreach ( array_keys( (array) $events ) as $hook ) {
			if ( str_starts_with( $hook, 'aftercare_' ) ) {
				$hooks[] = $hook;
			}
		}
	}
	return $hooks;
}

/**
 * Argument lists of every pending WP-Cron event for one hook.
 */
function aftercare_cron_args( string $hook ): array {
	$args = array();
	foreach ( (array) _get_cron_array() as $events ) {
		foreach ( (array) ( $events[ $hook ] ?? array() ) as $event ) {
			$args[] = $event['args'];
		}
	}
	return $args;
}

if ( 'install' !== $step ) {
	wp_set_current_user( 1 );
}

switch ( $step ) {
	case 'install':
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( ! is_blog_installed() ) {
			wp_install( 'Aftercare Compat', 'admin', 'admin@example.com', true, '', 'password' );
		}
		update_option( 'siteurl', $home );
		update_option( 'home', $home );
		check( is_blog_installed(), 'WordPress ' . get_bloginfo( 'version' ) . ' installed' );
		break;

	case 'activate':
		$result = activate_plugin( 'aftercare/aftercare.php' );
		check( ! is_wp_error( $result ), 'plugin activates' . ( is_wp_error( $result ) ? ': ' . $result->get_error_message() : '' ) );
		check( is_plugin_active( 'aftercare/aftercare.php' ), 'plugin is active' );
		check( 5 === count( aftercare_tables() ), 'five tables created' );
		$hooks = aftercare_cron_hooks();
		check( in_array( 'aftercare_daily_tasks', $hooks, true ), 'daily cron scheduled' );
		check( in_array( 'aftercare_weekly_digest', $hooks, true ), 'weekly digest cron scheduled' );
		check( in_array( 'aftercare_hourly_tasks', $hooks, true ), 'hourly cron scheduled' );
		break;

	case 'flow':
		$mails = array();
		add_filter(
			'pre_wp_mail',
			static function ( $short, $atts ) use ( &$mails ) {
				$mails[] = $atts['subject'];
				return true;
			},
			20,
			2
		);

		$plugin = Aftercare\Core\Plugin::instance();
		$before = $plugin->ledger()->count();

		activate_plugin( 'hello.php' );
		deactivate_plugins( 'hello.php' );
		$theme = wp_get_theme( 'twentytwentyfour' )->exists() ? 'twentytwentyfour' : WP_DEFAULT_THEME;
		switch_theme( $theme );
		update_option( 'blogname', 'Renamed site' );
		wp_insert_post(
			array(
				'post_title'   => 'Compat post',
				'post_status'  => 'publish',
				'post_content' => 'x',
			)
		);
		wp_insert_user(
			array(
				'user_login' => 'compat_user',
				'user_pass'  => wp_generate_password(),
				'user_email' => 'compat@example.com',
			)
		);

		$types = wp_list_pluck( $plugin->ledger()->query( array(), 50 ), 'event_type' );
		foreach ( array( 'plugin_activate', 'plugin_deactivate', 'settings_change', 'content_publish', 'user_created' ) as $type ) {
			check( in_array( $type, $types, true ), "ledger records $type" );
		}
		check( $plugin->ledger()->count() > $before, 'ledger count grows' );

		// Seed a stable LCP history then a regression yesterday.
		$url = home_url( '/' );
		for ( $day = 2; $day <= 12; $day++ ) {
			$plugin->samples()->insert( $url, 'LCP', 2000, 'crux', Aftercare\Core\Util::days_ago( $day ) . ' 06:00:00' );
		}
		$plugin->samples()->insert( $url, 'LCP', 4000, 'crux', Aftercare\Core\Util::days_ago( 1 ) . ' 06:00:00' );
		( new Aftercare\Core\Cron() )->run_daily();

		check( 1 === $plugin->incidents()->count_open(), 'regression opens one incident' );
		check( (bool) preg_grep( '/regression/i', $mails ), 'incident alert email sent' );

		do_action( 'aftercare_weekly_digest' );
		check( (bool) preg_grep( '/digest/i', $mails ), 'weekly digest email sent' );

		// Lab check: a mocked PageSpeed Insights response is stored as lab
		// samples and never leaks into the field readings.
		$psi_calls = 0;
		$psi_lcp    = 3000;
		$psi_status = 200;
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $request_url ) use ( &$psi_calls, &$psi_lcp, &$psi_status ) {
				if ( ! str_contains( $request_url, 'pagespeedonline' ) ) {
					return $pre;
				}
				++$psi_calls;
				if ( 200 !== $psi_status ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode( array( 'error' => array( 'message' => 'PageSpeed Insights API has not been used in project 123 before or it is disabled.' ) ) ),
						'response' => array(
							'code'    => $psi_status,
							'message' => 'Forbidden',
						),
						'cookies'  => array(),
					);
				}
				$audits = array(
					'largest-contentful-paint' => array( 'numericValue' => $psi_lcp + 100 * ( ( $psi_calls - 1 ) % 3 ) ),
					'cumulative-layout-shift'  => array( 'numericValue' => 0.02 ),
					'server-response-time'     => array( 'numericValue' => 350 ),
					'total-blocking-time'      => array( 'numericValue' => 180 ),
				);
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'lighthouseResult' => array( 'audits' => $audits ) ) ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
		Aftercare\Core\Options::update( array( 'api_key' => 'compat-test-key' ) );

		// A steady lab history: the LCP reading below regresses well past the
		// noise floor, while CLS doubles but by less than the minimum change.
		for ( $day = 1; $day <= 8; $day++ ) {
			$plugin->samples()->insert( $url, 'LCP', 2000, 'lab', Aftercare\Core\Util::days_ago( $day ) . ' 07:00:00' );
			$plugin->samples()->insert( $url, 'CLS', 0.01, 'lab', Aftercare\Core\Util::days_ago( $day ) . ' 07:00:00' );
		}
		$open_before = $plugin->incidents()->count_open();
		$mail_before = count( $mails );
		$lab = new Aftercare\Vitals\LabClient( $plugin->samples() );
		$lab->queue_daily();
		check( in_array( Aftercare\Vitals\LabClient::URL_HOOK, aftercare_cron_hooks(), true ), 'lab check queued' );
		$lab->run_url( $url );
		check( 3 === $psi_calls, 'lab check takes three runs' );
		$lab_lcp = $plugin->samples()->lab_series( $url, 'LCP', 1 );
		check( 3100.0 === (float) end( $lab_lcp )['value'], 'lab check stores the median LCP' );
		check( 1 === count( $plugin->samples()->lab_series( $url, 'TBT', 1 ) ), 'lab check stores TBT' );
		$field = $plugin->samples()->latest_for_day( $url, 'LCP', gmdate( 'Y-m-d' ) );
		check( null === $field || 'lab' !== $field['source'], 'lab samples stay out of field readings' );

		$lab_open = $plugin->incidents()->find_open( $url, 'LCP', 'lab' );
		check( null !== $lab_open, 'lab regression opens a lab incident' );
		check( null !== $plugin->incidents()->find_open( $url, 'LCP' ), 'field incident stays open alongside it' );
		check( null === $plugin->incidents()->find_open( $url, 'CLS', 'lab' ), 'lab change below the noise floor opens nothing' );
		check( $open_before + 1 === $plugin->incidents()->count_open(), 'exactly one lab incident opens' );
		check( (bool) preg_grep( '/LCP \(lab\)/', array_slice( $mails, $mail_before ) ), 'lab incident alert email sent' );

		$psi_lcp = 1900;
		$lab->run_url( $url );
		check( null === $plugin->incidents()->find_open( $url, 'LCP', 'lab' ), 'lab recovery resolves the lab incident' );
		check( null !== $plugin->incidents()->find_open( $url, 'LCP' ), 'lab recovery leaves the field incident open' );

		// Change-triggered re-test: two changes debounce into one check, which
		// carries both ledger ids into the incident it opens.
		$scheduler = Aftercare\Vitals\LabScheduler::class;
		// Changes earlier in the flow queued a re-test for the availability
		// check; start from an empty queue.
		delete_option( $scheduler::PENDING_OPT );
		wp_unschedule_hook( $scheduler::CHECK_HOOK );
		activate_plugin( 'hello.php' );
		$first_check = wp_next_scheduled( $scheduler::CHECK_HOOK );
		check( 1 === count( $scheduler::pending() ), 'plugin activation queues a lab re-test' );
		check( $first_check && $first_check - time() > 10 * MINUTE_IN_SECONDS, 'lab re-test waits for changes to settle' );
		do_action( '_core_updated_successfully', get_bloginfo( 'version' ) );
		$triggers = $scheduler::pending();
		check( 2 === count( $triggers ), 'a second change joins the pending re-test' );
		check( 1 === count( aftercare_cron_args( $scheduler::CHECK_HOOK ) ), 'changes debounce into one re-test' );

		$psi_lcp = 3000;
		do_action( $scheduler::CHECK_HOOK );
		check( array() === $scheduler::pending(), 're-test clears the pending changes' );
		$retests = array_values( array_filter( aftercare_cron_args( Aftercare\Vitals\LabClient::URL_HOOK ), static fn( $args ) => 3 === count( $args ) ) );
		check( 1 === count( $retests ) && $triggers === $retests[0][1], 're-test queues the URL with the triggering changes' );
		$mail_before = count( $mails );
		do_action_ref_array( Aftercare\Vitals\LabClient::URL_HOOK, $retests[0] );
		$caught = $plugin->incidents()->find_open( $url, 'LCP', 'lab' );
		check( null !== $caught && $triggers === Aftercare\Incidents\Repository::trigger_ids( $caught ), 'lab incident records the triggering changes' );
		check( 1 === count( $mails ) - $mail_before, 'lab re-test incident alert email sent' );

		add_filter( 'aftercare_lab_change_checks_per_day', static fn() => 1 );
		Aftercare\Vitals\LabScheduler::queue( $triggers[0] );
		wp_unschedule_hook( Aftercare\Vitals\LabClient::URL_HOOK );
		do_action( $scheduler::CHECK_HOOK );
		check( array() === aftercare_cron_args( Aftercare\Vitals\LabClient::URL_HOOK ), 'daily cap stops extra re-tests' );
		Aftercare\Vitals\LabScheduler::queue( $triggers[0] );

		$health = new Aftercare\Admin\SiteHealth();
		check( isset( $health->add_tests( array() )['direct']['aftercare_lab'] ), 'Site Health lists the lab test when lab checks are on' );
		check( 'good' === $health->test_lab()['status'], 'Site Health lab test passes after a completed check' );
		$psi_status = 403;
		$calls      = $psi_calls;
		$lab->run_url( $url );
		check( 1 === $psi_calls - $calls, 'an API error stops further runs' );
		$result = $health->test_lab();
		check( 'recommended' === $result['status'] && str_contains( $result['label'], 'not enabled' ), 'Site Health flags a disabled PageSpeed Insights API' );

		// The key stays set and the error stays recorded, so the screens and
		// Site Health checked over HTTP next render the lab section and the
		// failing lab test.
		break;

	case 'errors':
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $wpdb->prefix . 'aftercare_errors' ), ARRAY_A );
		check( 1 === count( $rows ), 'fatal errors grouped under one signature' );
		$row = $rows[0] ?? array();
		check( 4 === (int) ( $row['occurrences'] ?? 0 ), 'fatal errors counted while booting, before output and after output' );
		check( str_starts_with( (string) ( $row['file'] ?? '' ), 'wp-includes/' ), 'error file stored relative to the WordPress root' );
		check( 'mu-plugin:compat-fatal.php' === ( $row['culprit'] ?? '' ), 'culprit found in the stack trace of a core error' );
		check( ! str_contains( (string) ( $row['message'] ?? '' ), ABSPATH ) && ! str_contains( (string) ( $row['message'] ?? '' ), 'Stack trace' ), 'error message stripped of paths and stack trace' );
		$entries = Aftercare\Core\Plugin::instance()->ledger()->query( array( 'type' => 'php_error' ), 10 );
		check( 1 === count( $entries ), 'one ledger entry for a repeating fatal error' );

		$incidents = Aftercare\Core\Plugin::instance()->incidents();
		$location  = (string) $row['location'];
		$incident  = $incidents->find_open( $location, 'ERROR', 'error' );
		check( null !== $incident, 'fatal error opens an error incident' );
		check( 1 === count( $incidents->open_for_source( 'error' ) ), 'a repeating fatal error opens one incident' );
		$subjects = (array) get_option( 'compat_mail_log', array() );
		check( 1 === count( preg_grep( '/PHP fatal error/', $subjects ) ), 'alert email sent from a request that died before plugins_loaded' );

		( new Aftercare\Core\Cron() )->run_hourly();
		check( null !== $incidents->find_open( $location, 'ERROR', 'error' ), 'error incident stays open while the error is recent' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_seen = %s', $wpdb->prefix . 'aftercare_errors', gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) );
		( new Aftercare\Core\Cron() )->run_hourly();
		check( null === $incidents->find_open( $location, 'ERROR', 'error' ), 'error incident resolves after a quiet day' );
		delete_option( 'compat_mail_log' );
		break;

	case 'availability':
		$incidents   = Aftercare\Core\Plugin::instance()->incidents();
		$check       = new Aftercare\Errors\AvailabilityCheck();
		$page        = add_query_arg( 'compat_page', '1', home_url( '/' ) );
		$unreachable = 'http://127.0.0.1:1/';
		$is_open     = static fn( $url ) => $incidents->find_open( $url, 'HTTP', 'availability' );
		Aftercare\Core\Options::update( array( 'tracked_urls' => array( $page, $unreachable ) ) );
		update_option( 'compat_mail_log', array(), false );

		update_option( 'compat_page_mode', '503', false );
		$check->run();
		check( null === $is_open( $page ), 'one failed availability check opens nothing' );
		check( (bool) wp_next_scheduled( Aftercare\Errors\AvailabilityCheck::CONFIRM_HOOK ), 'a first failure schedules a confirming check' );
		$check->run();
		$open = $is_open( $page );
		check( null !== $open && 503 === (int) $open['breach_p75'], 'two failures in a row open an availability incident' );
		check( null === $is_open( home_url( '/' ) ), 'a healthy page opens nothing' );
		check( null === $is_open( $unreachable ), 'an unreachable URL is inconclusive, not a failure' );
		check( 1 === count( preg_grep( '/Page unavailable/', (array) get_option( 'compat_mail_log', array() ) ) ), 'availability alert email sent' );

		update_option( 'compat_page_mode', 'ok', false );
		$check->run();
		check( null === $is_open( $page ), 'the first healthy check resolves the incident' );

		update_option( 'compat_page_mode', 'cut', false );
		$check->run();
		$check->run();
		$open = $is_open( $page );
		check( null !== $open && 'page cut off' === Aftercare\Core\Util::format_metric( 'HTTP', (float) $open['breach_p75'] ), 'a page cut off mid-render counts as a failure' );

		// A host that blocks loopback requests: nothing opens, Site Health
		// gets told instead.
		$blocked = static fn( $pre, $args, $url ) => str_contains( $url, 'aftercare_check=' ) ? new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) : $pre;
		add_filter( 'pre_http_request', $blocked, 10, 3 );
		$check->run();
		remove_filter( 'pre_http_request', $blocked, 10 );
		check( false !== get_option( Aftercare\Errors\AvailabilityCheck::LOOPBACK_OPT ), 'blocked loopback requests are recorded, not reported as failures' );
		check( null !== $is_open( $page ), 'blocked loopback requests leave open incidents alone' );

		Aftercare\Core\Options::update( array( 'tracked_urls' => array() ) );
		delete_option( 'compat_page_mode' );
		delete_option( 'compat_mail_log' );
		break;

	case 'teardown':
		require_once ABSPATH . 'wp-admin/includes/file.php';
		deactivate_plugins( 'aftercare/aftercare.php' );
		check( array() === aftercare_cron_hooks(), 'deactivation clears cron events' );
		uninstall_plugin( 'aftercare/aftercare.php' );
		global $wpdb;
		check( array() === aftercare_tables(), 'uninstall drops tables' );
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", '%aftercare%' ) );
		check( 0 === $left, 'uninstall removes options' );
		break;

	default:
		fwrite( STDERR, "Unknown step: $step\n" );
		exit( 1 );
}
