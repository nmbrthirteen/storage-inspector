<?php

namespace StorageInspector\Scanner;

defined( 'ABSPATH' ) || exit;

final class Path {

	/**
	 * Resolves a path to its real location. With $follow_leaf false, a symlink
	 * as the final segment stays unresolved so callers can act on the link itself.
	 */
	public static function normalize( string $path, bool $follow_leaf = true ): string {
		if ( ! $follow_leaf && is_link( $path ) ) {
			$parent = realpath( dirname( $path ) );
			if ( $parent !== false ) {
				return rtrim( wp_normalize_path( $parent . '/' . basename( $path ) ), '/' );
			}
		}

		$real = realpath( $path );
		$path = $real !== false ? $real : $path;
		return rtrim( wp_normalize_path( $path ), '/' );
	}

	public static function relative( string $path, string $root, bool $follow_leaf = true ): string {
		$path = self::normalize( $path, $follow_leaf );
		$root = trailingslashit( self::normalize( $root ) );
		if ( strncmp( $path, $root, strlen( $root ) ) === 0 ) {
			return ltrim( substr( $path, strlen( $root ) ), '/' );
		}
		return basename( $path );
	}

	public static function inside( string $path, string $root, bool $follow_leaf = true ): bool {
		$path = self::normalize( $path, $follow_leaf );
		$root = self::normalize( $root );
		return $path === $root || strncmp( $path, trailingslashit( $root ), strlen( trailingslashit( $root ) ) ) === 0;
	}
}
