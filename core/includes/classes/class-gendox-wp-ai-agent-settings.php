<?php

// Exit if accessed directly.
if (! defined('ABSPATH')) exit;

/**
 * Class Gendox_WP_AI_Agent_Settings
 *
 * This class contains all of the plugin settings.
 * Here you can configure the whole plugin data.
 *
 * @package		GENDOX
 * @subpackage	Classes/Gendox_WP_AI_Agent_Settings
 * @author		Ctrl+Space Labs
 * @since		1.0.0
 */
class Gendox_WP_AI_Agent_Settings
{

	/**
	 * The plugin name
	 *
	 * @var		string
	 * @since   1.0.0
	 */
	private $plugin_name;

	/**
	 * Our Gendox_WP_AI_Agent_Settings constructor 
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
		add_action('admin_menu', array($this, 'add_hidden_settings_page'));
		add_action('admin_init', array($this, 'register_hidden_setting'));

		// On the option rather than a sanitize_callback, so it covers both settings pages
		// that write the API key.
		//
		// Static callback on purpose: get_organization_id() constructs this class, so an
		// instance callback would register a second copy of the handler on every call - WP
		// keys instance callbacks by object hash, but dedupes static ones.
		add_filter('pre_update_option_gendox_ai_chat_api_key', array(__CLASS__, 'gendox_handle_api_key_change'), 10, 2);
	}

	/**
	 * Moves the integration when the API key points at a different organization.
	 *
	 * Deactivates the outgoing organization and activates the incoming one. The key is only
	 * saved if both calls succeed; on failure the previous state is restored and the old key
	 * is kept, so the stored key always matches the active integration.
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
				$old_organization_id = Gendox_WP_AI_Agent_Helpers::get_organization_id($old_value);
				if ($old_organization_id) {
					Gendox_WP_AI_Agent_Helpers::send_integration_status($old_value, $old_organization_id, 'INACTIVE');
				}
			}
			return $new_value;
		}

		$new_organization_id = Gendox_WP_AI_Agent_Helpers::get_organization_id($new_value);
		if (!$new_organization_id) {
			add_settings_error(
				'gendox_ai_chat_api_key',
				'gendox_api_key_invalid',
				__('The API key was not accepted by Gendox. The previous key has been kept.', 'gendox-wp-ai-agent')
			);
			return $old_value;
		}

		$old_organization_id = empty($old_value)
			? null
			: Gendox_WP_AI_Agent_Helpers::get_organization_id($old_value);

		// Same organization, or no previous integration to move.
		if (!$old_organization_id || $old_organization_id === $new_organization_id) {
			return $new_value;
		}

		if (!Gendox_WP_AI_Agent_Helpers::send_integration_status($old_value, $old_organization_id, 'INACTIVE')) {
			add_settings_error(
				'gendox_ai_chat_api_key',
				'gendox_deactivate_failed',
				__('Could not deactivate the integration for the current organization. The API key has not been changed.', 'gendox-wp-ai-agent')
			);
			return $old_value;
		}

		if (!Gendox_WP_AI_Agent_Helpers::send_integration_status($new_value, $new_organization_id, 'ACTIVE')) {
			// Put the outgoing organization back, so a failed switch leaves nothing deactivated.
			Gendox_WP_AI_Agent_Helpers::send_integration_status($old_value, $old_organization_id, 'ACTIVE');
			add_settings_error(
				'gendox_ai_chat_api_key',
				'gendox_activate_failed',
				__('Could not activate the integration for the new organization. The API key has not been changed.', 'gendox-wp-ai-agent')
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
            __('Gendox AI Chat Settings', 'gendox-wp-ai-agent'),
			__('Gendox AI Chat', 'gendox-wp-ai-agent'),
			'edit_posts',
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

	public function add_hidden_settings_page()
	{
		add_submenu_page(
			null,
			'Chat Script Settings',
			'Chat Script Settings',
			'manage_options',
			'chat-script-settings',
			array($this, 'render_settings_page')
		);
	}

	public function render_settings_page()
	{
		// Check if user is allowed to access the page
		if (!current_user_can('manage_options')) {
			return;
		}

		// Output the page content
?>
		<div class="wrap">
			<h1><?php _e('Chat Script Settings', 'gendox-wp-ai-agent'); ?></h1>
			<form method="post" action="options.php">
				<?php
				// Same options group as the visible API Settings tab, so both places
				// save the same two options (gendox_chat_script_url, gendox_api_base_url).
				settings_fields('gendox_api_settings_group');
				do_settings_sections('chat-script-settings');
				submit_button();
				?>
			</form>
		</div>
	<?php
	}

	public function register_hidden_setting()
	{
		// Both options are already registered under 'gendox_api_settings_group' in
		// register_settings() below (that's what the visible API Settings tab uses).
		// We only need to add the same two fields again, targeting this page's slug,
		// so they render here too - the option registration/whitelisting stays in
		// the one group instead of a second, separate one.
		add_settings_section(
			'chat_script_main_section',
			__('Chat Script Settings', 'gendox-wp-ai-agent'),
			null,
			'chat-script-settings'
		);
		add_settings_field(
			'gendox_chat_script_url',
			__('Chat Script URL', 'gendox-wp-ai-agent'),
			array($this, 'chat_script_url_field_callback'),
			'chat-script-settings',
			'chat_script_main_section'
		);
		add_settings_field(
			'gendox_api_base_url',
			__('Gendox API Base URL', 'gendox-wp-ai-agent'),
			array($this, 'api_base_url_field_callback'),
			'chat-script-settings',
			'chat_script_main_section'
		);
	}


	public function chat_script_url_field_callback()
	{
		$chat_script_url = get_option('gendox_chat_script_url', GENDOX_DEFAULT_URL);
		echo '<input type="url" class="form-control" name="gendox_chat_script_url" value="' . esc_attr($chat_script_url) . '" placeholder="' . esc_attr(GENDOX_DEFAULT_URL) . '" />';
		echo '<p class="description">' . __('The URL where the Gendox chat script is hosted.', 'gendox-wp-ai-agent') . '</p>';
	}

	public function api_base_url_field_callback()
	{
		$api_base_url = get_option('gendox_api_base_url', GENDOX_DEFAULT_URL);
		echo '<input type="url" class="form-control" name="gendox_api_base_url" value="' . esc_attr($api_base_url) . '" placeholder="' . esc_attr(GENDOX_DEFAULT_URL) . '" />';
		echo '<p class="description">' . __('The base URL for Gendox API endpoints.', 'gendox-wp-ai-agent') . '</p>';
	}

	public function api_settings_section_callback()
	{
		echo '<p>' . __('Configure API endpoints and URLs for the Gendox service.', 'gendox-wp-ai-agent') . '</p>';
	}

	/**
	 * Register settings and fields
	 *
	 * @since 1.0.0
	 */
	public function register_settings()
	{
		// AI Chat Settings section
		register_setting('gendox_ai_chat_settings_group', 'gendox_ai_chat_api_key');
		add_settings_section(
			'gendox_ai_chat_main_section',
			__('AI Chat Settings', 'gendox-wp-ai-agent'),
			null,
			'gendox-ai-chat-settings'
		);
		add_settings_field(
			'gendox_ai_chat_api_key',
			__('API Key', 'gendox-wp-ai-agent'),
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
		//
		// Both options stay registered under 'gendox_api_settings_group' as well: the hidden
		// Chat Script Settings page still submits that group, and options.php only accepts
		// values whitelisted for the group being saved.
		register_setting('gendox_ai_chat_settings_group', 'gendox_chat_script_url');
		register_setting('gendox_ai_chat_settings_group', 'gendox_api_base_url');
		register_setting('gendox_api_settings_group', 'gendox_chat_script_url');
		register_setting('gendox_api_settings_group', 'gendox_api_base_url');
		add_settings_section(
			'gendox_api_main_section',
			__('API Settings', 'gendox-wp-ai-agent'),
			array($this, 'api_settings_section_callback'),
			'gendox-ai-chat-settings'
		);
		add_settings_field(
			'gendox_chat_script_url',
			__('Chat Script URL', 'gendox-wp-ai-agent'),
			array($this, 'chat_script_url_field_callback'),
			'gendox-ai-chat-settings',
			'gendox_api_main_section'
		);
		add_settings_field(
			'gendox_api_base_url',
			__('Gendox API Base URL', 'gendox-wp-ai-agent'),
			array($this, 'api_base_url_field_callback'),
			'gendox-ai-chat-settings',
			'gendox_api_main_section'
		);
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
		echo '<button type="button" id="gendox_toggle_api_key" class="btn btn-outline-secondary" aria-controls="gendox_api_key" aria-pressed="false" data-label-show="' . esc_attr__('Show', 'gendox-wp-ai-agent') . '" data-label-hide="' . esc_attr__('Hide', 'gendox-wp-ai-agent') . '">' . esc_html__('Show', 'gendox-wp-ai-agent') . '</button>';
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
			<h1><?php echo esc_html(__('Gendox AI Chat Settings', 'gendox-wp-ai-agent')); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields('gendox_ai_chat_settings_group'); ?>
				<div class="gendox-panel">
					<?php
					// Both sections and the Save button share one panel, so it is obvious
					// that Save Changes applies to everything inside it.
					$this->render_settings_section('gendox-ai-chat-settings', 'gendox_ai_chat_main_section');
					$this->render_settings_section('gendox-ai-chat-settings', 'gendox_api_main_section');
					submit_button();
					?>
				</div>
			</form>

			<div class="gendox-panel">
				<?php $this->render_settings_section('gendox-ai-chat-wp-settings', 'gendox_projects_section'); ?>
			</div>

			<!-- Gendox app panel. Sizing/framing lives in backend-styles.css -->
			<div class="gendox-app-frame">
				<?php $chat_script_url = get_option('gendox_chat_script_url', GENDOX_DEFAULT_URL); ?>
				<!-- Trailing slash is required: the app redirects /login-prompt to
				     /login-prompt/, and some deployments answer with an absolute http://
				     Location, which a browser blocks as mixed content inside an https admin
				     page. Requesting the canonical URL avoids the redirect entirely. -->
				<iframe src="<?php echo esc_url(rtrim($chat_script_url, '/') . '/login-prompt/'); ?>" allowfullscreen></iframe>
			</div>
		</div>
	<?php
	}

	public function gendox_projects_section()
	{

		global $wpdb;
		$table_name = $wpdb->prefix . 'gendox_projects';
		$projects = $wpdb->get_results("SELECT * FROM $table_name");

		echo '<h3 class="gendox-panel-title">' . __('Gendox Projects', 'gendox-wp-ai-agent') . '</h3>';
	?>
		<div id="gendox_projects_container">
			<table id="projects_table" class="table table-hover table-light">
				<thead class="thead-light">
					<tr>
						<th><?php _e('ID', 'gendox-wp-ai-agent'); ?></th>
						<th><?php _e('Project Name', 'gendox-wp-ai-agent'); ?></th>
						<th><?php _e('Description', 'gendox-wp-ai-agent'); ?></th>
						<th><?php _e('Actions', 'gendox-wp-ai-agent'); ?></th>
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
									<a href="#" class="btn btn-sm btn-warning edit-project" title="<?php echo esc_attr__('Choose which posts, pages, and products this project can train on.', 'gendox-wp-ai-agent'); ?>"><i class="fas fa-pencil-alt"></i> Assign Content</a>
									<a href="#" class="btn btn-sm btn-success assign-chat" data-project-id="<?php echo esc_attr($project->gendoxId); ?>" title="<?php echo esc_attr__('Choose which post types and taxonomies show this project\'s chat widget.', 'gendox-wp-ai-agent'); ?>"><i class="fas fa-eye"></i> Assign Chat</a>
									<a href="#" class="btn btn-sm btn-info view-project" title="<?php echo esc_attr__('See this project\'s details and currently assigned content.', 'gendox-wp-ai-agent'); ?>"><i class="fas fa-eye"></i> View</a>
									<a href="#" class="btn btn-sm btn-danger delete-project" title="<?php echo esc_attr__('Remove this project from the WordPress list. Does not delete it in Gendox.', 'gendox-wp-ai-agent'); ?>"><i class="fas fa-trash"></i> Delete</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else: ?>
						<tr>
							<td colspan="4"><?php _e('No projects found.', 'gendox-wp-ai-agent'); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
			<div class="gendox-projects-actions">
				<button type="button" id="fetch_projects_button" class="btn btn-primary" title="<?php echo esc_attr__('Sync projects from your Gendox organization into this list. Projects missing in Gendox may be removed locally.', 'gendox-wp-ai-agent'); ?>"><?php esc_html_e('Fetch Projects', 'gendox-wp-ai-agent'); ?></button>
				<button type="button" id="reload_content_button" class="btn btn-secondary" title="<?php echo esc_attr__('Ask Gendox to re-pull assigned WordPress content for training.', 'gendox-wp-ai-agent'); ?>"><?php esc_html_e('Reload Content', 'gendox-wp-ai-agent'); ?></button>
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
				<p><strong><?php _e('Project ID:', 'gendox-wp-ai-agent'); ?></strong> <span id="view_projectId"></span></p>
				<p><strong><?php _e('Project Name:', 'gendox-wp-ai-agent'); ?></strong> <span id="view_projectName"></span></p>
				<p><strong><?php _e('Project Description:', 'gendox-wp-ai-agent'); ?></strong> <span id="view_projectDescription"></span></p>
				<div class="gendoxAssignedItemsType">
					<span><strong><?php _e('Assigned Posts:', 'gendox-wp-ai-agent'); ?></strong></span>
					<div id="view_assignedPosts" class="gendoxAssignedItems"></div>
				</div>
				<div class="gendoxAssignedItemsType">
					<span><strong><?php _e('Assigned Products:', 'gendox-wp-ai-agent'); ?></strong></span>
					<div id="view_assignedProducts" class="gendoxAssignedItems"></div>
				</div>
				<div class="gendoxAssignedItemsType">
					<span><strong><?php _e('Assigned Pages:', 'gendox-wp-ai-agent'); ?></strong></span>
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

		$api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';

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

		$api_key = get_option('gendox_ai_chat_api_key');
		if (empty($api_key)) {
			wp_send_json_error('API Key is missing.');
		}

		$organization_id = Gendox_WP_AI_Agent_Helpers::get_organization_id($api_key);
		if (empty($organization_id)) {
			wp_send_json_error('Failed to retrieve organization ID.');
		}

		$api_base_url = get_option('gendox_api_base_url', GENDOX_DEFAULT_URL);
		$url = rtrim($api_base_url, '/') . '/gendox/api/v1/organizations/' . rawurlencode($organization_id) . '/integrations/trigger';

		$curl = curl_init();
		curl_setopt_array($curl, [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'x-api-key: ' . $api_key,
			],
			CURLOPT_SSL_VERIFYHOST => true,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
		]);

		curl_exec($curl);
		$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		if (curl_errno($curl)) {
			$error = curl_error($curl);
			curl_close($curl);
			wp_send_json_error('Failed to trigger content reload: ' . $error);
		}

		curl_close($curl);

		if ($http_code === 202) {
			wp_send_json_success(['message' => 'Content reload started.']);
		}

		wp_send_json_error('Failed to trigger content reload. HTTP status: ' . $http_code);
	}

	public function gendox_get_api_call($api_key)
	{
		$api_base_url = get_option('gendox_api_base_url', GENDOX_DEFAULT_URL);
		$curl = curl_init();

		curl_setopt_array($curl, array(
			CURLOPT_URL => rtrim($api_base_url, '/') . '/gendox/api/v1/profile',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'GET',
			CURLOPT_HTTPHEADER => array(
				'x-api-key: ' . $api_key
			),
			CURLOPT_HEADER => true
		));

		$response = curl_exec($curl);
		$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);

		$body = substr($response, $header_size);

		curl_close($curl);
		return [$http_code, $body];
	}

	// Fetch Posts
	public function gendox_fetch_posts()
	{
		check_ajax_referer('gendox_nonce', 'security');

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

		$projectId = isset($_POST['projectId']) ? sanitize_text_field($_POST['projectId']) : '';
		$assignedPosts = isset($_POST['posts']) ? array_map('intval', $_POST['posts']) : array();
		$assignedProducts = isset($_POST['products']) ? array_map('intval', $_POST['products']) : array();
		$assignedPages = isset($_POST['pages']) ? array_map('intval', $_POST['pages']) : array();

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

		$projectId = isset($_POST['projectId']) ? sanitize_text_field($_POST['projectId']) : '';

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

		$projectId = isset($_POST['projectId']) ? sanitize_text_field($_POST['projectId']) : '';

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

		// Retrieve selected values from the AJAX request
		$post_type = isset($_POST['post_type']) ? (array) $_POST['post_type'] : [];
		$taxonomies = isset($_POST['taxonomies']) ? (array) $_POST['taxonomies'] : [];
		$project_id = isset($_POST['project_id']) ? sanitize_text_field($_POST['project_id']) : '';

		if (empty($project_id)) {
			wp_send_json_error(__('Project ID is missing.', 'gendox-wp-ai-agent'));
		}

		// Reject a project that no longer exists locally, so a stale settings page cannot
		// store an option with no matching row.
		global $wpdb;
		$table_name = $wpdb->prefix . 'gendox_projects';
		$project_exists = $wpdb->get_var(
			$wpdb->prepare("SELECT id FROM $table_name WHERE gendoxId = %s", $project_id)
		);

		if (!$project_exists) {
			wp_send_json_error(__('Unknown project. Refresh the projects list and try again.', 'gendox-wp-ai-agent'));
		}

		// Save data as an option with the project ID
		update_option("gendox_ai_chat_positions_{$project_id}", [
			'post_type' => $post_type,
			'taxonomies' => $taxonomies,
		]);

		wp_send_json_success(__('Chat settings saved successfully.', 'gendox-wp-ai-agent'));
	}

	// Get chat settings for a project
	public function gendox_get_chat_settings()
	{
		check_ajax_referer('gendox_nonce', 'security');

		$project_id = isset($_POST['project_id']) ? sanitize_text_field($_POST['project_id']) : '';

		if (empty($project_id)) {
			wp_send_json_error(__('Project ID is missing.', 'gendox-wp-ai-agent'));
		}

		// Retrieve the saved settings for the project
		$settings = get_option("gendox_ai_chat_positions_{$project_id}", [
			'post_type' => [],
			'taxonomies' => []
		]);

		wp_send_json_success($settings);
	}
}
