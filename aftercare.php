<?php
/**
 * Plugin Name:       Aftercare
 * Plugin URI:        https://github.com/costibotez/aftercare
 * Description:       Daily Core Web Vitals monitoring, a complete change ledger and regression incidents with email alerts. Know what changed, know what it cost — catch performance problems before your visitors do.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Nomad Developer
 * Author URI:        https://www.nomad-developer.co.uk/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aftercare
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AFTERCARE_VERSION', '1.0.0' );
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

/*
 * Freemius SDK loader. The SDK is not bundled with the repository; drop it in
 * vendor/freemius and it will be picked up. Without it the plugin runs in
 * free mode (see Aftercare\Licensing\License).
 */
if ( file_exists( AFTERCARE_DIR . 'vendor/freemius/start.php' ) && ! function_exists( 'aftercare_fs' ) ) {
	/**
	 * Returns the Freemius instance for Aftercare.
	 *
	 * @return object
	 */
	function aftercare_fs() {
		global $aftercare_fs;
		if ( ! isset( $aftercare_fs ) ) {
			require_once AFTERCARE_DIR . 'vendor/freemius/start.php';
			$aftercare_fs = fs_dynamic_init(
				array(
					'id'             => '00000',
					'slug'           => 'aftercare',
					'type'           => 'plugin',
					'public_key'     => 'pk_REPLACE_ME',
					'is_premium'     => false,
					'has_addons'     => false,
					'has_paid_plans' => true,
					'menu'           => array(
						'slug'    => 'aftercare',
						'support' => false,
					),
				)
			);
		}
		return $aftercare_fs;
	}
	aftercare_fs();
	do_action( 'aftercare_fs_loaded' );
}

register_activation_hook( __FILE__, array( 'Aftercare\\Core\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Aftercare\\Core\\Activator', 'deactivate' ) );

// Since WP 6.7 translations must not load before the init hook.
add_action(
	'init',
	static function () {
		// Kept deliberately: WordPress.org auto-loads language packs, but the
		// bundled ro_RO translation must also load on installs that do not
		// receive packs (e.g. the premium build distributed off-directory).
		load_plugin_textdomain( 'aftercare', false, dirname( plugin_basename( AFTERCARE_FILE ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	},
	0
);

add_action(
	'plugins_loaded',
	static function () {
		Aftercare\Core\Plugin::instance()->boot();
	}
);
