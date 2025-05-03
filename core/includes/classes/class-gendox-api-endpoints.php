<?php

// Exit if accessed directly.
if (!defined('ABSPATH')) exit;

class Gendox_API_Endpoints
{
    /**
     * Constructor to register REST API endpoints.
     */
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register custom REST API routes.
     */
    public function register_routes()
    {
        // Endpoint to get assigned IDs by project ID
        register_rest_route('gendox/v1', '/assigned-ids', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_assigned_ids_by_project'),
            'permission_callback' => array($this, 'check_api_key_permission'),
            'args' => array(
                'project_id' => array(
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_string($param);
                    },
                ),
            ),
        ));

        // Endpoint to get content by post/page/product ID
        register_rest_route('gendox/v1', '/content', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_content_by_id'),
            'permission_callback' => array($this, 'check_api_key_permission'),
            'args' => array(
                'content_id' => array(
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    },
                ),
            ),
        ));

        // endpoint to get organization's projects with assigned content and chat
        register_rest_route(
            'gendox/v1',
            '/assigned-projects',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_assigned_projects'),
                'permission_callback' => array($this, 'check_api_key_permission'),
                'args' => array(
                    'organization_id' => array(
                        'required' => true,
                        'validate_callback' => function ($param) {
                            return is_string($param);
                        },
                    ),
                ),
            )
        );
    }

    /**
     * Check if the request has a valid WP Gendox API Key.
     *
     * @param WP_REST_Request $request The REST API request.
     * @return bool True if the API key is valid, false otherwise.
     */
    public function check_api_key_permission($request)
    {
        // Retrieve the API key from the request headers
        $api_key = $request->get_header('X-WP-Gendox-API-Key');

        // Get the stored WP Gendox API key from the options
        $stored_api_key = get_option('gendox_ai_chat_api_key');

        // Check if the provided API key matches the stored API key
        if ($api_key && $api_key === $stored_api_key) {
            return true;
        }

        return false;
    }

    /**
     * Callback function to get assigned IDs by project ID.
     *
     * @param WP_REST_Request $request The REST API request.
     * @return WP_REST_Response The assigned IDs or an error message.
     */
    public function get_assigned_ids_by_project($request)
    {
        global $wpdb;
        $project_id = sanitize_text_field($request->get_param('project_id'));
        $table_name = $wpdb->prefix . 'gendox_projects';

        // Retrieve the assigned items for the specified project ID
        $project = $wpdb->get_row($wpdb->prepare("SELECT postIds FROM $table_name WHERE gendoxId = %s", $project_id));

        if ($project) {
            $assigned_items = maybe_unserialize($project->postIds);
            $formatted_items = array();

            // Loop through each type (posts, products, pages)
            foreach ($assigned_items as $type => $ids) {
                foreach ($ids as $id) {
                    $post = get_post($id);

                    if ($post) {
                        // Format dates with microseconds and 'Z' timezone suffix
                        $created_at = date("Y-m-d\TH:i:s.u\Z", strtotime($post->post_date_gmt));
                        $updated_at = date("Y-m-d\TH:i:s.u\Z", strtotime($post->post_modified_gmt));

                        $formatted_items[$type][] = array(
                            'contentId' => $post->ID,
                            'createdAt' => $created_at,
                            'updatedAt' => $updated_at,
                        );
                    }
                }
            }

            return new WP_REST_Response($formatted_items, 200);
        } else {
            return new WP_REST_Response(['message' => 'Project not found.'], 404);
        }
    }

    /**
     * Callback function to get content by post/page/product ID.
     *
     * @param WP_REST_Request $request The REST API request.
     * @return WP_REST_Response The content or an error message.
     */
    public function get_content_by_id($request)
    {
        $content_id = intval($request->get_param('content_id'));
        $post = get_post($content_id);

        if ($post) {
            // Process the content to remove shortcodes but keep HTML
            $content = $post->post_content;

            // Remove shortcodes
            $content = preg_replace('#\[[^\]]+\]#', '', $content);

            // Apply the_content filters to preserve HTML structure
            $content = apply_filters('the_content', $content);

            // Return the content and other relevant details
            $content_data = array(
                'id' => $post->ID,
//                 'title' => $post->post_title,
                'content' => $content,
//                 'type' => $post->post_type,
//                 'status' => $post->post_status,
				'source' => get_permalink($post->ID)
            );

            return new WP_REST_Response($content_data, 200);
        } else {
            return new WP_REST_Response(['message' => 'Content not found.'], 404);
        }
    }

    /**
     * Callback function to get projects with assigned content and chat by organization ID.
     *
     * @param WP_REST_Request $request The REST API request.
     * @return WP_REST_Response The projects with assigned content and chat settings or an error message.
     */
    public function get_assigned_projects($request)
    {
        global $wpdb;
        $organization_id = sanitize_text_field($request->get_param('organization_id'));
        $table_name = $wpdb->prefix . 'gendox_projects';

        // Retrieve all projects for the specified organization
        $projects = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE organizationId = %s", $organization_id));

        if (empty($projects)) {
            return new WP_REST_Response(['message' => 'No projects found for this organization.'], 404);
        }

        $assigned_projects = array();

        foreach ($projects as $project) {
            $assigned_items = maybe_unserialize($project->postIds);

            // Check if the project has assigned content or chat settings
            if (!empty($assigned_items['posts']) || !empty($assigned_items['products']) || !empty($assigned_items['pages'])) {
                $formatted_items = array();

                // Loop through each type (posts, products, pages) to format the output
                foreach ($assigned_items as $type => $ids) {
                    foreach ($ids as $id) {
                        $post = get_post($id);

                        if ($post) {
                            // Format dates with microseconds and 'Z' timezone suffix
                            $created_at = date("Y-m-d\TH:i:s.u\Z", strtotime($post->post_date_gmt));
                            $updated_at = date("Y-m-d\TH:i:s.u\Z", strtotime($post->post_modified_gmt));

                            $formatted_items[] = array(
                                'contentId' => $post->ID,
								'title' => $post->post_title,
								'type' => $post->post_type,
								'status' => $post->post_status,
                                'createdAt' => $created_at,
                                'updatedAt' => $updated_at,
                            );
                        }
                    }
                }

                // Check if there are chat settings for this project
                $chat_settings = get_option("gendox_ai_chat_positions_{$project->gendoxId}", array());

                // Add project data to the response only if it has assigned content or chat settings
                $assigned_projects[] = array(
                    'projectId' => $project->gendoxId,
                    'name' => $project->name,
                    'description' => $project->description,
                    'assignedContent' => $formatted_items,
                    'chatSettings' => $chat_settings,
                );
            }
        }

        if (!empty($assigned_projects)) {
            return new WP_REST_Response($assigned_projects, 200);
        } else {
            return new WP_REST_Response(['message' => 'No assigned projects with content or chat settings found.'], 404);
        }
    }
}

// Initialize the class
new Gendox_API_Endpoints();
