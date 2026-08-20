# AGENTS.md — working on the Gendox WordPress plugin

Guidance for AI coding agents modifying **this repository** (the plugin itself).

If you are instead integrating Gendox into a *third-party* WordPress site, you do not
need this file — see the [`gendox-wordpress-integration`](https://docs.gendox.dev/skills/gendox-wordpress-integration/SKILL.md) skill instead.

## What this plugin is

`Gendox AI Agent` connects a WordPress site to a
[Gendox](https://gendox.dev) instance. It does three things:

1. **Injects the Gendox chat widget** into the public site (`wp_footer`), scoped to the
   post types / taxonomies an admin assigned to a Gendox project.
2. **Exposes a read-only REST API** (`/wp-json/gendox/v1/…`) so Gendox can pull WordPress
   content in for training.
3. **Provides an admin UI** to enter the API key, map Gendox projects to WordPress
   content, and open the Gendox app in a new tab.

## Layout

| Path | Role |
|---|---|
| `gendox-ai-agent.php` | Entry point. Defines all `GENDOX_*` constants, registers activation/deactivation hooks, boots the `GENDOX()` singleton. **Loads first — constants defined here are global and available everywhere else without any import.** |
| `core/class-gendox-ai-agent.php` | Singleton. `require_once`s the classes below and instantiates them. |
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
| `gendox_chat_script_url` | Gendox instance serving the widget SDK and the Open Gendox button target. |
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

**Changing the API key moves the integration.**
`Gendox_AI_Agent_Settings::gendox_handle_api_key_change()` hooks
`pre_update_option_gendox_ai_chat_api_key` — on the option, not a `sanitize_callback`, so
it covers both settings pages that write the key. If the new key resolves to a different
organization it sends `INACTIVE` for the old one and `ACTIVE` for the new, returning the
old key (cancelling the save) if either fails, so the stored key always matches the live
integration. Registered as a **static** callback: `get_organization_id()` constructs the
settings class, and WordPress keys instance callbacks by object hash, so an instance
callback registers a duplicate handler on every call and the save logic runs twice.

**`/profile` responses are cached by CloudFront across callers — API key validation cannot
be trusted until that is fixed.** The origin authenticates correctly (an unauthenticated
request that reaches it returns HTTP 500 `Full authentication is required`), but the
CloudFront distribution in front of `app.gendox.dev` caches the response with a cache key
that does **not** include `x-api-key`, and ignores the origin's
`no-cache, no-store, must-revalidate`. Once any successful `/profile` response is cached,
every later caller gets it — verified with no API key at all returning HTTP 200, a real
profile and `x-cache: RefreshHit`, `age: 40`.

Consequences for this plugin, until the cache policy is corrected:

- `Gendox_AI_Agent_Helpers::get_organization_id()` may return an organization the
  supplied key does not own, so the `gendox_api_key_invalid` branch in
  `gendox_handle_api_key_change()` is unreachable and the organization comparison can be
  wrong.
- Nothing client-side fixes it: a cache-busting query string, a `Cache-Control: no-cache`
  request header and dropping `Accept-Encoding` were all tried and all still hit the cache.

The fix belongs in the CloudFront cache policy — do not cache `/gendox/api/v1/*`, or include
the auth header in the cache key.

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

## Conventions

- Tabs for indentation in PHP, matching the existing files.
- Admin CSS is scoped to `body.toplevel_page_gendox-ai-chat-settings`. The stylesheet
  loads on **every** admin page, so unscoped selectors leak into all of wp-admin — a bug
  that has already happened once. Never add a bare `body {}` or element selector.
- The menu icon (`add_menu_page()`'s 6th argument) is a base64 data URI built from
  `core/includes/assets/Gendox-G-logo-letter-white.svg` by `get_menu_icon()` in the
  Settings class. It is a flat white recolour, not the brand gradient — WordPress applies
  its own opacity treatment (dimmed when inactive, full when current) the same way it does
  for dashicons, and a gradient would neither dim correctly nor read at 20px. If the
  gradient mark ever needs regenerating from `Gendox-G-logo-letter.svg`, keep the white
  variant a flat, single-colour recolour of the same paths.
- Admin colours come from the Gendox app palette, declared as custom properties at the
  top of `backend-styles.css`.
- Every `wp_ajax_*` handler verifies a nonce. Keep it that way.

## Gotchas

**The settings screen is one page, one form.** The API key, the two URL fields, and the
widget options all register under the single `gendox_ai_chat_settings_group` and save
together with one Save button — there used to be a second, hidden page
(`chat-script-settings`) duplicating the URL fields under a separate option group, but it
was removed once the URL fields moved onto the main screen; don't recreate it. Registering
a field whose `name` differs from the registered option makes WordPress silently discard
the value on save — that bug shipped once already, keep it in mind if this page is split
again.

The projects section renders **outside** that form — it is AJAX-driven rather than
Settings API driven, and its modals contain their own `<form>` elements, which would
otherwise nest.

**The widget `<script>` is raw HTML, not an enqueued asset.** It is echoed directly in
`wp_footer`, so it has no handle and nothing can declare a dependency on it. This
constrains how integrators hook into it — see the [`gendox-wordpress-integration`](https://docs.gendox.dev/skills/gendox-wordpress-integration/SKILL.md) skill.

**Almost no filter hooks exist.** The only other extension points are
`do_action('GENDOX/plugin_loaded')` and `apply_filters('gendox_product_content_fields', ...)`
(in `class-gendox-api-endpoints.php`, letting extensions like WooCommerce Subscriptions add
fields to a product's HTML content block without this plugin knowing about them). Third
parties still cannot alter the widget's `data-*` attributes — adding a filter around the
script attributes would be a genuinely useful, low-risk improvement.

**The widget script omits most SDK options.** The SDK supports
`data-gendox-chat-initial-state`, `data-gendox-local-context-*` and
`data-gendox-open-web-page-tool-enabled`, but the plugin emits only `src`,
`data-gendox-src`, `data-organization-id` and `data-project-id`. Anything else must be set
at runtime via `window.gendox.widget.updateConfig()`.

## Local development

The sibling `gendox-wp-portal` repo runs a full WordPress in Docker and bind-mounts this
repo to `wp-content/plugins/gendox-ai-agent`, so edits apply on reload with no rebuild.
See that repo's `AGENTS.md`. WordPress serves on `http://localhost:8085`.

Useful checks before finishing:

```bash
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;
```

## Releasing to wordpress.org

This repo is **public**. Never commit SVN passwords, API keys, or other secrets — only
GitHub Actions secrets (`SVN_USERNAME`, `SVN_PASSWORD`). The plugin API key belongs in
WordPress options on each site, not in git.

**Source of truth is GitHub.** WordPress.org is Subversion-only for the directory listing.
`.github/workflows/deploy-wordpress.yml` deploys on a tag matching `vMAJOR.MINOR.PATCH`
(e.g. `v1.0.6`; skips `v1.0.6-alpha`). It can also be run manually for an existing tag.
The Action strips the leading `v`, so SVN gets `tags/1.0.6` while git keeps `v1.0.6`.
`.distignore` (mirrored in `bin/build-release-zip.sh`) keeps `.github`, `.wordpress-org`,
`bin`, `builds`, and similar out of the shipped zip.

**Directory assets** (banner, icon, screenshots) live in `.wordpress-org/` in this repo.
They are not part of the plugin zip; the deploy Action copies them to SVN `assets/`.
Screenshot captions in `readme.txt` (`== Screenshots ==`) must stay numbered in lockstep
with `screenshot-1.png`, `screenshot-2.png`, ….

**Release checklist:** bump `Version` / `GENDOX_VERSION` / `Stable tag` + changelog →
commit → `git tag vX.Y.Z` → push the tag. Do not reuse an SVN tag; ship a new version
instead. Assets-only updates still need a new Stable tag if you change `readme.txt`, or
use a dedicated assets deploy — prefer a normal version bump so trunk and the stable tag
stay aligned.

## Known issues (unfixed — see readme/publishing notes)

- `add_submenu_page(null, …)` is deprecated on PHP 8.1+.
- API key is stored in plaintext in `wp_options` (standard for WP plugins, but the readme's
  external-service disclosure should stay accurate about it).
