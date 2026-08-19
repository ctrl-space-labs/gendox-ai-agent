=== Gendox AI Agent ===
Contributors: csekas
Donate link:
Tags: ai, chatbot, ai agent, ai assistant, customer support
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.5
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI assistant for WordPress websites. Train a chat agent on your posts, pages and products — answers customer questions 24/7 from your real content.

== Description ==

**Gendox AI Agent** turns your existing content into an AI assistant your visitors can
talk to. The agent is trained on the posts, pages and WooCommerce products *you* choose —
so it answers from your own material, not the open internet.

It can also go beyond answering questions: the agent can navigate visitors to the right
page for them, and developers can register custom actions (like filling in a form) so the
chat becomes a way for visitors to get things done, not just find information.

Pick which content trains each agent, pick which pages show the chat widget, and the
assistant does the rest.

= Why use an AI agent trained on your own content? =

* **Answer customer questions automatically** — visitors get instant answers drawn from your documentation, FAQs and product pages, day or night.
* **Grounded in your own content** — responses are drawn from the posts, pages and products you selected, not the open internet.
* **Reduce support load** — common pre-sales and how-to questions get handled before they reach your inbox.
* **Let visitors act, not just read** — the agent can navigate to the right page, and developers can extend it to trigger actions like filling in a form.

= Features =

* AI chat widget for WordPress, embedded with one click
* Train your AI agent on posts, pages, and WooCommerce products
* Choose exactly which post types and taxonomies display the chatbot
* Run multiple AI agents, each trained on a different set of content
* Manage API key, content assignment, and chat placement from the WordPress admin — open the Gendox app in a new tab for agent configuration
* Works with any theme
* Developer-friendly: register browser-side tools and page context so the agent can act on your site

= Who it is for =

Documentation sites, online shops, membership sites, SaaS marketing sites, and any site
where visitors ask the same questions repeatedly — or where you'd rather they just tell a
chat what they want instead of hunting through menus for it.

= External service =

This plugin connects your site to a **Gendox** instance, which is required for the plugin
to function. That can be the hosted service at [gendox.dev](https://gendox.dev) or your
own self-hosted install — Gendox is open source. Content you explicitly assign to a
project is sent to that Gendox instance to train your agent, and visitor chat messages
are sent there to generate replies.

A [gendox.dev](https://gendox.dev) account is **not** mandatory. You can self-host Gendox
and keep it free forever; see the open-source core at
[github.com/ctrl-space-labs/gendox-core](https://github.com/ctrl-space-labs/gendox-core).
Point the plugin's Chat Script URL and API Base URL at your instance, then use an API key
from that install.

* Service: [Gendox](https://gendox.dev)
* Terms and conditions: [https://gendox.dev/terms-conditions/](https://gendox.dev/terms-conditions/)
* Privacy policy: [https://gendox.dev/privacy-policy/](https://gendox.dev/privacy-policy/)
* Documentation: [https://docs.gendox.dev/](https://docs.gendox.dev/)
* Open source: [https://github.com/ctrl-space-labs/gendox-core](https://github.com/ctrl-space-labs/gendox-core)

An API key from whichever Gendox instance you use is required. No content leaves your site
until you enter that key and explicitly assign content to a project.

== Installation ==

1. Go to `Plugins` in the Admin menu
2. Click on the button `Add new`
3. Search for `Gendox AI Agent` and click 'Install Now', or use the `upload` link to upload the plugin zip
4. Click on `Activate plugin`
5. Open **Gendox AI Chat** in the admin menu and paste your Gendox API key, then click **Test Connection**
6. On the **WordPress Settings** tab, click **Fetch Projects**, then use **Assign Content** and **Assign Chat**

== Frequently Asked Questions ==

= Do I need a Gendox account? =

No. A hosted account at [app.gendox.dev](https://app.gendox.dev) is optional. The plugin
needs a Gendox instance and an API key from that instance — either the hosted platform or
one you run yourself.

= Can I self-host Gendox and keep it free? =

Yes. Gendox is open source. You can self-host it and use this plugin against your own
install with no gendox.dev subscription. The core project is on GitHub:
[https://github.com/ctrl-space-labs/gendox-core](https://github.com/ctrl-space-labs/gendox-core).
After you deploy it, set the Chat Script URL and Gendox API Base URL in the plugin
settings to your instance.

= Does the AI make up answers? =

The agent is trained on the content you assign to it, and answers from that material. Like
any AI system, it can still make mistakes — but grounding it in your own posts, pages and
products drastically reduces how often that happens compared to a generic chatbot.

= Can I choose which pages show the chat widget? =

Yes. Under **Assign Chat** you pick the post types and taxonomies where the widget
appears. It only loads on pages that match.

= Can I train more than one agent? =

Yes. Create several Gendox projects, assign different content to each, and target them at
different parts of your site.

= Does it work with WooCommerce? =

WooCommerce products can be assigned as training content alongside posts and pages. This
integration is still new and hasn't been broadly tested yet — if you try it, we'd
appreciate hearing how it goes.

= Can developers extend it? =

Yes. The widget SDK lets you register browser-side tools the agent can call, and supply
extra page context with each message. See the
[developer documentation](https://docs.gendox.dev/) and the
[agent skills](https://docs.gendox.dev/skills/).

== Screenshots ==

1. AI Chat Settings — connect your site with a Gendox API key
2. WordPress Settings — assign posts, pages and products to an AI agent
3. Open Gendox from the settings page (opens in a new tab)
4. The AI chat widget on the front end

== Changelog ==

= 1.0.5: July 30, 2026 =
* Remove the Gendox app iframe from wp-admin; settings now include an Open Gendox button that opens the app in a new tab
* Upgrade Select2 to 4.1.0 and Bootstrap to 5.3.8
* Restrict the /content REST endpoint so a valid API key can only fetch published posts, pages, or products that are assigned to a Gendox project
* Fix saving a new API key leaving the Gendox integration inactive until the plugin was deactivated and reactivated. Reload Content and other sync actions now work after the first Save
* Clearing the API key still marks the integration inactive; saving a key when none was stored (or after a clear) now marks it active
* WooCommerce products are returned to Gendox as structured HTML (title, price, SKU, stock, categories, short description, description, and image URLs), not only the long description
* Product content can be extended via the gendox_product_content_fields filter (for example WooCommerce Subscriptions fields)

= 1.0.4: July 28, 2026 =
* The settings screen is now a single page. The WordPress Settings and API Settings tabs are gone, and the API key and URL settings save together with one Save Changes button
* Settings and projects are now grouped in bordered panels that span the full page width, so it is clear which button applies to which fields
* Add a Gendox icon to the admin menu item
* The API key is hidden by default, with a Show button to reveal it
* Fix the embedded Gendox panel being blocked as insecure content on some deployments
* Saving an API key for a different organization now moves the integration: the previous organization is deactivated and the new one activated. The key is only saved if both succeed
* Remove the plugin's data on uninstall: the projects table, the API key and URL settings, and all per-project chat placement settings
* Notify Gendox that the integration is inactive when the plugin is deleted, not only when it is deactivated
* Fix the chat widget sending an empty organization id when a project had been removed from Gendox, which produced requests to /organizations//projects
* Remove chat placement settings belonging to projects that no longer exist, instead of leaving them behind on every projects refresh
* Reject chat placement settings saved against a project that is no longer available
* Fix a database error when refreshing projects for an account with no projects

= 1.0.3: July 25, 2026 =
* Default Chat Script URL and Gendox API Base URL for new installs are now https://app.gendox.dev
* Fix Chat Script Settings silently discarding the URL on save
* Align the admin screens with the Gendox app look and feel
* Give the embedded Gendox panel a proper height and framing
* Stop the plugin styles from restyling every WordPress admin page
* Bundle Bootstrap and Select2 locally instead of loading them from a CDN

= 1.0.2: Sept 16, 2025 =
* Minor Bug fix in the UI

= 1.0.1: June 06, 2025 =
* Add source url in the integration API

= 1.0.0: October 16, 2024 =
* Birthday of Gendox AI Chat for Wordpress
