<?php
/**
 * AI Search Engines — Uninstall
 *
 * Removes all plugin data when the plugin is deleted via WordPress admin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Remove options
$options = [
    'aise_organization_name',
    'aise_organization_logo',
    'aise_same_as_links',
    'aise_schema_enabled',
    'aise_llms_txt_enabled',
    'aise_ai_sitemap_enabled',
    'aise_post_types',
    'aise_crawler_rules',
    'aise_api_key',
    'aise_api_provider',
    'aise_db_version',
    'aise_llms_txt_cache',
    'aise_sitemap_cache',
];

foreach ($options as $option) {
    delete_option($option);
}

// Remove post meta
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_aise_%'");

// Remove audit log table
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aise_audit_log");

// Remove transients
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_aise_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_timeout_aise_%'");

// Flush rewrite rules
flush_rewrite_rules();
