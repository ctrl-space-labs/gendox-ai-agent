<?php

// Exit if accessed directly.
if (! defined('ABSPATH')) exit;

/**
 * Class Gendox_AI_Agent_Settings
 *
 * This class contains all of the plugin settings.
 * Here you can configure the whole plugin data.
 *
 * @package		GENDOX
 * @subpackage	Classes/Gendox_AI_Agent_Settings
 * @author		Ctrl+Space Labs
 * @since		1.0.0
 */
class Gendox_AI_Agent_Settings
{

	/**
	 * The plugin name
	 *
	 * @var		string
	 * @since   1.0.0
	 */
	private $plugin_name;

	/**
	 * Our Gendox_AI_Agent_Settings constructor 
	 * to run the plugin logic.
	 *
	 * @since 1.0.0
	 */
	function __construct()
	{
		$this->plugin_name = GENDOX_NAME;

		add_action('admin_menu', array($this, 'add_settings_page'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('wp_ajax_gendox_test_connection', array($this, 'gendox_test_connection'));
		add_action('wp_ajax_gendox_fetch_projects', array($this, 'gendox_fetch_projects'));
		add_action('wp_ajax_gendox_reload_content', array($this, 'gendox_reload_content'));
		add_action('wp_ajax_gendox_save_project_changes', array($this, 'gendox_save_project_changes'));
		add_action('wp_ajax_gendox_fetch_posts', array($this, 'gendox_fetch_posts'));
		add_action('wp_ajax_gendox_fetch_products', array($this, 'gendox_fetch_products'));
		add_action('wp_ajax_gendox_fetch_pages', array($this, 'gendox_fetch_pages'));
		add_action('wp_ajax_gendox_get_project_items', array($this, 'gendox_get_project_items'));
		add_action('wp_ajax_gendox_view_project', array($this, 'gendox_view_project'));
		add_action('wp_ajax_gendox_save_chat_settings', array($this, 'gendox_save_chat_settings'));
		add_action('wp_ajax_gendox_get_chat_settings', array($this, 'gendox_get_chat_settings'));

		// On the option rather than a sanitize_callback, so it covers both settings pages
		// that write the API key.
		//
		// Static callback on purpose: get_organization_id() constructs this class, so an
		// instance callback would register a second copy of the handler on every call - WP
		// keys instance callbacks by object hash, but dedupes static ones.
		add_filter('pre_update_option_gendox_ai_chat_api_key', array(__CLASS__, 'gendox_handle_api_key_change'), 10, 2);
	}

	/**
	 * Capability check shared by every wp_ajax_gendox_* handler.
	 *
	 * The nonce alone only proves the request came from a page we rendered - it says
	 * nothing about who is allowed to act. Every handler must also check this, since the
	 * settings menu (and therefore the nonce) is only ever exposed to users who already
	 * pass this check, but relying on that indirectly would break silently if the menu
	 * capability ever changed.
	 *
	 * @return bool
	 */
	private function current_user_can_manage_gendox()
	{
		return current_user_can('manage_options');
	}

	/**
	 * Keeps the Gendox integration status in sync when the API key changes.
	 *
	 * - Empty key: deactivate the current organization (save always proceeds).
	 * - First key / after clear: activate the new organization.
	 * - Different organization: deactivate the outgoing one, then activate the incoming one.
	 * - Same organization: key may change, but status is already correct — no-op.
	 *
	 * Except when clearing, the key is only saved if the required status call(s) succeed; on
	 * failure the previous state is restored and the old key is kept.
	 *
	 * @param string $new_value Incoming API key.
	 * @param string $old_value Currently stored API key.
	 * @return string The value to store.
	 */
	public static function gendox_handle_api_key_change($new_value, $old_value)
	{
		$new_value = is_string($new_value) ? trim($new_value) : $new_value;

		if ($new_value === $old_value) {
			return $new_value;
		}

		// Clearing the key: deactivate the current integration, but never block the save.
		if (empty($new_value)) {
			if (!empty($old_value)) {
				$old_organization_id = Gendox_AI_Agent_Helpers::get_organization_id($old_value);
				if ($old_organization_id) {
					Gendox_AI_Agent_Helpers::send_integration_status($old_value, $old_organization_id, 'INACTIVE');
				}
			}
			return $new_value;
		}

		$new_organization_id = Gendox_AI_Agent_Helpers::get_organization_id($new_value);
		if (!$new_organization_id) {
			add_settings_error(
				'gendox_ai_chat_api_key',
				'gendox_api_key_invalid',
				__('The API key was not accepted by Gendox. The previous key has been kept.', 'gendox-ai-agent')
			);
			return $old_value;
		}

		$old_organization_id = empty($old_value)
			? null
			: Gendox_AI_Agent_Helpers::get_organization_id($old_value);

		// First key, or re-adding after clear: activate — previously this returned early and
		// left the integration inactive until a plugin deactivate/reactivate.
		if (!$old_organization_id) {
			if (!Gendox_AI_Agent_Helpers::send_integration_status($new_value, $new_organization_id, 'ACTIVE')) {
				add_settings_error(
					'gendox_ai_chat_api_key',
					'gendox_activate_failed',
					__('Could not activate the integration for the new organization. The API key has not been changed.', 'gendox-ai-agent')
				);
				return $old_value;
			}
			return $new_value;
		}

		// Same organization: status is already correct.
		if ($old_organization_id === $new_organization_id) {
			return $new_value;
		}

		if (!Gendox_AI_Agent_Helpers::send_integration_status($old_value, $old_organization_id, 'INACTIVE')) {
			add_settings_error(
				'gendox_ai_chat_api_key',
				'gendox_deactivate_failed',
				__('Could not deactivate the integration for the current organization. The API key has not been changed.', 'gendox-ai-agent')
			);
			return $old_value;
		}

		if (!Gendox_AI_Agent_Helpers::send_integration_status($new_value, $new_organization_id, 'ACTIVE')) {
			// Put the outgoing organization back, so a failed switch leaves nothing deactivated.
			Gendox_AI_Agent_Helpers::send_integration_status($old_value, $old_organization_id, 'ACTIVE');
			add_settings_error(
				'gendox_ai_chat_api_key',
				'gendox_activate_failed',
				__('Could not activate the integration for the new organization. The API key has not been changed.', 'gendox-ai-agent')
			);
			return $old_value;
		}

		return $new_value;
	}

	/**
	 * Add the settings page to the WordPress admin menu
	 *
	 * @since 1.0.0
	 */
	public function add_settings_page()
	{
		add_menu_page(
            __('Gendox AI Chat Settings', 'gendox-ai-agent'),
			__('Gendox AI Chat', 'gendox-ai-agent'),
			'manage_options',
           'gendox-ai-chat-settings',
           array($this, 'settings_page_content'),
           $this->get_menu_icon()
        );
	}

	/**
	 * Menu icon as a base64 data URI.
	 *
	 * WordPress renders a data-URI SVG as the menu item's background image and applies the
	 * same opacity treatment as its own dashicons - 60% when inactive, 100% when the menu is
	 * current - so the mark is a flat white one rather than the brand gradient, which would
	 * neither dim nor read at 20px. Inlining avoids an extra request and works whatever the
	 * plugin directory is named.
	 *
	 * @return string Data URI, or a dashicon name if the file is unreadable.
	 */
	private function get_menu_icon()
	{
		static $icon = null;

		if (null !== $icon) {
			return $icon;
		}

		$path = GENDOX_PLUGIN_DIR . 'core/includes/assets/Gendox-G-logo-letter-white.svg';
		$svg = is_readable($path) ? file_get_contents($path) : '';

		$icon = $svg
			? 'data:image/svg+xml;base64,' . base64_encode($svg)
			: 'dashicons-format-chat';

		return $icon;
	}

	public function chat_script_url_field_callback()
	{
		$chat_script_url = get_option('gendox_chat_script_url', GENDOX_DEFAULT_URL);
		echo '<input type="url" class="form-control" name="gendox_chat_script_url" value="' . esc_url($chat_script_url) . '" placeholder="' . esc_attr(GENDOX_DEFAULT_URL) . '" />';
		echo '<p class="description">' . esc_html__('The URL where the Gendox chat script is hosted.', 'gendox-ai-agent') . '</p>';
	}

	public function api_base_url_field_callback()
	{
		$api_base_url = get_option('gendox_api_base_url', GENDOX_DEFAULT_URL);
		echo '<input type="url" class="form-control" name="gendox_api_base_url" value="' . esc_url($api_base_url) . '" placeholder="' . esc_attr(GENDOX_DEFAULT_URL) . '" />';
		echo '<p class="description">' . esc_html__('The base URL for Gendox API endpoints.', 'gendox-ai-agent') . '</p>';
	}

	public function api_settings_section_callback()
	{
		echo '<p>' . esc_html__('Configure API endpoints and URLs for the Gendox service.', 'gendox-ai-agent') . '</p>';
	}

	public function widget_options_section_callback()
	{
		echo '<p>' . esc_html__('Controls emitted as data-* attributes on the public chat widget script.', 'gendox-ai-agent') . '</p>';
	}

	/**
	 * Register the five widget-option fields on a settings page/section.
	 *
	 * @param string $page    Settings page slug.
	 * @param string $section Section id.
	 * @return void
	 */
	private function add_widget_option_fields($page, $section)
	{
		add_settings_field(
			'gendox_chat_initial_state',
			__('Initial Chat State', 'gendox-ai-agent'),
			array($this, 'chat_initial_state_field_callback'),
			$page,
			$section
		);
		add_settings_field(
			'gendox_local_context_selected_text_enabled',
			__('Selected Text Context', 'gendox-ai-agent'),
			array($this, 'local_context_selected_text_field_callback'),
			$page,
			$section
		);
		add_settings_field(
			'gendox_open_web_page_tool_enabled',
			__('Open Web Page Tool', 'gendox-ai-agent'),
			array($this, 'open_web_page_tool_field_callback'),
			$page,
			$section
		);
		add_settings_field(
			'gendox_local_context_max_responses',
			__('Local Context Max Responses', 'gendox-ai-agent'),
			array($this, 'local_context_max_responses_field_callback'),
			$page,
			$section
		);
		add_settings_field(
			'gendox_local_context_max_wait_ms',
			__('Local Context Max Wait (ms)', 'gendox-ai-agent'),
			array($this, 'local_context_max_wait_ms_field_callback'),
			$page,
			$section
		);
	}

	/**
	 * Register a widget option under the settings group that saves it.
	 *
	 * @param string $option Option name.
	 * @param array  $args   Settings API args including sanitize_callback.
	 * @return void
	 */
	private function register_widget_option($option, $args)
	{
		register_setting('gendox_ai_chat_settings_group', $option, $args);
	}

	/**
	 * Sanitize open/closed initial state.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_chat_initial_state($value)
	{
		$value = is_string($value) ? strtolower(trim($value)) : '';
		return in_array($value, array('open', 'closed'), true) ? $value : 'closed';
	}

	/**
	 * Sanitize a true/false string for data-* attributes.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_true_false($value)
	{
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		$value = is_string($value) ? strtolower(trim($value)) : '';
		return in_array($value, array('1', 'true', 'yes', 'on'), true) ? 'true' : 'false';
	}

	/**
	 * Sanitize a positive integer stored as a string.
	 *
	 * @param mixed $value   Submitted value.
	 * @param int   $default Fallback when empty or invalid.
	 * @return string
	 */
	public function sanitize_positive_int_string($value, $default = 1)
	{
		$int = absint($value);
		if ($int < 1) {
			$int = absint($default);
		}
		return (string) $int;
	}

	public function sanitize_local_context_max_responses($value)
	{
		return $this->sanitize_positive_int_string($value, 1);
	}

	public function sanitize_local_context_max_wait_ms($value)
	{
		return $this->sanitize_positive_int_string($value, 500);
	}

	public function chat_initial_state_field_callback()
	{
		$defaults = Gendox_AI_Agent_Helpers::get_widget_script_defaults();
		$value = get_option('gendox_chat_initial_state', $defaults['gendox_chat_initial_state']);
		echo '<select class="form-control" name="gendox_chat_initial_state">';
		echo '<option value="closed"' . selected($value, 'closed', false) . '>' . esc_html__('Closed', 'gendox-ai-agent') . '</option>';
		echo '<option value="open"' . selected($value, 'open', false) . '>' . esc_html__('Open', 'gendox-ai-agent') . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__('Whether the chat widget starts open or closed on page load.', 'gendox-ai-agent') . '</p>';
	}

	public function local_context_selected_text_field_callback()
	{
		$defaults = Gendox_AI_Agent_Helpers::get_widget_script_defaults();
		$value = get_option('gendox_local_context_selected_text_enabled', $defaults['gendox_local_context_selected_text_enabled']);
		// Hidden field so an unchecked box still posts "false" (Settings API skips missing keys).
		echo '<input type="hidden" name="gendox_local_context_selected_text_enabled" value="false" />';
		echo '<label><input type="checkbox" name="gendox_local_context_selected_text_enabled" value="true"' . checked($value, 'true', false) . ' /> ';
		echo esc_html__('Enable selected-text local context', 'gendox-ai-agent') . '</label>';
		echo '<p class="description">' . esc_html__('Allow the widget to use text the visitor has selected on the page as context.', 'gendox-ai-agent') . '</p>';
	}

	public function open_web_page_tool_field_callback()
	{
		$defaults = Gendox_AI_Agent_Helpers::get_widget_script_defaults();
		$value = get_option('gendox_open_web_page_tool_enabled', $defaults['gendox_open_web_page_tool_enabled']);
		echo '<input type="hidden" name="gendox_open_web_page_tool_enabled" value="false" />';
		echo '<label><input type="checkbox" name="gendox_open_web_page_tool_enabled" value="true"' . checked($value, 'true', false) . ' /> ';
		echo esc_html__('Enable open-web-page tool', 'gendox-ai-agent') . '</label>';
		echo '<p class="description">' . esc_html__('Allow the agent to open web pages as a tool during chat.', 'gendox-ai-agent') . '</p>';
	}

	public function local_context_max_responses_field_callback()
	{
		$defaults = Gendox_AI_Agent_Helpers::get_widget_script_defaults();
		$value = get_option('gendox_local_context_max_responses', $defaults['gendox_local_context_max_responses']);
		echo '<input type="number" class="form-control" name="gendox_local_context_max_responses" value="' . esc_attr($value) . '" min="1" step="1" />';
		echo '<p class="description">' . esc_html__('Maximum number of local-context responses the widget will collect.', 'gendox-ai-agent') . '</p>';
	}

	public function local_context_max_wait_ms_field_callback()
	{
		$defaults = Gendox_AI_Agent_Helpers::get_widget_script_defaults();
		$value = get_option('gendox_local_context_max_wait_ms', $defaults['gendox_local_context_max_wait_ms']);
		echo '<input type="number" class="form-control" name="gendox_local_context_max_wait_ms" value="' . esc_attr($value) . '" min="1" step="1" />';
		echo '<p class="description">' . esc_html__('How long (in milliseconds) to wait for local-context responses.', 'gendox-ai-agent') . '</p>';
	}

	/**
	 * Register settings and fields
	 *
	 * @since 1.0.0
	 */
	public function register_settings()
	{
		// AI Chat Settings section
		register_setting(
			'gendox_ai_chat_settings_group',
			'gendox_ai_chat_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		add_settings_section(
			'gendox_ai_chat_main_section',
			__('AI Chat Settings', 'gendox-ai-agent'),
			null,
			'gendox-ai-chat-settings'
		);
		add_settings_field(
			'gendox_ai_chat_api_key',
			__('API Key', 'gendox-ai-agent'),
			array($this, 'api_key_field_callback'),
			'gendox-ai-chat-settings',
			'gendox_ai_chat_main_section'
		);

		// Projects section. No registered option: the table is driven by AJAX, not by the
		// Settings API, so there is no form to submit.
		add_settings_section(
			'gendox_projects_section',
			'',
			array($this, 'gendox_projects_section'),
			'gendox-ai-chat-wp-settings'
		);

		// API Settings section, rendered on the same page and saved by the same form as the
		// API key above, so the screen has one Save button.
		register_setting('gendox_ai_chat_settings_group', 'gendox_chat_script_url', array('sanitize_callback' => 'esc_url_raw'));
		register_setting('gendox_ai_chat_settings_group', 'gendox_api_base_url', array('sanitize_callback' => 'esc_url_raw'));
		add_settings_section(
			'gendox_api_main_section',
			__('API Settings', 'gendox-ai-agent'),
			array($this, 'api_settings_section_callback'),
			'gendox-ai-chat-settings'
		);
		add_settings_field(
			'gendox_chat_script_url',
			__('Chat Script URL', 'gendox-ai-agent'),
			array($this, 'chat_script_url_field_callback'),
			'gendox-ai-chat-settings',
			'gendox_api_main_section'
		);
		add_settings_field(
			'gendox_api_base_url',
			__('Gendox API Base URL', 'gendox-ai-agent'),
			array($this, 'api_base_url_field_callback'),
			'gendox-ai-chat-settings',
			'gendox_api_main_section'
		);

		// Widget Options: emitted as data-* on the public chat script.
		$this->register_widget_option('gendox_chat_initial_state', array(
			'sanitize_callback' => array($this, 'sanitize_chat_initial_state'),
			'default'           => 'closed',
		));
		$this->register_widget_option('gendox_local_context_selected_text_enabled', array(
			'sanitize_callback' => array($this, 'sanitize_true_false'),
			'default'           => 'true',
		));
		$this->register_widget_option('gendox_open_web_page_tool_enabled', array(
			'sanitize_callback' => array($this, 'sanitize_true_false'),
			'default'           => 'true',
		));
		$this->register_widget_option('gendox_local_context_max_responses', array(
			'sanitize_callback' => array($this, 'sanitize_local_context_max_responses'),
			'default'           => '1',
		));
		$this->register_widget_option('gendox_local_context_max_wait_ms', array(
			'sanitize_callback' => array($this, 'sanitize_local_context_max_wait_ms'),
			'default'           => '500',
		));
		add_settings_section(
			'gendox_widget_options_section',
			__('Widget Options', 'gendox-ai-agent'),
			array($this, 'widget_options_section_callback'),
			'gendox-ai-chat-settings'
		);
		$this->add_widget_option_fields('gendox-ai-chat-settings', 'gendox_widget_options_section');
	}

	/**
	 * Callback function for the API key field (AI Chat Settings)
	 *
	 * @since 1.0.0
	 */
	public function api_key_field_callback()
	{
		$api_key = get_option('gendox_ai_chat_api_key');
		echo '<div id="gendox_api_key_container">';
		echo '<div class="input-group mb-3">';
		// Masked by default so the key is not readable over a shoulder or in a screen share.
		// autocomplete="off" keeps browsers from offering it as a saved password.
		echo '<input type="password" class="form-control" id="gendox_api_key" name="gendox_ai_chat_api_key" value="' . esc_attr($api_key) . '" autocomplete="off" spellcheck="false" />';
		echo '<div class="input-group-append">';
		echo '<button type="button" id="gendox_toggle_api_key" class="btn btn-outline-secondary" aria-controls="gendox_api_key" aria-pressed="false" data-label-show="' . esc_attr__('Show', 'gendox-ai-agent') . '" data-label-hide="' . esc_attr__('Hide', 'gendox-ai-agent') . '">' . esc_html__('Show', 'gendox-ai-agent') . '</button>';
		echo '<button type="button" id="test_connection_button" class="btn btn-outline-secondary">Test Connection</button>';
		echo '</div>';
		echo '</div>';
		echo '<span id="connection_status"></span>';
		echo '</div>';
	}

	/**
	 * Renders one settings section.
	 *
	 * Same output as do_settings_sections(), with two differences: it takes a single
	 * section so sections can be placed individually on the page, and the heading is an
	 * h3 under the page's h1 rather than core's h2.
	 *
	 * @param string $page       Settings page slug the section is registered on.
	 * @param string $section_id Section id.
	 * @return void
	 */
	private function render_settings_section($page, $section_id)
	{
		global $wp_settings_sections, $wp_settings_fields;

		if (!isset($wp_settings_sections[$page][$section_id])) {
			return;
		}

		$section = $wp_settings_sections[$page][$section_id];

		if ($section['title']) {
			echo '<h3 class="gendox-panel-title">' . esc_html($section['title']) . '</h3>';
		}

		if ($section['callback']) {
			call_user_func($section['callback'], $section);
		}

		if (!isset($wp_settings_fields[$page][$section_id])) {
			return;
		}

		echo '<table class="form-table" role="presentation">';
		do_settings_fields($page, $section_id);
		echo '</table>';
	}

	/**
	 * The content of the settings page.
	 *
	 * Single page, no tabs: the WordPress and API sections held too little to justify one
	 * each. The API key and both URL fields save together through one group, so there is a
	 * single Save button. The projects section sits outside the form - it is driven by AJAX,
	 * not by the Settings API, and wrapping it would nest the forms in its modals.
	 *
	 * @since 1.0.0
	 */
	public function settings_page_content()
	{
	?>
		<div class="wrap">
			<h1><?php echo esc_html(__('Gendox AI Chat Settings', 'gendox-ai-agent')); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields('gendox_ai_chat_settings_group'); ?>
				<div class="gendox-panel">
					<?php
					// Both sections and the Save button share one panel, so it is obvious
					// that Save Changes applies to everything inside it.
					$this->render_settings_section('gendox-ai-chat-settings', 'gendox_ai_chat_main_section');
					$this->render_settings_section('gendox-ai-chat-settings', 'gendox_api_main_section');
					$this->render_settings_section('gendox-ai-chat-settings', 'gendox_widget_options_section');
					?>
				<div class="submit">
					<button type="submit" class="btn btn-primary"><?php esc_html_e( 'Save Changes', 'gendox-ai-agent' ); ?></button>
				</div>
				<?php
					?>
				</div>
			</form>

			<div class="gendox-panel">
				<?php $this->render_settings_section('gendox-ai-chat-wp-settings', 'gendox_projects_section'); ?>
			</div>

			<div class="gendox-panel gendox-open-app">
				<?php
				global $wpdb;
				$gendox_url  = rtrim( (string) get_option( 'gendox_chat_script_url', GENDOX_DEFAULT_URL ), '/' );
				$org_row     = $wpdb->get_row( "SELECT organizationId FROM {$wpdb->prefix}gendox_projects LIMIT 1" );
				$org_id      = $org_row ? $org_row->organizationId : '';
				$open_url    = $gendox_url . '/gendox/home/';
				if ( $org_id ) {
					$open_url = add_query_arg( 'organizationId', rawurlencode( $org_id ), $open_url );
				}
				?>
				<h3 class="gendox-panel-title"><?php esc_html_e( 'Gendox App', 'gendox-ai-agent' ); ?></h3>
				<p><?php esc_html_e( 'Configure agents, documents, and chat behaviour in the Gendox app. WordPress keeps your API key, content assignment, and where the widget appears.', 'gendox-ai-agent' ); ?></p>
				<p>
					<a class="btn btn-primary" href="<?php echo esc_url( $open_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Gendox', 'gendox-ai-agent' ); ?></a>
				</p>
			</div>
		</div>
	<?php
	}

	public function gendox_projects_section()
	{

		global $wpdb;
		$table_name = $wpdb->prefix . 'gendox_projects';
		$projects = $wpdb->get_results("SELECT * FROM $table_name");

		echo '<h3 class="gendox-panel-title">' . esc_html__('Gendox Projects', 'gendox-ai-agent') . '</h3>';
	?>
		<div id="gendox_projects_container">
			<table id="projects_table" class="table table-hover table-light">
				<thead class="thead-light">
					<tr>
						<th><?php esc_html_e('ID', 'gendox-ai-agent'); ?></th>
						<th><?php esc_html_e('Project Name', 'gendox-ai-agent'); ?></th>
						<th><?php esc_html_e('Description', 'gendox-ai-agent'); ?></th>
						<th><?php esc_html_e('Actions', 'gendox-ai-agent'); ?></th>
					</tr>
				</thead>
				<tbody id="projects_list">
					<?php if (!empty($projects)): ?>
						<?php foreach ($projects as $project): ?>
							<tr>
								<td><?php echo esc_html($project->gendoxId); ?></td>
								<td><?php echo esc_html($project->name); ?></td>
								<td><?php echo esc_html($project->description) ?: 'No description'; ?></td>
								<td>
									<a href="#" class="btn btn-sm btn-warning edit-project" title="<?php echo esc_attr__('Choose which posts, pages, and products this project can train on.', 'gendox-ai-agent'); ?>"><i class="fas fa-pencil-alt"></i> Assign Content</a>
									<a href="#" class="btn btn-sm btn-success assign-chat" data-project-id="<?php echo esc_attr($project->gendoxId); ?>" title="<?php echo esc_attr__('Choose which post types and taxonomies show this project\'s chat widget.', 'gendox-ai-agent'); ?>"><i class="fas fa-eye"></i> Assign Chat</a>
									<a href="#" class="btn btn-sm btn-info view-project" title="<?php echo esc_attr__('See this project\'s details and currently assigned content.', 'gendox-ai-agent'); ?>"><i class="fas fa-eye"></i> View</a>
									<a href="#" class="btn btn-sm btn-danger delete-project" title="<?php echo esc_attr__('Remove this project from the WordPress list. Does not delete it in Gendox.', 'gendox-ai-agent'); ?>"><i class="fas fa-trash"></i> Delete</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else: ?>
						<tr>
							<td colspan="4"><?php esc_html_e('No projects found.', 'gendox-ai-agent'); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
			<div class="gendox-projects-actions">
				<button type="button" id="fetch_projects_button" class="btn btn-primary" title="<?php echo esc_attr__('Sync projects from your Gendox organization into this list. Projects missing in Gendox may be removed locally.', 'gendox-ai-agent'); ?>"><?php esc_html_e('Fetch Projects', 'gendox-ai-agent'); ?></button>
				<button type="button" id="reload_content_button" class="btn btn-secondary" title="<?php echo esc_attr__('Ask Gendox to re-pull assigned WordPress content for training.', 'gendox-ai-agent'); ?>"><?php esc_html_e('Reload Content', 'gendox-ai-agent'); ?></button>
				<span id="reload_content_status"></span>
			</div>
		</div>
		<div id="editProjectModal" style="display:none;">
			<!-- close modal -->
			<span id="closeModal">&times;</span>
			<h2>Assign Content</h2>
			<form id="editProjectForm">
				<input type="hidden" id="projectId" name="projectId" value="">

				<label for="postList">Assign Posts:</label>
				<div id="postList" class="gendoxItemsSelect">
					<select id="assign_posts" name="post[]" multiple="multiple" class="select2-multi" style="width: 100%;"></select>
				</div>

				<label for="productList">Assign Products:</label>
				<div id="productList" class="gendoxItemsSelect">
					<select id="assign_products" name="product[]" multiple="multiple" class="select2-multi" style="width: 100%;"></select>
				</div>

				<label for="pageList">Assign Pages:</label>
				<div id="pageList" class="gendoxItemsSelect">
					<select id="assign_pages" name="page[]" multiple="multiple" class="select2-multi" style="width: 100%;"></select>
				</div>

				<button type="button" id="saveProjectChanges">Save Changes</button>
			</form>
		</div>
		<!-- Add this modal to your existing HTML -->
		<div id="viewProjectModal" style="display:none;">
			<span id="closeViewModal">&times;</span> <!-- Close button -->
			<h2>View Project</h2>
			<div id="projectDetails">
				<p><strong><?php esc_html_e('Project ID:', 'gendox-ai-agent'); ?></strong> <span id="view_projectId"></span></p>
				<p><strong><?php esc_html_e('Project Name:', 'gendox-ai-agent'); ?></strong> <span id="view_projectName"></span></p>
				<p><strong><?php esc_html_e('Project Description:', 'gendox-ai-agent'); ?></strong> <span id="view_projectDescription"></span></p>
				<div class="gendoxAssignedItemsType">
					<span><strong><?php esc_html_e('Assigned Posts:', 'gendox-ai-agent'); ?></strong></span>
					<div id="view_assignedPosts" class="gendoxAssignedItems"></div>
				</div>
				<div class="gendoxAssignedItemsType">
					<span><strong><?php esc_html_e('Assigned Products:', 'gendox-ai-agent'); ?></strong></span>
					<div id="view_assignedProducts" class="gendoxAssignedItems"></div>
				</div>
				<div class="gendoxAssignedItemsType">
					<span><strong><?php esc_html_e('Assigned Pages:', 'gendox-ai-agent'); ?></strong></span>
					<div id="view_assignedPages" class="gendoxAssignedItems"></div>
				</div>
			</div>
		</div>

		<div id="assignChatModal" style="display:none;">
			<span id="closeChatModal">&times;</span> <!-- Close button -->
			<h2>Assign Chat</h2>
			<form>
				<input type="hidden" id="projectId" name="project_id" value="">

				<label for="postTypeSelect">Post Type:</label>
				<select id="postTypeSelect" multiple>
					<!-- get all post types dynamically -->
					<?php
					$post_types = get_post_types(array('public' => true), 'objects');
					foreach ($post_types as $post_type) {
						echo '<option value="' . esc_attr($post_type->name) . '">' . esc_html($post_type->label) . '</option>';
					}
					?>
				</select>

				<label for="taxonomySelect">Taxonomies:</label>
				<?php
				// Fetch all public taxonomies except WooCommerce attributes (starting with "pa_")
				$taxonomies = get_taxonomies(array('public' => true), 'objects');

				foreach ($taxonomies as $taxonomy) {
					if (strpos($taxonomy->name, 'pa_') === 0) {
						// Skip WooCommerce attributes
						continue;
					}

					// Fetch terms for each taxonomy
					$terms = get_terms(array(
						'taxonomy' => $taxonomy->name,
						'hide_empty' => false,
					));

					// Check if there are any terms to display
					if (!is_wp_error($terms) && !empty($terms)) {
						// Display the label and select only if there are terms
						echo '<label for="taxonomy_' . esc_attr($taxonomy->name) . '">' . esc_html($taxonomy->label) . ':</label>';
						echo '<select id="taxonomy_' . esc_attr($taxonomy->name) . '" name="taxonomies[' . esc_attr($taxonomy->name) . '][]" multiple>';

						// Populate the select with terms
						foreach ($terms as $term) {
							echo '<option value="' . esc_attr($term->term_id) . '">' . esc_html($term->name) . '</option>';
						}

						echo '</select><br>';
					}
				}
				?>


				<button type="button" id="saveChatSettings">Save Changes</button>
			</form>
		</div>


<?php
	}


	public function gendox_test_connection()
	{
		check_ajax_referer('gendox_nonce', 'security');

		if (!$this->current_user_can_manage_gendox()) {
			wp_send_json_error(__('You do not have permission to do this.', 'gendox-ai-agent'));
		}

		$api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';

		if (empty($api_key)) {
			wp_send_json_error('API Key is missing');
		}

		[$http_code, $body] = $this->gendox_get_api_call($api_key);

		if ($http_code === 200) {
			$response_data = json_decode($body, true); // Decode the response body into an associative array
			$userName = $response_data['userName']; // Get the username from the response

			if (!empty($userName)) {
				wp_send_json_success(array('status' => 200, 'username' => $userName));
			} else {
				wp_send_json_success(array('status' => 200, 'username' => 'null'));
			}
		} else {
			wp_send_json_success(array('status' => $http_code, 'message' => 'Failed to retrieve data'));
		}
	}

	public function gendox_fetch_projects()
	{
		check_ajax_referer('gendox_nonce', 'security');

		if (!$this->current_user_can_manage_gendox()) {
			wp_send_json_error(__('You do not have permission to do this.', 'gendox-ai-agent'));
		}

		$api_key = get_option('gendox_ai_chat_api_key');
		if (empty($api_key)) {
			wp_send_json_error('API Key is missing.');
		}

		[$http_code, $body] = $this->gendox_get_api_call($api_key);

		if ($http_code === 200) {
			$response_data = json_decode($body, true);

			if (!empty($response_data['organizations'])) {
				global $wpdb;
				$table_name = $wpdb->prefix . 'gendox_projects';

				$api_project_ids = []; // Array to hold project IDs from API

				foreach ($response_data['organizations'] as $organization) {
					$organizationId = sanitize_text_field($organization['id']);

					if (!empty($organization['projects'])) {
						foreach ($organization['projects'] as $project) {
							$projectId = sanitize_text_field($project['id']);
							$projectName = sanitize_text_field($project['name']);
							$projectDescription = sanitize_textarea_field($project['description']);
							$api_project_ids[] = $projectId; // Track this project ID from the API response

							// Check if project already exists in the database
							$existing_project = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE gendoxId = %s", $projectId));

							if ($existing_project) {
								// Update existing project
								$wpdb->update(
									$table_name,
									[
										'organizationId' => $organizationId,
										'name' => $projectName,
										'description' => $projectDescription,
									],
									['gendoxId' => $projectId],
									['%s', '%s', '%s'],
									['%s']
								);
							} else {
								// Insert new project
								$wpdb->insert(
									$table_name,
									[
										'gendoxId' => $projectId,
										'organizationId' => $organizationId,
										'name' => $projectName,
										'description' => $projectDescription,
										'postIds' => ''
									],
									['%s', '%s', '%s', '%s', '%s']
								);
							}
						}
					}
				}

				// Delete projects that no longer exist in the API response, together with their
				// chat placement options. An option left without its row makes
				// add_footer_script_for_chat() render the widget with an empty organization id.
				if (empty($api_project_ids)) {
					// `IN ()` is a SQL syntax error, so no-projects is handled separately.
					$stale_project_ids = $wpdb->get_col("SELECT gendoxId FROM $table_name");
					$wpdb->query("DELETE FROM $table_name");
				} else {
					$api_project_ids_placeholder = implode(', ', array_fill(0, count($api_project_ids), '%s'));

					// Collect before deleting.
					$stale_project_ids = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT gendoxId FROM $table_name WHERE gendoxId NOT IN ($api_project_ids_placeholder)",
							...$api_project_ids
						)
					);

					$wpdb->query(
						$wpdb->prepare(
							"DELETE FROM $table_name WHERE gendoxId NOT IN ($api_project_ids_placeholder)",
							...$api_project_ids
						)
					);
				}

				// delete_option(), not a bulk DELETE: these options are autoloaded and only
				// delete_option() invalidates the `alloptions` cache.
				foreach ($stale_project_ids as $stale_project_id) {
					delete_option("gendox_ai_chat_positions_{$stale_project_id}");
				}

				// Fetch and return the updated projects from the database
				$projects = $wpdb->get_results("SELECT * FROM $table_name");

				if (!empty($projects)) {
					$project_data = [];
					foreach ($projects as $project) {
						$project_data[] = [
							'id' => $project->id,
							'gendox_id' => $project->gendoxId,
							'name' => $project->name,
							'description' => $project->description,
							'actions' => ''
						];
					}

					wp_send_json_success($project_data);
				} else {
					wp_send_json_error('No projects found after saving.');
				}
			} else {
				wp_send_json_error('No organizations or projects found in API response.');
			}
		} else {
			wp_send_json_error('Failed to fetch projects from API.');
		}
	}

	public function gendox_reload_content()
	{
		check_ajax_referer('gendox_nonce', 'security');

		if (!$this->current_user_can_manage_gendox()) {
			wp_send_json_error(__('You do not have permission to do this.', 'gendox-ai-agent'));
		}

		$api_key = get_option('gendox_ai_chat_api_key');
		if (empty($api_key)) {
			wp_send_json_error('API Key is missing.');
		}

		$organization_id = Gendox_AI_Agent_Helpers::get_organization_id($api_key);
		if (empty($organization_id)) {
			wp_send_json_error('Failed to retrieve organization ID.');
		}

		$api_base_url = get_option('gendox_api_base_url', GENDOX_DEFAULT_URL);
		$url = rtrim($api_base_url, '/') . '/gendox/api/v1/organizations/' . rawurlencode($organization_id) . '/integrations/trigger';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'x-api-key'    => $api_key,
				),
			)
		);

		if (is_wp_error($response)) {
			wp_send_json_error('Failed to trigger content reload: ' . $response->get_error_message());
		}

		$http_code = (int) wp_remote_retrieve_response_code($response);

		if ($http_code === 202) {
			wp_send_json_success(['message' => 'Content reload started.']);
		}

		wp_send_json_error('Failed to trigger content reload. HTTP status: ' . $http_code);
	}

	public function gendox_get_api_call($api_key)
	{
		$api_base_url = get_option('gendox_api_base_url', GENDOX_DEFAULT_URL);
		$url = rtrim($api_base_url, '/') . '/gendox/api/v1/profile';

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'x-api-key' => $api_key,
				),
			)
		);

		if (is_wp_error($response)) {
			return [0, ''];
		}

		return [
			(int) wp_remote_retrieve_response_code($response),
			wp_remote_retrieve_body($response),
		];
	}

	// Fetch Posts
	public function gendox_fetch_posts()
	{
		check_ajax_referer('gendox_nonce', 'security');

		if (!$this->current_user_can_manage_gendox()) {
			wp_send_json_error(__('You do not have permission to do this.', 'gendox-ai-agent'));
		}

		$posts = get_posts(array(
			'post_type' => 'post',
			'numberposts' => -1,
		));

		if (!empty($posts)) {
			$output = array();
			foreach ($posts as $post) {
				$output[] = array(
					'id' => $post->ID,
					'title' => $post->post_title
				);
			}
			wp_send_json_success($output);
		} else {
			wp_send_json_error('No posts found.');
		}
	}

	// Fetch Products
	public function gendox_fetch_products()
	{
		check_ajax_referer('gendox_nonce', 'security');

		if (!$this->current_user_can_manage_gendox()) {
			wp_send_json_error(__('You do not have permission to do this.', 'gendox-ai-agent'));
		}

		$products = get_posts(array(
			'post_type' => 'product',
			'numberposts' => -1,
		));

		if (!empty($products)) {
			$output = array();
			foreach ($products as $product) {
				$output[] = array(
					'id' => $product->ID,
					'title' => $product->post_title
				);
			}
			wp_send_json_success($output);
		} else {
			wp_send_json_error('No products found.');
		}
	}

	// Fetch Pages
	public function gendox_fetch_pages()
	{
		check_ajax_referer('gendox_nonce', 'security');

		if (!$this->current_user_can_manage_gendox()) {
			wp_send_json_error(__('You do not have permission to do this.', 'gendox-ai-agent'));
		}

		$pages = get_posts(array(
			'post_type' => 'page',
			'numberposts' => -1,
		));

		if (!empty($pages)) {
			$output = array();
			foreach ($pages as $page) {
				$output[] = array(
					'id' => $page->ID,
					'title' => $page->post_title
				);
			}
			wp_send_json_success($output);
		} else {
			wp_send_json_error('No pages found.');
		}
	}

	public function gendox_save_project_changes()
	{
		check_ajax_referer('gendox_nonce', 'security');

		if (!$this->current_user_can_manage_gendox()) {
			wp_send_json_error(__('You do not have permission to do this.', 'gendox-ai-agent'));
		}

		$projectId = isset($_POST['projectId']) ? sanitize_text_field(wp_unslash($_POST['projectId'])) : '';
		$assignedPosts = isset($_POST['posts']) ? array_map('absint', wp_unslash((array) $_POST['posts'])) : array();
		$assignedProducts = isset($_POST['products']) ? array_map('absint', wp_unslash((array) $_POST['products'])) : array();
		$assignedPages = isset($_POST['pages']) ? array_map('absint', wp_unslash((array) $_POST['pages'])) : array();

		if (empty($projectId)) {
			wp_send_json_error('Project ID is missing.');
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'gendox_projects';

		// Save assigned posts/products/pages in a serialized format
		$assignedItems = serialize(array(
			'posts' => $assignedPosts,
			'products' => $assignedProducts,
			'pages' => $assignedPages
		));

		$wpdb->update(
			$table_name,
			array('postIds' => $assignedItems),
			array('gendoxId' => $projectId),
			array('%s'),
			array('%s')
		);

		wp_send_json_success('Project updated successfully.');
	}


	public function gendox_get_project_items()
	{
		check_ajax_referer('gendox_nonce', 'security');

		if (!$this->current_user_can_manage_gendox()) {
			wp_send_json_error(__('You do not have permission to do this.', 'gendox-ai-agent'));
		}

		$projectId = isset($_POST['projectId']) ? sanitize_text_field(wp_unslash($_POST['projectId'])) : '';

		global $wpdb;
		$table_name = $wpdb->prefix . 'gendox_projects';
		$project = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE gendoxId = %s", $projectId));

		if ($project) {
			$assignedItems = maybe_unserialize($project->postIds);
			wp_send_json_success($assignedItems);
		} else {
			wp_send_json_error('No project found.');
		}
	}

	public function gendox_view_project()
	{
		check_ajax_referer('gendox_nonce', 'security');

		if (!$this->current_user_can_manage_gendox()) {
			wp_send_json_error(__('You do not have permission to do this.', 'gendox-ai-agent'));
		}

		$projectId = isset($_POST['projectId']) ? sanitize_text_field(wp_unslash($_POST['projectId'])) : '';

		global $wpdb;
		$table_name = $wpdb->prefix . 'gendox_projects';
		$project = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE gendoxId = %s", $projectId));

		if ($project) {
			$assignedItems = maybe_unserialize($project->postIds);

			// Fetch names of assigned posts, products, and pages
			$post_titles = $this->get_titles_by_ids($assignedItems['posts'], 'post');
			$product_titles = $this->get_titles_by_ids($assignedItems['products'], 'product');
			$page_titles = $this->get_titles_by_ids($assignedItems['pages'], 'page');

			// Return detailed project data
			wp_send_json_success(array(
				'name' => $project->name,
				'description' => $project->description,
				'posts' => $post_titles,
				'products' => $product_titles,
				'pages' => $page_titles,
			));
		} else {
			wp_send_json_error('No project found.');
		}
	}

	// Helper function to fetch titles by IDs
	private function get_titles_by_ids($ids, $post_type)
	{
		if (!empty($ids)) {
			$posts = get_posts(array(
				'post_type' => $post_type,
				'post__in' => $ids,
				'numberposts' => -1
			));
			return wp_list_pluck($posts, 'post_title'); // Return titles of the items
		}
		return array();
	}

	// Save chat settings
	public function gendox_save_chat_settings()
	{
		check_ajax_referer('gendox_nonce', 'security');

		if (!$this->current_user_can_manage_gendox()) {
			wp_send_json_error(__('You do not have permission to do this.', 'gendox-ai-agent'));
		}

		// Retrieve selected values from the AJAX request
		$post_type = isset($_POST['post_type'])
			? array_map('sanitize_text_field', wp_unslash((array) $_POST['post_type']))
			: array();
		$taxonomies_raw = isset($_POST['taxonomies'])
			? wp_unslash((array) $_POST['taxonomies'])
			: array();
		$taxonomies = array();
		foreach ($taxonomies_raw as $taxonomy => $term_ids) {
			$taxonomy = sanitize_key($taxonomy);
			if ('' === $taxonomy) {
				continue;
			}
			$taxonomies[$taxonomy] = array_map('absint', (array) $term_ids);
		}
		$project_id = isset($_POST['project_id'])
			? sanitize_text_field(wp_unslash($_POST['project_id']))
			: '';

		if (empty($project_id)) {
			wp_send_json_error(__('Project ID is missing.', 'gendox-ai-agent'));
		}

		// Reject a project that no longer exists locally, so a stale settings page cannot
		// store an option with no matching row.
		global $wpdb;
		$table_name = $wpdb->prefix . 'gendox_projects';
		$project_exists = $wpdb->get_var(
			$wpdb->prepare("SELECT id FROM $table_name WHERE gendoxId = %s", $project_id)
		);

		if (!$project_exists) {
			wp_send_json_error(__('Unknown project. Refresh the projects list and try again.', 'gendox-ai-agent'));
		}

		// Save data as an option with the project ID
		update_option("gendox_ai_chat_positions_{$project_id}", [
			'post_type' => $post_type,
			'taxonomies' => $taxonomies,
		]);

		wp_send_json_success(__('Chat settings saved successfully.', 'gendox-ai-agent'));
	}

	// Get chat settings for a project
	public function gendox_get_chat_settings()
	{
		check_ajax_referer('gendox_nonce', 'security');

		if (!$this->current_user_can_manage_gendox()) {
			wp_send_json_error(__('You do not have permission to do this.', 'gendox-ai-agent'));
		}

		$project_id = isset($_POST['project_id']) ? sanitize_text_field(wp_unslash($_POST['project_id'])) : '';

		if (empty($project_id)) {
			wp_send_json_error(__('Project ID is missing.', 'gendox-ai-agent'));
		}

		// Retrieve the saved settings for the project
		$settings = get_option("gendox_ai_chat_positions_{$project_id}", [
			'post_type' => [],
			'taxonomies' => []
		]);

		wp_send_json_success($settings);
	}
}
