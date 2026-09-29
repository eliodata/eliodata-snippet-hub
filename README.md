# Eliodata MCP Bridge + WordPress Companion

Manage WordPress PHP snippets and MCP tools from your IDE (VS Code, Trae, and compatible editors).

![Icon](assets/icon.png)

This repository contains two parts that work together:

| Part | Folder | Version |
|---|---|---|
| **Eliodata MCP Bridge**, WordPress plugin | [`eliodata-snippet-hub/`](eliodata-snippet-hub/) | 4.2.2 |
| **Eliodata WordPress Companion**, IDE extension | repository root | 4.2.3 |

- The **plugin** runs the snippets on your site, exposes a secure REST API and MCP endpoints, and lets each site define its own MCP tools.
- The **extension** connects your IDE to one or more sites: edit snippets as local files, sync them on save, and browse or run MCP tools.

## Links

- Releases (plugin zip and extension VSIX): https://github.com/eliodata/eliodata-snippet-hub/releases
- WordPress plugin page: https://wordpress.org/plugins/eliodata-snippet-hub/
- Changelogs: [extension](CHANGELOG.md), [plugin](eliodata-snippet-hub/readme.txt)

## Requirements

- WordPress 5.6 or later (Application Passwords), PHP 7.4 or later
- An administrator account on the site (on multisite, a super admin)
- VS Code 1.90 or later, or a compatible editor such as Trae
- Optional: PHP installed locally, to check the syntax before a snippet is sent

## Installation

1. **Plugin**: download `eliodata-snippet-hub-<version>.zip` from the [latest release](https://github.com/eliodata/eliodata-snippet-hub/releases), then in WordPress go to **Plugins > Add New > Upload Plugin** and activate **Eliodata MCP Bridge**.
2. **Application Password**: in **Users > Profile**, create an Application Password for the IDE.
3. **Extension**: download `eliodata-wordpress-companion-<version>.vsix` from the same release, then in your IDE run **Extensions: Install from VSIX...**.
4. **Connection**: run **WordPress Companion: Manage Connections** and enter the site URL, your username and the Application Password.

Test on a staging site first when you upgrade from a version older than 4.2.0: see [Upgrading to 4.2](#upgrading-to-42).

## WordPress Plugin

- Native snippet engine with its own table, admin screens, content targeting (all content, post types, or specific posts), bulk actions, and JSON / NDJSON import and export.
- Optional support for the tables of the Code Snippets plugin as a second engine.
- REST API (`/wp-json/ide/v1/`) used by the extension.
- MCP endpoints (`/wp-json/eliodata-snippet-hub/v1/mcp/`): built-in snippet tools (list, get, create, update, delete) and custom tools that call any REST route of the site.
- MCP admin screen: catalog of every tool, tester with confirmation for write tools, and a form to create custom tools.

## IDE Extension

- Snippets are stored as Markdown files with YAML frontmatter in a local folder you choose (it can be inside an Obsidian vault).
- A snippet is sent to WordPress when you save its file in the editor, with a backup of the previous version.
- Several sites, and several windows per site: only one window sends changes to a site at a time (`wordpressSnippets.syncRole`).
- MCP: browse, refresh, and run the tools of the connected site, and create, edit, or delete its custom tools.
- Frontmatter enrichment after each sync: dependencies between snippets, hooks, shortcodes, and a domain based on rules you can configure.

## Security

- **Authentication**: WordPress Application Passwords, stored in the IDE secret storage.
- **Permissions**: the `manage_options` capability (`manage_network_options` on multisite). The REST API and MCP endpoints also accept the `eliodata_snippet_hub_manage` capability, if you grant it to a role.
- **Read-only access**: put `[readonly]` in the name of an Application Password. Every write request made with it is refused on the whole REST API, and XML-RPC is blocked.
- **Syntax check**: PHP code is checked before it is stored. The REST API rejects invalid code; the admin screen and the importer keep it but deactivated.
- **Fatal errors**: a snippet that causes a fatal error is deactivated automatically and the error is shown in the admin.
- **Safe mode**: add `define('ELIODATA_SNIPPET_HUB_SAFE_MODE', true);` to `wp-config.php` to stop running every snippet.
- **Recovery**: snippets never run on the plugin screens and on the `/ide/v1/` routes, so a broken snippet can always be fixed.
- **IDE side**: changes made outside the editor (git pull, branch switch, Obsidian, scripts) are never sent automatically. In an untrusted workspace, nothing is sent and the workspace cannot change the storage or sync settings.

## Upgrading to 4.2

- Snippets that target all content now run on `init` on every request, including REST, cron, and AJAX requests. Before 4.2 they only ran on front-end pages and in the admin, so REST routes or cron hooks declared in a snippet did not work.
- Check snippets that call conditional tags such as `is_page()` directly: call them inside a hook. To keep the previous timing, add `define('ELIODATA_SNIPPET_HUB_LEGACY_TIMING', true);` to `wp-config.php`.
- REST routes declared in snippets now respond: check their `permission_callback` before upgrading.
- On multisite, only super admins can manage snippets.

## Extension Settings

- `wordpressSnippets.workspaceStoragePath`
  Absolute path to the local folder used for snippets, backups, and MCP cache files. If empty, the extension uses an internal folder managed by the IDE.
- `wordpressSnippets.workspaceConnectionId`
  Optional. Forces one WordPress site for the current workspace.
- `wordpressSnippets.workspaceSiteFolder`
  Optional. Binds the current workspace to one `site-*` local folder.
- `wordpressSnippets.syncRole`
  - `owner`: this window sends changes to WordPress (default)
  - `editor`: this window stays read-only while another window sends changes
  - `off`: sync is disabled for this workspace
- `wordpressSnippets.domainRules`
  Optional rules used to fill the `domain` field in snippet frontmatter. Each rule maps a domain to keywords searched in the snippet name, title, and code. Leave empty to use the generic WordPress rules:

  ```json
  "wordpressSnippets.domainRules": [
      { "domain": "billing", "keywords": ["invoice", "credit_note"] },
      { "domain": "booking", "keywords": ["booking", "calendar"] }
  ]
  ```

### Obsidian

No dedicated integration is needed. Point `wordpressSnippets.workspaceStoragePath` to a folder inside your vault, for example `/Users/your-name/Documents/Obsidian/MyVault/wordpress-companion`. The extension then creates:

```text
wordpress-companion/
  snippets/site-example/
  .snippet_backups/site-example/
  .trae/locks/
```

Files edited in Obsidian stay local until you save them in the IDE.

## Français

Ce dépôt contient le plugin WordPress **Eliodata MCP Bridge** (dossier `eliodata-snippet-hub/`) et l'extension IDE **Eliodata WordPress Companion** (racine du dépôt).

1. Téléchargez le zip du plugin et le fichier `.vsix` de l'extension depuis la [dernière release](https://github.com/eliodata/eliodata-snippet-hub/releases).
2. Installez le plugin dans **Extensions > Ajouter > Téléverser une extension**, puis activez-le.
3. Créez un mot de passe d'application dans **Utilisateurs > Profil**. Ajoutez `[readonly]` à son nom pour un accès en lecture seule.
4. Installez l'extension avec **Extensions: Install from VSIX...**, puis ajoutez le site avec **WordPress Companion: Manage Connections**.

Un snippet est envoyé à WordPress quand vous sauvegardez son fichier dans l'éditeur. Un snippet qui provoque une erreur fatale est désactivé automatiquement, et le mode sans échec (`ELIODATA_SNIPPET_HUB_SAFE_MODE`) arrête tous les snippets. Lisez la section [Upgrading to 4.2](#upgrading-to-42) avant de mettre à jour depuis une version antérieure.

## License

GPL v3 or later. See [LICENSE](LICENSE).
