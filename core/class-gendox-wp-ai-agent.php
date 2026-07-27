<?php

// Exit if accessed directly.
if (! defined('ABSPATH')) exit;
if (! class_exists('Gendox_WP_AI_Agent')) :

	/**
	 * Main Gendox_WP_AI_Agent Class.
	 *
	 * @package		GENDOX
	 * @subpackage	Classes/Gendox_WP_AI_Agent
	 * @since		1.0.0
	 * @author		Ctrl+Space Labs
	 */
	final class Gendox_WP_AI_Agent
	{

		/**
		 * The real instance
		 *
		 * @access	private
		 * @since	1.0.0
		 * @var		object|Gendox_WP_AI_Agent
		 */
		private static $instance;

		/**
		 * GENDOX helpers object.
		 *
		 * @access	public
		 * @since	1.0.0
		 * @var		object|Gendox_WP_AI_Agent_Helpers
		 */
		public $helpers;

		/**
		 * GENDOX settings object.
		 *
		 * @access	public
		 * @since	1.0.0
		 * @var		object|Gendox_WP_AI_Agent_Settings
		 */
		public $settings;

		/**
		 * Throw error on object clone.
		 *
		 * Cloning instances of the class is forbidden.
		 *
		 * @access	public
		 * @since	1.0.0
		 * @return	void
		 */
		public function __clone()
		{
			_doing_it_wrong(__FUNCTION__, __('You are not allowed to clone this class.', 'gendox-wp-ai-agent'), '1.0.0');
		}

		/**
		 * Disable unserializing of the class.
		 *
		 * @access	public
		 * @since	1.0.0
		 * @return	void
		 */
		public function __wakeup()
		{
			_doing_it_wrong(__FUNCTION__, __('You are not allowed to unserialize this class.', 'gendox-wp-ai-agent'), '1.0.0');
		}

		/**
		 * Main Gendox_WP_AI_Agent Instance.
		 *
		 * Insures that only one instance of Gendox_WP_AI_Agent exists in memory at any one
		 * time. Also prevents needing to define globals all over the place.
		 *
		 * @access		public
		 * @since		1.0.0
		 * @static
		 * @return		object|Gendox_WP_AI_Agent	The one true Gendox_WP_AI_Agent
		 */
		public static function instance()
		{
			if (! isset(self::$instance) && ! (self::$instance instanceof Gendox_WP_AI_Agent)) {
				self::$instance					= new Gendox_WP_AI_Agent;
				self::$instance->base_hooks();
				self::$instance->includes();
				self::$instance->helpers		= new Gendox_WP_AI_Agent_Helpers();
				self::$instance->settings		= new Gendox_WP_AI_Agent_Settings();

				//Fire the plugin logic
				new Gendox_WP_AI_Agent_Run();

				//Load the api endpoints
				new Gendox_API_Endpoints();

				/**
				 * Fire a custom action to allow dependencies
				 * after the successful plugin setup
				 */
				do_action('GENDOX/plugin_loaded');
			}

			return self::$instance;
		}

		/**
		 * Include required files.
		 *
		 * @access  private
		 * @since   1.0.0
		 * @return  void
		 */
		private function includes()
		{
			require_once GENDOX_PLUGIN_DIR . 'core/includes/classes/class-gendox-wp-ai-agent-helpers.php';
			require_once GENDOX_PLUGIN_DIR . 'core/includes/classes/class-gendox-wp-ai-agent-settings.php';

			require_once GENDOX_PLUGIN_DIR . 'core/includes/classes/class-gendox-wp-ai-agent-run.php';
			require_once GENDOX_PLUGIN_DIR . 'core/includes/classes/class-gendox-api-endpoints.php';
		}

		/**
		 * Add base hooks for the core functionality
		 *
		 * @access  private
		 * @since   1.0.0
		 * @return  void
		 */
		private function base_hooks()
		{
			add_action('plugins_loaded', array(self::$instance, 'load_textdomain'));
		}

		/**
		 * Loads the plugin language files.
		 *
		 * @access  public
		 * @since   1.0.0
		 * @return  void
		 */
		public function load_textdomain()
		{
			load_plugin_textdomain('gendox-wp-ai-agent', FALSE, dirname(plugin_basename(GENDOX_PLUGIN_FILE)) . '/languages/');
		}
	}

endif; // End if class_exists check.