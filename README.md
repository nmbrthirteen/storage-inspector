# Storage Inspector

Inspect WordPress storage usage from wp-admin, find what is taking disk space, and safely clean generated files without blocking the admin page.

Storage Inspector is a WordPress admin tool that scans the main web root when available, falls back to the WordPress install root when needed, and reports exact file-byte usage by area: media uploads, plugins, themes, WordPress core, cache, backups, logs, and other site files.

## What it does

- Adds **Tools → Storage Inspector** in wp-admin.
- Scans the main web root when the server exposes it safely, otherwise scans the WordPress install root.
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

## Requirements

- WordPress 6.0 or newer.
- PHP 8.0 or newer.
- A WordPress admin account with `manage_options`.
- Writable WordPress options table for scan state.
- WP-Cron enabled or enough wp-admin traffic to continue background batches.
- Filesystem read permission for folders you want measured.

## What it needs to work well

- Start scans manually from **Tools → Storage Inspector**. The plugin does not auto-scan on install.
- Keep the page open for faster scanning, or let WP-Cron continue batches in the background.
- Review the displayed **Scanned root** path. This is the exact folder being measured.
- Use the **Scan errors** tab to see folders that could not be read; unreadable folders are not included in totals.
- Compare totals against hosting panels carefully: Storage Inspector reports logical file bytes, while hosting panels may include database size, mail, server logs, backups outside the web root, or disk block allocation.

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
