<?php

/**
 * Gendox AI Chat for Wordpress
 *
 * @package       GENDOX
 * @author        Ctrl+Space Labs
 * @license       gplv2
 * @version       1.0.0
 *
 * @wordpress-plugin
 * Plugin Name:   Gendox AI Chat for Wordpress
 * Plugin URI:    https://gendox.dev
 * Description:   This is some demo short description...
 * Version:       1.0.0
 * Author:        Ctrl+Space Labs
 * Author URI:    https://www.ctrlspace.dev/
 * Text Domain:   gendox-ai-chat-for-wordpress
 * Domain Path:   /languages
 * License:       GPLv2
 * License URI:   https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) exit;

// Plugin name
define('GENDOX_NAME', 'Gendox AI Chat for Wordpress');

// Plugin version
define('GENDOX_VERSION', '1.0.0');

// Plugin Root File
define('GENDOX_PLUGIN_FILE', __FILE__);

// Plugin base
define('GENDOX_PLUGIN_BASE', plugin_basename(GENDOX_PLUGIN_FILE));

// Plugin Folder Path
define('GENDOX_PLUGIN_DIR', plugin_dir_path(GENDOX_PLUGIN_FILE));

// Plugin Folder URL
define('GENDOX_PLUGIN_URL', plugin_dir_url(GENDOX_PLUGIN_FILE));

/**
 * Load the main class for the core functionality
 */
require_once GENDOX_PLUGIN_DIR . 'core/class-gendox-ai-chat-for-wordpress.php';

/**
 * The main function to load the only instance
 * of our master class.
 *
 * @author  Ctrl+Space Labs
 * @since   1.0.0
 * @return  object|Gendox_Ai_Chat_For_Wordpress
 */
function GENDOX()
{
	return Gendox_Ai_Chat_For_Wordpress::instance();
}

GENDOX();

// Hook for plugin activation
register_activation_hook(__FILE__, 'gendox_create_projects_table');

/**
 * Function to create gendox_projects table on plugin activation
 */
function gendox_create_projects_table()
{
	global $wpdb;

	$table_name = $wpdb->prefix . 'gendox_projects'; // Full table name with prefix

	// Charset and collation setup
	$charset_collate = $wpdb->get_charset_collate();

	// SQL to create the table
	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
		organizationId varchar(255) NOT NULL,
        gendoxId varchar(255) NOT NULL,
        name varchar(255) NOT NULL,
        description text,
        postIds longtext,
        PRIMARY KEY  (id)
    ) $charset_collate;";

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	dbDelta($sql);
}

register_activation_hook(__FILE__, 'gendox_activate_plugin');
register_deactivation_hook(__FILE__, 'gendox_deactivate_plugin');

function gendox_activate_plugin() {
    Gendox_Ai_Chat_For_Wordpress_Helpers::update_integration_status('ACTIVE');
}

function gendox_deactivate_plugin() {
    Gendox_Ai_Chat_For_Wordpress_Helpers::update_integration_status('INACTIVE');
}
