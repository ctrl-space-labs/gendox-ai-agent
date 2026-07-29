<?php

// Exit if accessed directly.
if (! defined('ABSPATH')) exit;
if (! class_exists('Gendox_AI_Agent')) :

	/**
	 * Main Gendox_AI_Agent Class.
	 *
	 * @package		GENDOX
	 * @subpackage	Classes/Gendox_AI_Agent
	 * @since		1.0.0
	 * @author		Ctrl+Space Labs
	 */
	final class Gendox_AI_Agent
	{

		/**
		 * The real instance
		 *
		 * @access	private
		 * @since	1.0.0
		 * @var		object|Gendox_AI_Agent
		 */
		private static $instance;

		/**
		 * GENDOX helpers object.
		 *
		 * @access	public
		 * @since	1.0.0
		 * @var		object|Gendox_AI_Agent_Helpers
		 */
		public $helpers;

		/**
		 * GENDOX settings object.
		 *
		 * @access	public
		 * @since	1.0.0
		 * @var		object|Gendox_AI_Agent_Settings
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
			_doing_it_wrong(__FUNCTION__, esc_html__('You are not allowed to clone this class.', 'gendox-ai-agent'), '1.0.0');
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
			_doing_it_wrong(__FUNCTION__, esc_html__('You are not allowed to unserialize this class.', 'gendox-ai-agent'), '1.0.0');
		}

		/**
		 * Main Gendox_AI_Agent Instance.
		 *
		 * Insures that only one instance of Gendox_AI_Agent exists in memory at any one
		 * time. Also prevents needing to define globals all over the place.
		 *
		 * @access		public
		 * @since		1.0.0
		 * @static
		 * @return		object|Gendox_AI_Agent	The one true Gendox_AI_Agent
		 */
		public static function instance()
		{
			if (! isset(self::$instance) && ! (self::$instance instanceof Gendox_AI_Agent)) {
				self::$instance					= new Gendox_AI_Agent;
				self::$instance->base_hooks();
				self::$instance->includes();
				self::$instance->helpers		= new Gendox_AI_Agent_Helpers();
				self::$instance->settings		= new Gendox_AI_Agent_Settings();

				//Fire the plugin logic
				new Gendox_AI_Agent_Run();

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
			require_once GENDOX_PLUGIN_DIR . 'core/includes/classes/class-gendox-ai-agent-helpers.php';
			require_once GENDOX_PLUGIN_DIR . 'core/includes/classes/class-gendox-ai-agent-settings.php';

			require_once GENDOX_PLUGIN_DIR . 'core/includes/classes/class-gendox-ai-agent-run.php';
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
			// Translations for wordpress.org-hosted plugins are loaded automatically since
			// WordPress 4.6; load_plugin_textdomain() is no longer required.
		}
	}

endif; // End if class_exists check.