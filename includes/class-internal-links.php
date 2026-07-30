<?php
namespace AISearchEngines;

defined( 'ABSPATH' ) || exit;

class Internal_Links {

	public function __construct() {
		add_action( 'save_post', [ $this, 'update_link_data' ], 20 );
	}

	public function analyze_links( $post_id = null ) {
		$post_types = get_option( 'aise_post_types', [ 'post', 'page' ] );
		
		if ( ! is_array( $post_types ) || empty( $post_types ) ) {
			$post_types = [ 'post', 'page' ];
		}

		$posts_to_analyze = [];

		if ( $post_id ) {
			$posts_to_analyze[] = get_post( $post_id );
		} else {
			$args = [
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			];
			$query = new \WP_Query( $args );
			$posts_to_analyze = $query->posts;
		}

		$home_url = home_url();
		$home_host = parse_url( $home_url, PHP_URL_HOST );

		foreach ( $posts_to_analyze as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$content = $post->post_content;
			$outbound_links = [];

			if ( preg_match_all( '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$url = $match[1];
					$text = strip_tags( $match[2] );
					
					$type = 'external';
					$url_host = parse_url( $url, PHP_URL_HOST );
					
					// Consider it internal if it's a relative URL or matches the home host
					if ( empty( $url_host ) || $url_host === $home_host ) {
						$type = 'internal';
					}

					$outbound_links[] = [
						'url'  => $url,
						'type' => $type,
						'text' => trim( $text ),
					];
				}
			}

			update_post_meta( $post->ID, '_aise_outbound_links', $outbound_links );
		}
	}

	public function update_link_data( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post_types = get_option( 'aise_post_types', [ 'post', 'page' ] );
		if ( ! is_array( $post_types ) || empty( $post_types ) ) {
			$post_types = [ 'post', 'page' ];
		}

		if ( ! in_array( get_post_type( $post_id ), $post_types, true ) ) {
			return;
		}

		$this->analyze_links( $post_id );

		// Clear transients
		delete_transient( 'aise_orphan_pages' );
		delete_transient( 'aise_site_link_stats' );
	}

	public function get_orphan_pages() {
		$orphan_pages = get_transient( 'aise_orphan_pages' );
		if ( false !== $orphan_pages ) {
			return $orphan_pages;
		}

		$post_types = get_option( 'aise_post_types', [ 'post', 'page' ] );
		if ( ! is_array( $post_types ) || empty( $post_types ) ) {
			$post_types = [ 'post', 'page' ];
		}

		$args = [
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		];
		
		$query = new \WP_Query( $args );
		$all_post_ids = $query->posts;

		// Map to store inbound link counts
		$inbound_counts = array_fill_keys( $all_post_ids, 0 );

		foreach ( $all_post_ids as $post_id ) {
			$outbound_links = get_post_meta( $post_id, '_aise_outbound_links', true );
			if ( is_array( $outbound_links ) ) {
				foreach ( $outbound_links as $link ) {
					if ( 'internal' === $link['type'] ) {
						// Try to match URL to a post ID
						$linked_post_id = url_to_postid( $link['url'] );
						if ( $linked_post_id && isset( $inbound_counts[ $linked_post_id ] ) ) {
							$inbound_counts[ $linked_post_id ]++;
						}
					}
				}
			}
		}

		$orphan_pages = [];
		foreach ( $inbound_counts as $post_id => $count ) {
			if ( 0 === $count ) {
				$orphan_pages[] = $post_id;
			}
		}

		set_transient( 'aise_orphan_pages', $orphan_pages, HOUR_IN_SECONDS );

		return $orphan_pages;
	}

	public function get_weak_pages() {
		$post_types = get_option( 'aise_post_types', [ 'post', 'page' ] );
		if ( ! is_array( $post_types ) || empty( $post_types ) ) {
			$post_types = [ 'post', 'page' ];
		}

		$args = [
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		];
		
		$query = new \WP_Query( $args );
		$all_post_ids = $query->posts;
		$weak_pages = [];

		foreach ( $all_post_ids as $post_id ) {
			$outbound_links = get_post_meta( $post_id, '_aise_outbound_links', true );
			$internal_count = 0;
			if ( is_array( $outbound_links ) ) {
				foreach ( $outbound_links as $link ) {
					if ( 'internal' === $link['type'] ) {
						$internal_count++;
					}
				}
			}
			if ( $internal_count < 2 ) {
				$weak_pages[] = $post_id;
			}
		}

		return $weak_pages;
	}

	public function get_link_suggestions( $post_id ) {
		$post_types = get_option( 'aise_post_types', [ 'post', 'page' ] );
		$post_type = get_post_type( $post_id );

		// Get linked post IDs to exclude them from suggestions
		$outbound_links = get_post_meta( $post_id, '_aise_outbound_links', true );
		$linked_ids = [];
		if ( is_array( $outbound_links ) ) {
			foreach ( $outbound_links as $link ) {
				if ( 'internal' === $link['type'] ) {
					$linked_id = url_to_postid( $link['url'] );
					if ( $linked_id ) {
						$linked_ids[] = $linked_id;
					}
				}
			}
		}

		$suggestions = [];
		
		$taxonomies = get_object_taxonomies( $post_type );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$args = [
					'post_type'      => $post_types,
					'post_status'    => 'publish',
					'posts_per_page' => 10,
					'post__not_in'   => array_merge( [ $post_id ], $linked_ids ),
					'tax_query'      => [
						[
							'taxonomy' => $taxonomy,
							'field'    => 'term_id',
							'terms'    => $terms,
						],
					],
				];
				
				$related_query = new \WP_Query( $args );
				foreach ( $related_query->posts as $related_post ) {
					// Avoid duplicates
					$already_suggested = false;
					foreach ( $suggestions as $s ) {
						if ( $s['post_id'] === $related_post->ID ) {
							$already_suggested = true;
							break;
						}
					}
					
					if ( ! $already_suggested ) {
						$tax_obj = get_taxonomy( $taxonomy );
						$suggestions[] = [
							'post_id' => $related_post->ID,
							'title'   => get_the_title( $related_post->ID ),
							'url'     => get_permalink( $related_post->ID ),
							'reason'  => sprintf( __( 'Shares %s', 'ai-search-engines' ), strtolower( $tax_obj->labels->singular_name ) ),
						];
						
						if ( count( $suggestions ) >= 5 ) {
							break 2; // break both loops
						}
					}
				}
			}
		}

		return $suggestions;
	}

	public function get_site_link_stats() {
		$stats = get_transient( 'aise_site_link_stats' );
		if ( false !== $stats ) {
			return $stats;
		}

		$post_types = get_option( 'aise_post_types', [ 'post', 'page' ] );
		if ( ! is_array( $post_types ) || empty( $post_types ) ) {
			$post_types = [ 'post', 'page' ];
		}

		$args = [
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		];
		
		$query = new \WP_Query( $args );
		$all_post_ids = $query->posts;

		$total_posts = count( $all_post_ids );
		$total_internal = 0;
		$total_external = 0;
		
		$inbound_counts = array_fill_keys( $all_post_ids, 0 );

		foreach ( $all_post_ids as $post_id ) {
			$outbound_links = get_post_meta( $post_id, '_aise_outbound_links', true );
			if ( is_array( $outbound_links ) ) {
				foreach ( $outbound_links as $link ) {
					if ( 'internal' === $link['type'] ) {
						$total_internal++;
						$linked_post_id = url_to_postid( $link['url'] );
						if ( $linked_post_id && isset( $inbound_counts[ $linked_post_id ] ) ) {
							$inbound_counts[ $linked_post_id ]++;
						}
					} else {
						$total_external++;
					}
				}
			}
		}

		$orphan_count = 0;
		foreach ( $inbound_counts as $count ) {
			if ( 0 === $count ) {
				$orphan_count++;
			}
		}

		$avg_internal = $total_posts > 0 ? round( $total_internal / $total_posts, 2 ) : 0;

		$stats = [
			'total_posts'                   => $total_posts,
			'total_internal_links'          => $total_internal,
			'total_external_links'          => $total_external,
			'orphan_count'                  => $orphan_count,
			'avg_internal_links_per_post'   => $avg_internal,
		];

		set_transient( 'aise_site_link_stats', $stats, HOUR_IN_SECONDS );

		return $stats;
	}
}
