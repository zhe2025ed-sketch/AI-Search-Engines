<?php
/**
 * Robots Manager Handler
 *
 * @package AISearchEngines
 */

namespace AISearchEngines;

defined( 'ABSPATH' ) || exit;

class Robots_Manager {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'robots_txt', [ $this, 'modify_robots_txt' ], 10, 2 );
	}

	/**
	 * Modify robots.txt content.
	 *
	 * @param string $output The robots.txt output.
	 * @param bool   $public Whether the site is considered "public".
	 * @return string Modified robots.txt output.
	 */
	public function modify_robots_txt( $output, $public ) {
		// If the site is not public, default rules typically block everything. We can still append our rules.
		$crawler_rules = get_option( 'aise_crawler_rules', [] );
		$ai_sitemap_enabled = get_option( 'aise_ai_sitemap_enabled', '0' );
		$llms_txt_enabled = get_option( 'aise_llms_txt_enabled', '0' );

		$additional_rules = "\n\n# AI Search Engines Plugin - AI Crawler Directives\n";
		$has_rules = false;

		if ( ! empty( $crawler_rules ) && is_array( $crawler_rules ) ) {
			foreach ( $crawler_rules as $bot_name => $directive ) {
				$bot_name = sanitize_text_field( $bot_name );
				if ( in_array( $directive, [ 'allow', 'disallow' ], true ) ) {
					$additional_rules .= "User-agent: {$bot_name}\n";
					if ( 'allow' === $directive ) {
						$additional_rules .= "Allow: /\n\n";
					} else {
						$additional_rules .= "Disallow: /\n\n";
					}
					$has_rules = true;
				}
			}
		}

		if ( '1' === $ai_sitemap_enabled ) {
			$additional_rules .= "# AI Sitemap\n";
			$additional_rules .= "Sitemap: " . home_url( '/ai-sitemap.xml' ) . "\n\n";
			$has_rules = true;
		}

		if ( '1' === $llms_txt_enabled ) {
			$additional_rules .= "# LLMs.txt\n";
			$additional_rules .= "# See: " . home_url( '/llms.txt' ) . "\n\n";
			$has_rules = true;
		}

		if ( $has_rules ) {
			$output .= rtrim( $additional_rules, "\n" ) . "\n";
		}

		return $output;
	}

	/**
	 * Get the effective robots.txt content.
	 *
	 * @return string Effective robots.txt content.
	 */
	public function get_effective_robots() {
		$public = get_option( 'blog_public' );
		$output = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";

		if ( '0' == $public ) {
			$output = "User-agent: *\nDisallow: /\n";
		}

		// Apply core filters to get accurate representation.
		$output = apply_filters( 'robots_txt', $output, $public );

		return $output;
	}

	/**
	 * Get posts and pages that have noindex set.
	 *
	 * @return array Array of [ post_id => post_title ].
	 */
	public function get_noindex_posts() {
		global $wpdb;

		// Check for common noindex post meta flags (like _aioseo_noindex or generic robots meta).
		$query = "
			SELECT p.ID, p.post_title
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_status = 'publish'
			AND p.post_type IN ('post', 'page')
			AND (
				(pm.meta_key = '_aioseo_noindex' AND pm.meta_value = '1')
				OR (pm.meta_key = '_yoast_wpseo_meta-robots-noindex' AND pm.meta_value = '1')
				OR (pm.meta_key = 'robotsmeta' AND pm.meta_value LIKE '%noindex%')
			)
			GROUP BY p.ID
		";

		$results = $wpdb->get_results( $query );
		$noindex_posts = [];

		if ( $results ) {
			foreach ( $results as $row ) {
				$noindex_posts[ $row->ID ] = $row->post_title;
			}
		}

		return $noindex_posts;
	}
}
