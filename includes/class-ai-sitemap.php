<?php
/**
 * Generates an AI-optimized sitemap at /ai-sitemap.xml.
 */

namespace AISearchEngines;

defined( 'ABSPATH' ) || exit;

class Ai_Sitemap {

	public function __construct() {
		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'serve_sitemap' ] );
		add_action( 'transition_post_status', [ $this, 'invalidate_cache' ], 10, 3 );
	}

	public function add_rewrite_rules() {
		add_rewrite_rule( '^ai-sitemap\.xml$', 'index.php?aise_ai_sitemap=1', 'top' );
	}

	public function add_query_vars( $vars ) {
		$vars[] = 'aise_ai_sitemap';
		return $vars;
	}

	public function serve_sitemap() {
		if ( ! get_query_var( 'aise_ai_sitemap' ) ) {
			return;
		}

		$enabled = get_option( 'aise_ai_sitemap_enabled', '0' );
		if ( '1' !== $enabled ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}

		header( 'Content-Type: application/xml; charset=utf-8' );
		echo $this->generate_sitemap();
		exit;
	}

	private function generate_sitemap() {
		$cache = get_transient( 'aise_sitemap_cache' );
		if ( false !== $cache ) {
			return $cache;
		}

		$post_types = get_option( 'aise_post_types', [ 'post', 'page' ] );

		$posts = get_posts( [
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		] );

		$sitemap_items = [];
		$now           = current_time( 'timestamp' );

		foreach ( $posts as $post ) {
			$audit_score = (int) get_post_meta( $post->ID, '_aise_audit_score', true );
			
			// Priority calculation
			$priority = 0.5;
			if ( $audit_score > 80 ) {
				$priority = 0.9;
			} elseif ( $audit_score >= 60 ) {
				$priority = 0.7;
			} elseif ( $audit_score >= 40 ) {
				$priority = 0.5;
			} elseif ( $audit_score > 0 ) {
				$priority = 0.3;
			}

			// Homepage check
			if ( $post->ID == get_option( 'page_on_front' ) ) {
				$priority = 1.0;
			} elseif ( 'page' === $post->post_type ) {
				$priority = min( 1.0, $priority + 0.1 );
			}

			// Changefreq
			$modified_time = get_post_modified_time( 'U', false, $post, true );
			$diff_days     = ( $now - $modified_time ) / DAY_IN_SECONDS;
			if ( $diff_days <= 7 ) {
				$changefreq = 'daily';
			} elseif ( $diff_days <= 30 ) {
				$changefreq = 'weekly';
			} else {
				$changefreq = 'monthly';
			}

			$sitemap_items[] = [
				'loc'         => get_permalink( $post->ID ),
				'lastmod'     => get_post_modified_time( 'c', false, $post, true ),
				'changefreq'  => $changefreq,
				'priority'    => number_format( $priority, 1 ),
				'audit_score' => $audit_score,
				'date'        => get_post_time( 'U', false, $post, true ),
			];
		}

		// Sort by audit score descending, then date descending
		usort( $sitemap_items, function ( $a, $b ) {
			if ( $a['audit_score'] === $b['audit_score'] ) {
				return $b['date'] <=> $a['date'];
			}
			return $b['audit_score'] <=> $a['audit_score'];
		} );

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $sitemap_items as $item ) {
			$xml .= "  <url>\n";
			$xml .= "    <loc>" . esc_url( $item['loc'] ) . "</loc>\n";
			$xml .= "    <lastmod>" . esc_html( $item['lastmod'] ) . "</lastmod>\n";
			$xml .= "    <changefreq>" . esc_html( $item['changefreq'] ) . "</changefreq>\n";
			$xml .= "    <priority>" . esc_html( $item['priority'] ) . "</priority>\n";
			$xml .= "  </url>\n";
		}

		$xml .= '</urlset>';

		set_transient( 'aise_sitemap_cache', $xml, 6 * HOUR_IN_SECONDS );

		return $xml;
	}

	public function invalidate_cache( $new_status, $old_status, $post ) {
		$post_types = get_option( 'aise_post_types', [ 'post', 'page' ] );
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}

		if ( 'publish' === $new_status || 'publish' === $old_status ) {
			delete_transient( 'aise_sitemap_cache' );
		}
	}

	public function regenerate() {
		delete_transient( 'aise_sitemap_cache' );
		$this->generate_sitemap();
	}
}
