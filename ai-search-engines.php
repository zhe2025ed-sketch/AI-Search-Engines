<?php
/**
 * Plugin Name: AI Search Engines
 * Plugin URI:  https://github.com/anomalyco/ai-search-engines
 * Description: Improve how AI search engines understand, crawl, and cite your website. Adds schema markup, llms.txt, AI sitemap, content auditing, and crawler controls.
 * Version:     1.0.0
 * Author:      NEJTCM
 * License:     GPL-2.0+
 * Text Domain: ai-search-engines
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

define('AISE_VERSION', '1.0.0');
define('AISE_FILE', __FILE__);
define('AISE_PATH', plugin_dir_path(__FILE__));
define('AISE_URL', plugin_dir_url(__FILE__));

// PSR-4-style autoloader for AISearchEngines namespace
spl_autoload_register(function ($class) {
    $prefix = 'AISearchEngines\\';
    $base   = AISE_PATH . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base . 'class-' . strtolower(str_replace('_', '-', $relative_class)) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// ── Activation ──────────────────────────────────────────────────────────────
register_activation_hook(__FILE__, function () {
    if (!current_user_can('activate_plugins')) {
        return;
    }

    global $wpdb;

    // Default options — auto-fill from existing site info
    add_option('aise_organization_name', get_bloginfo('name'));

    // Auto-detect logo: custom_logo → site_icon → empty
    $auto_logo = '';
    $custom_logo_id = get_theme_mod('custom_logo');
    if ($custom_logo_id) {
        $auto_logo = wp_get_attachment_image_url($custom_logo_id, 'full');
    }
    if (empty($auto_logo)) {
        $site_icon_id = get_option('site_icon');
        if ($site_icon_id) {
            $auto_logo = wp_get_attachment_image_url($site_icon_id, 'full');
        }
    }
    add_option('aise_organization_logo', $auto_logo ?: '');
    add_option('aise_same_as_links', []);
    add_option('aise_schema_enabled', '1');
    add_option('aise_llms_txt_enabled', '1');
    add_option('aise_ai_sitemap_enabled', '1');
    add_option('aise_post_types', ['post', 'page']);
    add_option('aise_crawler_rules', [
        'GPTBot'         => 'allow',
        'ChatGPT-User'   => 'allow',
        'ClaudeBot'      => 'allow',
        'PerplexityBot'  => 'allow',
        'Google-Extended' => 'allow',
        'Bytespider'     => 'disallow',
        'CCBot'          => 'allow',
    ]);
    add_option('aise_api_key', '');
    add_option('aise_api_provider', '');

    // Create audit log table
    $table   = $wpdb->prefix . 'aise_audit_log';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id BIGINT UNSIGNED NOT NULL,
        score INT NOT NULL DEFAULT 0,
        details LONGTEXT,
        audited_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_post_id (post_id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Store version for future upgrades
    update_option('aise_db_version', AISE_VERSION);

    // Flush rewrite rules for llms.txt and sitemap endpoints
    flush_rewrite_rules();
});

// ── Deactivation ────────────────────────────────────────────────────────────
register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

// ── Bootstrap modules ───────────────────────────────────────────────────────
add_action('plugins_loaded', function () {
    new AISearchEngines\Settings();
    new AISearchEngines\Admin_Dashboard();
    new AISearchEngines\Schema_Output();
    new AISearchEngines\Meta_Optimizer();
    new AISearchEngines\Content_Auditor();
    new AISearchEngines\Llms_Txt();
    new AISearchEngines\Ai_Sitemap();
    new AISearchEngines\Robots_Manager();
    new AISearchEngines\Internal_Links();
    new AISearchEngines\Post_Meta_Box();
    new AISearchEngines\Ajax_Handler();
});
