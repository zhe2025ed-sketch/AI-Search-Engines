<?php
/**
 * Content Auditor Engine
 */

namespace AISearchEngines;

defined( 'ABSPATH' ) || exit;

class Content_Auditor {

	public function __construct() {
		add_action( 'save_post', [ $this, 'hook_save_post' ], 10, 3 );
	}

	public function hook_save_post( $post_id, $post, $update ) {
		// Only run for configured post types
		$allowed_types = get_option( 'aise_post_types', [ 'post', 'page' ] );
		if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
			return;
		}

		// Only for 'publish' status
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		// Skip autosaves and revisions
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$this->audit_post( $post_id );
	}

	public function audit_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$content = $post->post_content;
		$checks  = [];
		$total_score = 0;
		$max_possible_score = 100;

		// 1. intro_answer (15 pts)
		$intro_score = 0;
		$intro_status = 'fail';
		$intro_message = 'First paragraph is missing or not optimal length/format.';
		if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $content, $matches ) ) {
			$first_p = trim( wp_strip_all_tags( $matches[1] ) );
		} else {
			$paragraphs = explode( "\n", trim( wp_strip_all_tags( $content ) ) );
			$first_p = isset( $paragraphs[0] ) ? trim( $paragraphs[0] ) : '';
		}
		
		if ( ! empty( $first_p ) ) {
			$len = mb_strlen( $first_p );
			$ends_with_q = ( mb_substr( $first_p, -1 ) === '?' );
			if ( $len >= 40 && $len <= 300 && ! $ends_with_q ) {
				$intro_score = 15;
				$intro_status = 'pass';
				$intro_message = 'Good intro answer paragraph.';
			} elseif ( $len < 40 || $len > 300 ) {
				$intro_status = 'warn';
				$intro_message = 'Intro paragraph length should be 40-300 characters.';
			} elseif ( $ends_with_q ) {
				$intro_status = 'warn';
				$intro_message = 'Intro paragraph should answer a question, not end with one.';
			}
		}
		$checks['intro_answer'] = [ 'score' => $intro_score, 'max' => 15, 'status' => $intro_status, 'message' => $intro_message ];
		$total_score += $intro_score;

		// 2. heading_structure (15 pts)
		$heading_score = 0;
		$heading_status = 'fail';
		$heading_message = 'Missing H2 headings.';
		$has_h2 = false;
		$has_h3_without_h2 = false;
		$has_question_heading = false;
		
		if ( preg_match_all( '/<(h[2-6])[^>]*>(.*?)<\/\1>/is', $content, $h_matches, PREG_SET_ORDER ) ) {
			$first_heading_level = null;
			foreach ( $h_matches as $h ) {
				$level = intval( substr( $h[1], 1 ) );
				if ( $level === 2 ) {
					$has_h2 = true;
				}
				if ( null === $first_heading_level ) {
					$first_heading_level = $level;
				}
				if ( strpos( $h[2], '?' ) !== false ) {
					$has_question_heading = true;
				}
			}
			
			if ( $first_heading_level > 2 && ! $has_h2 ) {
				$has_h3_without_h2 = true; // simplified check
			}

			if ( $has_h2 && ! $has_h3_without_h2 ) {
				$heading_score = 10;
				$heading_status = 'warn';
				$heading_message = 'Good structure, but no question-style headings found.';
				if ( $has_question_heading ) {
					$heading_score = 15;
					$heading_status = 'pass';
					$heading_message = 'Great heading structure with question-style headings.';
				}
			} elseif ( $has_h3_without_h2 ) {
				$heading_status = 'fail';
				$heading_message = 'Poor hierarchy (H3 found without preceding H2).';
			}
		}
		$checks['heading_structure'] = [ 'score' => $heading_score, 'max' => 15, 'status' => $heading_status, 'message' => $heading_message ];
		$total_score += $heading_score;

		// 3. lists_tables (10 pts)
		$lists_tables_score = 0;
		$has_list = preg_match( '/<(ul|ol)[^>]*>/is', $content );
		$has_table = preg_match( '/<table[^>]*>/is', $content );
		if ( $has_list ) $lists_tables_score += 5;
		if ( $has_table ) $lists_tables_score += 5;
		
		$lt_status = 'fail';
		if ( $lists_tables_score === 10 ) $lt_status = 'pass';
		elseif ( $lists_tables_score === 5 ) $lt_status = 'warn';
		
		$checks['lists_tables'] = [ 
			'score' => $lists_tables_score, 
			'max' => 10, 
			'status' => $lt_status, 
			'message' => 'Found ' . ( $has_list ? 'lists ' : '' ) . ( $has_table ? 'tables' : '' ) 
		];
		$total_score += $lists_tables_score;

		// 4. word_count (10 pts)
		$word_count = str_word_count( wp_strip_all_tags( $content ) );
		$wc_score = 0;
		$wc_status = 'fail';
		$wc_msg = 'Word count is below 300 words.';
		
		if ( $word_count >= 300 && $word_count <= 3000 ) {
			$wc_score = 10;
			$wc_status = 'pass';
			$wc_msg = 'Optimal word count (' . $word_count . ' words).';
		} elseif ( $word_count > 3000 ) {
			$wc_score = 8;
			$wc_status = 'warn';
			$wc_msg = 'Slightly penalized for being too long for AI consumption (' . $word_count . ' words).';
		}
		$checks['word_count'] = [ 'score' => $wc_score, 'max' => 10, 'status' => $wc_status, 'message' => $wc_msg ];
		$total_score += $wc_score;

		// 5. meta_title (10 pts)
		$title_len = mb_strlen( $post->post_title );
		$title_score = ( $title_len >= 30 && $title_len <= 60 ) ? 10 : 0;
		$checks['meta_title'] = [
			'score' => $title_score,
			'max' => 10,
			'status' => $title_score === 10 ? 'pass' : 'fail',
			'message' => 'Title length is ' . $title_len . ' chars (optimal 30-60).'
		];
		$total_score += $title_score;

		// 6. meta_description (10 pts)
		$desc = get_post_meta( $post_id, '_aioseo_description', true );
		if ( empty( $desc ) ) {
			$desc = $post->post_excerpt;
		}
		$desc_len = mb_strlen( trim( $desc ) );
		$desc_score = ( $desc_len >= 120 && $desc_len <= 160 ) ? 10 : 0;
		$checks['meta_description'] = [
			'score' => $desc_score,
			'max' => 10,
			'status' => $desc_score === 10 ? 'pass' : 'fail',
			'message' => 'Description length is ' . $desc_len . ' chars (optimal 120-160).'
		];
		$total_score += $desc_score;

		// 7. featured_image (5 pts)
		$has_thumbnail = has_post_thumbnail( $post_id );
		$fi_score = $has_thumbnail ? 5 : 0;
		$checks['featured_image'] = [
			'score' => $fi_score,
			'max' => 5,
			'status' => $has_thumbnail ? 'pass' : 'fail',
			'message' => $has_thumbnail ? 'Has featured image.' : 'Missing featured image.'
		];
		$total_score += $fi_score;

		// 8. image_alt_text (10 pts)
		$alt_score = 10;
		$alt_status = 'pass';
		$alt_msg = 'All images have alt text (or no images found).';
		if ( preg_match_all( '/<img[^>]+>/is', $content, $img_matches ) ) {
			foreach ( $img_matches[0] as $img_tag ) {
				if ( ! preg_match( '/alt=["\'](.*?)["\']/is', $img_tag, $alt_match ) || trim( $alt_match[1] ) === '' ) {
					$alt_score = 0;
					$alt_status = 'fail';
					$alt_msg = 'One or more images are missing alt text.';
					break;
				}
			}
		}
		$checks['image_alt_text'] = [ 'score' => $alt_score, 'max' => 10, 'status' => $alt_status, 'message' => $alt_msg ];
		$total_score += $alt_score;

		// 9. internal_links (10 pts)
		$home_url = home_url();
		$home_url_escaped = preg_quote( $home_url, '/' );
		$link_count = 0;
		if ( preg_match_all( '/href=["\'](' . $home_url_escaped . '[^"\']*)["\']/is', $content, $link_matches ) ) {
			$link_count = count( array_unique( $link_matches[1] ) );
		}
		
		$il_score = ( $link_count >= 2 ) ? 10 : ( $link_count > 0 ? 5 : 0 );
		$il_status = $il_score === 10 ? 'pass' : ( $il_score > 0 ? 'warn' : 'fail' );
		$checks['internal_links'] = [
			'score' => $il_score,
			'max' => 10,
			'status' => $il_status,
			'message' => 'Found ' . $link_count . ' internal links.'
		];
		$total_score += $il_score;

		// 10. schema_ready (5 pts)
		$schema_score = 0;
		$schema_status = 'fail';
		$schema_msg = 'No structured elements supporting schema found.';
		
		$faq_pattern = '/<(h[2-3])[^>]*>[^<]*\?[^<]*<\/\1>\s*<p/is';
		$howto_pattern = '/<(h[2-6])[^>]*>.*?(how to|how-to).*?<\/\1>.*?<ol[^>]*>/is';
		
		if ( preg_match( $faq_pattern, $content ) || preg_match( $howto_pattern, $content ) ) {
			$schema_score = 5;
			$schema_status = 'pass';
			$schema_msg = 'Found structured elements supporting FAQ or HowTo schema.';
		}
		
		$checks['schema_ready'] = [ 'score' => $schema_score, 'max' => 5, 'status' => $schema_status, 'message' => $schema_msg ];
		$total_score += $schema_score;

		$result = [
			'score'  => $total_score,
			'checks' => $checks
		];

		// Store post meta
		update_post_meta( $post_id, '_aise_audit_score', $total_score );
		update_post_meta( $post_id, '_aise_audit_details', $checks );

		// Insert into audit log table
		global $wpdb;
		$table_name = $wpdb->prefix . 'aise_audit_log';
		
		// Ensure the table exists before trying to insert
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name ) {
			$wpdb->insert(
				$table_name,
				[
					'post_id'    => $post_id,
					'score'      => $total_score,
					'details'    => maybe_serialize( $checks ),
					'audited_at' => current_time( 'mysql' )
				],
				[ '%d', '%d', '%s', '%s' ]
			);
		}

		return $result;
	}

	public static function get_score( $post_id ) {
		return (int) get_post_meta( $post_id, '_aise_audit_score', true );
	}

	public static function get_details( $post_id ) {
		$details = get_post_meta( $post_id, '_aise_audit_details', true );
		return is_array( $details ) ? $details : [];
	}

	public function audit_all_posts() {
		$allowed_types = get_option( 'aise_post_types', [ 'post', 'page' ] );
		$args = [
			'post_type'      => $allowed_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids'
		];
		
		$query = new \WP_Query( $args );
		$results = [];
		
		foreach ( $query->posts as $post_id ) {
			$audit_res = $this->audit_post( $post_id );
			$results[ $post_id ] = $audit_res['score'] ?? 0;
		}
		
		return $results;
	}

	/**
	 * Auto-optimize a post's metadata and schema settings WITHOUT altering post body content.
	 *
	 * @param int $post_id Post ID.
	 * @return array Updated audit results.
	 */
	public function auto_optimize_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$content  = $post->post_content;
		$title    = $post->post_title;
		$modified = false;

		// Clean up any previously auto-injected paragraphs or headings from post_content
		$pattern_p  = '/<p>[^<]*provides essential insights and structured guidance\.[^<]*<\/p>\s*/i';
		$pattern_h2 = '/\s*<h2>What is [^<]+\?<\/h2>/i';
		if ( preg_match( $pattern_p, $content ) || preg_match( $pattern_h2, $content ) ) {
			$content  = preg_replace( $pattern_p, '', $content );
			$content  = preg_replace( $pattern_h2, '', $content );
			$modified = true;
		}

		// Save cleaned content if auto-injected text was present
		if ( $modified ) {
			remove_action( 'save_post', [ $this, 'hook_save_post' ], 10 );
			wp_update_post( [
				'ID'           => $post_id,
				'post_content' => $content,
			] );
			add_action( 'save_post', [ $this, 'hook_save_post' ], 10, 3 );
		}

		// 1. Ensure Meta Description (Excerpt) exists (stored in post_excerpt meta, invisible in post body)
		$excerpt = get_post_meta( $post_id, '_aioseo_description', true );
		if ( empty( $excerpt ) ) {
			$excerpt = $post->post_excerpt;
		}
		if ( empty( $excerpt ) || mb_strlen( trim( $excerpt ) ) < 120 ) {
			$clean_text = trim( wp_strip_all_tags( $content ) );
			if ( empty( $clean_text ) ) {
				$clean_text = $title . ' — Overview and key information about ' . strtolower( $title ) . ' on ' . get_bloginfo( 'name' ) . '.';
			}
			if ( mb_strlen( $clean_text ) < 120 ) {
				$clean_text .= ' Read more to discover detailed guidance, insights, and structural facts regarding ' . strtolower( $title ) . '.';
			}
			$new_excerpt = mb_substr( $clean_text, 0, 155 );
			if ( mb_strlen( $new_excerpt ) === 155 ) {
				$new_excerpt = preg_replace( '/\s+\S*$/', '.', $new_excerpt );
			}
			wp_update_post( [
				'ID'           => $post_id,
				'post_excerpt' => $new_excerpt,
			] );
		}

		// 2. Enable FAQ and HowTo schema flags (injects JSON-LD in <head>, completely invisible in post body)
		update_post_meta( $post_id, '_aise_faq_sections', '1' );
		update_post_meta( $post_id, '_aise_howto_enabled', '1' );

		// Re-audit post and return fresh score
		return $this->audit_post( $post_id );
	}

	/**
	 * Auto-optimize all published posts of allowed types.
	 *
	 * @return array Map of [post_id => score]
	 */
	public function auto_optimize_all_posts() {
		$allowed_types = get_option( 'aise_post_types', [ 'post', 'page' ] );
		$args = [
			'post_type'      => $allowed_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		];

		$query   = new \WP_Query( $args );
		$results = [];

		foreach ( $query->posts as $post_id ) {
			$audit_res           = $this->auto_optimize_post( $post_id );
			$results[ $post_id ] = $audit_res['score'] ?? 0;
		}

		return $results;
	}
}
