<?php
namespace Aftercare\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the four custom tables.
 */
final class Migrations {

	public const SCHEMA_VERSION        = '1.0.0';
	public const SCHEMA_VERSION_OPTION = 'aftercare_schema_version';

	public static function maybe_upgrade(): void {
		if ( get_option( self::SCHEMA_VERSION_OPTION ) !== self::SCHEMA_VERSION ) {
			self::run();
		}
	}

	public static function run(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix . 'aftercare_';

		dbDelta(
			"CREATE TABLE {$prefix}vitals_samples (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				url_hash CHAR(32) NOT NULL,
				url VARCHAR(2000) NOT NULL,
				metric VARCHAR(10) NOT NULL,
				p75_value DECIMAL(10,3) NOT NULL DEFAULT 0,
				sample_source VARCHAR(10) NOT NULL DEFAULT 'crux',
				recorded_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY url_metric_time (url_hash, metric, recorded_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$prefix}ledger_events (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				event_type VARCHAR(32) NOT NULL,
				summary VARCHAR(255) NOT NULL DEFAULT '',
				actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				actor_label VARCHAR(100) NOT NULL DEFAULT '',
				payload LONGTEXT NULL,
				occurred_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY occurred_at (occurred_at),
				KEY event_type (event_type)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$prefix}incidents (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				metric VARCHAR(10) NOT NULL,
				url_hash CHAR(32) NOT NULL,
				url VARCHAR(2000) NOT NULL,
				baseline_p75 DECIMAL(10,3) NULL,
				breach_p75 DECIMAL(10,3) NOT NULL DEFAULT 0,
				budget_value DECIMAL(10,3) NOT NULL DEFAULT 0,
				status VARCHAR(20) NOT NULL DEFAULT 'open',
				opened_at DATETIME NOT NULL,
				resolved_at DATETIME NULL,
				causes LONGTEXT NULL,
				PRIMARY KEY  (id),
				KEY status_opened (status, opened_at),
				KEY url_metric (url_hash, metric)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$prefix}reports (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				period_month TINYINT UNSIGNED NOT NULL,
				period_year SMALLINT UNSIGNED NOT NULL,
				branding LONGTEXT NULL,
				summary_stats LONGTEXT NULL,
				content_html LONGTEXT NULL,
				personal_note TEXT NULL,
				status VARCHAR(10) NOT NULL DEFAULT 'draft',
				generated_at DATETIME NOT NULL,
				sent_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY period (period_month, period_year)
			) {$charset};"
		);

		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * @return string[] Fully prefixed table names.
	 */
	public static function tables(): array {
		global $wpdb;
		$prefix = $wpdb->prefix . 'aftercare_';
		return array(
			$prefix . 'vitals_samples',
			$prefix . 'ledger_events',
			$prefix . 'incidents',
			$prefix . 'reports',
		);
	}
}
