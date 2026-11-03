<?php

namespace StorageInspector\Scanner;

defined( 'ABSPATH' ) || exit;

final class StateStore {

	public const STATE_OPTION   = 'storage_inspector_state';
	public const GROUPS_OPTION  = 'storage_inspector_groups';
	public const FOLDERS_OPTION = 'storage_inspector_folders';
	public const FILES_OPTION   = 'storage_inspector_files';
	public const ERRORS_OPTION  = 'storage_inspector_errors';
	public const PLUGINS_OPTION = 'storage_inspector_plugins';
	public const SEEN_OPTION    = 'storage_inspector_seen';

	// FILES_OPTION holds the chunk count. Rows live in numbered chunk options so a batch rewrites one small row.
	public const FILES_CHUNK_PREFIX = 'storage_inspector_files_';
	private const FILES_CHUNK_SIZE  = 500;

	public function reset( string $root ): array {
		$state = [
			'status'     => 'running',
			'started_at' => time(),
			'finished_at'=> 0,
			'root'       => $root,
			'queue'      => [ $root ],
			'files'      => 0,
			'dirs'       => 0,
			'bytes'      => 0,
		];

		update_option( self::STATE_OPTION, $state, false );
		update_option( self::GROUPS_OPTION, [], false );
		update_option( self::FOLDERS_OPTION, [], false );
		$this->save_files( [] );
		update_option( self::ERRORS_OPTION, [], false );
		update_option( self::PLUGINS_OPTION, [], false );
		update_option( self::SEEN_OPTION, [], false );

		return $state;
	}

	public function state(): array {
		$state = get_option( self::STATE_OPTION );
		return is_array( $state ) ? $state : [ 'status' => 'empty' ];
	}

	public function fresh_state(): array {
		wp_cache_delete( self::STATE_OPTION, 'options' );
		return $this->state();
	}

	public function save_state( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}

	public function groups(): array {
		$rows = get_option( self::GROUPS_OPTION );
		return is_array( $rows ) ? $rows : [];
	}

	public function save_groups( array $groups ): void {
		update_option( self::GROUPS_OPTION, $groups, false );
	}

	public function folders(): array {
		$rows = get_option( self::FOLDERS_OPTION );
		return is_array( $rows ) ? $rows : [];
	}

	public function save_folders( array $folders ): void {
		update_option( self::FOLDERS_OPTION, $folders, false );
	}

	public function files(): array {
		$meta = get_option( self::FILES_OPTION );

		if ( is_array( $meta ) ) {
			// Rows saved by versions before chunked storage.
			$this->save_files( $meta );
			return $meta;
		}

		$files = [];
		for ( $i = 0, $count = (int) $meta; $i < $count; $i++ ) {
			$chunk = get_option( self::FILES_CHUNK_PREFIX . $i );
			if ( ! is_array( $chunk ) ) {
				continue;
			}

			// Union by loop: array_merge would renumber numeric-string paths.
			foreach ( $chunk as $path => $row ) {
				$files[ $path ] = $row;
			}
		}

		return $files;
	}

	public function save_files( array $files ): void {
		$previous = get_option( self::FILES_OPTION );
		$old      = is_array( $previous ) ? 0 : (int) $previous;

		$chunks = array_chunk( $files, self::FILES_CHUNK_SIZE, true );
		foreach ( $chunks as $i => $chunk ) {
			update_option( self::FILES_CHUNK_PREFIX . $i, $chunk, false );
		}

		for ( $i = count( $chunks ); $i < $old; $i++ ) {
			delete_option( self::FILES_CHUNK_PREFIX . $i );
		}

		update_option( self::FILES_OPTION, count( $chunks ), false );
	}

	/** Adds rows by rewriting only the last chunk. */
	public function append_files( array $rows ): void {
		if ( ! $rows ) {
			return;
		}

		$meta = get_option( self::FILES_OPTION );
		if ( is_array( $meta ) ) {
			$this->save_files( $meta );
			$meta = get_option( self::FILES_OPTION );
		}

		$count = (int) $meta;
		$last  = $count > 0 ? get_option( self::FILES_CHUNK_PREFIX . ( $count - 1 ) ) : [];
		$last  = is_array( $last ) ? $last : [];
		$index = max( 0, $count - 1 );

		foreach ( $rows as $path => $row ) {
			if ( count( $last ) >= self::FILES_CHUNK_SIZE && ! isset( $last[ $path ] ) ) {
				update_option( self::FILES_CHUNK_PREFIX . $index, $last, false );
				$last = [];
				$index++;
			}

			$last[ $path ] = $row;
		}

		update_option( self::FILES_CHUNK_PREFIX . $index, $last, false );
		update_option( self::FILES_OPTION, $index + 1, false );
	}

	public function plugins(): array {
		$rows = get_option( self::PLUGINS_OPTION );
		return is_array( $rows ) ? $rows : [];
	}

	public function save_plugins( array $plugins ): void {
		update_option( self::PLUGINS_OPTION, $plugins, false );
	}

	/** Set of hashed real directory paths already scanned in this run. */
	public function seen(): array {
		$rows = get_option( self::SEEN_OPTION );
		return is_array( $rows ) ? $rows : [];
	}

	public function save_seen( array $seen ): void {
		update_option( self::SEEN_OPTION, $seen, false );
	}

	public function errors(): array {
		$rows = get_option( self::ERRORS_OPTION );
		return is_array( $rows ) ? $rows : [];
	}

	public function save_errors( array $errors ): void {
		update_option( self::ERRORS_OPTION, $errors, false );
	}
}
