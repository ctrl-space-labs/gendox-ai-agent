# AGENTS.md — working on the Gendox WordPress plugin

Guidance for AI coding agents modifying **this repository** (the plugin itself).

If you are instead integrating Gendox into a *third-party* WordPress site, you do not
need this file — see the [`gendox-wordpress-integration`](https://docs.gendox.dev/skills/gendox-wordpress-integration/SKILL.md) skill instead.

## What this plugin is

`Gendox WP AI Agent` connects a WordPress site to a
[Gendox](https://gendox.dev) instance. It does three things:

1. **Injects the Gendox chat widget** into the public site (`wp_footer`), scoped to the
   post types / taxonomies an admin assigned to a Gendox project.
2. **Exposes a read-only REST API** (`/wp-json/gendox/v1/…`) so Gendox can pull WordPress
   content in for training.
3. **Provides an admin UI** to enter the API key, map Gendox projects to WordPress
   content, and embed the Gendox app itself in an iframe.

## Layout

| Path | Role |
|---|---|
| `gendox-wp-ai-agent.php` | Entry point. Defines all `GENDOX_*` constants, registers activation/deactivation hooks, boots the `GENDOX()` singleton. **Loads first — constants defined here are global and available everywhere else without any import.** |
| `core/class-gendox-wp-ai-agent.php` | Singleton. `require_once`s the classes below and instantiates them. |
| `core/includes/classes/…-settings.php` | Admin screens, Settings API registration, and all 10 `wp_ajax_*` handlers. Largest file; most changes land here. |
| `core/includes/classes/…-run.php` | WordPress hooks: asset enqueueing, metaboxes, quick edit, bulk actions, and the `wp_footer` widget `<script>` injection. |
| `core/includes/classes/…-helpers.php` | Static helpers. Notifies Gendox of integration status on activate/deactivate. |
| `core/includes/classes/class-gendox-api-endpoints.php` | The `gendox/v1` REST routes, all guarded by `check_api_key_permission`. |
| `core/includes/assets/` | `css/backend-styles.css`, `js/backend-scripts.js`. |

## Data model

**Custom table** `{$wpdb->prefix}gendox_projects` — created via `dbDelta` on activation.
Columns: `id`, `organizationId`, `gendoxId`, `name`, `description`, `postIds` (longtext).

**Options:**

| Option | Meaning |
|---|---|
| `gendox_ai_chat_api_key` | Gendox API key. Stored plaintext (see Known issues). |
| `gendox_chat_script_url` | Gendox instance serving the widget SDK and the admin iframe. |
| `gendox_api_base_url` | Gendox instance for server-side API calls. **Separate on purpose** — do not merge these two; real installs point them at different hosts. |
| `gendox_ai_chat_positions_{projectId}` | Serialized `post_type` / `taxonomies` array deciding which pages show that project's widget. |

`GENDOX_DEFAULT_URL` in the entry file is the **only** hardcoded Gendox URL. Both URL
options fall back to it. Change it there and nowhere else.

**Invariant: every `gendox_ai_chat_positions_{projectId}` must have a matching
`gendox_projects` row.** The table and these options are written by different code paths
(`gendox_fetch_projects()` syncs the table from the API; `gendox_save_chat_settings()`
writes the options), so they can drift apart. When they do, `add_footer_script_for_chat()`
still matches the orphaned option, finds no row to read `organizationId` from, and renders
the widget with `data-organization-id=""` — the frontend then calls
`/organizations//projects` with an empty path segment.

Two guards keep the invariant, and both must stay:

1. `gendox_fetch_projects()` deletes the position options for every row its
   `DELETE ... WHERE gendoxId NOT IN (...)` removes. Collect the ids **before** the delete.
2. `gendox_save_chat_settings()` rejects a `project_id` with no row, so a stale settings
   tab cannot recreate an orphan.

This is easy to trigger by pointing an existing install at a **different organization**
(new API key), because the sync then deletes every project belonging to the old org and
leaves their options behind. It has already happened in both the local dev DB and the
wp-demo clone.

**Uninstall.** `uninstall.php` (not `register_uninstall_hook`) drops the table, removes this
plugin's options, and sends `INACTIVE` to the backend. It must keep an **explicit** option
list — sibling plugins on the same sites share the `gendox_` prefix
(`gendox_checkout_validator_*`, `gendox_client_id`, `gendox_stripe_*`, `gendox_auth_url`,
`gendox_api_url`), so a `LIKE 'gendox%'` sweep would destroy their settings. No plugin code
is loaded during uninstall, so the file re-declares what it needs.

## Multisite: not supported, on purpose

The plugin is **single-site only**, and the code should stay internally consistent about
that. `uninstall.php` briefly had a `get_sites()`/`switch_to_blog()` cleanup loop; it was
removed because it implied a capability nothing else provides. Before adding any
multisite handling, all of these need addressing together:

- `gendox_create_projects_table()` ignores the `$network_wide` argument WordPress passes to
  activation hooks, so a network activation creates exactly **one** `gendox_projects`
  table. Other sites — and any site created later, since nothing hooks
  `wp_initialize_site` — never get one. Queries then hit a missing table and fail silently
  (`$wpdb` suppresses errors), surfacing as empty project lists and a widget rendered with
  `data-organization-id=""`.
- `update_integration_status()` posts `site_url()`, so a network activation announces only
  one site to the backend.
- Options are per-site, so the API key would need configuring on every site — an unmade
  product decision.

Unrelated but adjacent: `gendox_create_projects_table()` passes `CREATE TABLE IF NOT
EXISTS` to `dbDelta()`, which parses the statement itself and expects plain `CREATE TABLE`.
Harmless today because the schema hasn't changed since 1.0.0, but schema *upgrades* may
not apply until that's fixed.

## Conventions

- Tabs for indentation in PHP, matching the existing files.
- Admin CSS is scoped to `body.toplevel_page_gendox-ai-chat-settings`. The stylesheet
  loads on **every** admin page, so unscoped selectors leak into all of wp-admin — a bug
  that has already happened once. Never add a bare `body {}` or element selector.
- Admin colours come from the Gendox app palette, declared as custom properties at the
  top of `backend-styles.css`.
- Every `wp_ajax_*` handler verifies a nonce. Keep it that way.

## Gotchas

**Two settings pages edit the same options.** The visible **API Settings** tab and the
hidden page at `/wp-admin/admin.php?page=chat-script-settings` both render the same two
URL fields and both save through `gendox_api_settings_group`. If you add a field to one,
add it to the other, and register the option **once** in `register_settings()`. Registering
a field whose `name` differs from the registered option makes WordPress silently discard
the value on save — that bug shipped and went unnoticed for several releases.

**The widget `<script>` is raw HTML, not an enqueued asset.** It is echoed directly in
`wp_footer`, so it has no handle and nothing can declare a dependency on it. This
constrains how integrators hook into it — see the [`gendox-wordpress-integration`](https://docs.gendox.dev/skills/gendox-wordpress-integration/SKILL.md) skill.

**No filter hooks exist.** The only extension point is `do_action('GENDOX/plugin_loaded')`.
Third parties cannot currently alter the widget's `data-*` attributes. Adding a filter
around the script attributes would be a genuinely useful, low-risk improvement.

**The widget script omits most SDK options.** The SDK supports
`data-gendox-chat-initial-state`, `data-gendox-local-context-*` and
`data-gendox-open-web-page-tool-enabled`, but the plugin emits only `src`,
`data-gendox-src`, `data-organization-id` and `data-project-id`. Anything else must be set
at runtime via `window.gendox.widget.updateConfig()`.

## Local development

The sibling `gendox-wp-portal` repo runs a full WordPress in Docker and bind-mounts this
repo to `wp-content/plugins/gendox-wp-ai-agent`, so edits apply on reload with no rebuild.
See that repo's `AGENTS.md`. WordPress serves on `http://localhost:8085`.

Useful checks before finishing:

```bash
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;
```

## Known issues (unfixed — see readme/publishing notes)

- `add_submenu_page(null, …)` is deprecated on PHP 8.1+.
- `$_GET['tab']` is used unsanitized in `settings_page_content()`.
- No `uninstall.php` — options and the `gendox_projects` table are left behind on delete.
- API key is stored in plaintext in `wp_options` (standard for WP plugins, but the readme's
  external-service disclosure should stay accurate about it).
