<?php
/**
 * Schema Output Handler
 *
 * @package AISearchEngines
 */

namespace AISearchEngines;

defined( 'ABSPATH' ) || exit;

class Schema_Output {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_head', [ $this, 'output_schema' ], 5 );
	}

	/**
	 * Output schema structured data in wp_head.
	 */
	public function output_schema() {
		// Only output if schema is enabled.
		if ( '1' !== get_option( 'aise_schema_enabled', '0' ) ) {
			return;
		}

		$schema_types = apply_filters( 'aise_schema_types', [
			'Organization'   => true,
			'WebSite'        => true,
			'Article'        => true,
			'BreadcrumbList' => true,
			'FAQPage'        => true,
			'HowTo'          => true,
		] );

		if ( ! empty( $schema_types['Organization'] ) ) {
			$this->output_organization_schema();
		}

		if ( ! empty( $schema_types['WebSite'] ) ) {
			$this->output_website_schema();
		}

		if ( is_singular() ) {
			if ( ! empty( $schema_types['Article'] ) ) {
				$this->output_article_schema();
			}

			if ( ! empty( $schema_types['BreadcrumbList'] ) ) {
				$this->output_breadcrumb_schema();
			}

			if ( ! empty( $schema_types['FAQPage'] ) ) {
				$this->output_faq_schema();
			}

			if ( ! empty( $schema_types['HowTo'] ) ) {
				$this->output_howto_schema();
			}
		}
	}

	/**
	 * Print JSON-LD script tag.
	 *
	 * @param array $data Schema data array.
	 */
	private function print_json_ld( $data ) {
		if ( empty( $data ) ) {
			return;
		}
		
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		if ( $json ) {
			echo '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>' . "\n";
		}
	}

	/**
	 * Output Organization Schema.
	 */
	private function output_organization_schema() {
		$org_name = get_option( 'aise_organization_name', '' );
		$org_logo = get_option( 'aise_organization_logo', '' );
		$same_as  = get_option( 'aise_same_as_links', [] );

		// Auto-fill: organization name from site name.
		if ( empty( $org_name ) ) {
			$org_name = get_bloginfo( 'name' );
		}

		// Auto-fill: logo from custom_logo → site_icon.
		if ( empty( $org_logo ) ) {
			$custom_logo_id = get_theme_mod( 'custom_logo' );
			if ( $custom_logo_id ) {
				$org_logo = wp_get_attachment_image_url( $custom_logo_id, 'full' );
			}
		}
		if ( empty( $org_logo ) ) {
			$site_icon_id = get_option( 'site_icon' );
			if ( $site_icon_id ) {
				$org_logo = wp_get_attachment_image_url( $site_icon_id, 'full' );
			}
		}

		$schema = [
			'@context' => 'https://schema.org',
			'@type'    => 'Organization',
			'name'     => $org_name,
			'url'      => home_url( '/' ),
		];

		if ( ! empty( $org_logo ) ) {
			$schema['logo'] = esc_url( $org_logo );
		}

		if ( ! empty( $same_as ) && is_array( $same_as ) ) {
			// Clean up empty URLs.
			$valid_same_as = array_filter( array_map( 'esc_url', $same_as ) );
			if ( ! empty( $valid_same_as ) ) {
				$schema['sameAs'] = array_values( $valid_same_as );
			}
		}

		$this->print_json_ld( $schema );
	}

	/**
	 * Output WebSite Schema.
	 */
	private function output_website_schema() {
		$schema = [
			'@context'        => 'https://schema.org',
			'@type'           => 'WebSite',
			'name'            => get_bloginfo( 'name' ),
			'url'             => home_url( '/' ),
			'potentialAction' => [
				'@type'       => 'SearchAction',
				'target'      => home_url( '/?s={search_term_string}' ),
				'query-input' => 'required name=search_term_string',
			],
		];

		$this->print_json_ld( $schema );
	}

	/**
	 * Output Article/BlogPosting Schema.
	 */
	private function output_article_schema() {
		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$type = ( 'post' === $post->post_type ) ? 'BlogPosting' : 'Article';
		$author_name = get_the_author_meta( 'display_name', $post->post_author );

		$org_name = get_option( 'aise_organization_name', get_bloginfo( 'name' ) );
		$org_logo = get_option( 'aise_organization_logo', '' );

		$publisher = [
			'@type' => 'Organization',
			'name'  => $org_name,
		];

		if ( ! empty( $org_logo ) ) {
			$publisher['logo'] = [
				'@type' => 'ImageObject',
				'url'   => esc_url( $org_logo ),
			];
		}

		$word_count = str_word_count( wp_strip_all_tags( $post->post_content ) );

		$schema = [
			'@context'         => 'https://schema.org',
			'@type'            => $type,
			'headline'         => get_the_title( $post ),
			'author'           => [
				'@type' => 'Person',
				'name'  => $author_name,
			],
			'datePublished'    => get_the_date( 'c', $post ),
			'dateModified'     => get_the_modified_date( 'c', $post ),
			'publisher'        => $publisher,
			'mainEntityOfPage' => get_permalink( $post ),
			'wordCount'        => $word_count,
		];

		if ( has_post_thumbnail( $post ) ) {
			$image_url = get_the_post_thumbnail_url( $post, 'full' );
			if ( $image_url ) {
				$schema['image'] = esc_url( $image_url );
			}
		}

		$ai_summary = get_post_meta( $post->ID, '_aise_ai_summary', true );
		if ( ! empty( $ai_summary ) ) {
			$schema['description'] = esc_html( $ai_summary );
			$schema['abstract']    = esc_html( $ai_summary );
		}

		$this->print_json_ld( $schema );
	}

	/**
	 * Output BreadcrumbList Schema.
	 */
	private function output_breadcrumb_schema() {
		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$schema = [
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => [],
		];

		// Position 1: Home
		$schema['itemListElement'][] = [
			'@type'    => 'ListItem',
			'position' => 1,
			'name'     => __( 'Home', 'ai-search-engines' ),
			'item'     => home_url( '/' ),
		];

		$position = 2;

		if ( 'post' === $post->post_type ) {
			$categories = get_the_category( $post->ID );
			if ( ! empty( $categories ) ) {
				$category = $categories[0];
				$schema['itemListElement'][] = [
					'@type'    => 'ListItem',
					'position' => $position,
					'name'     => $category->name,
					'item'     => get_category_link( $category->term_id ),
				];
				$position++;
			}
		} elseif ( 'page' === $post->post_type && $post->post_parent ) {
			$parent_id = $post->post_parent;
			$parents = [];
			while ( $parent_id ) {
				$parent_page = get_post( $parent_id );
				if ( $parent_page ) {
					$parents[] = $parent_page;
					$parent_id = $parent_page->post_parent;
				} else {
					$parent_id = 0;
				}
			}
			$parents = array_reverse( $parents );
			
			foreach ( $parents as $parent ) {
				$schema['itemListElement'][] = [
					'@type'    => 'ListItem',
					'position' => $position,
					'name'     => get_the_title( $parent ),
					'item'     => get_permalink( $parent ),
				];
				$position++;
			}
		}

		// Current Page
		$schema['itemListElement'][] = [
			'@type'    => 'ListItem',
			'position' => $position,
			'name'     => get_the_title( $post ),
		];

		$this->print_json_ld( $schema );
	}

	/**
	 * Output FAQPage Schema.
	 */
	private function output_faq_schema() {
		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$faq_sections = get_post_meta( $post->ID, '_aise_faq_sections', true );
		$ai_faq       = get_post_meta( $post->ID, '_aise_ai_faq', true );
		$questions_answers = [];

		if ( ! empty( $ai_faq ) && is_array( $ai_faq ) ) {
			$questions_answers = array_merge( $questions_answers, $ai_faq );
		}

		if ( ! empty( $faq_sections ) && is_array( $faq_sections ) ) {
			$questions_answers = array_merge( $questions_answers, $faq_sections );
		} else {
			// Auto-detect from content.
			$content = $post->post_content;
			// Matches H2 or H3 ending with a question mark, followed by paragraphs.
			$pattern = '/<h[23][^>]*>(.*?\?)<\/h[23]>\s*(<p>.*?<\/p>)+/is';
			if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$question = wp_strip_all_tags( $match[1] );
					// Extract paragraphs until next heading.
					// A simpler approximation: just grab the <p> tags immediately following.
					// $match[0] contains the heading and the <p> tags caught by the regex.
					$answer_html = preg_replace( '/<h[23][^>]*>.*?<\/h[23]>/is', '', $match[0] );
					$answer = wp_strip_all_tags( $answer_html );
					
					if ( ! empty( $question ) && ! empty( $answer ) ) {
						$questions_answers[] = [
							'question' => trim( $question ),
							'answer'   => trim( $answer ),
						];
					}
				}
			}
		}

		if ( empty( $questions_answers ) ) {
			return;
		}

		$schema = [
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => [],
		];

		foreach ( $questions_answers as $qa ) {
			if ( ! empty( $qa['question'] ) && ! empty( $qa['answer'] ) ) {
				$schema['mainEntity'][] = [
					'@type'          => 'Question',
					'name'           => esc_html( $qa['question'] ),
					'acceptedAnswer' => [
						'@type' => 'Answer',
						'text'  => esc_html( $qa['answer'] ),
					],
				];
			}
		}

		if ( ! empty( $schema['mainEntity'] ) ) {
			$this->print_json_ld( $schema );
		}
	}

	/**
	 * Output HowTo Schema.
	 */
	private function output_howto_schema() {
		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$howto_enabled = get_post_meta( $post->ID, '_aise_howto_enabled', true );
		if ( '1' !== $howto_enabled ) {
			return;
		}

		$content = $post->post_content;
		$steps = [];

		// Extract ordered lists.
		if ( preg_match( '/<ol[^>]*>(.*?)<\/ol>/is', $content, $ol_match ) ) {
			$li_content = $ol_match[1];
			if ( preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $li_content, $li_matches ) ) {
				foreach ( $li_matches[1] as $index => $li_html ) {
					$step_text = trim( wp_strip_all_tags( $li_html ) );
					if ( ! empty( $step_text ) ) {
						$steps[] = [
							'@type' => 'HowToStep',
							'text'  => $step_text,
						];
					}
				}
			}
		}

		if ( empty( $steps ) ) {
			return;
		}

		$schema = [
			'@context' => 'https://schema.org',
			'@type'    => 'HowTo',
			'name'     => get_the_title( $post ),
			'step'     => $steps,
		];

		$this->print_json_ld( $schema );
	}
}
