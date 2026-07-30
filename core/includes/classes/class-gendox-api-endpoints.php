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
        if ($api_key && $stored_api_key && hash_equals($stored_api_key, $api_key)) {
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
                        $created_at = gmdate("Y-m-d\TH:i:s.u\Z", strtotime($post->post_date_gmt));
                        $updated_at = gmdate("Y-m-d\TH:i:s.u\Z", strtotime($post->post_modified_gmt));

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

        if (!$post) {
            return new WP_REST_Response(['message' => 'Content not found.'], 404);
        }

        $product = ('product' === $post->post_type && function_exists('wc_get_product'))
            ? wc_get_product($post)
            : false;

        $content = $product
            ? $this->build_product_content($product)
            : $this->build_post_content($post);

        $content_data = array(
            'id' => $post->ID,
            'content' => $content,
            'source' => get_permalink($post->ID),
        );

        return new WP_REST_Response($content_data, 200);
    }

    /**
     * Rendered content for a regular post/page.
     *
     * Shortcodes are stripped (their output isn't meaningful outside a live page render)
     * but `the_content` filters still run, so block/embed HTML structure is preserved.
     *
     * @param WP_Post $post
     * @return string
     */
    private function build_post_content($post)
    {
        $content = preg_replace('#\[[^\]]+\]#', '', $post->post_content);

        return apply_filters('the_content', $content);
    }

    /**
     * Builds the HTML content block sent to Gendox for a WooCommerce product.
     *
     * Real HTML (not pseudo-XML): an `<article>` with semantic children and
     * `gendox-product-*` classes. Description HTML is kept (headings/lists help the
     * model); shortcodes are stripped because they do not render usefully off-page.
     * Price display HTML is reduced to plain text — its `<del>`/`<ins>`/spans are
     * storefront chrome, not content structure.
     *
     * Kept as its own method (and filter) so product data can grow — attributes,
     * variations, subscription terms from extensions — without the post/page path
     * having to know about any of it.
     *
     * @param WC_Product $product
     * @return string
     */
    private function build_product_content($product)
    {
        $category_names = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
        $availability = $product->get_availability();
        $stock_text = isset($availability['availability']) ? $availability['availability'] : '';

        $fields = array(
            'title' => $product->get_name(),
            'price' => $this->html_to_plain_text($product->get_price_html()),
            'sku' => $product->get_sku(),
            'stock' => $stock_text,
            'categories' => is_array($category_names) ? implode(', ', $category_names) : '',
            'short_description' => $this->prepare_product_rich_text($product->get_short_description()),
            'description' => $this->prepare_product_rich_text($product->get_description()),
            'images' => $this->get_product_image_urls($product),
        );

        /**
         * Filters the fields used to build a product's Gendox HTML content.
         *
         * Lets integrations (e.g. WooCommerce Subscriptions, Bookings) add their own
         * fields without this plugin needing built-in knowledge of every extension.
         * Empty string / empty array values are omitted. Known keys use fixed wrappers
         * (`title` → `<h1>`, `images` → `<ul>`, etc.); any other key becomes
         * `<div class="gendox-product-{key}">…</div>`.
         *
         * @param array<string, string|string[]> $fields  Field name => string value, or
         *                                                URL list for `images`.
         * @param WC_Product                     $product
         */
        $fields = apply_filters('gendox_product_content_fields', $fields, $product);

        $type = esc_attr($product->get_type());
        $html = '<article class="gendox-product" data-product-type="' . $type . '">' . "\n";

        foreach ($fields as $key => $value) {
            $rendered = $this->render_product_content_field($key, $value);
            if ('' !== $rendered) {
                $html .= $rendered . "\n";
            }
        }

        $html .= '</article>';

        return $html;
    }

    /**
     * Renders one product content field as HTML, or an empty string when omitted.
     *
     * @param string              $key   Field name (`title`, `price`, …, or a custom key).
     * @param string|string[]     $value Plain text, rich HTML, or image URL list.
     * @return string
     */
    private function render_product_content_field($key, $value)
    {
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $key));
        if ('' === $key) {
            return '';
        }

        if ('images' === $key) {
            $urls = is_array($value) ? $value : array_filter(array_map('trim', explode(',', (string) $value)));
            return $this->render_product_images_list($urls);
        }

        $value = is_array($value) ? implode(', ', $value) : (string) $value;
        $value = trim($value);
        if ('' === $value) {
            return '';
        }

        $class = 'gendox-product-' . str_replace('_', '-', $key);

        // Description fields keep author HTML; scalar metadata is escaped.
        $rich_keys = array('short_description', 'description');
        $inner = in_array($key, $rich_keys, true) ? $value : esc_html($value);

        $wrappers = array(
            'title' => 'h1',
            'price' => 'p',
            'sku' => 'p',
            'stock' => 'p',
            'categories' => 'p',
            'short_description' => 'div',
            'description' => 'div',
        );
        $tag = isset($wrappers[$key]) ? $wrappers[$key] : 'div';

        return '<' . $tag . ' class="' . esc_attr($class) . '">' . $inner . '</' . $tag . '>';
    }

    /**
     * Public, full-size image URLs for a product: featured image first, then gallery.
     *
     * These are the same URLs WordPress serves on the live site, so no auth is needed
     * to fetch them.
     *
     * @param WC_Product $product
     * @return string[]
     */
    private function get_product_image_urls($product)
    {
        $attachment_ids = array_unique(array_filter(array_merge(
            array($product->get_image_id()),
            $product->get_gallery_image_ids()
        )));

        $urls = array();
        foreach ($attachment_ids as $attachment_id) {
            $url = wp_get_attachment_image_url($attachment_id, 'full');
            if ($url) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Builds the `<ul class="gendox-product-images">` list, or empty string if none.
     *
     * @param string[] $urls
     * @return string
     */
    private function render_product_images_list($urls)
    {
        $items = '';
        foreach ($urls as $url) {
            $url = esc_url(trim((string) $url));
            if ('' === $url) {
                continue;
            }
            $items .= '<li><a href="' . $url . '">' . esc_html($url) . '</a></li>';
        }

        if ('' === $items) {
            return '';
        }

        return '<ul class="gendox-product-images">' . $items . '</ul>';
    }

    /**
     * Description / short description: strip shortcodes, keep HTML structure.
     *
     * @param string $html
     * @return string
     */
    private function prepare_product_rich_text($html)
    {
        return trim(strip_shortcodes((string) $html));
    }

    /**
     * Reduces price (and similar) HTML to plain text — strips tags/shortcodes and
     * normalizes whitespace/entities so storefront chrome does not enter the corpus.
     *
     * @param string $html
     * @return string
     */
    private function html_to_plain_text($html)
    {
        $text = strip_shortcodes((string) $html);
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text); // Non-breaking spaces from price/currency HTML.
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
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
                            $created_at = gmdate("Y-m-d\TH:i:s.u\Z", strtotime($post->post_date_gmt));
                            $updated_at = gmdate("Y-m-d\TH:i:s.u\Z", strtotime($post->post_modified_gmt));

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
