<?php
/**
 * Settings API Implementation
 */

namespace AISearchEngines;

defined( 'ABSPATH' ) || exit;

class Settings {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_pages' ], 15 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( AISE_FILE ), [ $this, 'add_plugin_action_links' ] );
	}

	public function add_menu_pages() {
		// Register the submenu page for settings
		add_submenu_page(
			'aise-dashboard',
			__( 'AI Search Settings', 'ai-search-engines' ),
			__( 'Settings', 'ai-search-engines' ),
			'manage_options',
			'aise-settings',
			[ $this, 'render_page' ]
		);
	}

	public function add_plugin_action_links( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=aise-settings' ) ) . '">' . esc_html__( 'Settings', 'ai-search-engines' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	public function register_settings() {
		// Section: General
		add_settings_section(
			'aise_general',
			__( 'General', 'ai-search-engines' ),
			'__return_false',
			'aise_settings'
		);

		register_setting( 'aise_settings', 'aise_schema_enabled', [
			'type'              => 'boolean',
			'sanitize_callback' => 'absint',
			'default'           => 1,
		] );
		add_settings_field( 'aise_schema_enabled', __( 'Enable AI Schema', 'ai-search-engines' ), [ $this, 'render_checkbox' ], 'aise_settings', 'aise_general', [ 'label_for' => 'aise_schema_enabled' ] );

		register_setting( 'aise_settings', 'aise_llms_txt_enabled', [
			'type'              => 'boolean',
			'sanitize_callback' => 'absint',
			'default'           => 1,
		] );
		add_settings_field( 'aise_llms_txt_enabled', __( 'Enable llms.txt', 'ai-search-engines' ), [ $this, 'render_checkbox' ], 'aise_settings', 'aise_general', [ 'label_for' => 'aise_llms_txt_enabled' ] );

		register_setting( 'aise_settings', 'aise_ai_sitemap_enabled', [
			'type'              => 'boolean',
			'sanitize_callback' => 'absint',
			'default'           => 1,
		] );
		add_settings_field( 'aise_ai_sitemap_enabled', __( 'Enable AI Sitemap', 'ai-search-engines' ), [ $this, 'render_checkbox' ], 'aise_settings', 'aise_general', [ 'label_for' => 'aise_ai_sitemap_enabled' ] );

		register_setting( 'aise_settings', 'aise_post_types', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_post_types' ],
			'default'           => [ 'post', 'page' ],
		] );
		add_settings_field( 'aise_post_types', __( 'Enabled Post Types', 'ai-search-engines' ), [ $this, 'render_post_types' ], 'aise_settings', 'aise_general' );

		// Section: Organization Schema
		add_settings_section(
			'aise_organization',
			__( 'Organization Schema', 'ai-search-engines' ),
			[ $this, 'render_organization_description' ],
			'aise_settings'
		);

		register_setting( 'aise_settings', 'aise_organization_name', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => get_bloginfo( 'name' ),
		] );
		add_settings_field( 'aise_organization_name', __( 'Organization Name', 'ai-search-engines' ), [ $this, 'render_text_input' ], 'aise_settings', 'aise_organization', [ 'label_for' => 'aise_organization_name' ] );

		register_setting( 'aise_settings', 'aise_organization_logo', [
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		] );
		add_settings_field( 'aise_organization_logo', __( 'Organization Logo URL', 'ai-search-engines' ), [ $this, 'render_logo_input' ], 'aise_settings', 'aise_organization', [ 'label_for' => 'aise_organization_logo' ] );

		register_setting( 'aise_settings', 'aise_same_as_links', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_same_as_links' ],
			'default'           => [],
		] );
		add_settings_field( 'aise_same_as_links', __( 'Same As Links', 'ai-search-engines' ), [ $this, 'render_textarea' ], 'aise_settings', 'aise_organization', [
			'label_for'   => 'aise_same_as_links',
			'description' => __( 'One URL per line (social profiles, Wikidata, etc.).', 'ai-search-engines' ),
		] );

		// Section: AI Crawler Directives
		add_settings_section(
			'aise_crawlers',
			__( 'AI Crawler Directives', 'ai-search-engines' ),
			'__return_false',
			'aise_settings'
		);

		register_setting( 'aise_settings', 'aise_crawler_rules', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_crawler_rules' ],
			'default'           => [],
		] );
		add_settings_field( 'aise_crawler_rules', __( 'Crawler Rules', 'ai-search-engines' ), [ $this, 'render_crawler_rules' ], 'aise_settings', 'aise_crawlers' );

		// Section: API Settings
		add_settings_section(
			'aise_api',
			__( 'API Settings (Optional)', 'ai-search-engines' ),
			[ $this, 'render_api_description' ],
			'aise_settings'
		);

		register_setting( 'aise_settings', 'aise_api_provider', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );
		add_settings_field( 'aise_api_provider', __( 'API Provider', 'ai-search-engines' ), [ $this, 'render_api_provider' ], 'aise_settings', 'aise_api', [ 'label_for' => 'aise_api_provider' ] );

		register_setting( 'aise_settings', 'aise_api_key', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );
		add_settings_field( 'aise_api_key', __( 'API Key', 'ai-search-engines' ), [ $this, 'render_api_key' ], 'aise_settings', 'aise_api', [ 'label_for' => 'aise_api_key' ] );
	}

	public function render_organization_description() {
		echo '<p>' . esc_html__( 'Auto-filled from your WordPress site info. Override any field below, or leave blank to use the detected value.', 'ai-search-engines' ) . '</p>';
	}

	public function render_api_description() {
		echo '<p>' . esc_html__( 'Optional. Connect an AI API for content rewriting suggestions. The plugin works fully without this.', 'ai-search-engines' ) . '</p>';
	}

	public function render_checkbox( $args ) {
		$name  = $args['label_for'];
		$value = get_option( $name, 1 );
		echo '<input type="checkbox" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( 1, $value, false ) . ' />';
	}

	public function render_post_types() {
		$types    = get_post_types( [ 'public' => true ], 'objects' );
		$selected = get_option( 'aise_post_types', [ 'post', 'page' ] );

		foreach ( $types as $type ) {
			$checked = in_array( $type->name, $selected, true ) ? 'checked' : '';
			echo '<label style="display:block;">';
			echo '<input type="checkbox" name="aise_post_types[]" value="' . esc_attr( $type->name ) . '" ' . $checked . '> ';
			echo esc_html( $type->label );
			echo '</label>';
		}
	}

	public function sanitize_post_types( $input ) {
		if ( ! is_array( $input ) ) {
			return [];
		}
		$valid_types = get_post_types( [ 'public' => true ] );
		return array_intersect( $input, $valid_types );
	}

	public function render_text_input( $args ) {
		$name        = $args['label_for'];
		$value       = get_option( $name, '' );
		$placeholder = '';

		// Show auto-detected placeholder for organization name.
		if ( 'aise_organization_name' === $name && empty( $value ) ) {
			$placeholder = get_bloginfo( 'name' );
		}

		echo '<input type="text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" class="regular-text" />';
		if ( ! empty( $placeholder ) && empty( $value ) ) {
			echo '<p class="description">' . esc_html__( 'Auto-detected from Site Title.', 'ai-search-engines' ) . '</p>';
		}
	}

	public function render_logo_input( $args ) {
		$name  = $args['label_for'];
		$value = get_option( $name, '' );

		// Auto-detect logo for placeholder.
		$auto_logo = $this->get_auto_logo_url();

		echo '<input type="url" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_url( $value ) . '" placeholder="' . esc_attr( $auto_logo ) . '" class="regular-text" />';
		if ( ! empty( $auto_logo ) ) {
			$source = get_theme_mod( 'custom_logo' ) ? __( 'Custom Logo', 'ai-search-engines' ) : __( 'Site Icon', 'ai-search-engines' );
			echo '<p class="description">' . sprintf(
				/* translators: %s: source of auto-detected logo */
				esc_html__( 'Auto-detected from %s. Leave blank to use it.', 'ai-search-engines' ),
				esc_html( $source )
			) . '</p>';
		}
	}

	/**
	 * Get auto-detected logo URL from custom_logo or site_icon.
	 */
	private function get_auto_logo_url() {
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id ) {
			$url = wp_get_attachment_image_url( $custom_logo_id, 'full' );
			if ( $url ) {
				return $url;
			}
		}
		$site_icon_id = get_option( 'site_icon' );
		if ( $site_icon_id ) {
			$url = wp_get_attachment_image_url( $site_icon_id, 'full' );
			if ( $url ) {
				return $url;
			}
		}
		return '';
	}

	public function render_textarea( $args ) {
		$name  = $args['label_for'];
		$value = get_option( $name, [] );
		if ( ! is_array( $value ) ) {
			$value = [];
		}
		echo '<textarea id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" rows="5" class="large-text code">' . esc_textarea( implode( "\n", $value ) ) . '</textarea>';
		if ( isset( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	public function sanitize_same_as_links( $input ) {
		if ( empty( $input ) ) {
			return [];
		}
		$lines = explode( "\n", $input );
		$urls  = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( ! empty( $line ) && filter_var( $line, FILTER_VALIDATE_URL ) ) {
				$urls[] = esc_url_raw( $line );
			}
		}
		return $urls;
	}

	public function render_crawler_rules() {
		$bots = [ 'GPTBot', 'ChatGPT-User', 'ClaudeBot', 'PerplexityBot', 'Google-Extended', 'Bytespider', 'CCBot' ];
		$rules = get_option( 'aise_crawler_rules', [] );

		echo '<table class="widefat striped" style="max-width:400px;">';
		echo '<thead><tr><th>' . esc_html__( 'Bot Name', 'ai-search-engines' ) . '</th><th>' . esc_html__( 'Allow / Disallow', 'ai-search-engines' ) . '</th></tr></thead>';
		echo '<tbody>';
		foreach ( $bots as $bot ) {
			$val = isset( $rules[ $bot ] ) ? $rules[ $bot ] : 'allow';
			echo '<tr>';
			echo '<td>' . esc_html( $bot ) . '</td>';
			echo '<td>';
			echo '<select name="aise_crawler_rules[' . esc_attr( $bot ) . ']">';
			echo '<option value="allow" ' . selected( $val, 'allow', false ) . '>' . esc_html__( 'Allow', 'ai-search-engines' ) . '</option>';
			echo '<option value="disallow" ' . selected( $val, 'disallow', false ) . '>' . esc_html__( 'Disallow', 'ai-search-engines' ) . '</option>';
			echo '</select>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	public function sanitize_crawler_rules( $input ) {
		if ( ! is_array( $input ) ) {
			return [];
		}
		$bots = [ 'GPTBot', 'ChatGPT-User', 'ClaudeBot', 'PerplexityBot', 'Google-Extended', 'Bytespider', 'CCBot' ];
		$sanitized = [];
		foreach ( $bots as $bot ) {
			if ( isset( $input[ $bot ] ) && in_array( $input[ $bot ], [ 'allow', 'disallow' ], true ) ) {
				$sanitized[ $bot ] = $input[ $bot ];
			} else {
				$sanitized[ $bot ] = 'allow';
			}
		}
		return $sanitized;
	}

	public function render_api_provider() {
		$value = get_option( 'aise_api_provider', '' );
		echo '<select name="aise_api_provider" id="aise_api_provider">';
		echo '<option value="" ' . selected( $value, '', false ) . '>' . esc_html__( 'None', 'ai-search-engines' ) . '</option>';
		echo '<option value="openai" ' . selected( $value, 'openai', false ) . '>' . esc_html__( 'OpenAI', 'ai-search-engines' ) . '</option>';
		echo '<option value="gemini" ' . selected( $value, 'gemini', false ) . '>' . esc_html__( 'Google Gemini', 'ai-search-engines' ) . '</option>';
		echo '</select>';
	}

	public function render_api_key( $args ) {
		$name  = $args['label_for'];
		$value = get_option( $name, '' );
		echo '<input type="password" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI Search Settings', 'ai-search-engines' ) . '</h1>';
		echo '<form action="options.php" method="post">';
		settings_fields( 'aise_settings' );
		do_settings_sections( 'aise_settings' );
		submit_button();
		echo '</form>';
		echo '</div>';
	}
}
