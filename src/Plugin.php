<?php

namespace StorageInspector;

use StorageInspector\Admin\Admin;
use StorageInspector\Scanner\Scanner;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;
	private Scanner $scanner;

	public static function instance(): Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->scanner = new Scanner();
	}

	public function boot(): void {
		load_plugin_textdomain( 'storage-inspector', false, dirname( plugin_basename( STORAGE_INSPECTOR_FILE ) ) . '/languages' );

		$this->scanner->register();

		if ( is_admin() ) {
			( new Admin( $this->scanner ) )->register();
		}

		do_action( 'storage_inspector_booted', $this );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( Scanner::CRON_HOOK );
		delete_transient( Scanner::LOCK_KEY );
	}
}
