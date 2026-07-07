<?php
/**
 * Plugin Name: Storage Inspector
 * Plugin URI:  https://github.com/nmbrthirteen/storage-inspector
 * Description: Inspect WordPress storage usage by plugins, media, themes, cache, backups, logs, and generated files.
 * Version:     0.4.0
 * Author:      Nika Siradze
 * Author URI:  https://nikusha.com
 * Text Domain: storage-inspector
 * Requires PHP: 8.0
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

defined( 'STORAGE_INSPECTOR_VERSION' ) || define( 'STORAGE_INSPECTOR_VERSION', '0.4.0' );
defined( 'STORAGE_INSPECTOR_FILE' ) || define( 'STORAGE_INSPECTOR_FILE', __FILE__ );
defined( 'STORAGE_INSPECTOR_PATH' ) || define( 'STORAGE_INSPECTOR_PATH', plugin_dir_path( __FILE__ ) );
defined( 'STORAGE_INSPECTOR_URL' ) || define( 'STORAGE_INSPECTOR_URL', plugin_dir_url( __FILE__ ) );

$storage_inspector_composer = STORAGE_INSPECTOR_PATH . 'vendor/autoload.php';
if ( is_readable( $storage_inspector_composer ) ) {
	require $storage_inspector_composer;
} else {
	spl_autoload_register(
		static function ( $class ) {
			$prefix = 'StorageInspector\\';
			$len    = strlen( $prefix );
			if ( strncmp( $class, $prefix, $len ) !== 0 ) {
				return;
			}

			$relative = substr( $class, $len );
			$file     = STORAGE_INSPECTOR_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	);
}

register_deactivation_hook( __FILE__, [ StorageInspector\Plugin::class, 'deactivate' ] );

$storage_inspector_puc = STORAGE_INSPECTOR_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';
if ( is_readable( $storage_inspector_puc ) ) {
	require $storage_inspector_puc;

	$storage_inspector_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/nmbrthirteen/storage-inspector/',
		STORAGE_INSPECTOR_FILE,
		'storage-inspector'
	);
	$storage_inspector_updater->setBranch( 'main' );
	$storage_inspector_updater->getVcsApi()->enableReleaseAssets();
}

add_action(
	'init',
	static function () {
		if ( PHP_VERSION_ID < 80000 ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'Storage Inspector requires PHP 8.0 or newer.', 'storage-inspector' ) .
						'</p></div>';
				}
			);
			return;
		}

		try {
			StorageInspector\Plugin::instance()->boot();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Storage Inspector] boot failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
		}
	}
);
