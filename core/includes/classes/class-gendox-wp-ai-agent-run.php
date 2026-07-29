<?php

// Exit if accessed directly.
if (! defined('ABSPATH')) exit;

/**
 * Class Gendox_WP_AI_Agent_Run
 *
 * Thats where we bring the plugin to life
 *
 * @package		GENDOX
 * @subpackage	Classes/Gendox_WP_AI_Agent_Run
 * @author		Ctrl+Space Labs
 * @since		1.0.0
 */
class Gendox_WP_AI_Agent_Run
{

	/**
	 * Our Gendox_WP_AI_Agent_Run constructor 
	 * to run the plugin logic.
	 *
	 * @since 1.0.0
	 */
	function __construct()
	{
		$this->add_hooks();
	}

	/**
	 * ######################
	 * ###
	 * #### WORDPRESS HOOKS
	 * ###
	 * ######################
	 */

	/**
	 * Registers all WordPress and plugin related hooks
	 *
	 * @access	private
	 * @since	1.0.0
	 * @return	void
	 */
	private function add_hooks()
	{

		add_action('plugin_action_links_' . GENDOX_PLUGIN_BASE, array($this, 'add_plugin_action_link'), 20);
		add_action('admin_enqueue_scripts', array($this, 'enqueue_backend_scripts_and_styles'), 20);
		add_action('admin_bar_menu', array($this, 'add_admin_bar_menu_items'), 100, 1);

		// Add the admin scripts
		add_action('admin_enqueue_scripts', array($this, 'gendox_enqueue_admin_scripts'));

		// Add metabox for product assignment
		add_action('add_meta_boxes', array($this, 'add_project_metabox'));
		add_action('save_post', array($this, 'save_project_assignment'));
		// Add Quick Edit functionality
		add_filter('manage_edit-product_columns', array($this, 'add_project_column'));
		add_filter('manage_edit-post_columns', array($this, 'add_project_column'));
		add_filter('manage_edit-page_columns', array($this, 'add_project_column'));

		add_action('manage_product_posts_custom_column', array($this, 'show_assigned_project_in_column'), 10, 2);
		add_action('manage_post_posts_custom_column', array($this, 'show_assigned_project_in_column'), 10, 2);
		add_action('manage_page_posts_custom_column', array($this, 'show_assigned_project_in_column'), 10, 2);

		add_action('quick_edit_custom_box', array($this, 'display_quick_edit_project_box'), 10, 2);
		add_action('save_post', array($this, 'save_project_quick_edit'));
		// Add custom bulk actions
		add_filter('bulk_actions-edit-product', array($this, 'add_custom_bulk_actions'));
		add_filter('bulk_actions-edit-post', array($this, 'add_custom_bulk_actions'));
		add_filter('bulk_actions-edit-page', array($this, 'add_custom_bulk_actions'));

		add_filter('handle_bulk_actions-edit-product', array($this, 'handle_custom_bulk_actions'), 10, 3);
		add_filter('handle_bulk_actions-edit-post', array($this, 'handle_custom_bulk_actions'), 10, 3);
		add_filter('handle_bulk_actions-edit-page', array($this, 'handle_custom_bulk_actions'), 10, 3);

		add_action('admin_notices', array($this, 'custom_bulk_action_admin_notice'));

		// Add footer script for chat
		add_action('wp_footer', array($this, 'add_footer_script_for_chat'));
	}

	/**
	 * ######################
	 * ###
	 * #### WORDPRESS HOOK CALLBACKS
	 * ###
	 * ######################
	 */

	/**
	 * Adds action links to the plugin list table
	 *
	 * @access	public
	 * @since	1.0.0
	 *
	 * @param	array	$links An array of plugin action links.
	 *
	 * @return	array	An array of plugin action links.
	 */
	public function add_plugin_action_link($links)
	{
		// Add the custom settings link
		$settings_link = sprintf(
			'<a href="%s" title="%s">%s</a>',
			admin_url('options-general.php?page=gendox-ai-chat-settings'),
			__('Settings', 'gendox-wp-ai-agent'),
			__('Settings', 'gendox-wp-ai-agent')
		);

		// Add it to the links array
		array_unshift($links, $settings_link);

		return $links;
	}

	/**
	 * Enqueue the backend related scripts and styles for this plugin.
	 * All of the added scripts andstyles will be available on every page within the backend.
	 *
	 * @access	public
	 * @since	1.0.0
	 *
	 * @return	void
	 */
	public function enqueue_backend_scripts_and_styles()
	{
		// GENDOX_VERSION is the cache-buster; do not append a random query string, it
		// would defeat browser caching on every page load.
		wp_enqueue_style('gendox-backend-styles', GENDOX_PLUGIN_URL . 'core/includes/assets/css/backend-styles.css', array(), GENDOX_VERSION, 'all');
		wp_enqueue_script('gendox-backend-scripts', GENDOX_PLUGIN_URL . 'core/includes/assets/js/backend-scripts.js', array(), GENDOX_VERSION, false);
		wp_localize_script('gendox-backend-scripts', 'gendox', array(
			'plugin_name'   	=> __(GENDOX_NAME, 'gendox-wp-ai-agent'),
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('gendox_nonce')
		));

		// enqueue select2 (bundled locally - wordpress.org does not allow loading
		// executable assets from third-party CDNs)
		wp_enqueue_style('select2', GENDOX_PLUGIN_URL . 'core/includes/assets/vendor/select2/select2.min.css', array(), '4.1.0-rc.0');
		wp_enqueue_script('select2', GENDOX_PLUGIN_URL . 'core/includes/assets/vendor/select2/select2.min.js', array('jquery'), '4.1.0-rc.0', true);
	}

	/**
	 * Add a new menu item to the WordPress topbar
	 *
	 * @access	public
	 * @since	1.0.0
	 *
	 * @param	object $admin_bar The WP_Admin_Bar object
	 *
	 * @return	void
	 */
	public function add_admin_bar_menu_items($admin_bar)
	{
		// Main menu item
		$admin_bar->add_menu(array(
			'id'    => 'gendox-wp-ai-agent-id',
			'title' => __('Gendox AI Chat Settings', 'gendox-wp-ai-agent'),
			'href'  => admin_url('options-general.php?page=gendox-ai-chat-settings'), // Link to the main settings page
			'meta'  => array(
				'title' => __('Gendox AI Chat Settings', 'gendox-wp-ai-agent'),
				'class' => 'gendox-wp-ai-agent-class',
			),
		));

		// Sub-menu items for each tab
		$tabs = [
			'ai-chat-settings' => __('AI Chat Settings', 'gendox-wp-ai-agent'),
			'wp-settings'      => __('WordPress Settings', 'gendox-wp-ai-agent'),
			'api-settings'     => __('API Settings', 'gendox-wp-ai-agent'),
		];

		foreach ($tabs as $tab_id => $tab_title) {
			$admin_bar->add_menu(array(
				'id'     => 'gendox-wp-ai-agent-' . $tab_id,
				'title'  => $tab_title,
				'parent' => 'gendox-wp-ai-agent-id',
				'href'   => admin_url('options-general.php?page=gendox-ai-chat-settings&tab=' . $tab_id), // Link to each tab
				'meta'   => array(
					'title' => $tab_title,
					'class' => 'gendox-wp-ai-agent-sub-class',
				),
			));
		}
	}


	public function gendox_enqueue_admin_scripts($hook_suffix)
	{
		// Check if we're on the Gendox AI Chat settings page
		$screen = get_current_screen();
		
		// Debug: Add screen ID to check what it actually is
		// error_log('Current screen ID: ' . $screen->id);
		
		// Check for both possible screen IDs - main menu page and settings submenu page
		if ($screen->id === 'settings_page_gendox-ai-chat-settings' || 
		    $screen->id === 'toplevel_page_gendox-ai-chat-settings' ||
		    strpos($screen->id, 'gendox-ai-chat-settings') !== false) {
			// Enqueue Bootstrap only on this page. Bundled locally - wordpress.org does
			// not allow loading executable assets from third-party CDNs.
			wp_enqueue_style('bootstrap-css', GENDOX_PLUGIN_URL . 'core/includes/assets/vendor/bootstrap/bootstrap.min.css', array(), '4.5.2');
			wp_enqueue_script('bootstrap-js', GENDOX_PLUGIN_URL . 'core/includes/assets/vendor/bootstrap/bootstrap.bundle.min.js', array('jquery'), '4.5.2', true);
		}
	}

	// 1. Add Project Metabox to Product Edit Page
	public function add_project_metabox()
	{
		$post_types = ['product', 'post', 'page'];
		foreach ($post_types as $post_type) {
			add_meta_box(
				'project_assignment_metabox',
				__('Assign Project', 'gendox-wp-ai-agent'),
				array($this, 'render_project_metabox'),
				$post_type,
				'side',
				'default'
			);
		}
	}

	// Render the Metabox
	public function render_project_metabox($post)
	{
		// Get all projects
		global $wpdb;
		$table_name = $wpdb->prefix . 'gendox_projects';
		$projects = $wpdb->get_results("SELECT * FROM $table_name");

		// Retrieve the current project assignment from the `postIds` field in the custom table
		$post_id = $post->ID;
		$assigned_projects = [];

		foreach ($projects as $project) {
			$assigned_items = maybe_unserialize($project->postIds);
			$post_type = get_post_type($post_id);
			$item_key = ($post_type === 'product') ? 'products' : ($post_type === 'page' ? 'pages' : 'posts');

			if (isset($assigned_items[$item_key]) && in_array($post_id, $assigned_items[$item_key])) {
				$assigned_projects[] = $project->gendoxId;
			}
		}

		// Always posted with the classic editor form so save_project_assignment can tell a
		// real metabox save from Avada / Live Builder / REST saves that omit the checkboxes.
		// Without this, a missing assigned_projects[] is indistinguishable from "uncheck all".
		wp_nonce_field('gendox_project_assignment', 'gendox_project_assignment_nonce');
		echo '<input type="hidden" name="gendox_project_assignment_submitted" value="1" />';

		echo '<label>' . __('Select Projects:', 'gendox-wp-ai-agent') . '</label><br>';

		// Loop through projects and create checkboxes
		foreach ($projects as $project) {
			$is_checked = in_array($project->gendoxId, $assigned_projects) ? 'checked' : '';
			echo '<label>';
			echo '<input type="checkbox" name="assigned_projects[]" value="' . esc_attr($project->gendoxId) . '" ' . $is_checked . '>';
			echo esc_html($project->name);
			echo '</label><br>';
		}
	}

	// Save the Project Assignment
	public function save_project_assignment($post_id)
	{
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (wp_is_post_revision($post_id)) {
			return;
		}

		// Only act when the classic-editor metabox was part of this save. Avada (and other
		// builders) fire save_post without our fields; treating that as an empty selection
		// would wipe the page/post from every project's assigned content.
		if (empty($_POST['gendox_project_assignment_submitted'])) {
			return;
		}

		if (
			empty($_POST['gendox_project_assignment_nonce']) ||
			!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['gendox_project_assignment_nonce'])), 'gendox_project_assignment')
		) {
			return;
		}

		$post_type = get_post_type($post_id);
		if (!in_array($post_type, ['product', 'post', 'page'], true)) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'gendox_projects';
		$assigned_projects = isset($_POST['assigned_projects']) ? (array) $_POST['assigned_projects'] : [];
		$assigned_projects = array_map('sanitize_text_field', $assigned_projects);
		$all_projects = $wpdb->get_results("SELECT gendoxId, postIds FROM $table_name");
		$item_key = ($post_type === 'product') ? 'products' : ($post_type === 'page' ? 'pages' : 'posts');
		$post_id = (int) $post_id;

		foreach ($all_projects as $project) {
			$project_id = $project->gendoxId;
			$assigned_items = maybe_unserialize($project->postIds) ?: ['products' => [], 'posts' => [], 'pages' => []];
			if (!isset($assigned_items[$item_key]) || !is_array($assigned_items[$item_key])) {
				$assigned_items[$item_key] = [];
			}

			if (in_array($project_id, $assigned_projects, true)) {
				if (!in_array($post_id, array_map('intval', $assigned_items[$item_key]), true)) {
					$assigned_items[$item_key][] = $post_id;
				}
			} else {
				$assigned_items[$item_key] = array_values(array_filter(
					$assigned_items[$item_key],
					function ($id) use ($post_id) {
						return (int) $id !== $post_id;
					}
				));
			}

			$wpdb->update(
				$table_name,
				['postIds' => maybe_serialize($assigned_items)],
				['gendoxId' => $project_id],
				['%s'],
				['%s']
			);
		}
	}

	// 2. Add Project Assignment in Quick Edit
	public function add_project_column($columns)
	{
		$columns['assigned_project'] = __('Assigned Project', 'gendox-wp-ai-agent');
		return $columns;
	}

	public function show_assigned_project_in_column($column, $post_id)
	{
		if ($column === 'assigned_project') {
			global $wpdb;
			$table_name = $wpdb->prefix . 'gendox_projects';
			$projects = $wpdb->get_results("SELECT * FROM $table_name");

			$assigned_projects = [];
			foreach ($projects as $project) {
				$assigned_items = maybe_unserialize($project->postIds);
				$post_type = get_post_type($post_id);
				$item_key = ($post_type === 'product') ? 'products' : ($post_type === 'page' ? 'pages' : 'posts');

				if (isset($assigned_items[$item_key]) && in_array($post_id, $assigned_items[$item_key])) {
					$assigned_projects[] = array(
						'name' => $project->name,
						'id' => $project->gendoxId
					);
				}
			}
			if (!empty($assigned_projects)) {
				foreach ($assigned_projects as $project) {
					echo '<span class="assigned-project" data-gendoxId="' . esc_attr($project['id']) . '">' . esc_html($project['name']) . '</span><br>';
				}
			} else {
				echo __('-', 'gendox-wp-ai-agent');
			}
		}
	}

	// Display Project Quick Edit Box with checkboxes
	public function display_quick_edit_project_box($column_name, $post_type)
	{
		if ($column_name === 'assigned_project') {
			global $wpdb;
			$table_name = $wpdb->prefix . 'gendox_projects';
			$projects = $wpdb->get_results("SELECT * FROM $table_name");

			echo '<fieldset class="inline-edit-col-right">';
			echo '<div class="inline-edit-col">';
			echo '<input type="hidden" name="gendox_project_quick_edit_submitted" value="1" />';
			echo '<label><p class="title"><strong>' . __('Assigned Projects', 'gendox-wp-ai-agent') . '</strong></p><div class="checkbox-group">';

			foreach ($projects as $project) {
				echo '<label class="inline-edit-project-label">';
				echo '<input type="checkbox" name="assigned_projects[]" value="' . esc_attr($project->gendoxId) . '"> ';
				echo esc_html($project->name);
				echo '</label>';
			}

			echo '</div></label></div></fieldset>';
		}
	}

	// Save Project in Quick Edit
	public function save_project_quick_edit($post_id)
	{
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (wp_is_post_revision($post_id)) {
			return;
		}

		// Same trap as the metabox: a normal editor save (or Avada) does not include
		// quick-edit fields. Missing assigned_projects[] must not mean "remove from all".
		if (empty($_POST['gendox_project_quick_edit_submitted'])) {
			return;
		}

		$post_type = get_post_type($post_id);
		if (!in_array($post_type, ['product', 'post', 'page'], true)) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'gendox_projects';
		$assigned_projects = isset($_POST['assigned_projects']) ? (array) $_POST['assigned_projects'] : [];
		$assigned_projects = array_map('sanitize_text_field', $assigned_projects);
		$all_projects = $wpdb->get_results("SELECT gendoxId, postIds FROM $table_name");
		$item_key = ($post_type === 'product') ? 'products' : ($post_type === 'page' ? 'pages' : 'posts');
		$post_id = (int) $post_id;

		foreach ($all_projects as $project) {
			$project_id = $project->gendoxId;
			$assigned_items = maybe_unserialize($project->postIds) ?: ['products' => [], 'posts' => [], 'pages' => []];
			if (!isset($assigned_items[$item_key]) || !is_array($assigned_items[$item_key])) {
				$assigned_items[$item_key] = [];
			}

			if (in_array($project_id, $assigned_projects, true)) {
				if (!in_array($post_id, array_map('intval', $assigned_items[$item_key]), true)) {
					$assigned_items[$item_key][] = $post_id;
				}
			} else {
				$assigned_items[$item_key] = array_values(array_filter(
					$assigned_items[$item_key],
					function ($id) use ($post_id) {
						return (int) $id !== $post_id;
					}
				));
			}

			$wpdb->update(
				$table_name,
				['postIds' => maybe_serialize($assigned_items)],
				['gendoxId' => $project_id],
				['%s'],
				['%s']
			);
		}
	}

	public function add_custom_bulk_actions($bulk_actions)
	{
		global $wpdb;
		$table_name = $wpdb->prefix . 'gendox_projects';
		$projects = $wpdb->get_results("SELECT * FROM $table_name");

		// Add Assign and Remove actions for each project
		foreach ($projects as $project) {
			$bulk_actions['assign_to_project_' . $project->gendoxId] = sprintf(__('Assign to Project: %s', 'gendox-wp-ai-agent'), $project->name);
			$bulk_actions['remove_from_project_' . $project->gendoxId] = sprintf(__('Remove from Project: %s', 'gendox-wp-ai-agent'), $project->name);
		}

		return $bulk_actions;
	}

	public function handle_custom_bulk_actions($redirect_url, $action, $post_ids)
	{
		global $wpdb;
		$table_name = $wpdb->prefix . 'gendox_projects';

		if (strpos($action, 'assign_to_project_') === 0 || strpos($action, 'remove_from_project_') === 0) {
			$project_id = (int)str_replace(['assign_to_project_', 'remove_from_project_'], '', $action);
			$is_assign_action = strpos($action, 'assign_to_project_') === 0;

			$project = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE gendoxId = %d", $project_id));
			if ($project) {
				$assigned_items = maybe_unserialize($project->postIds) ?: ['products' => [], 'posts' => [], 'pages' => []];

				foreach ($post_ids as $post_id) {
					$post_type = get_post_type($post_id);

					// Assign or remove based on post type
					$item_key = $post_type === 'product' ? 'products' : ($post_type === 'page' ? 'pages' : 'posts');

					if ($is_assign_action) {
						if (!in_array($post_id, $assigned_items[$item_key])) {
							$assigned_items[$item_key][] = $post_id;
						}
					} else {
						$assigned_items[$item_key] = array_filter($assigned_items[$item_key], function ($id) use ($post_id) {
							return $id !== $post_id;
						});
						$assigned_items[$item_key] = array_values($assigned_items[$item_key]);
					}
				}

				// Update the project in the database
				$wpdb->update(
					$table_name,
					['postIds' => maybe_serialize($assigned_items)],
					['gendoxId' => $project_id],
					['%s'],
					['%d']
				);
			}

			$redirect_url = add_query_arg('bulk_assigned_to_project', count($post_ids), $redirect_url);
		}

		return $redirect_url;
	}

	public function custom_bulk_action_admin_notice()
	{
		if (!empty($_REQUEST['bulk_assigned_to_project'])) {
			$count = intval($_REQUEST['bulk_assigned_to_project']);
			$screen = get_current_screen();
			$post_type = $screen->post_type;
			$message = _n('%s item updated.', '%s items updated.', $count, 'gendox-wp-ai-agent');
			printf('<div id="message" class="updated notice is-dismissible"><p>' . sprintf($message, $count) . '</p></div>');
		}
	}

	// Add script to the footer
	public function add_footer_script_for_chat()
	{
		// Get the current post ID and post type
		$page_id = get_the_ID();
		$post_type = get_post_type($page_id);

		// Initialize variables
		$project_id = null;
		$organization_id = ""; // Replace with your actual organization ID

		// Fetch all project settings from the options table
		global $wpdb;
		$all_options = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'gendox_ai_chat_positions_%'");

		foreach ($all_options as $option) {
			// Retrieve each project’s settings
			$settings = maybe_unserialize($option->option_value);

			// Check if the post type matches
			$post_type_match = in_array($post_type, $settings['post_type']);

			// Check if any of the taxonomy terms match
			$taxonomy_match = false;
			foreach ($settings['taxonomies'] as $taxonomy => $term_ids) {
				$page_terms = wp_get_post_terms($page_id, $taxonomy, ['fields' => 'ids']);
				if (!empty(array_intersect($term_ids, $page_terms))) {
					$taxonomy_match = true;
					break; // Stop checking further taxonomies if a match is found
				}
			}

			// If either post type or taxonomy matches, select this project ID
			if ($post_type_match || $taxonomy_match) {
				// Extract project ID from the option name
				$project_id = str_replace('gendox_ai_chat_positions_', '', $option->option_name);
				break; // Exit loop once a matching project ID is found
			}
		}

		// Only output the script if a matching project ID was found
		if ($project_id) {
			// get the project organization id from the project id from table gendox_projects
			$table_name = $wpdb->prefix . 'gendox_projects';
			$project = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM $table_name WHERE gendoxId = %s", $project_id )
			);
			if ($project) {
				$organization_id = $project->organizationId;
			}
			$chat_script_url = get_option('gendox_chat_script_url', GENDOX_DEFAULT_URL);
			$widget_options  = Gendox_WP_AI_Agent_Helpers::get_widget_script_options();
?>
			<script
				id="gendox-chat-script"
				src="<?php echo esc_url(rtrim($chat_script_url, '/') . '/gendox-sdk/gendox-widget-plugin.js'); ?>"
				data-gendox-src="<?php echo esc_url($chat_script_url); ?>"
				data-organization-id="<?php echo esc_attr($organization_id); ?>"
				data-project-id="<?php echo esc_attr($project_id); ?>"
				data-gendox-chat-initial-state="<?php echo esc_attr($widget_options['gendox_chat_initial_state']); ?>"
				data-gendox-local-context-selected-text-enabled="<?php echo esc_attr($widget_options['gendox_local_context_selected_text_enabled']); ?>"
				data-gendox-open-web-page-tool-enabled="<?php echo esc_attr($widget_options['gendox_open_web_page_tool_enabled']); ?>"
				data-gendox-local-context-max-responses="<?php echo esc_attr($widget_options['gendox_local_context_max_responses']); ?>"
				data-gendox-local-context-max-wait-ms="<?php echo esc_attr($widget_options['gendox_local_context_max_wait_ms']); ?>">
			</script>
<?php
		}
	}
}
