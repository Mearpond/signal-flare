<?php
namespace SignalFlare;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves tool paths and keeps them inside the WordPress root.
 */
class Paths {

	public static function root(): string {
		return rtrim( (string) realpath( ABSPATH ), '/' );
	}

	/**
	 * Absolute, symlink-free path for $path (relative paths start at the WordPress root).
	 * Works for files that don't exist yet by resolving the nearest existing parent.
	 *
	 * @throws \RuntimeException When the path leaves the WordPress root.
	 */
	public static function resolve( string $path ): string {
		$path = str_replace( "\0", '', trim( $path ) );
		if ( '' === $path ) {
			throw new \RuntimeException( 'A path is required.' );
		}
		$root = self::root();
		$abs  = str_starts_with( $path, '/' ) ? $path : $root . '/' . $path;

		// Walk up to the nearest existing ancestor, resolve it, then re-append the rest.
		$rest = array();
		$base = $abs;
		while ( ! file_exists( $base ) ) {
			$rest[] = basename( $base );
			$parent = dirname( $base );
			if ( $parent === $base ) {
				break;
			}
			$base = $parent;
		}
		$real = realpath( $base );
		if ( false === $real ) {
			throw new \RuntimeException( 'That path does not exist.' );
		}
		foreach ( array_reverse( $rest ) as $segment ) {
			if ( '..' === $segment || '.' === $segment || '' === $segment ) {
				throw new \RuntimeException( 'Paths may not contain "." or ".." segments.' );
			}
			$real .= '/' . $segment;
		}

		if ( $real !== $root && ! str_starts_with( $real, $root . '/' ) ) {
			throw new \RuntimeException( 'Paths must stay inside the WordPress folder.' );
		}
		return $real;
	}

	/**
	 * Signal Flare's own files: never written or deleted through its tools,
	 * so a bad edit can't cut the connection that would fix it.
	 */
	public static function is_self( string $real ): bool {
		$self = rtrim( (string) realpath( SIGNAL_FLARE_DIR ), '/' );
		return $real === $self || str_starts_with( $real, $self . '/' );
	}

	/** Folders that may never be deleted. */
	public static function is_protected_dir( string $real ): bool {
		$root      = self::root();
		$protected = array(
			$root,
			$root . '/wp-admin',
			$root . '/wp-includes',
			rtrim( (string) realpath( WP_CONTENT_DIR ), '/' ),
			rtrim( (string) realpath( WP_PLUGIN_DIR ), '/' ),
			rtrim( (string) realpath( get_theme_root() ), '/' ),
		);
		return in_array( $real, $protected, true );
	}

	public static function relative( string $real ): string {
		$root = self::root();
		return $real === $root ? '.' : ltrim( substr( $real, strlen( $root ) ), '/' );
	}
}
