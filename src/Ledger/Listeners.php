<?php
namespace Aftercare\Ledger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks into core lifecycle events and writes human-readable ledger entries.
 */
final class Listeners {

	private const VERSION_SNAPSHOT = 'aftercare_plugin_versions';

	/**
	 * Options worth logging. Never log secret-bearing options; values are
	 * truncated and only recorded for this allow-list.
	 */
	private const OPTION_ALLOWLIST = array(
		'blogname',
		'blogdescription',
		'siteurl',
		'home',
		'permalink_structure',
		'posts_per_page',
		'blog_public',
		'admin_email',
		'users_can_register',
		'default_role',
		'timezone_string',
		'WPLANG',
	);

	public function __construct( private Repository $ledger ) {}

	public function register(): void {
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade' ), 10, 2 );
		add_action( 'activated_plugin', array( $this, 'on_plugin_activated' ), 10, 1 );
		add_action( 'deactivated_plugin', array( $this, 'on_plugin_deactivated' ), 10, 1 );
		add_action( 'switch_theme', array( $this, 'on_theme_switch' ), 10, 3 );
		add_action( '_core_updated_successfully', array( $this, 'on_core_updated' ), 10, 1 );
		add_action( 'updated_option', array( $this, 'on_option_updated' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'on_post_status' ), 10, 3 );
		add_action( 'user_register', array( $this, 'on_user_register' ), 10, 1 );
	}

	/**
	 * Keep a slug => version map so plugin updates can report old => new.
	 */
	public static function snapshot_plugin_versions(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$versions = array();
		foreach ( get_plugins() as $file => $data ) {
			$versions[ $file ] = (string) ( $data['Version'] ?? '' );
		}
		update_option( self::VERSION_SNAPSHOT, $versions, false );
	}

	/**
	 * @param \WP_Upgrader         $upgrader
	 * @param array<string, mixed> $extra
	 */
	public function on_upgrade( $upgrader, $extra ): void {
		$action = $extra['action'] ?? '';
		$type   = $extra['type'] ?? '';
		if ( 'update' !== $action && 'install' !== $action ) {
			return;
		}

		if ( 'plugin' === $type && 'update' === $action ) {
			$old_versions = get_option( self::VERSION_SNAPSHOT, array() );
			$files        = ! empty( $extra['plugins'] ) ? (array) $extra['plugins'] : ( ! empty( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() );

			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			foreach ( $files as $file ) {
				$path = WP_PLUGIN_DIR . '/' . $file;
				if ( ! file_exists( $path ) ) {
					continue;
				}
				$data = get_plugin_data( $path, false, false );
				$name = $data['Name'] ?: $file;
				$new  = (string) ( $data['Version'] ?? '' );
				$old  = (string) ( $old_versions[ $file ] ?? '' );

				$summary = $old && $old !== $new
					/* translators: 1: plugin name, 2: old version, 3: new version */
					? sprintf( __( '%1$s updated %2$s to %3$s', 'aftercare' ), $name, $old, $new )
					/* translators: 1: plugin name, 2: version */
					: sprintf( __( '%1$s updated to %2$s', 'aftercare' ), $name, $new );

				$this->ledger->record(
					'plugin_update',
					$summary,
					array(
						'file'        => $file,
						'name'        => $name,
						'old_version' => $old,
						'new_version' => $new,
					)
				);
			}
			self::snapshot_plugin_versions();
			return;
		}

		if ( 'theme' === $type && 'update' === $action ) {
			$themes = ! empty( $extra['themes'] ) ? (array) $extra['themes'] : ( ! empty( $extra['theme'] ) ? array( $extra['theme'] ) : array() );
			foreach ( $themes as $stylesheet ) {
				$theme = wp_get_theme( $stylesheet );
				$this->ledger->record(
					'theme_update',
					/* translators: 1: theme name, 2: version */
					sprintf( __( 'Theme %1$s updated to %2$s', 'aftercare' ), $theme->get( 'Name' ) ?: $stylesheet, $theme->get( 'Version' ) ),
					array(
						'stylesheet' => $stylesheet,
						'version'    => $theme->get( 'Version' ),
					)
				);
			}
		}
	}

	public function on_plugin_activated( string $plugin_file ): void {
		$this->ledger->record(
			'plugin_activate',
			/* translators: %s: plugin name */
			sprintf( __( 'Plugin activated: %s', 'aftercare' ), $this->plugin_name( $plugin_file ) ),
			array( 'file' => $plugin_file )
		);
	}

	public function on_plugin_deactivated( string $plugin_file ): void {
		$this->ledger->record(
			'plugin_deactivate',
			/* translators: %s: plugin name */
			sprintf( __( 'Plugin deactivated: %s', 'aftercare' ), $this->plugin_name( $plugin_file ) ),
			array( 'file' => $plugin_file )
		);
	}

	/**
	 * @param string    $new_name
	 * @param \WP_Theme $new_theme
	 * @param \WP_Theme $old_theme
	 */
	public function on_theme_switch( $new_name, $new_theme, $old_theme ): void {
		$this->ledger->record(
			'theme_switch',
			/* translators: 1: old theme, 2: new theme */
			sprintf( __( 'Theme switched from %1$s to %2$s', 'aftercare' ), $old_theme->get( 'Name' ), $new_name ),
			array(
				'old' => $old_theme->get_stylesheet(),
				'new' => $new_theme->get_stylesheet(),
			)
		);
	}

	public function on_core_updated( string $wp_version ): void {
		$this->ledger->record(
			'core_update',
			/* translators: %s: WordPress version */
			sprintf( __( 'WordPress core updated to %s', 'aftercare' ), $wp_version ),
			array( 'version' => $wp_version )
		);
	}

	/**
	 * @param string $option
	 * @param mixed  $old_value
	 * @param mixed  $value
	 */
	public function on_option_updated( $option, $old_value, $value ): void {
		if ( ! in_array( $option, self::OPTION_ALLOWLIST, true ) ) {
			return;
		}
		$this->ledger->record(
			'settings_change',
			/* translators: %s: option name */
			sprintf( __( 'Setting changed: %s', 'aftercare' ), $option ),
			array(
				'option' => $option,
				'old'    => $this->truncate_value( $old_value ),
				'new'    => $this->truncate_value( $value ),
			)
		);
	}

	/**
	 * @param string   $new_status
	 * @param string   $old_status
	 * @param \WP_Post $post
	 */
	public function on_post_status( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type || ! $post_type->public ) {
			return;
		}
		$this->ledger->record(
			'content_publish',
			/* translators: 1: post type label, 2: post title */
			sprintf( __( '%1$s published: %2$s', 'aftercare' ), $post_type->labels->singular_name, wp_strip_all_tags( $post->post_title ) ),
			array(
				'post_id'   => (int) $post->ID,
				'post_type' => $post->post_type,
				'permalink' => get_permalink( $post ),
			)
		);
	}

	public function on_user_register( int $user_id ): void {
		$user = get_userdata( $user_id );
		$this->ledger->record(
			'user_created',
			/* translators: 1: user login, 2: role list */
			sprintf( __( 'User created: %1$s (%2$s)', 'aftercare' ), $user ? $user->user_login : "#{$user_id}", $user ? implode( ', ', $user->roles ) : '' ),
			array( 'user_id' => $user_id )
		);
	}

	private function plugin_name( string $plugin_file ): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( file_exists( $path ) ) {
			$data = get_plugin_data( $path, false, false );
			if ( ! empty( $data['Name'] ) ) {
				return $data['Name'];
			}
		}
		return $plugin_file;
	}

	/**
	 * @param mixed $value
	 */
	private function truncate_value( $value ): string {
		if ( is_scalar( $value ) ) {
			return mb_substr( (string) $value, 0, 120 );
		}
		return mb_substr( (string) wp_json_encode( $value ), 0, 120 );
	}
}
