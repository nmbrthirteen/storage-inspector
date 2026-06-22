<?php

namespace StorageInspector\Scanner;

defined( 'ABSPATH' ) || exit;

final class CleanupPolicy {

	public function can_delete( string $path, string $root ): bool {
		$path = Path::normalize( $path );
		$root = Path::normalize( $root );
		$wp_root = Path::normalize( ABSPATH );

		if ( ! Path::inside( $path, $root ) || ! file_exists( $path ) ) {
			return false;
		}

		if ( ! Path::inside( $path, $wp_root ) ) {
			return false;
		}

		$relative = str_replace( '\\', '/', Path::relative( $path, $root ) );
		if ( $this->is_protected( $relative, $path ) ) {
			return false;
		}

		$wp_relative = str_replace( '\\', '/', Path::relative( $path, $wp_root ) );

		$plugin_dir = str_replace( '\\', '/', Path::relative( STORAGE_INSPECTOR_PATH, $root ) );
		if ( strncmp( trailingslashit( $relative ), trailingslashit( $plugin_dir ), strlen( trailingslashit( $plugin_dir ) ) ) === 0 ) {
			return false;
		}

		if ( is_file( $path ) ) {
			return $this->is_deletable_file( $wp_relative, $path );
		}

		return $this->is_deletable_directory( $wp_relative );
	}

	public function delete( string $path, string $root ): bool|\WP_Error {
		if ( ! $this->can_delete( $path, $root ) ) {
			return new \WP_Error( 'storage_inspector_protected', __( 'This path is protected and cannot be deleted.', 'storage-inspector' ), [ 'status' => 403 ] );
		}

		if ( is_file( $path ) ) {
			$attachment_id = $this->attachment_id_for_file( $path );
			if ( $attachment_id > 0 ) {
				return wp_delete_attachment( $attachment_id, true ) !== false;
			}

			return @unlink( $path );
		}

		return $this->delete_directory( $path, $root );
	}

	private function is_protected( string $relative, string $path ): bool {
		$protected = [ '', 'wp-admin', 'wp-includes', 'wp-content', 'wp-content/uploads', 'wp-config.php', '.htaccess', 'index.php' ];
		if ( in_array( $relative, $protected, true ) ) {
			return true;
		}

		if ( strncmp( $relative, 'wp-admin/', 9 ) === 0
			|| strncmp( $relative, 'wp-includes/', 12 ) === 0
			|| strncmp( $relative, 'wp-content/plugins/', 19 ) === 0
			|| strncmp( $relative, 'wp-content/themes/', 18 ) === 0 ) {
			return true;
		}

		$wp_root = Path::normalize( ABSPATH );
		if ( ! Path::inside( $path, $wp_root ) ) {
			return false;
		}

		$wp_relative = str_replace( '\\', '/', Path::relative( $path, $wp_root ) );
		if ( in_array( $wp_relative, $protected, true ) ) {
			return true;
		}

		return strncmp( $wp_relative, 'wp-admin/', 9 ) === 0
			|| strncmp( $wp_relative, 'wp-includes/', 12 ) === 0
			|| strncmp( $wp_relative, 'wp-content/plugins/', 19 ) === 0
			|| strncmp( $wp_relative, 'wp-content/themes/', 18 ) === 0;
	}

	private function is_deletable_file( string $relative, string $path ): bool {
		if ( strncmp( $relative, 'wp-content/uploads/', 19 ) === 0 ) {
			return Classifier::is_backup_path( $relative )
				|| Classifier::is_log_path( $relative )
				|| $this->attachment_id_for_file( $path ) > 0;
		}

		return strncmp( $relative, 'wp-content/cache/', 17 ) === 0
			|| strncmp( $relative, 'wp-content/upgrade/', 19 ) === 0
			|| strncmp( $relative, 'wp-content/ai1wm-backups/', 25 ) === 0
			|| strncmp( $relative, 'wp-content/updraft/', 19 ) === 0
			|| strncmp( $relative, 'wp-content/backups/', 19 ) === 0
			|| Classifier::is_backup_path( $relative )
			|| Classifier::is_log_path( $relative );
	}

	private function is_deletable_directory( string $relative ): bool {
		$relative = trim( str_replace( '\\', '/', $relative ), '/' );
		if ( $relative === '' || $relative === 'wp-content/uploads' ) {
			return false;
		}

		$allowed = [
			'wp-content/cache/',
			'wp-content/upgrade/',
			'wp-content/ai1wm-backups/',
			'wp-content/updraft/',
			'wp-content/backups/',
		];

		foreach ( $allowed as $prefix ) {
			if ( strncmp( trailingslashit( $relative ), $prefix, strlen( $prefix ) ) === 0 ) {
				return true;
			}
		}

		if ( strncmp( $relative, 'wp-content/uploads/', 19 ) === 0 ) {
			return false;
		}

		return false;
	}

	private function delete_directory( string $dir, string $root ): bool {
		$entries = @scandir( $dir );
		if ( $entries === false ) {
			return false;
		}

		foreach ( $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}

			$path = Path::normalize( $dir . DIRECTORY_SEPARATOR . $entry );
			if ( ! $this->can_delete( $path, $root ) ) {
				return false;
			}

			$deleted = is_dir( $path ) ? $this->delete_directory( $path, $root ) : @unlink( $path );
			if ( ! $deleted ) {
				return false;
			}
		}

		return @rmdir( $dir );
	}

	private function attachment_id_for_file( string $path ): int {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return 0;
		}

		$base = Path::normalize( (string) $uploads['basedir'] );
		$path = Path::normalize( $path );
		if ( strncmp( $path, trailingslashit( $base ), strlen( trailingslashit( $base ) ) ) !== 0 ) {
			return 0;
		}

		$relative = ltrim( substr( $path, strlen( trailingslashit( $base ) ) ), '/' );
		$url      = trailingslashit( (string) $uploads['baseurl'] ) . str_replace( '%2F', '/', rawurlencode( $relative ) );

		return (int) attachment_url_to_postid( $url );
	}
}
