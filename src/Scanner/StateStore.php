<?php

namespace StorageInspector\Scanner;

defined( 'ABSPATH' ) || exit;

final class StateStore {

	public const STATE_OPTION   = 'storage_inspector_state';
	public const GROUPS_OPTION  = 'storage_inspector_groups';
	public const FOLDERS_OPTION = 'storage_inspector_folders';
	public const FILES_OPTION   = 'storage_inspector_files';
	public const ERRORS_OPTION  = 'storage_inspector_errors';

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
		update_option( self::FILES_OPTION, [], false );
		update_option( self::ERRORS_OPTION, [], false );

		return $state;
	}

	public function state(): array {
		$state = get_option( self::STATE_OPTION );
		return is_array( $state ) ? $state : [ 'status' => 'empty' ];
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
		$rows = get_option( self::FILES_OPTION );
		return is_array( $rows ) ? $rows : [];
	}

	public function save_files( array $files ): void {
		update_option( self::FILES_OPTION, $files, false );
	}

	public function errors(): array {
		$rows = get_option( self::ERRORS_OPTION );
		return is_array( $rows ) ? $rows : [];
	}

	public function save_errors( array $errors ): void {
		update_option( self::ERRORS_OPTION, $errors, false );
	}
}
