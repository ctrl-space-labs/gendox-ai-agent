# Gendox AI Agent

Connect a WordPress site to [Gendox](https://gendox.dev): train an AI agent on your posts,
pages and products, then serve it as a chat widget on the pages you choose.

- **Documentation:** <https://docs.gendox.dev/>
- **Agent skills** (Cursor / Claude Code / Codex): <https://docs.gendox.dev/skills/>
- **Gendox app:** <https://app.gendox.dev>

## What it does

| | |
|---|---|
| **Chat widget** | Injects the Gendox widget on the public site, scoped to the post types and taxonomies you assign to each Gendox project. |
| **Content sync** | Exposes read-only REST routes under `/wp-json/gendox/v1/` so Gendox can pull your content in for training. |
| **Admin UI** | Enter your API key, map Gendox projects to WordPress content, and work with the Gendox app embedded directly in wp-admin. |

## Install

1. Upload the plugin folder to `wp-content/plugins/`, or install the zip via
   **Plugins → Add New → Upload Plugin**.
2. Activate it.
3. Go to **Gendox AI Chat → AI Chat Settings** and paste your Gendox API key.

Full setup walkthrough: [docs.gendox.dev/website-widget/wordpress-plugin](https://docs.gendox.dev/website-widget/wordpress-plugin).

## Configuration

Both URLs default to `https://app.gendox.dev` and only need changing if you self-host
Gendox. They can be edited under **Gendox AI Chat → API Settings**, or on the hidden page
at `/wp-admin/admin.php?page=chat-script-settings`.

| Setting | Used for |
|---|---|
| Chat Script URL | Serving the widget SDK and the embedded admin app. |
| Gendox API Base URL | Server-side API calls (connection test, integration status). |

These are deliberately separate settings and may point at different hosts.

## Documentation for developers and AI agents

| You are… | Read |
|---|---|
| Adding Gendox to a WordPress site, or an agent working on such a site | [gendox-wordpress-integration skill](https://docs.gendox.dev/skills/gendox-wordpress-integration/SKILL.md) |
| Modifying this plugin | [`AGENTS.md`](AGENTS.md) |

The `gendox-wordpress-integration` skill covers the WordPress-specific parts that the
generic [widget skill](https://docs.gendox.dev/skills/gendox-widget-integration/SKILL.md)
does not: the plugin injects the widget `<script>` for you, so registering local-context
callbacks and browser tools needs a different hook point than the skill describes.

## Requirements

- WordPress with a Gendox account and a **public** agent
- PHP 7.4+ (see the note on PHP 8.1 deprecations in [`AGENTS.md`](AGENTS.md))

## License

GPLv2 — see [`license.txt`](license.txt).
