<?php

namespace StorageInspector\Scanner;

defined( 'ABSPATH' ) || exit;

final class CleanupPolicy {

	private const GENERATED_DIRS = [ 'cache', 'upgrade', 'upgrade-temp-backup', 'ai1wm-backups', 'updraft', 'backups' ];

	/** @var array<string,bool>|null Real path of a generated directory => whether the directory itself may be deleted. */
	private ?array $generated_roots = null;

	/** @var string[]|null */
	private ?array $protected_dirs = null;

	/**
	 * Symlinks are judged by where the link lives, never by their target, so a link
	 * inside a generated directory can be removed without touching what it points to.
	 */
	public function can_delete( string $path, string $root ): bool {
		$path    = Path::normalize( $path, false );
		$root    = Path::normalize( $root );
		$wp_root = Path::normalize( ABSPATH );

		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return false;
		}

		if ( ! Path::inside( $path, $root, false ) || ! Path::inside( $path, $wp_root, false ) ) {
			return false;
		}

		if ( $this->is_protected( $path, $wp_root ) ) {
			return false;
		}

		if ( is_link( $path ) || is_file( $path ) ) {
			return $this->is_deletable_file( $path );
		}

		return $this->is_deletable_directory( $path );
	}

	/**
	 * @param string[] $deleted Receives scan-root-relative paths of everything removed, even on partial failure.
	 */
	public function delete( string $path, string $root, array &$deleted = [] ): bool|\WP_Error {
		if ( ! $this->can_delete( $path, $root ) ) {
			return new \WP_Error( 'storage_inspector_protected', __( 'This path is protected and cannot be deleted.', 'storage-inspector' ), [ 'status' => 403 ] );
		}

		$path = Path::normalize( $path, false );
		$root = Path::normalize( $root );

		if ( is_link( $path ) || is_file( $path ) ) {
			if ( ! is_link( $path ) ) {
				$attachment_id = $this->attachment_id_for_file( $path );
				if ( $attachment_id > 0 ) {
					if ( wp_delete_attachment( $attachment_id, true ) === false ) {
						return false;
					}

					$deleted[] = Path::relative( $path, $root );
					return true;
				}
			}

			$relative = Path::relative( $path, $root, false );
			$res      = $this->remove_file( $path, $relative );
			if ( ! is_wp_error( $res ) ) {
				$deleted[] = $relative;
			}

			return $res;
		}

		return $this->delete_directory( $path, $root, $deleted );
	}

	private function is_protected( string $path, string $wp_root ): bool {
		foreach ( $this->protected_dirs( $wp_root ) as $dir ) {
			if ( $path === $dir ) {
				return true;
			}
		}

		foreach ( $this->protected_trees( $wp_root ) as $tree ) {
			if ( Path::inside( $path, $tree, false ) ) {
				return true;
			}
		}

		return in_array( $path, [ $wp_root . '/wp-config.php', $wp_root . '/.htaccess', $wp_root . '/index.php' ], true );
	}

	/** @return string[] Directories that are protected themselves but may hold deletable children. */
	private function protected_dirs( string $wp_root ): array {
		if ( $this->protected_dirs === null ) {
			$uploads              = wp_get_upload_dir();
			$this->protected_dirs = array_filter(
				[
					$wp_root,
					Path::normalize( WP_CONTENT_DIR ),
					empty( $uploads['basedir'] ) ? '' : Path::normalize( (string) $uploads['basedir'] ),
				]
			);
		}

		return $this->protected_dirs;
	}

	/** @return string[] Code areas, resolved to real paths, that are protected with everything below them. */
	private function protected_trees( string $wp_root ): array {
		$trees = [
			$wp_root . '/wp-admin',
			$wp_root . '/wp-includes',
			Path::normalize( WP_PLUGIN_DIR ),
			Path::normalize( WPMU_PLUGIN_DIR ),
			Path::normalize( get_theme_root() ),
			Path::normalize( STORAGE_INSPECTOR_PATH ),
		];

		if ( defined( 'WP_LANG_DIR' ) ) {
			$trees[] = Path::normalize( WP_LANG_DIR );
		}

		return $trees;
	}

	private function is_deletable_file( string $path ): bool {
		if ( $this->generated_root_for( $path ) !== null ) {
			return true;
		}

		if ( is_link( $path ) ) {
			return false;
		}

		$name = basename( $path );
		if ( $this->is_generated_artifact( $name ) ) {
			return true;
		}

		return $this->attachment_id_for_file( $path ) > 0;
	}

	/**
	 * Backup archives, database dumps, and logs, matched by extension. A name match
	 * alone must never reach code, so anything else (php, js, ...) stays protected.
	 */
	private function is_generated_artifact( string $name ): bool {
		return (bool) preg_match( '/\.(zip|tar|gz|tgz|bz2|xz|rar|7z|sql|bak|old|wpress|log)$/i', $name )
			|| in_array( strtolower( $name ), [ 'debug.log', 'error_log' ], true );
	}

	private function is_deletable_directory( string $path ): bool {
		$roots = $this->generated_roots();

		if ( isset( $roots[ $path ] ) ) {
			return $roots[ $path ];
		}

		return $this->generated_root_for( $path ) !== null;
	}

	/** @return string|null The generated root containing $path, if any. */
	private function generated_root_for( string $path ): ?string {
		foreach ( array_keys( $this->generated_roots() ) as $dir ) {
			if ( $path !== $dir && Path::inside( $path, $dir, false ) ) {
				return $dir;
			}
		}

		return null;
	}

	/** @return array<string,bool> */
	private function generated_roots(): array {
		if ( $this->generated_roots !== null ) {
			return $this->generated_roots;
		}

		$content = Path::normalize( WP_CONTENT_DIR );
		$roots   = [];
		foreach ( self::GENERATED_DIRS as $dir ) {
			$roots[ $content . '/' . $dir ] = true;
		}

		// get_temp_dir() falls back to WP_CONTENT_DIR itself, which must never count as temp space.
		$wp_root = Path::normalize( ABSPATH );
		$temp    = Path::normalize( get_temp_dir() );
		if ( $temp !== $wp_root && Path::inside( $temp, $wp_root ) && ! $this->contains_protected( $temp, $wp_root ) ) {
			$roots[ $temp ] = false;
		}

		$this->generated_roots = $roots;
		return $roots;
	}

	private function contains_protected( string $dir, string $wp_root ): bool {
		$protected = array_merge( $this->protected_dirs( $wp_root ), $this->protected_trees( $wp_root ) );
		foreach ( $protected as $item ) {
			if ( Path::inside( $item, $dir ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Walks the whole tree and checks every entry before removing anything, so one
	 * protected file cannot leave a half-deleted folder behind.
	 *
	 * @param string[] $deleted
	 */
	private function delete_directory( string $dir, string $root, array &$deleted ): bool|\WP_Error {
		$items = [];
		$walk  = $this->collect_tree( $dir, $root, $items );
		if ( is_wp_error( $walk ) ) {
			return $walk;
		}
		$items[] = [ 'path' => $dir, 'dir' => true ];

		$failed    = [];
		$remaining = [];
		$first     = '';

		foreach ( $items as $item ) {
			$relative = Path::relative( $item['path'], $root, false );

			if ( $this->has_failed_descendant( $item['path'], $failed ) ) {
				$remaining[] = $relative;
				continue;
			}

			$res = $item['dir'] ? $this->remove_dir( $item['path'], $relative ) : $this->remove_file( $item['path'], $relative );
			if ( is_wp_error( $res ) ) {
				$failed[]    = $item['path'];
				$remaining[] = $relative;
				$first       = $first !== '' ? $first : $res->get_error_message();
				continue;
			}

			$deleted[] = $relative;
		}

		if ( ! $remaining ) {
			return true;
		}

		$shown = array_slice( $remaining, 0, 5 );
		$more  = count( $remaining ) - count( $shown );

		return new \WP_Error(
			'storage_inspector_partial_delete',
			sprintf(
				/* translators: 1: deleted item count, 2: remaining item count, 3: first error, 4: list of remaining paths. */
				__( 'Deleted %1$s items, but %2$s remain. %3$s Remaining: %4$s', 'storage-inspector' ),
				number_format_i18n( count( $deleted ) ),
				number_format_i18n( count( $remaining ) ),
				$first,
				implode( ', ', $shown ) . ( $more > 0 ? sprintf( /* translators: %s number of additional paths. */ __( ' and %s more', 'storage-inspector' ), number_format_i18n( $more ) ) : '' )
			),
			[
				'status'    => 500,
				'remaining' => $remaining,
			]
		);
	}

	/**
	 * Lists entries children-first. Symlinks are leaves: they are never entered.
	 *
	 * @param array<int,array{path:string,dir:bool}> $items
	 */
	private function collect_tree( string $dir, string $root, array &$items ): bool|\WP_Error {
		error_clear_last();
		$entries = @scandir( $dir );
		if ( $entries === false ) {
			return new \WP_Error(
				'storage_inspector_scandir_failed',
				sprintf(
					/* translators: 1: folder path, 2: system error. */
					__( 'Could not read %1$s: %2$s', 'storage-inspector' ),
					Path::relative( $dir, $root, false ),
					$this->last_error()
				),
				[ 'status' => 500 ]
			);
		}

		foreach ( $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}

			$path = Path::normalize( $dir . DIRECTORY_SEPARATOR . $entry, false );
			if ( ! $this->can_delete( $path, $root ) ) {
				return new \WP_Error(
					'storage_inspector_protected',
					sprintf(
						/* translators: %s path of the protected item. */
						__( 'Nothing was deleted because %s is protected.', 'storage-inspector' ),
						Path::relative( $path, $root, false )
					),
					[ 'status' => 403 ]
				);
			}

			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$walk = $this->collect_tree( $path, $root, $items );
				if ( is_wp_error( $walk ) ) {
					return $walk;
				}
				$items[] = [ 'path' => $path, 'dir' => true ];
				continue;
			}

			$items[] = [ 'path' => $path, 'dir' => false ];
		}

		return true;
	}

	private function has_failed_descendant( string $path, array $failed ): bool {
		foreach ( $failed as $item ) {
			if ( strncmp( $item, trailingslashit( $path ), strlen( trailingslashit( $path ) ) ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	private function remove_file( string $path, string $relative ): bool|\WP_Error {
		error_clear_last();
		if ( @unlink( $path ) ) {
			return true;
		}

		return new \WP_Error(
			'storage_inspector_unlink_failed',
			sprintf(
				/* translators: 1: file path, 2: system error. */
				__( 'Could not delete %1$s: %2$s', 'storage-inspector' ),
				$relative,
				$this->last_error()
			),
			[ 'status' => 500 ]
		);
	}

	private function remove_dir( string $path, string $relative ): bool|\WP_Error {
		error_clear_last();
		if ( @rmdir( $path ) ) {
			return true;
		}

		return new \WP_Error(
			'storage_inspector_rmdir_failed',
			sprintf(
				/* translators: 1: folder path, 2: system error. */
				__( 'Could not remove folder %1$s: %2$s', 'storage-inspector' ),
				$relative,
				$this->last_error()
			),
			[ 'status' => 500 ]
		);
	}

	private function last_error(): string {
		$error = error_get_last();
		return $error ? $error['message'] : __( 'unknown error', 'storage-inspector' );
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
