<?php
/**
 * Licensing bootstrap for the paid Aftercare add-on distribution.
 *
 * This file is excluded from the WordPress.org build (see .distignore). The
 * plugin published in the directory contains no licensing code and gates
 * nothing: features are available whenever the class that implements them is
 * present, which is what class_exists() checks throughout src/ test for.
 *
 * The add-on plugin is responsible for requiring this file and for defining
 * the classes the directory build looks for.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
