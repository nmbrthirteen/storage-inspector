<?php

namespace StorageInspector\Scanner;

defined( 'ABSPATH' ) || exit;

final class Scanner {

	public const CRON_HOOK        = 'storage_inspector_cron_scan';
	public const LOCK_KEY         = 'storage_inspector_scan_lock';
	public const HUGE_FILE_BYTES  = 1073741824;
	public const LARGE_FILE_BYTES = 52428800;
	public const BIG_FILE_BYTES   = 2097152;
	public const BIG_MEDIA_BYTES  = 1048576;
	public const FOLDER_MAX_DEPTH = 10;

	private const FOLDER_MAX_ROWS    = 20000;
	private const BATCH_DIRS         = 80;
	private const WORKER_MAX_SECONDS = 15;
	private const WORKER_RETRY_DELAY = 2;
	private const FALLBACK_DELAY     = 15;

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
		$this->ensure_scheduled( self::WORKER_RETRY_DELAY );
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
		if ( get_transient( self::LOCK_KEY ) ) {
			$this->ensure_scheduled( self::FALLBACK_DELAY );
			return;
		}

		$deadline = time() + self::WORKER_MAX_SECONDS;
		$worked   = false;
		$last_sig = '';

		do {
			$state = $this->process_batch();
			if ( ( $state['status'] ?? '' ) !== 'running' ) {
				return;
			}

			$sig = $state['dirs'] . ':' . $state['files'] . ':' . $state['queued'];
			if ( $sig === $last_sig ) {
				break;
			}

			$last_sig = $sig;
			$worked   = true;
		} while ( time() < $deadline && ! $this->approaching_memory_limit() );

		$this->ensure_scheduled( $worked ? self::WORKER_RETRY_DELAY : self::FALLBACK_DELAY );
	}

	public function process_batch( bool $ensure_fallback = false ): array {
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
			$folders    = $this->store->folders();
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
					$this->add_folder_totals( $folders, $relative, $size );
					$wp_relative = Path::inside( $path, Path::normalize( ABSPATH ) )
						? Path::relative( $path, Path::normalize( ABSPATH ) )
						: $relative;
					if ( $this->is_large_file( $relative, $size )
						|| $this->is_cleanup_candidate_file( $relative, $size )
						|| $this->is_cleanup_candidate_file( $wp_relative, $size ) ) {
						$reason             = $this->cleanup_reason( $relative, $wp_relative, $size, $group );
						$files[ $relative ] = $this->row( $relative, 'file', $size, $group, $this->cleanup->can_delete( $path, $root ), $reason );
					}
				}
			}

			if ( empty( $state['queue'] ) ) {
				$state['status']      = 'complete';
				$state['finished_at'] = time();
			}

			if ( ( $this->store->fresh_state()['status'] ?? '' ) === 'stopped' ) {
				$state['status']      = 'stopped';
				$state['finished_at'] = $state['finished_at'] ?: time();
			}

			$this->store->save_state( $state );
			$this->store->save_groups( $groups );
			$this->store->save_folders( $this->prune_folders( $folders ) );
			$this->store->save_files( $files );
			$this->store->save_errors( $errors );

			if ( $ensure_fallback && ( $state['status'] ?? '' ) === 'running' ) {
				$this->ensure_scheduled( self::FALLBACK_DELAY );
			}

			return $this->public_state( $state );
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	public function rows( string $kind, int $page, int $per_page, string $parent = '' ): array {
		$page     = max( 1, $page );
		$per_page = min( 200, max( 10, $per_page ) );

		if ( $kind === 'folders' ) {
			return $this->folder_rows( $parent, $per_page );
		}

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

	private function folder_rows( string $parent, int $limit ): array {
		$parent       = trim( str_replace( '\\', '/', $parent ), '/' );
		$folders      = $this->store->folders();
		$parent_depth = $parent === '' ? 0 : count( explode( '/', $parent ) );
		$child_depth  = $parent_depth + 1;
		$prefix       = $parent === '' ? '' : $parent . '/';

		$has_children = [];
		foreach ( array_keys( $folders ) as $path ) {
			$dir = trim( str_replace( '\\', '/', dirname( (string) $path ) ), '/' );
			if ( $dir !== '' && $dir !== '.' ) {
				$has_children[ $dir ] = true;
			}
		}

		$rows = [];
		foreach ( $folders as $path => $row ) {
			$path     = trim( str_replace( '\\', '/', (string) $path ), '/' );
			$segments = explode( '/', $path );
			if ( count( $segments ) !== $child_depth ) {
				continue;
			}
			if ( $prefix !== '' && strncmp( $path, $prefix, strlen( $prefix ) ) !== 0 ) {
				continue;
			}

			$bytes  = (int) ( $row['bytes'] ?? 0 );
			$rows[] = [
				'path'       => $path,
				'name'       => $segments[ count( $segments ) - 1 ],
				'depth'      => $child_depth,
				'bytes'      => $bytes,
				'bytesHuman' => size_format( $bytes, 2 ),
				'files'      => (int) ( $row['files'] ?? 0 ),
				'expandable' => $child_depth < self::FOLDER_MAX_DEPTH && isset( $has_children[ $path ] ),
			];
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return $b['bytes'] <=> $a['bytes'];
			}
		);

		$total = count( $rows );
		$rows  = array_slice( $rows, 0, $limit );

		return [
			'rows'      => $rows,
			'parent'    => $parent,
			'total'     => $total,
			'shown'     => count( $rows ),
			'truncated' => max( 0, $total - count( $rows ) ),
		];
	}

	private function add_folder_totals( array &$folders, string $relative, int $size ): void {
		$parts = explode( '/', str_replace( '\\', '/', $relative ) );
		array_pop( $parts );

		$prefix = '';
		$depth  = 0;
		foreach ( $parts as $part ) {
			if ( $part === '' ) {
				continue;
			}

			$depth++;
			if ( $depth > self::FOLDER_MAX_DEPTH ) {
				break;
			}

			$prefix = $prefix === '' ? $part : $prefix . '/' . $part;
			if ( ! isset( $folders[ $prefix ] ) ) {
				$folders[ $prefix ] = [ 'bytes' => 0, 'files' => 0 ];
			}

			$folders[ $prefix ]['bytes'] += $size;
			$folders[ $prefix ]['files']++;
		}
	}

	private function prune_folders( array $folders ): array {
		if ( count( $folders ) <= self::FOLDER_MAX_ROWS ) {
			return $folders;
		}

		uasort(
			$folders,
			static function ( array $a, array $b ): int {
				return ( (int) $b['bytes'] ) <=> ( (int) $a['bytes'] );
			}
		);

		return array_slice( $folders, 0, self::FOLDER_MAX_ROWS, true );
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

		$this->forget_path( ltrim( str_replace( '\\', '/', $relative ), '/' ) );
		return true;
	}

	private function forget_path( string $relative ): void {
		$files         = $this->store->files();
		$prefix        = $relative . '/';
		$removed_files = 0;
		$removed_bytes = 0;

		foreach ( $files as $key => $row ) {
			$normalized = ltrim( str_replace( '\\', '/', (string) $key ), '/' );
			if ( $normalized !== $relative && strncmp( $normalized, $prefix, strlen( $prefix ) ) !== 0 ) {
				continue;
			}

			if ( is_array( $row ) ) {
				$removed_bytes += (int) ( $row['bytes'] ?? 0 );
				$removed_files++;
			}

			unset( $files[ $key ] );
		}

		$this->store->save_files( $files );

		$state = $this->store->state();
		if ( ! isset( $state['status'] ) || $state['status'] === 'empty' ) {
			return;
		}

		$state['files'] = max( 0, (int) ( $state['files'] ?? 0 ) - $removed_files );
		$state['bytes'] = max( 0, (int) ( $state['bytes'] ?? 0 ) - $removed_bytes );
		$this->store->save_state( $state );

		if ( $state['status'] === 'running' ) {
			$this->ensure_scheduled( self::FALLBACK_DELAY );
		}
	}

	public function stop(): array {
		$this->cancel();

		$state = $this->store->state();
		if ( ( $state['status'] ?? '' ) === 'running' ) {
			$state['status']      = 'stopped';
			$state['finished_at'] = time();
			$this->store->save_state( $state );
		}

		return $this->public_state( $state );
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

	private function row( string $path, string $type, int $bytes, array $group, bool $deletable, string $reason = '', int $files = 0 ): array {
		if ( $reason === '' ) {
			$reason = $type === 'folder'
				? sprintf( __( 'Recursive folder total across %s files.', 'storage-inspector' ), number_format_i18n( $files ) )
				: $group['reason'];
		}

		return [
			'path'      => $path,
			'type'      => $type,
			'bytes'     => $bytes,
			'area'      => $group['label'],
			'areaType'  => $group['type'],
			'reason'    => $reason,
			'deletable' => $deletable,
		];
	}

	private function cleanup_reason( string $relative, string $wp_relative, int $size, array $group ): string {
		if ( Classifier::is_backup_path( $relative ) || Classifier::is_backup_path( $wp_relative )
			|| Classifier::is_log_path( $relative ) || Classifier::is_log_path( $wp_relative ) ) {
			return (string) $group['reason'];
		}

		if ( $size >= self::HUGE_FILE_BYTES ) {
			/* translators: %s human-readable file size. */
			return sprintf( __( 'Very large file (%s).', 'storage-inspector' ), size_format( $size, 1 ) );
		}

		if ( $size >= self::LARGE_FILE_BYTES ) {
			/* translators: %s human-readable file size. */
			return sprintf( __( 'Large file (%s).', 'storage-inspector' ), size_format( $size, 1 ) );
		}

		return (string) $group['reason'];
	}

	private function format_row( array $row ): array {
		$row['bytesHuman'] = size_format( (int) ( $row['bytes'] ?? 0 ), 2 );
		return $row;
	}

	private function ensure_scheduled( int $delay ): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + max( 1, $delay ), self::CRON_HOOK );
		}
	}

	private function approaching_memory_limit(): bool {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return false;
		}

		return memory_get_usage( true ) >= (int) ( $limit * 0.85 );
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
