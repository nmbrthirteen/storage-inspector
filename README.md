# Storage Inspector

A WordPress admin tool for inspecting storage usage from the site root down through plugins, media uploads, themes, cache, backups, logs, and other generated files.

## What it does

- Adds **Tools → Storage Inspector** in wp-admin.
- Scans the WordPress filesystem from `ABSPATH` in small batches.
- Continues scan work through WP-Cron, so the admin page does not need to stay open.
- Shows a dismissible scan-progress banner across wp-admin while a scan is running.
- Counts exact logical file bytes with recursive folder totals.
- Shows plugin folders separately with plugin name, version, author, active state, URI, and a WordPress plugin icon.
- Shows paginated result tables instead of returning the whole scan in every request.
- Shows unreadable folder errors so undercounted scans are visible.

## Safety model

Storage Inspector blocks deletion for WordPress core, root config files, plugin code, theme code, and Storage Inspector itself.

Deletion is only exposed for:

- files in `wp-content/uploads/`
- generated cache/temp/upgrade directories
- known backup plugin directories such as `wp-content/updraft/` and `wp-content/ai1wm-backups/`
- backup, export, dump, archive, and log files

When a media file maps to a WordPress attachment, deletion is routed through `wp_delete_attachment()` so WordPress can remove attachment metadata and generated sizes. Files that are not registered as attachments are deleted from the filesystem only.

## Install

1. Copy this folder into `wp-content/plugins/storage-inspector`.
2. Optional: run `composer dump-autoload -o`. A PSR-4 fallback autoloader is built in.
3. Activate **Storage Inspector** in WP Admin → Plugins.
4. Open **Tools → Storage Inspector**.

Requires PHP 8.0+.

## Releases

The plugin is wired for GitHub Releases through bundled Plugin Update Checker. Installed sites can receive native WordPress update notices once releases are published from `nmbrthirteen/storage-inspector`.

To cut a release:

```bash
bin/release.sh 0.1.0
```

This updates the plugin version, commits, tags `v0.1.0`, and pushes. The GitHub Action builds `storage-inspector.zip` and publishes it on the release.

## Notes

Reported sizes are exact file byte totals from `filesize()`. Hosting control panels may show different disk usage because filesystems allocate disk blocks and may count backups, logs, or files outside the WordPress root differently.

Symlinks are skipped to avoid scanning or deleting outside the WordPress root.

## License

GPL-2.0-or-later. See [`LICENSE`](./LICENSE).
