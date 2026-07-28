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
           array($this, 'settings_page_content')
        );
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

		// WordPress Settings section
		register_setting('gendox_wp_settings_group', 'gendox_wp_setting_example');
		add_settings_section(
			'gendox_wp_main_section',
			__('WordPress Settings', 'gendox-wp-ai-agent'),
			null,
			'gendox-ai-chat-wp-settings'
		);

		add_settings_section(
			'gendox_projects_section',
			'',
			array($this, 'gendox_projects_section'),
			'gendox-ai-chat-wp-settings'
		);

		// API Settings section
		register_setting('gendox_api_settings_group', 'gendox_chat_script_url');
		register_setting('gendox_api_settings_group', 'gendox_api_base_url');
		add_settings_section(
			'gendox_api_main_section',
			__('API Settings', 'gendox-wp-ai-agent'),
			array($this, 'api_settings_section_callback'),
			'gendox-ai-chat-api-settings'
		);
		add_settings_field(
			'gendox_chat_script_url',
			__('Chat Script URL', 'gendox-wp-ai-agent'),
			array($this, 'chat_script_url_field_callback'),
			'gendox-ai-chat-api-settings',
			'gendox_api_main_section'
		);
		add_settings_field(
			'gendox_api_base_url',
			__('Gendox API Base URL', 'gendox-wp-ai-agent'),
			array($this, 'api_base_url_field_callback'),
			'gendox-ai-chat-api-settings',
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
		echo '<input type="text" class="form-control" id="gendox_api_key" name="gendox_ai_chat_api_key" value="' . esc_attr($api_key) . '" />';
		echo '<div class="input-group-append">';
		echo '<button type="button" id="test_connection_button" class="btn btn-outline-secondary">Test Connection</button>';
		echo '</div>';
		echo '</div>';
		echo '<span id="connection_status"></span>';
		echo '</div>';
	}

	/**
	 * The content of the settings page with tabs
	 *
	 * @since 1.0.0
	 */
	public function settings_page_content()
	{
		$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'ai-chat-settings';
	?>
		<div class="wrap">
			<h1><?php echo esc_html(__('Gendox AI Chat Settings', 'gendox-wp-ai-agent')); ?></h1>

			<!-- Tabs Navigation -->
			<ul class="nav nav-tabs" id="gendoxSettingsTabs" role="tablist">
				<li class="nav-item">
					<a class="nav-link <?php echo ($active_tab == 'ai-chat-settings') ? 'active' : ''; ?>" href="?page=gendox-ai-chat-settings&tab=ai-chat-settings"><?php _e('AI Chat Settings', 'gendox-wp-ai-agent'); ?></a>
				</li>
				<li class="nav-item">
					<a class="nav-link <?php echo ($active_tab == 'wp-settings') ? 'active' : ''; ?>" href="?page=gendox-ai-chat-settings&tab=wp-settings"><?php _e('WordPress Settings', 'gendox-wp-ai-agent'); ?></a>
				</li>
				<li class="nav-item">
					<a class="nav-link <?php echo ($active_tab == 'api-settings') ? 'active' : ''; ?>" href="?page=gendox-ai-chat-settings&tab=api-settings"><?php _e('API Settings', 'gendox-wp-ai-agent'); ?></a>
				</li>
			</ul>

			<!-- Tab Content -->
			<div class="tab-content" id="gendoxSettingsTabContent">
				<div class="tab-pane fade <?php echo ($active_tab == 'ai-chat-settings') ? 'show active' : ''; ?>" id="ai-chat-settings">
					<form method="post" action="options.php">
						<?php
						settings_fields('gendox_ai_chat_settings_group');
						do_settings_sections('gendox-ai-chat-settings');
						submit_button();
						?>
					</form>

					<!-- Gendox app panel. Sizing/framing lives in backend-styles.css -->
					<div class="gendox-app-frame">
						<?php $chat_script_url = get_option('gendox_chat_script_url', GENDOX_DEFAULT_URL); ?>
						<iframe src="<?php echo esc_url(rtrim($chat_script_url, '/') . '/login-prompt'); ?>" allowfullscreen></iframe>
					</div>
				</div>

				<div class="tab-pane fade <?php echo ($active_tab == 'wp-settings') ? 'show active' : ''; ?>" id="wp-settings">
					<form method="post" action="options.php">
						<?php
						settings_fields('gendox_wp_settings_group');
						do_settings_sections('gendox-ai-chat-wp-settings');
						?>
					</form>
				</div>

				<div class="tab-pane fade <?php echo ($active_tab == 'api-settings') ? 'show active' : ''; ?>" id="api-settings">
					<form method="post" action="options.php">
						<?php
						settings_fields('gendox_api_settings_group');
						do_settings_sections('gendox-ai-chat-api-settings');
						submit_button();
						?>
					</form>
				</div>

			</div>
		</div>
	<?php
	}

	public function gendox_projects_section()
	{

		global $wpdb;
		$table_name = $wpdb->prefix . 'gendox_projects';
		$projects = $wpdb->get_results("SELECT * FROM $table_name");

		echo '<h3>' . __('Gendox Projects', 'gendox-wp-ai-agent') . '</h3>';
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
									<a href="#" class="btn btn-sm btn-warning edit-project" title="Edit"><i class="fas fa-pencil-alt"></i> Assign Content</a>
									<a href="#" class="btn btn-sm btn-success assign-chat" title="Assign Chat" data-project-id="<?php echo esc_attr($project->gendoxId); ?>"><i class="fas fa-eye"></i> Assign Chat</a>
									<a href="#" class="btn btn-sm btn-info view-project" title="View"><i class="fas fa-eye"></i> View</a>
									<a href="#" class="btn btn-sm btn-danger delete-project" title="Delete"><i class="fas fa-trash"></i> Delete</a>
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
			<button type="button" id="fetch_projects_button" class="btn btn-primary">Fetch Projects</button>
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

				// Delete projects from the database that no longer exist in the API response.
				//
				// The stale rows' chat-placement options MUST go with them. Each project can
				// have a `gendox_ai_chat_positions_{gendoxId}` option, written independently by
				// gendox_save_chat_settings(). Deleting only the row leaves that option orphaned
				// forever: add_footer_script_for_chat() still matches on it, finds no row to read
				// organizationId from, and emits the widget with data-organization-id="" - which
				// makes the frontend call /organizations//projects with an empty segment.
				// Nothing else in the plugin ever prunes these, so they accumulate silently.
				if (empty($api_project_ids)) {
					// No projects at all upstream. `IN ()` is a SQL syntax error, so this case
					// has to be handled separately rather than folded into the query below.
					$stale_project_ids = $wpdb->get_col("SELECT gendoxId FROM $table_name");
					$wpdb->query("DELETE FROM $table_name");
				} else {
					$api_project_ids_placeholder = implode(', ', array_fill(0, count($api_project_ids), '%s'));

					// Collect before deleting - afterwards the ids are gone.
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

				// delete_option() rather than a bulk DELETE: these options are autoloaded, and
				// only delete_option() invalidates the `alloptions` object cache. A raw DELETE
				// would keep serving the removed values from cache until the next flush. The
				// loop is bounded by the number of projects removed in this sync - typically
				// zero, occasionally one or two - so the per-call cost is irrelevant here.
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

		// Only allow placement settings for a project that actually exists locally.
		// Without this check the handler stores whatever project_id the browser posts, so a
		// settings page left open across a project deletion (or a stale cached page) can
		// recreate an orphaned gendox_ai_chat_positions_* option that no row backs - the
		// empty-organization-id bug the sync in gendox_fetch_projects() now cleans up.
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
