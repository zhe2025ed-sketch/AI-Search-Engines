<?php
/**
 * Generates and serves llms.txt and llms-full.txt files.
 */

namespace AISearchEngines;

defined( 'ABSPATH' ) || exit;

class Llms_Txt {

	public function __construct() {
		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'serve_file' ] );
		add_action( 'transition_post_status', [ $this, 'invalidate_cache' ], 10, 3 );
	}

	public function add_rewrite_rules() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?aise_llms_txt=1', 'top' );
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?aise_llms_full_txt=1', 'top' );
	}

	public function add_query_vars( $vars ) {
		$vars[] = 'aise_llms_txt';
		$vars[] = 'aise_llms_full_txt';
		return $vars;
	}

	public function serve_file() {
		$is_llms = get_query_var( 'aise_llms_txt' );
		$is_llms_full = get_query_var( 'aise_llms_full_txt' );

		if ( ! $is_llms && ! $is_llms_full ) {
			return;
		}

		$enabled = get_option( 'aise_llms_txt_enabled', '0' );
		if ( '1' !== $enabled ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );

		if ( $is_llms ) {
			echo $this->generate_llms_txt();
		} else {
			echo $this->generate_llms_full_txt();
		}
		exit;
	}

	private function generate_llms_txt() {
		$cache = get_transient( 'aise_llms_txt_cache' );
		if ( false !== $cache ) {
			return $cache;
		}

		$site_name = get_bloginfo( 'name' );
		$tagline   = get_bloginfo( 'description' );
		$org_name  = get_option( 'aise_organization_name', $site_name );
		$home_url  = home_url( '/' );

		$content = "# {$site_name}\n\n> {$tagline}\n\n## About\n\n{$org_name} — {$tagline}\n\n## Main Pages\n\n- [Home]({$home_url}): Main website\n";

		$pages = get_posts( [
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		] );

		foreach ( $pages as $page ) {
			$url     = get_permalink( $page->ID );
			$excerpt = ! empty( $page->post_excerpt ) ? $page->post_excerpt : wp_trim_words( $page->post_content, 20 );
			$excerpt = substr( strip_tags( $excerpt ), 0, 100 );
			$content .= "- [{$page->post_title}]({$url}): {$excerpt}\n";
		}

		$content .= "\n## Latest Articles\n\n";

		$posts = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		foreach ( $posts as $post ) {
			$url     = get_permalink( $post->ID );
			$excerpt = ! empty( $post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( $post->post_content, 20 );
			$excerpt = substr( strip_tags( $excerpt ), 0, 100 );
			$content .= "- [{$post->post_title}]({$url}): {$excerpt}\n";
		}

		$content .= "\n## Categories\n\n";

		$categories = get_categories( [
			'hide_empty' => true,
		] );

		foreach ( $categories as $category ) {
			$url     = get_category_link( $category->term_id );
			$desc    = ! empty( $category->description ) ? $category->description : "{$category->count} articles";
			$content .= "- [{$category->name}]({$url}): {$desc}\n";
		}

		set_transient( 'aise_llms_txt_cache', $content, 12 * HOUR_IN_SECONDS );

		return $content;
	}

	private function generate_llms_full_txt() {
		$cache = get_transient( 'aise_llms_full_txt_cache' );
		if ( false !== $cache ) {
			return $cache;
		}

		$site_name = get_bloginfo( 'name' );
		$tagline   = get_bloginfo( 'description' );
		$org_name  = get_option( 'aise_organization_name', $site_name );
		$home_url  = home_url( '/' );

		$content = "# {$site_name}\n\n> {$tagline}\n\n## About\n\n{$org_name} — {$tagline}\n\n";

		$post_types = get_option( 'aise_post_types', [ 'post', 'page' ] );
		
		$posts = get_posts( [
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		foreach ( $posts as $post ) {
			$url = get_permalink( $post->ID );
			$content .= "## [{$post->post_title}]({$url})\n\n";
			$content .= $this->html_to_markdown( $post->post_content ) . "\n\n";
		}

		set_transient( 'aise_llms_full_txt_cache', $content, 12 * HOUR_IN_SECONDS );

		return $content;
	}

	private function html_to_markdown( $html ) {
		// Basic HTML to Markdown
		$html = preg_replace( '/<h1[^>]*>(.*?)<\/h1>/i', "# $1", $html );
		$html = preg_replace( '/<h2[^>]*>(.*?)<\/h2>/i', "## $1", $html );
		$html = preg_replace( '/<h3[^>]*>(.*?)<\/h3>/i', "### $1", $html );
		$html = preg_replace( '/<h4[^>]*>(.*?)<\/h4>/i', "#### $1", $html );
		$html = preg_replace( '/<(strong|b)[^>]*>(.*?)<\/\1>/i', "**$2**", $html );
		$html = preg_replace( '/<(em|i)[^>]*>(.*?)<\/\1>/i', "*$2*", $html );
		$html = preg_replace( '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i', "[$2]($1)", $html );
		$html = preg_replace( '/<li[^>]*>(.*?)<\/li>/i', "- $1", $html );
		$html = preg_replace( '/<br\s*\/?>/i', "\n", $html );
		
		$html = wp_strip_all_tags( $html );
		return trim( $html );
	}

	public function invalidate_cache( $new_status, $old_status, $post ) {
		$post_types = get_option( 'aise_post_types', [ 'post', 'page' ] );
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}

		if ( 'publish' === $new_status || 'publish' === $old_status ) {
			delete_transient( 'aise_llms_txt_cache' );
			delete_transient( 'aise_llms_full_txt_cache' );
		}
	}

	public function regenerate() {
		delete_transient( 'aise_llms_txt_cache' );
		delete_transient( 'aise_llms_full_txt_cache' );
		$this->generate_llms_txt();
		$this->generate_llms_full_txt();
	}
}
