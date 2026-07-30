<?php
namespace AISearchEngines;

defined('ABSPATH') || exit;

/**
 * AJAX Handler — Endpoints for admin dashboard interactions.
 *
 * All endpoints require manage_options capability and valid nonce.
 */
class Ajax_Handler {

    public function __construct() {
        add_action('wp_ajax_aise_run_audit',       [$this, 'run_audit']);
        add_action('wp_ajax_aise_auto_optimize',   [$this, 'auto_optimize']);
        add_action('wp_ajax_aise_audit_single',    [$this, 'audit_single']);
        add_action('wp_ajax_aise_auto_optimize_single', [$this, 'auto_optimize_single']);
        add_action('wp_ajax_aise_generate_llms',   [$this, 'generate_llms']);
        add_action('wp_ajax_aise_generate_sitemap', [$this, 'generate_sitemap']);
        add_action('wp_ajax_aise_get_audit_data',  [$this, 'get_audit_data']);
        add_action('wp_ajax_aise_get_link_data',   [$this, 'get_link_data']);
    }

    /**
     * Run full site audit on all configured post types.
     */
    public function run_audit() {
        $this->verify_request('aise_dashboard_nonce');

        $auditor = new Content_Auditor();
        $results = $auditor->audit_all_posts();

        $total  = count($results);
        $sum    = array_sum($results);
        $avg    = $total > 0 ? round($sum / $total) : 0;

        $distribution = [
            'excellent' => 0, // 80-100
            'good'      => 0, // 60-79
            'needs_work' => 0, // 40-59
            'poor'      => 0, // 0-39
        ];

        foreach ($results as $score) {
            if ($score >= 80) {
                $distribution['excellent']++;
            } elseif ($score >= 60) {
                $distribution['good']++;
            } elseif ($score >= 40) {
                $distribution['needs_work']++;
            } else {
                $distribution['poor']++;
            }
        }

        wp_send_json_success([
            'total_posts'  => $total,
            'average_score' => $avg,
            'distribution' => $distribution,
            'message'      => sprintf(
                /* translators: %d: number of posts */
                __('Audit complete. %d posts analyzed.', 'ai-search-engines'),
                $total
            ),
        ]);
    }

    /**
     * Audit a single post by ID.
     */
    public function audit_single() {
        $this->verify_request('aise_meta_box_nonce');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id || !get_post($post_id)) {
            wp_send_json_error(['message' => __('Invalid post ID.', 'ai-search-engines')]);
        }

        $auditor = new Content_Auditor();
        $result  = $auditor->audit_post($post_id);

        wp_send_json_success([
            'post_id' => $post_id,
            'score'   => $result['score'],
            'checks'  => $result['checks'],
        ]);
    }

    /**
     * Regenerate llms.txt file.
     */
    public function generate_llms() {
        $this->verify_request('aise_dashboard_nonce');

        $llms = new Llms_Txt();
        $llms->regenerate();

        wp_send_json_success([
            'message' => __('llms.txt regenerated successfully.', 'ai-search-engines'),
            'url'     => home_url('/llms.txt'),
        ]);
    }

    /**
     * Regenerate AI sitemap.
     */
    public function generate_sitemap() {
        $this->verify_request('aise_dashboard_nonce');

        $sitemap = new Ai_Sitemap();
        $sitemap->regenerate();

        wp_send_json_success([
            'message' => __('AI sitemap regenerated successfully.', 'ai-search-engines'),
            'url'     => home_url('/ai-sitemap.xml'),
        ]);
    }

    /**
     * Get audit data for dashboard table (paginated).
     */
    public function get_audit_data() {
        $this->verify_request('aise_dashboard_nonce');

        $page     = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $per_page = 20;
        $orderby  = isset($_POST['orderby']) && $_POST['orderby'] === 'title' ? 'title' : 'meta_value_num';
        $order    = isset($_POST['order']) && strtoupper($_POST['order']) === 'ASC' ? 'ASC' : 'ASC'; // lowest score first by default

        $post_types = get_option('aise_post_types', ['post', 'page']);

        $args = [
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'meta_key'       => '_aise_audit_score',
            'orderby'        => $orderby,
            'order'          => $order,
        ];

        $query = new \WP_Query($args);
        $posts = [];

        foreach ($query->posts as $post) {
            $score   = (int) get_post_meta($post->ID, '_aise_audit_score', true);
            $details = get_post_meta($post->ID, '_aise_audit_details', true);

            $posts[] = [
                'id'         => $post->ID,
                'title'      => $post->post_title,
                'url'        => get_permalink($post->ID),
                'edit_url'   => get_edit_post_link($post->ID, 'raw'),
                'score'      => $score,
                'post_type'  => $post->post_type,
                'modified'   => get_the_modified_date('Y-m-d', $post->ID),
                'checks'     => is_array($details) ? $details : [],
            ];
        }

        wp_send_json_success([
            'posts'       => $posts,
            'total'       => $query->found_posts,
            'total_pages' => $query->max_num_pages,
            'page'        => $page,
        ]);
    }

    /**
     * Get internal link analysis data.
     */
    public function get_link_data() {
        $this->verify_request('aise_dashboard_nonce');

        $links = new Internal_Links();
        $stats = $links->get_site_link_stats();

        $orphans = $links->get_orphan_pages();
        $orphan_list = [];
        foreach (array_slice($orphans, 0, 20, true) as $pid => $title) {
            $orphan_list[] = [
                'id'       => $pid,
                'title'    => $title,
                'edit_url' => get_edit_post_link($pid, 'raw'),
            ];
        }

        $weak = $links->get_weak_pages();
        $weak_list = [];
        foreach (array_slice($weak, 0, 20, true) as $pid => $title) {
            $weak_list[] = [
                'id'       => $pid,
                'title'    => $title,
                'edit_url' => get_edit_post_link($pid, 'raw'),
            ];
        }

        wp_send_json_success([
            'stats'   => $stats,
            'orphans' => $orphan_list,
            'weak'    => $weak_list,
        ]);
    }

    /**
     * Auto-optimize all posts via AJAX.
     */
    public function auto_optimize() {
        $this->verify_request('aise_dashboard_nonce');

        $auditor = new Content_Auditor();
        $results = $auditor->auto_optimize_all_posts();

        $total  = count($results);
        $sum    = array_sum($results);
        $avg    = $total > 0 ? round($sum / $total) : 0;

        $distribution = [
            'excellent'  => 0,
            'good'       => 0,
            'needs_work' => 0,
            'poor'       => 0,
        ];

        foreach ($results as $score) {
            if ($score >= 80) {
                $distribution['excellent']++;
            } elseif ($score >= 60) {
                $distribution['good']++;
            } elseif ($score >= 40) {
                $distribution['needs_work']++;
            } else {
                $distribution['poor']++;
            }
        }

        wp_send_json_success([
            'total_posts'   => $total,
            'average_score' => $avg,
            'distribution'  => $distribution,
            'message'       => sprintf(
                __('Auto-optimization complete! %d posts updated and re-audited.', 'ai-search-engines'),
                $total
            ),
        ]);
    }

    /**
     * Auto-optimize single post via AJAX.
     */
    public function auto_optimize_single() {
        if (isset($_POST['nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aise_meta_nonce')) {
            // Meta box nonce
        } elseif (isset($_POST['nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aise_dashboard_nonce')) {
            // Dashboard nonce
        } else {
            wp_send_json_error(['message' => __('Security check failed.', 'ai-search-engines')], 403);
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id) {
            wp_send_json_error(['message' => __('Invalid post ID.', 'ai-search-engines')]);
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'ai-search-engines')]);
        }

        $auditor = new Content_Auditor();
        $res     = $auditor->auto_optimize_post($post_id);

        if (false === $res) {
            wp_send_json_error(['message' => __('Could not optimize post.', 'ai-search-engines')]);
        }

        wp_send_json_success($res);
    }

    /**
     * Verify AJAX request: nonce + capability.
     */
    private function verify_request(string $nonce_action) {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'ai-search-engines')], 403);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, $nonce_action)) {
            wp_send_json_error(['message' => __('Security check failed.', 'ai-search-engines')], 403);
        }
    }
}
