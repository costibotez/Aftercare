<?php
namespace Aftercare\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro gating.
 *
 * When the Freemius SDK is present (see aftercare.php) its plan check is
 * authoritative. Without it the plugin runs in free mode; the
 * `aftercare_is_pro` filter exists for development and for the Aftercare
 * service installs.
 */
final class License {

	public static function is_pro(): bool {
		$is_pro = false;

		if ( function_exists( 'aftercare_fs' ) ) {
			$fs = aftercare_fs();
			if ( is_object( $fs ) && method_exists( $fs, 'can_use_premium_code' ) ) {
				$is_pro = (bool) $fs->can_use_premium_code();
			}
		}

		/**
		 * Filter whether Pro features are unlocked.
		 *
		 * @param bool $is_pro
		 */
		return (bool) apply_filters( 'aftercare_is_pro', $is_pro );
	}

	/**
	 * Upgrade URL used by upsell prompts.
	 */
	public static function upgrade_url(): string {
		if ( function_exists( 'aftercare_fs' ) ) {
			$fs = aftercare_fs();
			if ( is_object( $fs ) && method_exists( $fs, 'get_upgrade_url' ) ) {
				return (string) $fs->get_upgrade_url();
			}
		}
		return 'https://github.com/costibotez/aftercare#pro';
	}
}
