<?php
namespace AISearchEngines;

defined('ABSPATH') || exit;

/**
 * Admin Dashboard — Main AI Visibility overview page.
 *
 * Shows site-wide AI readiness score, per-post audit table,
 * file generation status, and quick actions.
 */
class Admin_Dashboard {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_items']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Register top-level menu and sub-pages.
     */
    public function add_menu_items() {
        add_menu_page(
            __('AI Search', 'ai-search-engines'),
            __('AI Search', 'ai-search-engines'),
            'manage_options',
            'aise-dashboard',
            [$this, 'render_dashboard'],
            'dashicons-visibility',
            80
        );

        add_submenu_page(
            'aise-dashboard',
            __('AI Visibility Dashboard', 'ai-search-engines'),
            __('Dashboard', 'ai-search-engines'),
            'manage_options',
            'aise-dashboard',
            [$this, 'render_dashboard']
        );
    }

    /**
     * Enqueue dashboard CSS and JS.
     */
    public function enqueue_assets($hook) {
        if (strpos($hook, 'aise-dashboard') === false) {
            return;
        }

        wp_enqueue_style(
            'aise-dashboard-css',
            AISE_URL . 'assets/css/admin-dashboard.css',
            [],
            AISE_VERSION . '.' . time()
        );

        wp_enqueue_script(
            'aise-dashboard-js',
            AISE_URL . 'assets/js/admin-dashboard.js',
            ['jquery'],
            AISE_VERSION . '.' . time(),
            true
        );

        wp_localize_script('aise-dashboard-js', 'aiseDashboard', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('aise_dashboard_nonce'),
            'strings'  => [
                'running'    => __('Running audit…', 'ai-search-engines'),
                'complete'   => __('Audit complete!', 'ai-search-engines'),
                'error'      => __('An error occurred. Please try again.', 'ai-search-engines'),
                'generating' => __('Generating…', 'ai-search-engines'),
                'generated'  => __('Generated successfully!', 'ai-search-engines'),
                'confirm_audit' => __('Run a full site audit? This may take a moment on large sites.', 'ai-search-engines'),
            ],
        ]);
    }

    /**
     * Render the main dashboard page.
     */
    public function render_dashboard() {
        $post_types = get_option('aise_post_types', ['post', 'page']);

        // Get aggregate stats
        global $wpdb;
        $scores = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_value as score
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_aise_audit_score'
             AND p.post_status = 'publish'
             AND p.post_type IN (" . implode(',', array_fill(0, count($post_types), '%s')) . ")",
            ...$post_types
        ));

        $total     = count($scores);
        $avg_score = 0;
        $dist      = ['excellent' => 0, 'good' => 0, 'needs_work' => 0, 'poor' => 0];

        if ($total > 0) {
            $sum = 0;
            foreach ($scores as $row) {
                $s = (int) $row->score;
                $sum += $s;
                if ($s >= 80) $dist['excellent']++;
                elseif ($s >= 60) $dist['good']++;
                elseif ($s >= 40) $dist['needs_work']++;
                else $dist['poor']++;
            }
            $avg_score = round($sum / $total);
        }

        // Feature status
        $schema_on  = (bool) get_option('aise_schema_enabled', '1');
        $llms_on    = (bool) get_option('aise_llms_txt_enabled', '1');
        $sitemap_on = (bool) get_option('aise_ai_sitemap_enabled', '1');

        // Get worst scoring posts
        $worst_posts = new \WP_Query([
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => 15,
            'meta_key'       => '_aise_audit_score',
            'orderby'        => 'meta_value_num',
            'order'          => 'ASC',
        ]);

        // Get posts without audit
        $unaudited = new \WP_Query([
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'     => '_aise_audit_score',
                    'compare' => 'NOT EXISTS',
                ],
            ],
            'fields' => 'ids',
        ]);
        $unaudited_count = $unaudited->found_posts;

        // Robots manager info
        $robots = new Robots_Manager();
        $noindex_posts = $robots->get_noindex_posts();

        ?>
        <div class="wrap aise-dashboard">
            <h1><?php esc_html_e('AI Search Visibility Dashboard', 'ai-search-engines'); ?></h1>

            <div id="aise-action-status" class="notice notice-info" style="display:none; margin: 15px 0; padding: 12px; font-size: 14px;"></div>

            <!-- Score Overview Cards -->
            <div class="aise-cards">
                <div class="aise-card aise-card-score">
                    <div class="aise-score-circle <?php echo esc_attr($this->score_class($avg_score)); ?>">
                        <span class="aise-score-number"><?php echo esc_html($avg_score); ?></span>
                        <span class="aise-score-label"><?php esc_html_e('Average', 'ai-search-engines'); ?></span>
                    </div>
                    <h3><?php esc_html_e('AI Readiness Score', 'ai-search-engines'); ?></h3>
                    <p class="aise-card-subtitle">
                        <?php printf(
                            /* translators: %d: total number of posts */
                            esc_html__('%d posts audited', 'ai-search-engines'),
                            $total
                        ); ?>
                    </p>
                </div>

                <div class="aise-card">
                    <h3><?php esc_html_e('Score Distribution', 'ai-search-engines'); ?></h3>
                    <div class="aise-distribution">
                        <div class="aise-dist-bar">
                            <div class="aise-dist-fill aise-score-green" style="width: <?php echo $total ? esc_attr(round($dist['excellent'] / $total * 100)) : 0; ?>%"></div>
                        </div>
                        <div class="aise-dist-legend">
                            <span class="aise-legend-item"><span class="aise-dot aise-score-green"></span> <?php printf(esc_html__('Excellent (80+): %d', 'ai-search-engines'), $dist['excellent']); ?></span>
                            <span class="aise-legend-item"><span class="aise-dot aise-score-blue"></span> <?php printf(esc_html__('Good (60-79): %d', 'ai-search-engines'), $dist['good']); ?></span>
                            <span class="aise-legend-item"><span class="aise-dot aise-score-yellow"></span> <?php printf(esc_html__('Needs Work (40-59): %d', 'ai-search-engines'), $dist['needs_work']); ?></span>
                            <span class="aise-legend-item"><span class="aise-dot aise-score-red"></span> <?php printf(esc_html__('Poor (<40): %d', 'ai-search-engines'), $dist['poor']); ?></span>
                        </div>
                        <canvas id="aise-donut-chart" width="180" height="180"
                            data-excellent="<?php echo esc_attr($dist['excellent']); ?>"
                            data-good="<?php echo esc_attr($dist['good']); ?>"
                            data-needs-work="<?php echo esc_attr($dist['needs_work']); ?>"
                            data-poor="<?php echo esc_attr($dist['poor']); ?>">
                        </canvas>
                    </div>
                </div>

                <div class="aise-card">
                    <h3><?php esc_html_e('Feature Status', 'ai-search-engines'); ?></h3>
                    <ul class="aise-feature-list">
                        <li>
                            <span class="aise-status-badge <?php echo $schema_on ? 'aise-badge-on' : 'aise-badge-off'; ?>">
                                <?php echo $schema_on ? esc_html__('ON', 'ai-search-engines') : esc_html__('OFF', 'ai-search-engines'); ?>
                            </span>
                            <?php esc_html_e('Schema Markup', 'ai-search-engines'); ?>
                        </li>
                        <li>
                            <span class="aise-status-badge <?php echo $llms_on ? 'aise-badge-on' : 'aise-badge-off'; ?>">
                                <?php echo $llms_on ? esc_html__('ON', 'ai-search-engines') : esc_html__('OFF', 'ai-search-engines'); ?>
                            </span>
                            <?php esc_html_e('llms.txt', 'ai-search-engines'); ?>
                            <?php if ($llms_on): ?>
                                — <a href="<?php echo esc_url(home_url('/llms.txt')); ?>" target="_blank"><?php esc_html_e('View', 'ai-search-engines'); ?></a>
                            <?php endif; ?>
                        </li>
                        <li>
                            <span class="aise-status-badge <?php echo $sitemap_on ? 'aise-badge-on' : 'aise-badge-off'; ?>">
                                <?php echo $sitemap_on ? esc_html__('ON', 'ai-search-engines') : esc_html__('OFF', 'ai-search-engines'); ?>
                            </span>
                            <?php esc_html_e('AI Sitemap', 'ai-search-engines'); ?>
                            <?php if ($sitemap_on): ?>
                                — <a href="<?php echo esc_url(home_url('/ai-sitemap.xml')); ?>" target="_blank"><?php esc_html_e('View', 'ai-search-engines'); ?></a>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="aise-actions">
                <button type="button" id="aise-run-audit" class="button button-secondary button-hero">
                    <span class="dashicons dashicons-search"></span>
                    <?php esc_html_e('Run Full Site Audit', 'ai-search-engines'); ?>
                </button>
                <button type="button" id="aise-btn-auto-optimize" class="button button-primary button-hero">
                    <span class="dashicons dashicons-magic"></span>
                    <?php esc_html_e('Auto-Optimize All Posts', 'ai-search-engines'); ?>
                </button>
                <button type="button" id="aise-regen-llms" class="button button-secondary">
                    <span class="dashicons dashicons-media-text"></span>
                    <?php esc_html_e('Regenerate llms.txt', 'ai-search-engines'); ?>
                </button>
                <button type="button" id="aise-regen-sitemap" class="button button-secondary">
                    <span class="dashicons dashicons-networking"></span>
                    <?php esc_html_e('Regenerate AI Sitemap', 'ai-search-engines'); ?>
                </button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=aise-settings')); ?>" class="button button-secondary">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <?php esc_html_e('Settings', 'ai-search-engines'); ?>
                </a>
            </div>              <div id="aise-action-status" class="aise-action-status" style="display:none;"></div>
            </div>

            <!-- Alerts Section -->
            <?php if ($unaudited_count > 0): ?>
            <div class="aise-alert aise-alert-info">
                <span class="dashicons dashicons-info"></span>
                <?php printf(
                    /* translators: %d: number of unaudited posts */
                    esc_html__('%d posts have not been audited yet. Click "Run Full Site Audit" to analyze them.', 'ai-search-engines'),
                    $unaudited_count
                ); ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($noindex_posts)): ?>
            <div class="aise-alert aise-alert-warning">
                <span class="dashicons dashicons-warning"></span>
                <?php printf(
                    /* translators: %d: number of noindex posts */
                    esc_html__('%d posts are set to noindex, which blocks AI crawlers from seeing them:', 'ai-search-engines'),
                    count($noindex_posts)
                ); ?>
                <ul class="aise-noindex-list">
                    <?php foreach (array_slice($noindex_posts, 0, 5, true) as $pid => $ptitle): ?>
                        <li><a href="<?php echo esc_url(get_edit_post_link($pid, 'raw')); ?>"><?php echo esc_html($ptitle); ?></a></li>
                    <?php endforeach; ?>
                    <?php if (count($noindex_posts) > 5): ?>
                        <li><em><?php printf(esc_html__('…and %d more', 'ai-search-engines'), count($noindex_posts) - 5); ?></em></li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Posts Audit Table -->
            <div class="aise-section">
                <h2><?php esc_html_e('Pages by AI Readiness', 'ai-search-engines'); ?></h2>
                <p class="description"><?php esc_html_e('Sorted by lowest score first — fix these pages to improve your AI visibility.', 'ai-search-engines'); ?></p>

                <table class="wp-list-table widefat fixed striped aise-audit-table">
                    <thead>
                        <tr>
                            <th class="aise-col-score"><?php esc_html_e('Score', 'ai-search-engines'); ?></th>
                            <th><?php esc_html_e('Title', 'ai-search-engines'); ?></th>
                            <th><?php esc_html_e('Type', 'ai-search-engines'); ?></th>
                            <th><?php esc_html_e('Issues', 'ai-search-engines'); ?></th>
                            <th class="aise-col-actions"><?php esc_html_e('Actions', 'ai-search-engines'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($worst_posts->have_posts()): ?>
                            <?php while ($worst_posts->have_posts()): $worst_posts->the_post(); ?>
                                <?php
                                $pid     = get_the_ID();
                                $score   = (int) get_post_meta($pid, '_aise_audit_score', true);
                                $details = get_post_meta($pid, '_aise_audit_details', true);
                                $checks  = is_array($details) && isset($details['checks']) ? $details['checks'] : [];
                                $fails   = [];
                                foreach ($checks as $name => $check) {
                                    if (isset($check['status']) && $check['status'] === 'fail') {
                                        $fails[] = isset($check['message']) ? $check['message'] : $name;
                                    }
                                }
                                ?>
                                <tr>
                                    <td class="aise-col-score">
                                        <span class="aise-score-badge <?php echo esc_attr($this->score_class($score)); ?>">
                                            <?php echo esc_html($score); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><a href="<?php echo esc_url(get_permalink($pid)); ?>" target="_blank"><?php the_title(); ?></a></strong>
                                    </td>
                                    <td><?php echo esc_html(get_post_type_object(get_post_type())->labels->singular_name); ?></td>
                                    <td>
                                        <?php if (!empty($fails)): ?>
                                            <ul class="aise-issues-list">
                                                <?php foreach (array_slice($fails, 0, 3) as $f): ?>
                                                    <li><?php echo esc_html($f); ?></li>
                                                <?php endforeach; ?>
                                                <?php if (count($fails) > 3): ?>
                                                    <li><em><?php printf(esc_html__('+%d more', 'ai-search-engines'), count($fails) - 3); ?></em></li>
                                                <?php endif; ?>
                                            </ul>
                                        <?php elseif ($score === 0): ?>
                                            <span class="description"><?php esc_html_e('Not yet audited', 'ai-search-engines'); ?></span>
                                        <?php else: ?>
                                            <span class="aise-all-pass"><?php esc_html_e('All checks passed', 'ai-search-engines'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="aise-col-actions">
                                        <a href="<?php echo esc_url(get_edit_post_link($pid, 'raw')); ?>" class="button button-small">
                                            <?php esc_html_e('Edit', 'ai-search-engines'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            <?php wp_reset_postdata(); ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5">
                                    <?php esc_html_e('No audited posts found. Run a site audit to get started.', 'ai-search-engines'); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Robots.txt Preview -->
            <div class="aise-section">
                <h2><?php esc_html_e('AI Crawler Directives', 'ai-search-engines'); ?></h2>
                <p class="description"><?php esc_html_e('These directives are appended to your robots.txt for AI search engine crawlers.', 'ai-search-engines'); ?></p>
                <pre class="aise-robots-preview"><?php echo esc_html($robots->get_effective_robots()); ?></pre>
            </div>
        </div>
        <?php
    }

    /**
     * Get CSS class for a score value.
     */
    private function score_class(int $score): string {
        if ($score >= 70) return 'aise-score-green';
        if ($score >= 40) return 'aise-score-yellow';
        return 'aise-score-red';
    }
}
