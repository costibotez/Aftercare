<?php
/**
 * Plugin Name:       Aftercare
 * Plugin URI:        https://github.com/costibotez/aftercare
 * Description:       Daily Core Web Vitals monitoring, a complete change ledger and regression incidents with email alerts. Know what changed, know what it cost — catch performance problems before your visitors do.
 * Version:           1.0.3
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Nomad Developer
 * Author URI:        https://www.nomad-developer.co.uk/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aftercare
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AFTERCARE_VERSION', '1.0.3' );
define( 'AFTERCARE_FILE', __FILE__ );
define( 'AFTERCARE_DIR', plugin_dir_path( __FILE__ ) );
define( 'AFTERCARE_URL', plugin_dir_url( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Aftercare requires PHP 8.1 or newer. The plugin is inactive until PHP is upgraded.', 'aftercare' );
			echo '</p></div>';
		}
	);
	return;
}

spl_autoload_register(
	static function ( $class ) {
		if ( ! str_starts_with( $class, 'Aftercare\\' ) ) {
			return;
		}
		$path = AFTERCARE_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( 'Aftercare\\' ) ) ) . '.php';
		if ( file_exists( $path ) ) {
			require $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'Aftercare\\Core\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Aftercare\\Core\\Activator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		Aftercare\Core\Plugin::instance()->boot();
	}
);
