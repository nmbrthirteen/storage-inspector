<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('storage_inspector_state');
delete_option('storage_inspector_groups');
delete_option('storage_inspector_folders');
delete_option('storage_inspector_files');
delete_option('storage_inspector_errors');
delete_option('storage_inspector_plugins');
delete_option('storage_inspector_seen');
delete_option('storage_inspector_scan_lock');

global $wpdb;
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('storage_inspector_files_') . '%'
    )
);
wp_clear_scheduled_hook('storage_inspector_cron_scan');
