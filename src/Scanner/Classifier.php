<?php

namespace StorageInspector\Scanner;

defined( 'ABSPATH' ) || exit;

final class Classifier {

	/** @var array<string,array<string,mixed>> */
	private array $plugins = [];

	public function __construct() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( get_plugins() as $file => $data ) {
			$slug = dirname( $file );
			if ( $slug === '.' ) {
				$slug = basename( $file, '.php' );
			}

			$this->plugins[ $slug ] = [
				'slug'    => $slug,
				'name'    => $data['Name'] ?? $slug,
				'version' => $data['Version'] ?? '',
				'author'  => wp_strip_all_tags( $data['Author'] ?? '' ),
				'uri'     => esc_url_raw( $data['PluginURI'] ?? '' ),
				'active'  => is_plugin_active( $file ),
				'icon'    => 'dashicons-admin-plugins',
			];
		}
	}

	public function classify( string $path, string $root, int $size = 0 ): array {
		$relative = str_replace( '\\', '/', Path::relative( $path, $root ) );
		$wp_root_relative = Path::inside( $path, Path::normalize( ABSPATH ) )
			? str_replace( '\\', '/', Path::relative( $path, Path::normalize( ABSPATH ) ) )
			: $relative;

		if ( strncmp( $wp_root_relative, 'wp-content/plugins/', 19 ) === 0 ) {
			$parts  = explode( '/', $wp_root_relative );
			$slug   = $parts[2] ?? __( 'unknown-plugin', 'storage-inspector' );
			if ( count( $parts ) === 3 && pathinfo( $slug, PATHINFO_EXTENSION ) === 'php' ) {
				$slug = basename( $slug, '.php' );
			}
			$plugin = $this->plugins[ $slug ] ?? [
				'slug'    => $slug,
				'name'    => $slug,
				'version' => '',
				'author'  => '',
					'uri'     => '',
					'active'  => false,
					'icon'    => 'dashicons-admin-plugins',
				];

			return [
				'key'     => 'plugin:' . $slug,
				'type'    => 'plugin',
				'label'   => (string) $plugin['name'],
				'reason'  => __( 'Files inside this plugin directory.', 'storage-inspector' ),
				'details' => $plugin,
			];
		}

		if ( strncmp( $wp_root_relative, 'wp-content/uploads/', 19 ) === 0 ) {
			return [
				'key'     => 'media',
				'type'    => 'media',
				'label'   => __( 'Media uploads', 'storage-inspector' ),
				'reason'  => __( 'Files stored in the WordPress uploads directory.', 'storage-inspector' ),
				'details' => [ 'icon' => 'dashicons-format-image' ],
			];
		}

		if ( strncmp( $wp_root_relative, 'wp-content/themes/', 18 ) === 0 ) {
			$parts = explode( '/', $wp_root_relative );
			$slug  = $parts[2] ?? __( 'unknown-theme', 'storage-inspector' );
			return [
				'key'     => 'theme:' . $slug,
				'type'    => 'theme',
				'label'   => 'Theme: ' . $slug,
				'reason'  => __( 'Files inside this theme directory.', 'storage-inspector' ),
				'details' => [ 'slug' => $slug, 'icon' => 'dashicons-admin-appearance' ],
			];
		}

		if ( strncmp( $wp_root_relative, 'wp-admin/', 9 ) === 0 || strncmp( $wp_root_relative, 'wp-includes/', 12 ) === 0 ) {
			return [
				'key'     => 'wordpress-core',
				'type'    => 'core',
				'label'   => __( 'WordPress core', 'storage-inspector' ),
				'reason'  => __( 'Core WordPress installation files.', 'storage-inspector' ),
				'details' => [ 'icon' => 'dashicons-wordpress' ],
			];
		}

		if ( $this->is_cache_path( $wp_root_relative ) || $this->is_cache_path( $relative ) ) {
			return [
				'key'     => 'cache',
				'type'    => 'cache',
				'label'   => __( 'Cache', 'storage-inspector' ),
				'reason'  => __( 'Likely generated cache data.', 'storage-inspector' ),
				'details' => [ 'icon' => 'dashicons-performance' ],
			];
		}

		if ( self::is_backup_path( $wp_root_relative ) || self::is_backup_path( $relative ) ) {
			return [
				'key'     => 'backups',
				'type'    => 'backup',
				'label'   => __( 'Backups and exports', 'storage-inspector' ),
				'reason'  => __( 'Backup, archive, database dump, or export pattern.', 'storage-inspector' ),
				'details' => [ 'icon' => 'dashicons-database-export' ],
			];
		}

		if ( self::is_log_path( $wp_root_relative ) || self::is_log_path( $relative ) ) {
			return [
				'key'     => 'logs',
				'type'    => 'log',
				'label'   => __( 'Logs', 'storage-inspector' ),
				'reason'  => __( 'Log or debug output pattern.', 'storage-inspector' ),
				'details' => [ 'icon' => 'dashicons-media-text' ],
			];
		}

		if ( strncmp( $wp_root_relative, 'wp-content/', 11 ) === 0 ) {
			return [
				'key'     => 'wp-content-other',
				'type'    => 'content',
				'label'   => __( 'Other wp-content data', 'storage-inspector' ),
				'reason'  => __( 'Files in wp-content outside plugins, themes, uploads, and cache.', 'storage-inspector' ),
				'details' => [ 'icon' => 'dashicons-portfolio' ],
			];
		}

		return [
			'key'     => 'site-root-other',
			'type'    => 'other',
			'label'   => __( 'Other site files', 'storage-inspector' ),
			'reason'  => $size >= Scanner::LARGE_FILE_BYTES ? __( 'Large file outside known WordPress areas.', 'storage-inspector' ) : __( 'File outside known WordPress areas.', 'storage-inspector' ),
			'details' => [ 'icon' => 'dashicons-media-default' ],
		];
	}

	public static function is_backup_path( string $path ): bool {
		return (bool) preg_match( '/(^|[\/_.-])(backup|backups|export|exports|dump)([\/_.-]|$)|\.(zip|tar|gz|rar|7z|sql|bak|old)$/i', $path );
	}

	public static function is_log_path( string $path ): bool {
		return (bool) preg_match( '/(^|\/)(debug\.log|error_log)$|\.log$/i', $path );
	}

	private function is_cache_path( string $path ): bool {
		return strncmp( $path, 'wp-content/cache/', 17 ) === 0
			|| strpos( $path, '/cache/' ) !== false
			|| strpos( $path, '/cache-' ) !== false;
	}
}
