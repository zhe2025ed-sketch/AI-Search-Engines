<?php
namespace AISearchEngines;

defined( 'ABSPATH' ) || exit;

class Post_Meta_Box {

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
		add_action( 'save_post', [ $this, 'save_meta' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function register_meta_box() {
		$post_types = get_option( 'aise_post_types', [ 'post', 'page' ] );
		
		if ( ! is_array( $post_types ) || empty( $post_types ) ) {
			$post_types = [ 'post', 'page' ];
		}

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'aise-audit-box',
				__( 'AI Search Readiness', 'ai-search-engines' ),
				[ $this, 'render_meta_box' ],
				$post_type,
				'side',
				'high'
			);
		}
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'aise_meta_box', 'aise_meta_nonce' );

		$score = get_post_meta( $post->ID, '_aise_audit_score', true );
		$details = get_post_meta( $post->ID, '_aise_audit_details', true );
		$faq_enabled = get_post_meta( $post->ID, '_aise_faq_sections', true );
		$howto_enabled = get_post_meta( $post->ID, '_aise_howto_enabled', true );

		echo '<div class="aise-meta-box-wrapper">';
		
		if ( '' === $score || false === $score ) {
			echo '<p style="color: #666;">' . esc_html__( 'Not yet audited.', 'ai-search-engines' ) . '</p>';
			echo '<div style="display: flex; gap: 8px;">';
			echo '<button type="button" id="aise-reaudit-btn" class="button button-secondary aise-run-audit" data-post-id="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Run Audit', 'ai-search-engines' ) . '</button>';
			echo '<button type="button" class="button button-primary aise-auto-optimize-single" data-post-id="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Auto-Optimize', 'ai-search-engines' ) . '</button>';
			echo '</div>';
		} else {
			$score_class = 'red';
			if ( $score >= 70 ) {
				$score_class = 'green';
			} elseif ( $score >= 40 ) {
				$score_class = 'yellow';
			}

			echo '<div class="aise-score-circle ' . esc_attr( $score_class ) . '">';
			echo '<strong>' . esc_html( $score ) . '</strong>';
			echo '</div>';

			if ( is_array( $details ) && ! empty( $details ) ) {
				echo '<ul class="aise-audit-checklist">';
				$check_keys = [
					'intro_answer',
					'heading_structure',
					'lists_tables',
					'word_count',
					'meta_title',
					'meta_description',
					'featured_image',
					'image_alt_text',
					'internal_links',
					'schema_ready'
				];

				foreach ( $check_keys as $key ) {
					if ( isset( $details[ $key ] ) ) {
						$check = $details[ $key ];
						$status = isset( $check['status'] ) ? $check['status'] : 'fail';
						$message = isset( $check['message'] ) ? $check['message'] : '';
						
						$icon = '✗';
						$color = 'color: #d63638;';
						if ( 'pass' === $status ) {
							$icon = '✓';
							$color = 'color: #00a32a;';
						} elseif ( 'warning' === $status ) {
							$icon = '⚠';
							$color = 'color: #dba617;';
						}

						echo '<li>';
						echo '<span style="' . esc_attr( $color ) . ' font-weight: bold; margin-right: 5px;">' . esc_html( $icon ) . '</span>';
						echo '<strong>' . esc_html( str_replace( '_', ' ', ucfirst( $key ) ) ) . ':</strong> ';
						echo esc_html( $message );
						echo '</li>';
					}
				}
				echo '</ul>';
			}

			echo '<div style="margin-top: 10px; display: flex; gap: 8px;">';
			echo '<button type="button" id="aise-reaudit-btn" class="button button-secondary aise-run-audit" data-post-id="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Re-audit', 'ai-search-engines' ) . '</button>';
			echo '<button type="button" class="button button-primary aise-auto-optimize-single" data-post-id="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Auto-Optimize', 'ai-search-engines' ) . '</button>';
			echo '</div>';
		}

		echo '<hr />';
		
		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="aise_faq_schema" value="1" ' . checked( $faq_enabled, '1', false ) . ' />';
		echo ' ' . esc_html__( 'Auto-detect FAQ schema from Q&A headings', 'ai-search-engines' );
		echo '</label>';
		echo '</p>';

		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="aise_howto_schema" value="1" ' . checked( $howto_enabled, '1', false ) . ' />';
		echo ' ' . esc_html__( 'Enable HowTo schema for this post', 'ai-search-engines' );
		echo '</label>';
		echo '</p>';

		echo '</div>'; // .aise-meta-box-wrapper
	}

	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['aise_meta_nonce'] ) || ! wp_verify_nonce( $_POST['aise_meta_nonce'], 'aise_meta_box' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$faq_val = isset( $_POST['aise_faq_schema'] ) ? '1' : '0';
		update_post_meta( $post_id, '_aise_faq_sections', $faq_val );

		$howto_val = isset( $_POST['aise_howto_schema'] ) ? '1' : '0';
		update_post_meta( $post_id, '_aise_howto_enabled', $howto_val );
	}

	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		global $post;
		if ( ! $post ) {
			return;
		}

		$post_types = get_option( 'aise_post_types', [ 'post', 'page' ] );
		if ( ! is_array( $post_types ) || empty( $post_types ) ) {
			$post_types = [ 'post', 'page' ];
		}

		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}

		wp_enqueue_style(
			'aise-meta-box-css',
			AISE_URL . 'assets/css/meta-box.css',
			[],
			AISE_VERSION
		);

		wp_enqueue_script(
			'aise-meta-box-js',
			AISE_URL . 'assets/js/meta-box.js',
			[ 'jquery' ],
			AISE_VERSION,
			true
		);

		wp_localize_script(
			'aise-meta-box-js',
			'aiseMetaBox',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'aise_audit_single' ), // Standard nonce for AJAX
			]
		);
	}
}
