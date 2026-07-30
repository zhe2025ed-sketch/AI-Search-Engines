<?php
/**
 * Meta Optimizer Handler
 *
 * @package AISearchEngines
 */

namespace AISearchEngines;

defined( 'ABSPATH' ) || exit;

class Meta_Optimizer {

	/**
	 * Flag to check if another plugin is handling Open Graph.
	 *
	 * @var bool
	 */
	private $has_og_plugin = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_head', [ $this, 'output_meta' ], 3 );
	}

	/**
	 * Output meta tags.
	 */
	public function output_meta() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$this->check_og_plugins();

		$this->output_meta_description( $post );
		$this->output_open_graph_tags( $post );
	}

	/**
	 * Check if known SEO plugins are active.
	 */
	private function check_og_plugins() {
		// Simple check for Yoast or AIOSEO classes/constants.
		if ( defined( 'WPSEO_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
			$this->has_og_plugin = true;
		}
	}

	/**
	 * Output Meta Description if not present.
	 *
	 * @param \WP_Post $post The current post.
	 */
	private function output_meta_description( $post ) {
		// If another SEO plugin is active, assume it handles the description.
		if ( $this->has_og_plugin ) {
			return;
		}

		$description = '';

		if ( ! empty( $post->post_excerpt ) ) {
			$description = wp_strip_all_tags( $post->post_excerpt );
		} else {
			$content = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
			// Get first 160 characters.
			if ( mb_strlen( $content ) > 160 ) {
				$description = mb_substr( $content, 0, 157 ) . '...';
			} else {
				$description = $content;
			}
		}

		$description = trim( preg_replace( '/\s+/', ' ', $description ) );

		if ( ! empty( $description ) ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
		}
	}

	/**
	 * Output Open Graph Tags.
	 *
	 * @param \WP_Post $post The current post.
	 */
	private function output_open_graph_tags( $post ) {
		$should_output = apply_filters( 'aise_output_og_tags', ! $this->has_og_plugin );

		if ( ! $should_output ) {
			return;
		}

		$og_tags = [
			'og:title'       => get_the_title( $post ),
			'og:type'        => 'article',
			'og:url'         => get_permalink( $post ),
			'og:site_name'   => get_bloginfo( 'name' ),
		];

		// Description.
		$description = '';
		if ( ! empty( $post->post_excerpt ) ) {
			$description = wp_strip_all_tags( $post->post_excerpt );
		} else {
			$content = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
			if ( mb_strlen( $content ) > 160 ) {
				$description = mb_substr( $content, 0, 157 ) . '...';
			} else {
				$description = $content;
			}
		}
		$description = trim( preg_replace( '/\s+/', ' ', $description ) );
		if ( ! empty( $description ) ) {
			$og_tags['og:description'] = $description;
		}

		// Image.
		if ( has_post_thumbnail( $post ) ) {
			$image_url = get_the_post_thumbnail_url( $post, 'full' );
			if ( $image_url ) {
				$og_tags['og:image'] = esc_url( $image_url );
			}
		}

		foreach ( $og_tags as $property => $content ) {
			echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( $content ) . '" />' . "\n";
		}
	}
}
