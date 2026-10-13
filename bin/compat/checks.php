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
		check( 4 === count( aftercare_tables() ), 'four tables created' );
		$hooks = aftercare_cron_hooks();
		check( in_array( 'aftercare_daily_tasks', $hooks, true ), 'daily cron scheduled' );
		check( in_array( 'aftercare_weekly_digest', $hooks, true ), 'weekly digest cron scheduled' );
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
