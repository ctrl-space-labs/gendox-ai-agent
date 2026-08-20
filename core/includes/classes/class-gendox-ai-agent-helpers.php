<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class Gendox_AI_Agent_Helpers
 *
 * This class contains repetitive functions that
 * are used globally within the plugin.
 *
 * @package		GENDOX
 * @subpackage	Classes/Gendox_AI_Agent_Helpers
 * @author		Ctrl+Space Labs
 * @since		1.0.0
 */
class Gendox_AI_Agent_Helpers {

	/**
	 * Updates the integration status via API.
	 *
	 * @param string $status 'ACTIVE' or 'INACTIVE'
	 * @return void
	 */
	public static function update_integration_status( $status ) {
		$api_key = get_option( 'gendox_ai_chat_api_key' );
		if ( ! $api_key ) {
			return;
		}

		$organization_id = self::get_organization_id( $api_key );
		if ( ! $organization_id ) {
			return;
		}

		self::send_integration_status( $api_key, $organization_id, $status );
	}

	/**
	 * Sends an integration status for an explicit key and organization.
	 *
	 * Takes both as arguments so it can act on a key that is not the stored one - the API
	 * key change flow has to reach the outgoing organization before the new key is saved.
	 *
	 * @param string      $api_key
	 * @param string      $organization_id
	 * @param string      $status 'ACTIVE' or 'INACTIVE'
	 * @param string|null $failure_detail Optional. Filled with a redacted reason on failure.
	 * @return bool True when the API accepted the change.
	 */
	public static function send_integration_status( $api_key, $organization_id, $status, &$failure_detail = null ) {
		$api_base_url = get_option( 'gendox_api_base_url', GENDOX_DEFAULT_URL );
		$url          = rtrim( $api_base_url, '/' ) . '/gendox/api/v1/organizations/' . rawurlencode( $organization_id ) . '/websites/integration';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'x-api-key'    => $api_key,
				),
				'body'    => wp_json_encode(
					array(
						'domain'            => site_url(),
						'contextPath'       => '/wp-json/gendox/v1',
						'apiKey'            => array( 'apiKey' => $api_key ),
						'integrationType'   => array( 'name' => 'API_INTEGRATION' ),
						'integrationStatus' => array( 'name' => $status ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$failure_detail = 'transport error: ' . $response->get_error_message();
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body           = self::redact_secrets( (string) wp_remote_retrieve_body( $response ) );
			$failure_detail = sprintf( 'HTTP %d%s', $code, $body !== '' ? ': ' . $body : '' );
			return false;
		}

		$failure_detail = null;
		return true;
	}

	/**
	 * Fetches the organization ID using the API key.
	 *
	 * @param string      $api_key API Key
	 * @param string|null $failure_detail Optional. Filled with a redacted reason on failure.
	 * @return string|null Organization ID or null on failure
	 */
	public static function get_organization_id( $api_key, &$failure_detail = null ) {
		// Create an instance of the settings class
		$settings = new Gendox_AI_Agent_Settings();

		// Call the gendox_get_api_call method
		$response  = $settings->gendox_get_api_call( $api_key );
		$http_code = $response[0];
		$body      = $response[1];

		if ( 200 !== $http_code ) {
			$failure_detail = sprintf(
				'profile HTTP %d%s',
				$http_code,
				$body !== '' ? ': ' . self::redact_secrets( (string) $body ) : ''
			);
			return null;
		}

		$data = json_decode( $body, true );

		if ( ! isset( $data['organizations'][0]['id'] ) ) {
			$failure_detail = 'profile response had no organizations[0].id';
			return null;
		}

		$failure_detail = null;
		return $data['organizations'][0]['id'];
	}

	/**
	 * Writes a diagnostic line to the PHP error log without ever including API keys.
	 *
	 * @param string $message Message; any gxsk-… tokens are redacted before logging.
	 * @return void
	 */
	public static function log_error( $message ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional server-side diagnostic.
		error_log( '[Gendox AI Agent] ' . self::redact_secrets( (string) $message ) );
	}

	/**
	 * Strips API key material from a string before it is logged.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function redact_secrets( $text ) {
		$text = (string) $text;
		// Gendox secret keys (gxsk-…) and any x-api-key style bearer blobs in JSON/text dumps.
		$text = preg_replace( '/gxsk-[A-Za-z0-9]+/', '[REDACTED_API_KEY]', $text );
		$text = preg_replace(
			'/(["\']?(?:api[_-]?key|x-api-key)["\']?\s*[:=]\s*["\']?)[^"\'\s,&}]+/i',
			'$1[REDACTED_API_KEY]',
			$text
		);
		return $text;
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
			'gendox_chat_initial_state'                  => 'closed',
			'gendox_local_context_selected_text_enabled' => 'true',
			'gendox_open_web_page_tool_enabled'          => 'true',
			'gendox_local_context_max_responses'         => '1',
			'gendox_local_context_max_wait_ms'           => '500',
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
