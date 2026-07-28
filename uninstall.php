<?php
/**
 * Uninstall Gendox WP AI Agent.
 *
 * Runs when the user DELETES the plugin from the Plugins screen (or via WP-CLI
 * `wp plugin uninstall`). Deactivation alone never reaches this file - WordPress only
 * loads uninstall.php on deletion, and it does so INSTEAD of loading the plugin, so no
 * plugin code, constant or class is available here unless this file loads it itself.
 *
 * WordPress prefers this file over register_uninstall_hook() when both exist, and
 * wordpress.org review expects a plugin to clean up after itself - which this plugin
 * previously did not do at all: the projects table and every chat-position option
 * survived deletion and were silently re-adopted on reinstall.
 *
 * @package		GENDOX
 * @author		Ctrl+Space Labs
 * @since		1.0.4
 */

// Exit if not called by WordPress' uninstall routine.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Options owned by THIS plugin.
 *
 * Deliberately an explicit list rather than a `LIKE 'gendox%'` sweep. Real installs of
 * this site run sibling plugins that share the `gendox_` prefix but are NOT part of this
 * plugin - gendox-checkout-business-validator (`gendox_checkout_validator_*`) and the
 * OAuth/Stripe snippets (`gendox_client_id`, `gendox_client_secret`, `gendox_auth_url`,
 * `gendox_api_url`, `gendox_stripe_*`). A prefix wipe would silently destroy their
 * settings. Only add a name here if this plugin is what writes it.
 */
const GENDOX_UNINSTALL_OPTIONS = array(
	'gendox_ai_chat_api_key',
	'gendox_api_base_url',
	'gendox_chat_script_url',
);

/**
 * Tell Gendox this site's integration is gone.
 *
 * Deactivation already sends INACTIVE, but deleting a plugin does not require
 * deactivating it first in every code path, and a reinstall-less delete would otherwise
 * leave the backend believing the integration is still ACTIVE forever. Sending it twice
 * is harmless - it is a state assertion, not an event.
 *
 * Self-contained on purpose: loading the plugin's Settings class here would run its
 * constructor and register a dozen admin hooks during uninstall for the sake of one
 * HTTP call. Uses the WP HTTP API rather than raw cURL so it honours proxies and filters.
 *
 * @return void
 */
function gendox_uninstall_notify_backend() {
	$api_key = get_option( 'gendox_ai_chat_api_key' );
	if ( empty( $api_key ) ) {
		return; // Never configured - nothing on the far side to deactivate.
	}

	// Mirrors GENDOX_DEFAULT_URL from the main plugin file, which is not loaded here.
	$api_base_url = get_option( 'gendox_api_base_url', 'https://app.gendox.dev' );
	$api_base_url = rtrim( $api_base_url, '/' );

	// The integration is addressed by organization, so resolve it from the API key first.
	$profile = wp_remote_get(
		$api_base_url . '/gendox/api/v1/profile',
		array(
			'timeout' => 15,
			'headers' => array( 'x-api-key' => $api_key ),
		)
	);

	if ( is_wp_error( $profile ) || 200 !== wp_remote_retrieve_response_code( $profile ) ) {
		return; // Unreachable or rejected - do not block the uninstall over it.
	}

	$data = json_decode( wp_remote_retrieve_body( $profile ), true );
	if ( ! isset( $data['organizations'][0]['id'] ) ) {
		return;
	}

	$organization_id = $data['organizations'][0]['id'];

	wp_remote_post(
		$api_base_url . '/gendox/api/v1/organizations/' . rawurlencode( $organization_id ) . '/websites/integration',
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
 * Remove this plugin's data from the CURRENT site.
 *
 * @return void
 */
function gendox_uninstall_clean_site() {
	global $wpdb;

	foreach ( GENDOX_UNINSTALL_OPTIONS as $option ) {
		delete_option( $option );
	}

	// The per-project chat placement options are named
	// `gendox_ai_chat_positions_{gendoxId}`, so they cannot be listed literally.
	//
	// esc_like() matters: `_` is a single-character wildcard in SQL LIKE, so an unescaped
	// pattern would also match names like `gendoxXaiXchatXpositionsX...`. Options are
	// autoloaded, so they are collected first and removed via delete_option() rather than
	// one bulk DELETE - delete_option() invalidates the `alloptions` object cache, which
	// raw SQL does not, and a stale cache would keep serving deleted values.
	$like = $wpdb->esc_like( 'gendox_ai_chat_positions_' ) . '%';

	$position_options = $wpdb->get_col(
		$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
	);

	foreach ( $position_options as $option_name ) {
		delete_option( $option_name );
	}

	// Created by gendox_create_projects_table() on activation.
	$table_name = $wpdb->prefix . 'gendox_projects';
	$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
}

/**
 * Single-site only, deliberately.
 *
 * This file previously looped get_sites()/switch_to_blog() to clean every site on a
 * multisite network. That was removed on purpose - do not add it back without first
 * making the REST of the plugin multisite-capable, because on its own it is misleading:
 *
 *   - gendox_create_projects_table() ignores the $network_wide flag that WordPress passes
 *     to activation hooks, so a network activation creates exactly ONE
 *     {prefix}gendox_projects table. Every other site in the network never has one, and
 *     sites created later never get one either (nothing hooks wp_initialize_site).
 *   - update_integration_status() posts site_url(), so a network activation announces
 *     only a single site to the Gendox backend.
 *   - Options are per-site, so the API key would have to be configured on every site
 *     separately - a product decision nobody has made.
 *
 * A cleanup loop here would also have fired two blocking HTTP calls per site
 * (/profile + the integration POST), which on any real network would time out partway
 * through and leave the uninstall half-done.
 *
 * So: the plugin does not claim multisite support, and this file does not pretend to
 * provide it. If multisite support is ever added, this is one of several places to change,
 * not the first.
 */
gendox_uninstall_notify_backend();
gendox_uninstall_clean_site();
