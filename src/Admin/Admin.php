<?php

namespace StorageInspector\Admin;

use StorageInspector\Scanner\Scanner;

defined( 'ABSPATH' ) || exit;

final class Admin {

	private const SLUG        = 'storage-inspector';
	private const NONCE       = 'storage_inspector';
	private const AJAX_PREFIX = 'storage_inspector_';

	private string $hook = '';

	public function __construct( private Scanner $scanner ) {}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'wp_ajax_' . self::AJAX_PREFIX . 'start', [ $this, 'ajax_start' ] );
		add_action( 'wp_ajax_' . self::AJAX_PREFIX . 'scan', [ $this, 'ajax_scan' ] );
		add_action( 'wp_ajax_' . self::AJAX_PREFIX . 'stop', [ $this, 'ajax_stop' ] );
		add_action( 'wp_ajax_' . self::AJAX_PREFIX . 'state', [ $this, 'ajax_state' ] );
		add_action( 'wp_ajax_' . self::AJAX_PREFIX . 'rows', [ $this, 'ajax_rows' ] );
		add_action( 'wp_ajax_' . self::AJAX_PREFIX . 'delete', [ $this, 'ajax_delete' ] );
	}

	public function menu(): void {
		$this->hook = add_management_page(
			__( 'Storage Inspector', 'storage-inspector' ),
			__( 'Storage Inspector', 'storage-inspector' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	public function assets( string $hook ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style( 'storage-inspector-admin', STORAGE_INSPECTOR_URL . 'assets/css/admin.css', [], $this->asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'storage-inspector-admin', STORAGE_INSPECTOR_URL . 'assets/js/admin.js', [], $this->asset_version( 'assets/js/admin.js' ), true );

		wp_localize_script(
			'storage-inspector-admin',
			'StorageInspector',
			[
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( self::NONCE ),
				'pageUrl'         => admin_url( 'tools.php?page=' . self::SLUG ),
				'isInspectorPage' => $hook === $this->hook,
				'i18n'            => $this->strings(),
				]
			);
		}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to inspect storage.', 'storage-inspector' ), '', [ 'response' => 403 ] );
		}
		?>
		<div class="wrap storage-inspector">
				<div class="si-header">
					<div>
						<h1><?php esc_html_e( 'Storage Inspector', 'storage-inspector' ); ?></h1>
					</div>
					<div class="si-actions">
						<button type="button" class="button" id="si-stop" hidden><?php esc_html_e( 'Cancel scan', 'storage-inspector' ); ?></button>
						<button type="button" class="button button-primary" id="si-start"><?php esc_html_e( 'Start new scan', 'storage-inspector' ); ?></button>
					</div>
				</div>

			<div class="si-progress" aria-live="polite">
				<div class="si-progress-bar"><span id="si-progress-fill"></span></div>
				<div id="si-status"><?php esc_html_e( 'Loading scan state...', 'storage-inspector' ); ?></div>
			</div>

			<div class="si-grid" id="si-summary"></div>
			<div class="si-root" id="si-root" hidden></div>
			<div class="notice notice-warning inline si-root-warning" id="si-root-warning" hidden></div>

			<nav class="nav-tab-wrapper si-tabs" aria-label="<?php esc_attr_e( 'Storage Inspector views', 'storage-inspector' ); ?>">
				<a href="#" class="nav-tab nav-tab-active si-tab" data-kind="groups"><?php esc_html_e( 'Areas', 'storage-inspector' ); ?></a>
				<a href="#" class="nav-tab si-tab" data-kind="items"><?php esc_html_e( 'Folders & cleanup', 'storage-inspector' ); ?></a>
				<a href="#" class="nav-tab si-tab" data-kind="errors"><?php esc_html_e( 'Scan errors', 'storage-inspector' ); ?></a>
			</nav>

			<div class="si-table-wrap">
				<table class="widefat striped">
					<thead id="si-table-head"></thead>
					<tbody id="si-table-body"></tbody>
				</table>
			</div>
			<div class="si-pager">
				<button type="button" class="button" id="si-prev"><?php esc_html_e( 'Previous', 'default' ); ?></button>
				<span id="si-page"></span>
				<button type="button" class="button" id="si-next"><?php esc_html_e( 'Next', 'default' ); ?></button>
			</div>
		</div>
		<?php
	}

	public function ajax_start(): void {
		$this->guard();
		wp_send_json_success( $this->scanner->start() );
	}

	public function ajax_scan(): void {
		$this->guard();
		wp_send_json_success( $this->scanner->process_batch( true ) );
	}

	public function ajax_stop(): void {
		$this->guard();
		wp_send_json_success( $this->scanner->stop() );
	}

	public function ajax_state(): void {
		$this->guard();
		wp_send_json_success( $this->scanner->state() );
	}

	public function ajax_rows(): void {
		$this->guard();

		$kind     = sanitize_key( wp_unslash( $_POST['kind'] ?? 'groups' ) );
		$page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
		$per_page = max( 10, (int) ( $_POST['perPage'] ?? 50 ) );

		wp_send_json_success( $this->scanner->rows( $kind, $page, $per_page ) );
	}

	public function ajax_delete(): void {
		$this->guard();

		$path = sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) );
		$res  = $this->scanner->delete( $path );

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( [ 'message' => $res->get_error_message() ], (int) ( $res->get_error_data()['status'] ?? 500 ) );
		}

		wp_send_json_success( [ 'message' => __( 'Deleted. Run a new scan for updated totals.', 'storage-inspector' ) ] );
	}

	private function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'storage-inspector' ) ], 403 );
		}
	}

	private function strings(): array {
		return [
			// Common UI words reuse WordPress core translations (default domain), falling back to English.
			'delete'       => __( 'Delete', 'default' ),
			'folder'       => __( 'Folder', 'default' ),
			'file'         => __( 'File', 'default' ),
			'files'        => __( 'Files', 'default' ),
			'folders'      => __( 'Folders', 'default' ),
			'size'         => __( 'Size', 'default' ),
			'type'         => __( 'Type', 'default' ),
			'errors'       => __( 'Errors', 'default' ),
			'details'      => __( 'Details', 'default' ),
			'action'       => __( 'Action', 'default' ),
			'path'         => __( 'Path', 'default' ),
			'reason'       => __( 'Reason', 'default' ),
			'message'      => __( 'Message', 'default' ),
			'area'         => __( 'Area', 'default' ),
			'previous'     => __( 'Previous', 'default' ),
			'next'         => __( 'Next', 'default' ),
			'copy'         => __( 'Copy', 'default' ),
			'copied'       => __( 'Copied', 'default' ),
			'active'       => __( 'Active', 'default' ),

			// Plugin-specific copy.
			'bannerTitle'  => __( 'Storage Inspector scan is running', 'storage-inspector' ),
			'bannerLink'   => __( 'View progress', 'storage-inspector' ),
			'dismiss'      => __( 'Dismiss', 'storage-inspector' ),
			'deleteFile'   => __( 'Delete this file permanently? Media attachments will be removed through WordPress when possible.', 'storage-inspector' ),
			'deleteFolder' => __( 'Delete this generated cleanup folder permanently?', 'storage-inspector' ),
			'loading'      => __( 'Loading scan state...', 'storage-inspector' ),
			'loadingRows'  => __( 'Loading rows...', 'storage-inspector' ),
			'empty'        => __( 'No scan has been run yet.', 'storage-inspector' ),
			'noRows'       => __( 'No rows found.', 'storage-inspector' ),
			'protected'    => __( 'Protected', 'storage-inspector' ),
			'largeFiles'   => __( 'large files', 'storage-inspector' ),
			'starting'     => __( 'Starting...', 'storage-inspector' ),
			'startNewScan' => __( 'Start new scan', 'storage-inspector' ),
			'stopScan'     => __( 'Cancel scan', 'storage-inspector' ),
			'stopping'     => __( 'Cancelling...', 'storage-inspector' ),
			/* translators: %1$s files, %2$s folders, %3$s human-readable size. */
			'stopped'      => __( 'Scan cancelled. %1$s files across %2$s folders, using %3$s so far.', 'storage-inspector' ),
			'startingScan' => __( 'Starting scan...', 'storage-inspector' ),
			'startingNew'  => __( 'Starting new scan...', 'storage-inspector' ),
			'copyPath'     => __( 'Copy path', 'storage-inspector' ),
			'scannedRoot'  => __( 'Scanned root', 'storage-inspector' ),
			'pluginUri'    => __( 'Plugin URI', 'storage-inspector' ),
			'totalStorage' => __( 'Total storage', 'storage-inspector' ),
			'folderFile'   => __( 'Folder / file', 'storage-inspector' ),
			'genericError' => __( 'Something went wrong.', 'storage-inspector' ),
			'requestError' => __( 'Request failed.', 'storage-inspector' ),
			/* translators: %1$s folders checked, %2$s folders queued, %3$s files found. */
			'scanning'     => __( 'Scanning... %1$s folders checked, %2$s queued, %3$s files found.', 'storage-inspector' ),
			/* translators: %1$s files, %2$s folders, %3$s human-readable size. */
			'complete'     => __( 'Scan complete. %1$s files across %2$s folders, using %3$s.', 'storage-inspector' ),
			/* translators: %1$s current page, %2$s total pages, %3$s total rows. */
			'pager'        => __( 'Page %1$s of %2$s · %3$s rows', 'storage-inspector' ),
			/* translators: %s expected scan root path. */
			'staleRoot'    => __( 'These results were scanned from an old root. Start a new scan to use %s.', 'storage-inspector' ),
		];
	}

	private function asset_version( string $relative ): string {
		$path  = STORAGE_INSPECTOR_PATH . $relative;
		$mtime = file_exists( $path ) ? filemtime( $path ) : false;
		return $mtime ? (string) $mtime : STORAGE_INSPECTOR_VERSION;
	}
}
