<?php
/**
 * Uninstall Gendox WP AI Agent.
 *
 * Runs on plugin deletion only. No plugin code, constant or class is loaded here.
 *
 * @package		GENDOX
 * @author		Ctrl+Space Labs
 * @since		1.0.4
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Options owned by this plugin.
 *
 * Explicit list, not a `LIKE 'gendox%'` sweep: sibling plugins share the prefix
 * (`gendox_checkout_validator_*`, `gendox_client_id`, `gendox_stripe_*`, `gendox_auth_url`,
 * `gendox_api_url`) and a sweep would delete their settings too.
 */
const GENDOX_UNINSTALL_OPTIONS = array(
	'gendox_ai_chat_api_key',
	'gendox_api_base_url',
	'gendox_chat_script_url',
);

/**
 * Report this site's integration as inactive.
 *
 * Self-contained: loading the Settings class here would run its constructor and register
 * admin hooks for the sake of one HTTP call.
 *
 * @return void
 */
function gendox_uninstall_notify_backend() {
	$api_key = get_option( 'gendox_ai_chat_api_key' );
	if ( empty( $api_key ) ) {
		return;
	}

	// Mirrors GENDOX_DEFAULT_URL, which is not loaded here.
	$api_base_url = rtrim( get_option( 'gendox_api_base_url', 'https://app.gendox.dev' ), '/' );

	$profile = wp_remote_get(
		$api_base_url . '/gendox/api/v1/profile',
		array(
			'timeout' => 15,
			'headers' => array( 'x-api-key' => $api_key ),
		)
	);

	if ( is_wp_error( $profile ) || 200 !== wp_remote_retrieve_response_code( $profile ) ) {
		return; // Never block the uninstall on a failed call.
	}

	$data = json_decode( wp_remote_retrieve_body( $profile ), true );
	if ( ! isset( $data['organizations'][0]['id'] ) ) {
		return;
	}

	wp_remote_post(
		$api_base_url . '/gendox/api/v1/organizations/' . rawurlencode( $data['organizations'][0]['id'] ) . '/websites/integration',
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
					'integrationStatus' => array( 'name' => 'INACTIVE' ),
				)
			),
		)
	);
}

/**
 * Remove this plugin's table and options.
 *
 * @return void
 */
function gendox_uninstall_clean_site() {
	global $wpdb;

	foreach ( GENDOX_UNINSTALL_OPTIONS as $option ) {
		delete_option( $option );
	}

	// Per-project options are named `gendox_ai_chat_positions_{gendoxId}`, so they cannot be
	// listed literally. esc_like() escapes `_`, which is a single-character LIKE wildcard.
	// delete_option() rather than one bulk DELETE: these options are autoloaded, and only
	// delete_option() invalidates the `alloptions` cache.
	$like = $wpdb->esc_like( 'gendox_ai_chat_positions_' ) . '%';

	$position_options = $wpdb->get_col(
		$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
	);

	foreach ( $position_options as $option_name ) {
		delete_option( $option_name );
	}

	$table_name = $wpdb->prefix . 'gendox_projects';
	$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
}

// Single-site only: the plugin does not support multisite. See AGENTS.md.
gendox_uninstall_notify_backend();
gendox_uninstall_clean_site();
