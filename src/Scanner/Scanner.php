<?php

namespace StorageInspector\Scanner;

defined( 'ABSPATH' ) || exit;

final class Scanner {

	public const CRON_HOOK        = 'storage_inspector_cron_scan';
	public const LOCK_KEY         = 'storage_inspector_scan_lock';
	public const LARGE_FILE_BYTES = 52428800;
	public const BIG_FILE_BYTES   = 2097152;
	public const BIG_MEDIA_BYTES  = 1048576;

	private const BATCH_DIRS = 80;

	private StateStore $store;
	private CleanupPolicy $cleanup;

	public function __construct() {
		$this->store   = new StateStore();
		$this->cleanup = new CleanupPolicy();
	}

	public function register(): void {
		add_action( self::CRON_HOOK, [ $this, 'cron_scan' ] );
	}

	public function start(): array {
		$this->cancel();
		$state = $this->store->reset( $this->scan_root() );
		$this->schedule();
		return $this->public_state( $state );
	}

	public function state(): array {
		$state = $this->store->state();
		if ( isset( $state['root'] ) && $this->normalize_scan_root_candidate( (string) $state['root'] ) !== (string) $state['root'] ) {
			$state['stale_root'] = true;
		}
		return $this->public_state( $state );
	}

	public function cron_scan(): void {
		$this->process_batch();
	}

	public function process_batch(): array {
		if ( get_transient( self::LOCK_KEY ) ) {
			return $this->state();
		}

		set_transient( self::LOCK_KEY, time(), 60 );

		try {
			wp_raise_memory_limit( 'admin' );

			$state = $this->store->state();
			if ( ( $state['status'] ?? '' ) !== 'running' ) {
				return $this->public_state( $state );
			}

			$root       = (string) $state['root'];
			$classifier = new Classifier();
			$groups     = $this->store->groups();
			$files      = $this->store->files();
			$errors     = $this->store->errors();
			$processed  = 0;

				while ( ! empty( $state['queue'] ) && $processed < self::BATCH_DIRS ) {
					$dir = Path::normalize( (string) array_shift( $state['queue'] ) );
					if ( ! Path::inside( $dir, $root ) || is_link( $dir ) ) {
						continue;
					}

					$state['dirs']++;
				$processed++;

				$group = $classifier->classify( $dir, $root );
				$this->add_group( $groups, $group, 0, 0, 1 );

				try {
					$entries = new \DirectoryIterator( $dir );
				} catch ( \Throwable $e ) {
					$errors[] = [
						'path'    => Path::relative( $dir, $root ),
						'message' => __( 'Folder could not be read.', 'storage-inspector' ),
					];
					continue;
				}

				foreach ( $entries as $entry ) {
					if ( $entry->isDot() ) {
						continue;
					}

					$path = Path::normalize( $entry->getPathname() );
					if ( ! Path::inside( $path, $root ) || is_link( $path ) ) {
						continue;
					}

					if ( is_dir( $path ) ) {
						$state['queue'][] = $path;
						continue;
					}

					if ( ! is_file( $path ) ) {
						continue;
					}

					$size = (int) @filesize( $path );
					$state['files']++;
					$state['bytes'] += $size;

					$group = $classifier->classify( $path, $root, $size );
					$this->add_group( $groups, $group, $size, 1, 0 );

					$relative = Path::relative( $path, $root );
					$wp_relative = Path::inside( $path, Path::normalize( ABSPATH ) )
						? Path::relative( $path, Path::normalize( ABSPATH ) )
						: $relative;
					if ( $this->is_large_file( $relative, $size )
						|| $this->is_cleanup_candidate_file( $relative, $size )
						|| $this->is_cleanup_candidate_file( $wp_relative, $size ) ) {
						$files[ $relative ] = $this->row( $relative, 'file', $size, $group, $this->cleanup->can_delete( $path, $root ) );
					}
				}
			}

			if ( empty( $state['queue'] ) ) {
				$state['status']      = 'complete';
				$state['finished_at'] = time();
			} else {
				$this->schedule();
			}

			$this->store->save_state( $state );
			$this->store->save_groups( $groups );
			$this->store->save_files( $files );
			$this->store->save_errors( $errors );

			return $this->public_state( $state );
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	public function rows( string $kind, int $page, int $per_page ): array {
		$page     = max( 1, $page );
		$per_page = min( 200, max( 10, $per_page ) );

		if ( $kind === 'items' ) {
			return $this->item_rows( $page, $per_page );
		}

		$rows = $kind === 'errors'
			? array_values( $this->store->errors() )
			: array_values( $this->store->groups() );

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return ( (int) ( $b['bytes'] ?? 0 ) ) <=> ( (int) ( $a['bytes'] ?? 0 ) );
			}
		);

		$total = count( $rows );
		$rows  = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );

		return [
			'rows'      => array_values( array_map( [ $this, 'format_row' ], $rows ) ),
			'total'     => $total,
			'page'      => $page,
			'perPage'   => $per_page,
			'totalPages'=> (int) ceil( $total / $per_page ),
		];
	}

	private function item_rows( int $page, int $per_page ): array {
		$folders = [];

		foreach ( $this->store->files() as $relative => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$relative = str_replace( '\\', '/', (string) $relative );
			$parent   = trim( str_replace( '\\', '/', dirname( $relative ) ), '/' );
			if ( $parent === '.' ) {
				$parent = '';
			}

			if ( ! isset( $folders[ $parent ] ) ) {
				$folders[ $parent ] = [
					'path'     => $parent === '' ? __( 'Site root', 'storage-inspector' ) : $parent,
					'type'     => 'folder',
					'bytes'    => 0,
					'files'    => 0,
					'children' => [],
				];
			}

			$folders[ $parent ]['children'][] = $this->format_row( $row );
			$folders[ $parent ]['bytes']     += (int) ( $row['bytes'] ?? 0 );
			$folders[ $parent ]['files']++;
		}

		foreach ( $folders as &$folder ) {
			usort(
				$folder['children'],
				static function ( array $a, array $b ): int {
					return ( (int) ( $b['bytes'] ?? 0 ) ) <=> ( (int) ( $a['bytes'] ?? 0 ) );
				}
			);
		}
		unset( $folder );

		$rows = array_values( $folders );
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return ( (int) ( $b['bytes'] ?? 0 ) ) <=> ( (int) ( $a['bytes'] ?? 0 ) );
			}
		);

		$total = count( $rows );
		$rows  = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );

		foreach ( $rows as &$folder ) {
			$folder['bytesHuman'] = size_format( (int) $folder['bytes'], 2 );
		}
		unset( $folder );

		return [
			'rows'      => $rows,
			'total'     => $total,
			'page'      => $page,
			'perPage'   => $per_page,
			'totalPages'=> (int) ceil( $total / $per_page ),
		];
	}

	public function delete( string $relative ): bool|\WP_Error {
		if ( get_transient( self::LOCK_KEY ) ) {
			return new \WP_Error( 'storage_inspector_scan_running', __( 'A scan is currently running. Wait for the current batch to finish before deleting files.', 'storage-inspector' ), [ 'status' => 409 ] );
		}

		$this->cancel();

		$root = $this->scan_root();
		$path = Path::normalize( $root . DIRECTORY_SEPARATOR . ltrim( $relative, '/\\' ) );
		$res  = $this->cleanup->delete( $path, $root );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		if ( ! $res ) {
			return new \WP_Error( 'storage_inspector_delete_failed', __( 'Delete failed. Check filesystem permissions.', 'storage-inspector' ), [ 'status' => 500 ] );
		}

		$this->store->clear();
		return true;
	}

	public function cancel(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_transient( self::LOCK_KEY );
	}

	private function public_state( array $state ): array {
		$queue    = isset( $state['queue'] ) && is_array( $state['queue'] ) ? count( $state['queue'] ) : 0;
		$dirs     = (int) ( $state['dirs'] ?? 0 );
		$progress = ( $queue + $dirs ) > 0 ? (int) floor( ( $dirs / ( $queue + $dirs ) ) * 100 ) : 0;

		return [
			'status'     => $state['status'] ?? 'empty',
			'startedAt'  => (int) ( $state['started_at'] ?? 0 ),
			'finishedAt' => (int) ( $state['finished_at'] ?? 0 ),
			'progress'   => min( 100, $progress ),
			'queued'     => $queue,
			'files'      => (int) ( $state['files'] ?? 0 ),
			'dirs'       => $dirs,
			'bytes'      => (int) ( $state['bytes'] ?? 0 ),
			'bytesHuman' => size_format( (int) ( $state['bytes'] ?? 0 ), 2 ),
			'errors'     => count( $this->store->errors() ),
			'root'       => (string) ( $state['root'] ?? $this->scan_root() ),
			'expectedRoot'=> $this->scan_root(),
			'staleRoot'  => (bool) ( $state['stale_root'] ?? false ),
		];
	}

	private function add_group( array &$groups, array $group, int $bytes, int $files, int $dirs ): void {
		$key = (string) $group['key'];
		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = [
				'key'     => $key,
				'type'    => $group['type'],
				'label'   => $group['label'],
				'reason'  => $group['reason'],
				'details' => $group['details'],
				'bytes'   => 0,
				'files'   => 0,
				'dirs'    => 0,
			];
		}

		$groups[ $key ]['bytes'] += $bytes;
		$groups[ $key ]['files'] += $files;
		$groups[ $key ]['dirs']  += $dirs;
	}

	private function row( string $path, string $type, int $bytes, array $group, bool $deletable, int $files = 0 ): array {
		return [
			'path'      => $path,
			'type'      => $type,
			'bytes'     => $bytes,
			'area'      => $group['label'],
			'areaType'  => $group['type'],
			'reason'    => $type === 'folder'
				? sprintf( __( 'Recursive folder total across %s files.', 'storage-inspector' ), number_format_i18n( $files ) )
				: $group['reason'],
			'deletable' => $deletable,
		];
	}

	private function format_row( array $row ): array {
		$row['bytesHuman'] = size_format( (int) ( $row['bytes'] ?? 0 ), 2 );
		return $row;
	}

	private function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK );
		}
	}

	private function is_large_file( string $relative, int $size ): bool {
		$threshold = $this->is_media_file( $relative ) ? self::BIG_MEDIA_BYTES : self::BIG_FILE_BYTES;
		return $size >= $threshold;
	}

	private function is_media_file( string $relative ): bool {
		return (bool) preg_match( '/\.(jpe?g|png|gif|webp|avif|bmp|tiff?|svg|ico|heic|heif|mp4|m4v|mov|avi|mkv|webm|wmv|flv|mpe?g|mp3|wav|ogg|oga|m4a|aac|flac|wma)$/i', $relative );
	}

	private function is_cleanup_candidate_file( string $relative, int $size ): bool {
		return $size >= self::LARGE_FILE_BYTES
			|| Classifier::is_backup_path( $relative )
			|| Classifier::is_log_path( $relative )
			|| strncmp( $relative, 'wp-content/cache/', 17 ) === 0
			|| strncmp( $relative, 'wp-content/upgrade/', 19 ) === 0
			|| strncmp( $relative, 'wp-content/ai1wm-backups/', 25 ) === 0
			|| strncmp( $relative, 'wp-content/updraft/', 19 ) === 0
			|| strncmp( $relative, 'wp-content/backups/', 19 ) === 0;
	}

	private function scan_root(): string {
		$wp_root = Path::normalize( ABSPATH );
			$docroot = isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) : '';
			if ( $docroot !== '' && is_dir( $docroot ) ) {
				$docroot = $this->normalize_scan_root_candidate( Path::normalize( $docroot ) );
				if ( $this->is_allowed_scan_root( $docroot, $wp_root ) ) {
					return $docroot;
				}
			}

		return $this->normalize_scan_root_candidate( $wp_root );
	}

	private function normalize_scan_root_candidate( string $path ): string {
		$path = Path::normalize( $path );
		$basename = basename( $path );
		if ( in_array( $basename, [ 'wp-content', 'plugins', 'themes', 'uploads' ], true ) ) {
			return Path::normalize( dirname( $path ) );
		}

		return $path;
	}

	private function is_allowed_scan_root( string $candidate, string $wp_root ): bool {
		$candidate = Path::normalize( $candidate );
		$wp_root = Path::normalize( $wp_root );

		if ( $candidate === '/' || $candidate === '' ) {
			return false;
		}

		return $candidate === $wp_root || $candidate === Path::normalize( dirname( $wp_root ) );
	}
}
