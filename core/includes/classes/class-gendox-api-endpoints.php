<?php

// Exit if accessed directly.
if (!defined('ABSPATH')) exit;

class Gendox_API_Endpoints
{
    /**
     * Layout wrappers: kept through the allowlist only so they can become line breaks.
     *
     * @var string[]
     */
    const LAYOUT_WRAPPERS = array('div', 'section', 'article', 'header', 'footer', 'aside', 'main', 'figure');

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

        // Only published content that an admin assigned to a Gendox project — a valid
        // API key alone must not unlock arbitrary drafts/private/unassigned posts.
        if ('publish' !== $post->post_status || !$this->is_content_assigned($content_id)) {
            return new WP_REST_Response(['message' => 'Content not available.'], 403);
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
     * Whether a post/page/product ID is assigned to at least one local Gendox project.
     *
     * @param int $content_id
     * @return bool
     */
    private function is_content_assigned($content_id)
    {
        global $wpdb;

        $content_id = (int) $content_id;
        if ($content_id <= 0) {
            return false;
        }

        $table_name = $wpdb->prefix . 'gendox_projects';
        $projects = $wpdb->get_results("SELECT postIds FROM $table_name");

        if (empty($projects)) {
            return false;
        }

        foreach ($projects as $project) {
            $assigned_items = maybe_unserialize($project->postIds);
            if (!is_array($assigned_items)) {
                continue;
            }

            foreach (array('posts', 'products', 'pages') as $type) {
                if (empty($assigned_items[$type]) || !is_array($assigned_items[$type])) {
                    continue;
                }

                $ids = array_map('intval', $assigned_items[$type]);
                if (in_array($content_id, $ids, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Rendered content for a regular post/page.
     *
     * Shortcodes are executed, not stripped: `the_content` runs `do_shortcode()`, so
     * Gendox gets the text a visitor reads. Stripping (up to 1.0.6) lost anything a
     * shortcode generates, and left encoded payloads — Avada's base64 Code Block — as
     * raw noise.
     *
     * A shortcode that throws falls back to the stripped content rather than failing the
     * request — see `render_the_content()`.
     *
     * @param WP_Post $post
     * @return string
     */
    private function build_post_content($post)
    {
        /**
         * Filters whether shortcodes are executed when building post/page content.
         *
         * Return false to strip them instead (the behaviour up to 1.0.6), for sites where
         * running shortcode callbacks on every content fetch is too expensive.
         *
         * @param bool    $render Whether to render shortcodes. Default true.
         * @param WP_Post $post   The post being sent to Gendox.
         */
        $render = (bool) apply_filters('gendox_render_shortcodes', true, $post);

        if ($render) {
            $content = $this->render_the_content($post, $post->post_content);

            if (null !== $content) {
                return $this->clean_rendered_html($content);
            }
            // A shortcode threw. Fall through and retry with them stripped, so one broken
            // third-party callback costs the shortcode output rather than the whole post.
        }

        $stripped = strip_shortcodes($post->post_content);
        $content = $this->render_the_content($post, $stripped);

        // Both passes threw: the filters themselves are broken, so send the raw text.
        return $this->clean_rendered_html(null === $content ? $stripped : $content);
    }

    /**
     * `the_content` over `$raw` with `$post` in context, or null when it threw.
     *
     * Rendering runs third-party shortcode callbacks, and up to 1.0.6 building content
     * could not fail. A throw here must not turn a whole `/content` fetch into a 500 and
     * leave the post permanently unsynced, so it is caught and reported as no result.
     *
     * @param WP_Post $post
     * @param string  $raw
     * @return string|null
     */
    private function render_the_content($post, $raw)
    {
        try {
            return $this->with_post_context($post, function () use ($raw) {
                return apply_filters('the_content', $raw);
            });
        } catch (Throwable $e) {
            Gendox_AI_Agent_Helpers::log_error(sprintf(
                'the_content threw while building content for post %d: %s in %s:%d',
                (int) $post->ID,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            return null;
        }
    }

    /**
     * Runs a callback with `$target` as the global post, capturing anything it prints.
     *
     * Builder shortcodes read the global post and a REST request has none. A shortcode
     * that prints instead of returning would corrupt the JSON response, so it is
     * buffered — and kept, since a front-end render would show it too.
     *
     * @param WP_Post  $target
     * @param callable $callback
     * @return string
     */
    private function with_post_context($target, $callback)
    {
        $previous = isset($GLOBALS['post']) ? $GLOBALS['post'] : null;
        $depth = ob_get_level();

        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored in finally.
        $GLOBALS['post'] = $target;
        setup_postdata($target);
        ob_start();

        try {
            $returned = (string) call_user_func($callback);

            return ob_get_clean() . $returned;
        } finally {
            // A shortcode that threw left our buffer, and any it opened, behind.
            while (ob_get_level() > $depth) {
                ob_end_clean();
            }

            $this->restore_post_context($previous);
        }
    }

    /**
     * Restores every global `setup_postdata()` populates, not just `$post`.
     *
     * @param WP_Post|null $previous
     * @return void
     */
    private function restore_post_context($previous)
    {
        if ($previous instanceof WP_Post) {
            // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring.
            $GLOBALS['post'] = $previous;
            setup_postdata($previous);

            return;
        }

        foreach (array('post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages') as $global) {
            unset($GLOBALS[$global]);
        }
    }

    /**
     * Reduces a rendered document to the content Gendox should train on.
     *
     * Nothing downstream strips tags, so `wp_kses()` is the only guard: it keeps the
     * semantic skeleton, drops presentational attributes and filters `href`/`src`
     * protocols. The passes before it hand it markup it will not misread; the passes
     * after it touch text only.
     *
     * @param string $html
     * @return string
     */
    private function clean_rendered_html($html)
    {
        $html = $this->drop_non_content_elements((string) $html);
        $html = wp_kses($this->escape_stray_brackets($html), self::content_html());

        // Wrappers become a line break so the text either side does not run together.
        $html = $this->replace('#</?(?:' . implode('|', self::LAYOUT_WRAPPERS) . ')\s*/?>#i', "\n", $html);

        return trim($this->normalize_text_nodes($html));
    }

    /**
     * The tags `clean_rendered_html()` keeps, in `wp_kses()` form.
     *
     * Deliberately narrow: anything outside the list kses drops, keeping its text.
     *
     * @return array<string, array<string, bool>>
     */
    private static function content_html()
    {
        $with_attributes = array(
            'a'   => array('href' => true, 'title' => true),
            'img' => array('src' => true, 'alt' => true, 'title' => true),
            'td'  => array('colspan' => true, 'rowspan' => true),
            'th'  => array('colspan' => true, 'rowspan' => true),
        );

        $bare = array_merge(
            self::LAYOUT_WRAPPERS,
            array(
                'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'ul', 'ol', 'li', 'dl', 'dt', 'dd',
                'table', 'caption', 'thead', 'tbody', 'tfoot', 'tr',
                'blockquote', 'q', 'cite', 'pre', 'code', 'figcaption',
                'strong', 'em', 'b', 'i', 'del', 'ins', 'mark',
                'sub', 'sup', 'small', 'abbr', 'time',
            )
        );

        // `+` keeps the left-hand entries, so the attribute lists win.
        return $with_attributes + array_fill_keys($bare, array());
    }

    /**
     * Removes elements whose contents are not prose, along with HTML comments.
     *
     * Each runs to its closing tag or, unclosed, to the end of the document — the reach
     * a browser gives it, so what survives is what a visitor would have read.
     *
     * @param string $html
     * @return string
     */
    private function drop_non_content_elements($html)
    {
        $html = $this->replace('#<(script|style|noscript|template)\b[^>]*>.*?(?:</\1\s*>|$)#is', '', $html);

        return $this->replace('#<!--.*?(?:-->|$)#s', '', $html);
    }

    /**
     * Escapes a `<` that does not open a tag.
     *
     * kses reads the `<` in "if x < y" as a malformed tag and drops everything up to the
     * next `>`. Anything that does look like a tag is left for kses to judge.
     *
     * @param string $html
     * @return string
     */
    private function escape_stray_brackets($html)
    {
        return $this->replace('#<(?![a-zA-Z/!?])#', '&lt;', $html);
    }

    /**
     * Applies the text-only passes — entities, then whitespace — between the tags.
     *
     * Splitting keeps a decoded entity from becoming markup, leaves kept attributes
     * alone, and shows where `<pre>` starts. After kses a `<` outside a tag is always an
     * entity, so the split is exact.
     *
     * @param string $html
     * @return string
     */
    private function normalize_text_nodes($html)
    {
        // One capture group, so the pieces alternate text, tag, text, tag…
        $parts = preg_split('#(<[^>]*>)#', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (!is_array($parts)) {
            return $html;
        }

        $preformatted = 0;

        foreach ($parts as $index => $part) {
            if ($index % 2) {
                // kses keeps the author's tag-name case.
                if (preg_match('#^<(/?)pre[\s/>]#i', $part, $tag)) {
                    $preformatted = '' === $tag[1] ? $preformatted + 1 : max(0, $preformatted - 1);
                }

                continue;
            }

            $text = $this->decode_text_entities($part);
            $parts[$index] = $preformatted > 0 ? $text : $this->collapse_whitespace($text);
        }

        return implode('', $parts);
    }

    /**
     * Decodes entities so the corpus reads "agents & chatbots", not "agents &#038; chatbots".
     *
     * Text nodes only, so any angle bracket left after decoding came from an entity and
     * goes straight back — example code stays example code. A bare `&` opens nothing.
     *
     * @param string $text
     * @return string
     */
    private function decode_text_entities($text)
    {
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text); // Non-breaking spaces.

        return str_replace(array('<', '>'), array('&lt;', '&gt;'), $text);
    }

    /**
     * Collapses runs of spaces and blank lines. Never reaches a `<pre>`.
     *
     * @param string $text
     * @return string
     */
    private function collapse_whitespace($text)
    {
        return $this->replace('#\n\s*\n\s*\n+#', "\n\n", $this->replace('#[ \t]+#', ' ', $text));
    }

    /**
     * `preg_replace()` that keeps the subject when PCRE gives up.
     *
     * A large enough page can exhaust the backtrack limit, and a null return would
     * quietly become an empty document. Losing one formatting pass is the better failure.
     *
     * @param string $pattern
     * @param string $replacement
     * @param string $subject
     * @return string
     */
    private function replace($pattern, $replacement, $subject)
    {
        $result = preg_replace($pattern, $replacement, $subject);

        return null === $result ? $subject : $result;
    }

    /**
     * Builds the HTML content block sent to Gendox for a WooCommerce product.
     *
     * Real HTML (not pseudo-XML): an `<article>` with semantic children and
     * `gendox-product-*` classes. Description HTML is kept (headings/lists help the
     * model); shortcodes are stripped, unlike the post/page path.
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
     * Products deliberately do not render: a catalogue multiplies the per-fetch cost, and
     * product shortcodes mostly emit storefront chrome.
     *
     * @param string $html
     * @return string
     */
    private function prepare_product_rich_text($html)
    {
        return trim($this->clean_rendered_html(strip_shortcodes((string) $html)));
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
