<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('storage_inspector_state');
delete_option('storage_inspector_groups');
delete_option('storage_inspector_folders');
delete_option('storage_inspector_files');
delete_option('storage_inspector_errors');
delete_transient('storage_inspector_scan_lock');
wp_clear_scheduled_hook('storage_inspector_cron_scan');
