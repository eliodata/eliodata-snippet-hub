=== Eliodata MCP Bridge ===
Contributors: fgelio
Donate link: https://eliodata.com
Tags: snippets, mcp, rest-api, ide, code
Requires at least: 5.6
Tested up to: 7.1
Stable tag: 4.2.2
Requires PHP: 7.4
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Native snippet engine + secure WordPress MCP bridge with a built-in admin UI and IDE companion support.

== Description ==

Eliodata MCP Bridge is a WordPress plugin that combines snippet management and MCP access in one place:

* Native snippet engine included in the plugin.
* Secure REST API bridge for IDE integrations and site automation.
* MCP discovery, execution, and custom tool registration endpoints.
* Dedicated WordPress admin screens for snippets and MCP.
* Per-site custom MCP tools stored directly in WordPress.
* Import/export, activation, targeting, and bulk actions for snippets.
* Authentication via WordPress Application Passwords, with optional read-only passwords.
* PHP syntax check before saving, automatic deactivation of snippets that cause a fatal error, and a safe mode.
* Companion extension support for VS Code, Trae, and compatible IDEs.

The IDE extension (Eliodata WordPress Companion, for VS Code, Trae and compatible editors) is available as a VSIX file on the GitHub releases page. The plugin also works on its own, from the WordPress admin.

Project links:

* IDE extension and source code: https://github.com/eliodata/eliodata-snippet-hub/releases
* GitHub repository: https://github.com/eliodata/eliodata-snippet-hub

= English =

Use Eliodata MCP Bridge to create, edit, activate, deactivate, and target snippets directly in WordPress or remotely from a companion IDE extension. The same plugin also exposes generic MCP endpoints and lets each site define its own custom tools.

Main capabilities:

1. Manage snippets from WordPress admin.
2. Browse and test MCP tools from the WordPress admin.
3. Discover and run MCP tools remotely through secure API endpoints.
4. Define custom MCP tools per WordPress site.
5. Assign snippets globally, by post type, or by specific content IDs.
6. Import and export snippets in JSON and NDJSON.

= Français =

Eliodata MCP Bridge permet de créer, modifier, activer, désactiver et cibler des snippets directement dans WordPress ou à distance depuis une extension IDE compagnon. Le même plugin expose aussi des endpoints MCP génériques et permet à chaque site de définir ses propres outils custom.

Fonctionnalités principales :

1. Gestion des snippets depuis l’administration WordPress.
2. Parcours et test des outils MCP depuis l’administration WordPress.
3. Découverte et exécution d’outils MCP via des endpoints API sécurisés.
4. Création d’outils MCP personnalisés propres à chaque site WordPress.
5. Ciblage global, par type de contenu, ou par IDs de contenus.
6. Import et export des snippets en JSON et NDJSON.

= Requirements / Prérequis =

* WordPress 5.6+ (Application Passwords)
* PHP 7.4+
* WordPress admin access for plugin management

== Installation ==

= English =

1. Download the latest plugin package.
2. Upload it in WordPress: **Plugins > Add New > Upload Plugin**.
3. Activate **Eliodata MCP Bridge**.
4. Generate an Application Password in **Users > Profile**.
5. Optionally install the IDE extension from https://github.com/eliodata/eliodata-snippet-hub/releases and connect it with the site URL, your username and the application password.

= Français =

1. Téléchargez la dernière archive du plugin.
2. Importez-la dans WordPress : **Extensions > Ajouter > Téléverser une extension**.
3. Activez **Eliodata MCP Bridge**.
4. Générez un mot de passe d’application dans **Utilisateurs > Profil**.
5. Installez si besoin l’extension IDE depuis https://github.com/eliodata/eliodata-snippet-hub/releases et connectez-la avec l’URL du site, l’identifiant et le mot de passe d’application.

= Security / Sécurité =

* Safe mode: add `define('ELIODATA_SNIPPET_HUB_SAFE_MODE', true);` to wp-config.php to stop running all snippets.
* On multisite, only super admins can manage snippets.
* Snippets with a PHP syntax error are never activated, and a snippet that causes a fatal error is deactivated automatically.
* Read-only Application Passwords: include `[readonly]` in the password name to block every write request made with it.
* Uses WordPress REST API permissions.
* Supports Application Password authentication.
* Capabilities can be filtered by the site owner.
* Input is sanitized and validated server-side.

== Frequently Asked Questions ==

= English =

= Do I need another snippet plugin? =

No. Eliodata MCP Bridge includes its own native snippet engine.

= Does it work with VS Code and Trae? =

Yes. The plugin exposes generic MCP endpoints and works with compatible IDE companions such as VS Code, Trae, and similar tools.

= Can each site have its own MCP tools? =

Yes. Custom MCP tools are stored per WordPress site, so every connected site can expose its own actions and schemas.

= Is it safe for production sites? =

Yes, when used with standard WordPress security practices and Application Passwords. Test new snippets on a staging site first.

= A snippet broke my site. How do I recover? =

A snippet that causes a fatal error is deactivated automatically and the error is shown in the admin. If the site is still unavailable, add `define('ELIODATA_SNIPPET_HUB_SAFE_MODE', true);` to wp-config.php: no snippet runs, and you can fix or deactivate the snippet from the plugin screens.

= What changes when upgrading from 2.0? =

Snippets that target all content now run on `init`, also during REST API, cron and AJAX requests. Call conditional tags such as `is_page()` inside a hook, and check the `permission_callback` of REST routes declared in snippets. To keep the previous timing, add `define('ELIODATA_SNIPPET_HUB_LEGACY_TIMING', true);` to wp-config.php.

= Français =

= Faut-il installer un autre plugin de snippets ? =

Non. Eliodata MCP Bridge intègre son propre moteur natif.

= Est-ce compatible avec VS Code et Trae ? =

Oui. Le plugin expose des endpoints MCP génériques et fonctionne avec des extensions IDE compatibles comme VS Code, Trae et outils similaires.

= Chaque site peut-il avoir ses propres outils MCP ? =

Oui. Les outils MCP custom sont stockés par site WordPress, ce qui permet à chaque connexion IDE d’exposer ses propres actions et schémas.

= Est-ce utilisable en production ? =

Oui, en appliquant les bonnes pratiques WordPress et les mots de passe d’application. Testez les nouveaux snippets sur un site de recette.

= Un snippet a cassé mon site, que faire ? =

Un snippet qui provoque une erreur fatale est désactivé automatiquement et l’erreur est affichée dans l’administration. Si le site reste indisponible, ajoutez `define('ELIODATA_SNIPPET_HUB_SAFE_MODE', true);` dans wp-config.php : aucun snippet ne s’exécute et vous pouvez corriger ou désactiver le snippet depuis les écrans du plugin.

== Changelog ==

= 4.2.2 =
* New: a header with the plugin navigation (Snippets, New snippet, Assignments, Import / Export, MCP tools) stays at the top of every plugin screen.
* Fix: snippets written to the Code Snippets tables are now checked for PHP syntax errors before they are stored (PHP scopes only), as native snippets already were.
* Fix: the Code Snippets cache is cleared after a snippet is created, updated or deleted through the API.
* Removed the permanent information notice shown on every plugin screen; runtime notices are now in French.

= 4.2.1 =
* MCP screen redesigned: one catalog listing every tool once, with where it is visible (read and write, or write only), its parameters and its JSON schema on demand.
* The catalog explains why a custom tool is hidden from the read profile (not declared read-only, or declared read-only but not called with GET).
* Tester: the profile follows the tool, required arguments are prefilled from the schema, the last arguments are kept after a run, a write tool asks for confirmation, and the result shows status, duration and a copy button.
* Custom tool form: folded until needed, live JSON check, method and argument mode kept consistent, and a failed save keeps what was typed.
* Admin menu and page titles are now all in French, like the rest of the screens.

= 4.2.0 =
* Plugin renamed to "Eliodata MCP Bridge" (same slug, settings and snippets are preserved).
* New: MCP endpoints to discover and run tools (`/mcp/tools`, `/mcp/call`).
* New: per-site custom MCP tools, managed from the admin screen or through `/mcp/custom-tools`.
* New: "MCP" admin screen to browse and test tools from WordPress.
* New: built-in MCP tools for snippets (list, get, create, update, delete) with read and write profiles.
* New: read-only Application Passwords. Add `[readonly]` to the password name: every write request made with it is refused on the whole REST API (not only the plugin routes), and XML-RPC is blocked.
* New: PHP syntax check before a snippet is saved. The REST API rejects invalid code; the admin screen and the importer keep it but deactivated.
* New: a snippet that causes a fatal error is deactivated automatically, including errors raised later by its callbacks. The error is shown in the admin.
* New: safe mode. Add `define('ELIODATA_SNIPPET_HUB_SAFE_MODE', true);` to wp-config.php to stop running snippets.
* Improved: snippets never run on the plugin admin screens and on the `/ide/v1/` routes, so a broken snippet can always be fixed.
* Changed: snippets that target all content now run on `init` on every request, including REST API, cron and AJAX requests. Snippets that target post types or specific posts still wait for the main query on the front end. Conditional tags such as `is_page()` must be called inside a hook. To keep the previous timing, add `define('ELIODATA_SNIPPET_HUB_LEGACY_TIMING', true);` to wp-config.php.
* Changed: snippet code is compiled once per version in a private folder of uploads (`eliodata-snippet-hub`), instead of a temporary file on every request.
* Changed: on multisite, managing snippets requires the `manage_network_options` capability (super admins). Filterable with `eliodata_snippet_hub_required_capability`.
* Fixed: custom MCP tools are listed in the MCP catalog (read-only GET tools for the read profile, all tools for the write profile).
* Fixed: snippet changes made through the REST API and bulk actions now clear the object cache.
* New: optional support for the Code Snippets plugin tables as a secondary snippet engine.
* New: dedicated `eliodata_snippet_hub_manage` capability, filterable via `eliodata_snippet_hub_api_capability` and `eliodata_snippet_hub_mcp_capability`.
* Improved: snippet code can be sent base64-encoded (`code_b64`) to avoid transport issues with special characters.
* Security: removed all site-specific code from the public package; only generic snippet tools and site-defined custom tools are exposed.

= 2.0.0 =
* Native snippet engine and secure REST bridge for remote snippet management from Trae AI and VS Code.
* Admin screens for snippets, content targeting, and import/export (JSON, NDJSON).

== Upgrade Notice ==

= 4.2.2 =
Upgrading from 2.0: snippets targeting all content now run on init, also during REST, cron and AJAX requests. Test on a staging site first and read the FAQ. Adds MCP tools, read-only Application Passwords, syntax checks and automatic deactivation of broken snippets.

= 4.2.0 =
Snippets targeting all content now run earlier (on init) and also during REST, cron and AJAX requests. Test on a staging site first, and see the changelog to keep the previous timing. Also adds MCP endpoints, custom MCP tools, read-only Application Passwords and automatic deactivation of broken snippets.
