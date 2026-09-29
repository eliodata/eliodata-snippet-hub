# Changelog

## 4.2.2

### Fixed
- A snippet file changed outside the editor (script, Obsidian, AI agent, git) was silently replaced by the older server version at the next refresh, which follows any save in the editor. The next save then published that older version. Since 4.2.0 such files are no longer sent automatically, so the refresh must not overwrite them either: a file whose code differs both from its last sync (`local_hash`) and from the server is now kept, with a warning offering to open it. Saving it in the editor publishes it, as before.
- A doc comment at the top of a snippet (`/** ... */`) was removed from the code sent to WordPress. Only the header written by the old v3 cache format (` * Snippet ID: N`) is removed now. This also prevents the check above from holding such a file after every save.

## 4.2.1

### Fixed
- Saving a snippet in a window that did not hold the sync lock did nothing, silently. The lock now follows the window you save in: it is taken over from the previous window, which drops to read-only on its next heartbeat. Windows set to `syncRole: editor` or `off` never take it.
- When a save is still not sent to WordPress (untrusted workspace, `editor` or `off` role), a warning says so instead of a console log.

## 4.2.0

Requires the **Eliodata MCP Bridge** WordPress plugin 4.2.0 or later.

### Added
- MCP tools: browse, refresh, and run the MCP tools exposed by the connected site (`Browse MCP Tools`, `Run MCP Tool`, `Run Current MCP Tool`, `Refresh MCP Catalog`).
- Per-site custom MCP tools: create, edit, and delete them from the IDE.
- Configurable local storage (`wordpressSnippets.workspaceStoragePath`): snippets are stored as Markdown files with YAML frontmatter, and backups and MCP caches go in the same folder. Point it to a folder inside an Obsidian vault to see the files in Obsidian.
- Per-workspace binding to one site (`wordpressSnippets.workspaceConnectionId`, `wordpressSnippets.workspaceSiteFolder`).
- Multi-workspace sync roles (`wordpressSnippets.syncRole`: `owner`, `editor`, `off`) with lock files, so only one workspace pushes changes to a site.
- Automatic frontmatter enrichment (domain, dependencies, function calls) after a snippet is synced to WordPress. Domains use generic WordPress rules by default and can be customized with `wordpressSnippets.domainRules`.
- Snippet analysis detects post types declared or queried by the snippet (`register_post_type`, `post_type` queries, `get_post_type()` checks).

### Security
- Snippets are sent to WordPress only when you save them in the editor. Changes made outside the editor (git pull, branch switch, Obsidian, scripts) are no longer pushed automatically.
- PHP syntax is checked with `php -l` before a snippet is sent, when PHP is installed locally. The WordPress plugin checks it again on its side.
- Untrusted workspaces: snippets stay readable, but nothing is sent to WordPress and the workspace cannot change the storage or sync settings.

### Fixed
- The MCP catalog now lists write tools and custom MCP tools (requires plugin 4.2.0). A read-only application password still only sees read-only tools.
- Snippet code sent through the MCP `snippet_create` and `snippet_update` tools is base64-encoded, like direct API calls.
- A snippet refused by WordPress no longer overwrites the local file with the server version.
- Errors returned by WordPress (for example a syntax error) are shown instead of a generic HTTP error.

### Changed
- Extension renamed to **Eliodata WordPress Companion**.
- Snippet engines now follow the plugin: the native engine, and the Code Snippets plugin tables when available.
- `wordpressSnippets.obsidianVaultPath` is deprecated in favor of `wordpressSnippets.workspaceStoragePath`. The old setting is still read as a fallback.

### Removed
- FluentSnippets provider.
- Local vector search service.

## 3.0.1

- Aligned the extension with the native snippet engine of the WordPress plugin (IDE Snippets Bridge v2).

## 3.0.0

- Multi-site management, FluentSnippets support, and a simpler setup with the WordPress bridge plugin.

Earlier releases: see the [GitHub releases](https://github.com/eliodata/eliodata-snippet-hub/releases).
