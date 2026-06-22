<?php

namespace StorageInspector\Scanner;

defined( 'ABSPATH' ) || exit;

final class Path {

	public static function normalize( string $path ): string {
		$real = realpath( $path );
		$path = $real !== false ? $real : $path;
		return rtrim( wp_normalize_path( $path ), '/' );
	}

	public static function relative( string $path, string $root ): string {
		$path = self::normalize( $path );
		$root = trailingslashit( self::normalize( $root ) );
		if ( strncmp( $path, $root, strlen( $root ) ) === 0 ) {
			return ltrim( substr( $path, strlen( $root ) ), '/' );
		}
		return basename( $path );
	}

	public static function inside( string $path, string $root ): bool {
		$path = self::normalize( $path );
		$root = self::normalize( $root );
		return $path === $root || strncmp( $path, trailingslashit( $root ), strlen( trailingslashit( $root ) ) ) === 0;
	}
}
