<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class Gendox_WP_AI_Agent_Helpers
 *
 * This class contains repetitive functions that
 * are used globally within the plugin.
 *
 * @package		GENDOX
 * @subpackage	Classes/Gendox_WP_AI_Agent_Helpers
 * @author		Ctrl+Space Labs
 * @since		1.0.0
 */
class Gendox_WP_AI_Agent_Helpers {

	/**
     * Updates the integration status via API.
     * 
     * @param string $status 'ACTIVE' or 'INACTIVE'
     * @return void
     */
	public static function update_integration_status($status) {
		$api_key = get_option('gendox_ai_chat_api_key'); // Replace with your option key
		if (!$api_key) {
			error_log('Gendox API Key is missing.');
			return;
		}

		$organization_id = self::get_organization_id($api_key);
		if (!$organization_id) {
			error_log('Failed to retrieve organization ID.');
			return;
		}

		self::send_integration_status($api_key, $organization_id, $status);
	}

	/**
	 * Sends an integration status for an explicit key and organization.
	 *
	 * Takes both as arguments so it can act on a key that is not the stored one - the API
	 * key change flow has to reach the outgoing organization before the new key is saved.
	 *
	 * @param string $api_key
	 * @param string $organization_id
	 * @param string $status 'ACTIVE' or 'INACTIVE'
	 * @return bool True when the API accepted the change.
	 */
	public static function send_integration_status($api_key, $organization_id, $status) {
		$api_base_url = get_option('gendox_api_base_url', GENDOX_DEFAULT_URL);
		$url = rtrim($api_base_url, '/') . "/gendox/api/v1/organizations/$organization_id/websites/integration";

		$body = json_encode([
			'domain' => site_url(),
			'contextPath' => '/wp-json/gendox/v1',
			'apiKey' => ['apiKey' => $api_key],
			'integrationType' => ['name' => 'API_INTEGRATION'],
			'integrationStatus' => ['name' => $status],
		]);

		$curl = curl_init();

		curl_setopt_array($curl, [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 15,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'x-api-key: ' . $api_key,
			],
			CURLOPT_SSL_VERIFYHOST => true,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2, // Enforce modern TLS
		]);

		curl_exec($curl);
		$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		if (curl_errno($curl)) {
			error_log('Failed to update integration status: ' . curl_error($curl));
			curl_close($curl);
			return false;
		}

		curl_close($curl);

		if ($http_code !== 200) {
			error_log("API request failed with status code: $http_code");
			return false;
		}

		return true;
	}

	/**
     * Fetches the organization ID using the API key.
     * 
     * @param string $api_key API Key
     * @return string|null Organization ID or null on failure
     */
	public static function get_organization_id($api_key) {
		// Create an instance of the settings class
		$settings = new Gendox_WP_AI_Agent_Settings();

		// Call the gendox_get_api_call method
		$response = $settings->gendox_get_api_call($api_key);
		$http_code = $response[0];
		$body = $response[1];

		if ($http_code !== 200) {
			error_log("Failed to validate API key: HTTP Status $http_code");
			return null;
		}

		$data = json_decode($body, true);

		if (!isset($data['organizations'][0]['id'])) {
			error_log('Organization ID not found in /profile response.');
			return null;
		}

		return $data['organizations'][0]['id'];
	}

	/**
	 * Defaults for the chat widget <script> data-* attributes.
	 *
	 * Matches the Gendox SDK's documented defaults so a fresh install emits the same
	 * attributes as the reference embed snippet.
	 *
	 * @return array<string, string>
	 */
	public static function get_widget_script_defaults() {
		return array(
			'gendox_chat_initial_state'                      => 'closed',
			'gendox_local_context_selected_text_enabled'     => 'true',
			'gendox_open_web_page_tool_enabled'              => 'true',
			'gendox_local_context_max_responses'             => '1',
			'gendox_local_context_max_wait_ms'               => '500',
		);
	}

	/**
	 * Stored widget script options, with SDK defaults applied when unset.
	 *
	 * @return array<string, string>
	 */
	public static function get_widget_script_options() {
		$options = array();
		foreach ( self::get_widget_script_defaults() as $option => $default ) {
			$options[ $option ] = (string) get_option( $option, $default );
		}
		return $options;
	}

}
