<?php
namespace Aftercare\Errors;

use Aftercare\Core\Options;
use Aftercare\Ledger\Repository as LedgerRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures PHP fatal errors and writes them to the errors table and the
 * ledger.
 *
 * Registered when the plugin file loads, before plugins_loaded, so a fatal in
 * a plugin that loads after Aftercare is still caught. Core's fatal error
 * handler is a shutdown function registered earlier than ours, and it ends
 * the request with wp_die() after showing the "critical error" page, which
 * stops every later shutdown function. So capture happens in two places:
 *
 * - the wp_php_error_args filter, which core applies just before that page;
 * - our own shutdown function, for every case where core shows no page
 *   (front-end output already sent, the handler disabled, maintenance mode).
 *
 * Nothing here may throw: every step is guarded and wrapped.
 */
final class Collector {

	/** Error types core's handler treats as fatal. */
	private const FATAL_TYPES = array( E_ERROR, E_PARSE, E_USER_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR, E_CORE_ERROR );

	private static bool $registered = false;
	private static bool $handled    = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_filter( 'wp_php_error_args', array( self::class, 'on_error_page' ), 10, 2 );
		register_shutdown_function( array( self::class, 'on_shutdown' ) );
	}

	/**
	 * @param array<string, mixed> $args  Arguments for the error page.
	 * @param array<string, mixed> $error Error from error_get_last().
	 * @return array<string, mixed>
	 */
	public static function on_error_page( $args, $error ) {
		if ( is_array( $error ) ) {
			self::capture( $error );
		}
		return $args;
	}

	public static function on_shutdown(): void {
		$error = error_get_last();
		if ( is_array( $error ) ) {
			self::capture( $error );
		}
	}

	/**
	 * @param array<string, mixed> $error type, message, file and line.
	 */
	public static function capture( array $error ): void {
		if ( self::$handled || ! in_array( (int) ( $error['type'] ?? 0 ), self::FATAL_TYPES, true ) ) {
			return;
		}
		self::$handled = true;

		try {
			global $wpdb;
			if ( ! is_object( $wpdb ) || ! function_exists( 'get_option' ) || ! Options::get( 'error_watch' ) ) {
				return;
			}

			$raw_message = (string) ( $error['message'] ?? '' );
			$type        = (int) $error['type'];
			$file        = self::relative_path( (string) ( $error['file'] ?? '' ) );
			$line        = (int) ( $error['line'] ?? 0 );
			$message     = self::clean_message( $raw_message );
			$culprit     = self::culprit( $file, $raw_message );
			$signature   = md5( $type . '|' . $file . '|' . $line );

			$notable = ( new Repository() )->record( $signature, $type, $file, $line, $message, $culprit );
			if ( ! $notable ) {
				return;
			}

			( new LedgerRepository() )->record(
				'php_error',
				sprintf(
					/* translators: 1: file and line, 2: error message */
					__( 'PHP fatal error in %1$s: %2$s', 'aftercare' ),
					Repository::location( $file, $line ),
					$message
				),
				array(
					'signature' => $signature,
					'file'      => $file,
					'line'      => $line,
					'culprit'   => $culprit,
				)
			);

			( new Incidents() )->on_error( $signature );

			/**
			 * Fires when a fatal error signature is first seen, or seen again
			 * after a day of silence.
			 *
			 * @param string $signature Error signature.
			 */
			do_action( 'aftercare_php_error', $signature );
		} catch ( \Throwable $e ) {
			// A failure while recording a fatal must never become a second fatal.
			unset( $e );
		}
	}

	/**
	 * Path relative to wp-content ("plugins/acme/acme.php") or, outside it,
	 * to the WordPress root ("wp-includes/class-wp.php"). Absolute server
	 * paths never reach the database.
	 */
	public static function relative_path( string $path ): string {
		$path = wp_normalize_path( $path );
		foreach ( self::bases() as $base => $prefix ) {
			if ( str_starts_with( $path, $base ) ) {
				return $prefix . substr( $path, strlen( $base ) );
			}
		}
		return basename( $path );
	}

	/**
	 * Normalised directory => prefix it maps to, most specific first, so a
	 * plugin directory moved outside wp-content still reads "plugins/...".
	 *
	 * @return array<string, string>
	 */
	private static function bases(): array {
		$bases = array();
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$bases[ trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ) ] = 'mu-plugins/';
		}
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$bases[ trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ) ] = 'plugins/';
		}
		$bases[ trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) ] = '';
		$bases[ trailingslashit( wp_normalize_path( ABSPATH ) ) ]        = '';
		return $bases;
	}

	/**
	 * First line of the message, without the stack trace, server paths or
	 * anything that looks like a credential. Database errors such as a
	 * PDOException or a mysqli connect error can name the database user,
	 * host or password, and this text is stored and emailed.
	 */
	public static function clean_message( string $message ): string {
		$message = strtok( $message, "\n" );
		$message = false === $message ? '' : $message;
		// Only directory prefixes are removed; the message itself is not
		// normalised, so namespaces keep their backslashes. The WordPress
		// root itself becomes "~/".
		$root = trailingslashit( wp_normalize_path( ABSPATH ) );
		foreach ( self::bases() as $base => $prefix ) {
			$prefix  = $base === $root ? '~/' : $prefix;
			$message = str_replace( array( $base, str_replace( '/', DIRECTORY_SEPARATOR, $base ) ), $prefix, $message );
		}
		$message = str_replace( array( untrailingslashit( $root ), untrailingslashit( str_replace( '/', DIRECTORY_SEPARATOR, $root ) ) ), '~', $message );
		return mb_substr( trim( self::redact( $message ) ), 0, 200 );
	}

	/**
	 * Masks credentials: "password=...", "token: ..." and the like, the
	 * 'user'@'host' pair of a MySQL access error, and the database password
	 * and host from wp-config.php wherever they appear verbatim.
	 *
	 * @param string $message Error message.
	 */
	public static function redact( string $message ): string {
		$message = (string) preg_replace( '/(password|passwd|pwd|secret|token|key)\s*[=:]\s*\S+/i', '$1=[redacted]', $message );
		$message = (string) preg_replace( "/'[^'\s]*'@'[^'\s]*'/", "'[redacted]'@'[redacted]'", $message );

		$literals = array();
		if ( defined( 'DB_PASSWORD' ) && strlen( (string) DB_PASSWORD ) >= 6 ) {
			$literals[] = (string) DB_PASSWORD;
		}
		if ( defined( 'DB_HOST' ) && strlen( (string) DB_HOST ) >= 4 && ! in_array( strtolower( (string) DB_HOST ), array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			$literals[] = (string) DB_HOST;
		}
		foreach ( $literals as $literal ) {
			$message = (string) preg_replace( '/(?<![\w.-])' . preg_quote( $literal, '/' ) . '(?![\w.-])/', '[redacted]', $message );
		}
		return $message;
	}

	/**
	 * The extension most likely responsible: "plugin:slug", "mu-plugin:file",
	 * "theme:stylesheet", "core" or "unknown".
	 *
	 * An error raised inside core is usually caused by the plugin or theme
	 * that called it, so for core files the stack trace in the raw message is
	 * searched for the first plugin or theme frame.
	 */
	public static function culprit( string $relative_file, string $raw_message ): string {
		$own = self::culprit_for_path( $relative_file );
		if ( 'core' !== $own && 'unknown' !== $own ) {
			return $own;
		}
		if ( preg_match_all( '#(/[^\s:(]+\.php)#', str_replace( '\\', '/', $raw_message ), $matches ) ) {
			foreach ( $matches[1] as $frame ) {
				$candidate = self::culprit_for_path( self::relative_path( $frame ) );
				if ( 'core' !== $candidate && 'unknown' !== $candidate ) {
					return $candidate;
				}
			}
		}
		return $own;
	}

	private static function culprit_for_path( string $relative ): string {
		$parts = explode( '/', $relative );
		if ( count( $parts ) >= 2 ) {
			switch ( $parts[0] ) {
				case 'plugins':
					return 'plugin:' . $parts[1];
				case 'mu-plugins':
					return 'mu-plugin:' . $parts[1];
				case 'themes':
					return 'theme:' . $parts[1];
				case 'wp-includes':
				case 'wp-admin':
					return 'core';
			}
		}
		return 'unknown';
	}
}
