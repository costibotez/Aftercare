<?php
namespace Aftercare\Incidents;

use Aftercare\Ledger\Repository as LedgerRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rule-based cause attribution. When an incident opens, ledger events from the
 * 72 hours before the first bad sample are scored and ranked with confidence
 * labels. No machine learning — deliberately explainable heuristics.
 */
final class Attribution {

	private const WINDOW_HOURS = 72;

	/**
	 * Plugin-name keywords that mark a performance-relevant category.
	 */
	private const PERF_CATEGORIES = array(
		'caching'   => array( 'cache', 'rocket', 'litespeed', 'w3-total', 'w3 total', 'autoptimize', 'wp-optimize', 'wp optimize', 'hummingbird', 'breeze', 'flying', 'perfmatters', 'nitropack' ),
		'sliders'   => array( 'slider', 'revolution', 'carousel', 'swiper' ),
		'builders'  => array( 'elementor', 'divi', 'beaver', 'wpbakery', 'visual composer', 'oxygen', 'bricks', 'brizy' ),
		'analytics' => array( 'analytics', 'pixel', 'tag manager', 'gtm', 'monsterinsights', 'matomo', 'statistics', 'tracking' ),
		'fonts'     => array( 'font', 'typekit', 'typography' ),
	);

	/**
	 * Rank candidate causes for an incident.
	 *
	 * @param string $url       Affected URL.
	 * @param string $breach_at GMT datetime of the first bad sample.
	 * @return array<int, array{label: string, confidence: string, score: int, event_id?: int, event_type?: string, occurred_at?: string}>
	 */
	public function rank( string $url, string $breach_at ): array {
		$from   = gmdate( 'Y-m-d H:i:s', strtotime( $breach_at . ' UTC' ) - self::WINDOW_HOURS * HOUR_IN_SECONDS );
		$events = ( new LedgerRepository() )->in_window( $from, $breach_at );

		$causes = array();
		foreach ( $events as $event ) {
			$score = $this->score( $event, $url );
			if ( $score <= 0 ) {
				continue;
			}
			$causes[] = array(
				'label'       => (string) $event['summary'],
				'confidence'  => $this->confidence( $score ),
				'score'       => $score,
				'event_id'    => (int) $event['id'],
				'event_type'  => (string) $event['event_type'],
				'occurred_at' => (string) $event['occurred_at'],
			);
		}

		usort( $causes, static fn( $a, $b ) => $b['score'] <=> $a['score'] );

		if ( empty( $causes ) ) {
			$causes[] = array(
				'label'      => __( 'External factor likely (hosting, traffic spike or a third-party service). No site changes were recorded in the 72 hours before the regression.', 'aftercare' ),
				'confidence' => 'low',
				'score'      => 10,
			);
		}

		/**
		 * Filter the ranked attribution causes for an incident.
		 *
		 * @param array  $causes
		 * @param string $url
		 * @param string $breach_at
		 */
		return apply_filters( 'aftercare_attribution_causes', $causes, $url, $breach_at );
	}

	/**
	 * @param array<string, mixed> $event
	 */
	private function score( array $event, string $url ): int {
		$type    = (string) $event['event_type'];
		$payload = json_decode( (string) ( $event['payload'] ?? '' ), true ) ?: array();
		$haystack = strtolower( ( $event['summary'] ?? '' ) . ' ' . ( $payload['file'] ?? '' ) . ' ' . ( $payload['name'] ?? '' ) . ' ' . ( $payload['stylesheet'] ?? '' ) );

		switch ( $type ) {
			case 'plugin_update':
			case 'theme_update':
				return $this->is_perf_relevant( $haystack ) ? 90 : 55;

			case 'plugin_activate':
				return 85;

			case 'theme_switch':
				return 85;

			case 'core_update':
				return 60;

			case 'content_publish':
				$permalink = (string) ( $payload['permalink'] ?? '' );
				if ( $permalink && untrailingslashit( strtolower( $permalink ) ) === untrailingslashit( strtolower( $url ) ) ) {
					return 65; // Published on the affected URL itself.
				}
				return 25;

			case 'settings_change':
				return 50;

			case 'plugin_deactivate':
				return 45;

			default:
				return 0;
		}
	}

	private function is_perf_relevant( string $haystack ): bool {
		foreach ( self::PERF_CATEGORIES as $keywords ) {
			foreach ( $keywords as $keyword ) {
				if ( str_contains( $haystack, $keyword ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private function confidence( int $score ): string {
		if ( $score >= 80 ) {
			return 'high';
		}
		if ( $score >= 50 ) {
			return 'medium';
		}
		return 'low';
	}
}
