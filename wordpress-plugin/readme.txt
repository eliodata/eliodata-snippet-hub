=== IDE Code Snippets Bridge ===
Contributors: eliodata
Donate link: https://eliodata.com/donate
Tags: snippets, code, ide, api, development, fluentsnippets, codesnippets
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.2.0
Requires PHP: 7.4
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Bridge plugin that connects your WordPress site with IDE extensions (like Trae AI, VS Code) for seamless AI-powered code snippet management.

== Description ==

**Transform your WordPress development workflow with AI-powered snippet management directly from your IDE.**

IDE Code Snippets Bridge serves as the essential connection between your WordPress site and compatible IDE extensions, enabling seamless code snippet management with cutting-edge artificial intelligence assistance.

= 🚀 Compatible with =

This plugin works as a bridge for:
*   **Code Snippets**: The popular snippet management plugin.
*   **FluentSnippets**: The advanced snippet manager.

It connects these plugins with IDE extensions like **Trae AI - WordPress Snippets Manager** to allow you to:

*   **Edit snippets with natural language**: Simply describe what you want to change.
*   **Create complex functionality**: From simple design tweaks to advanced logic.
*   **Manage snippets effortlessly**: List, view, edit, and organize all your snippets.
*   **Sync in real-time**: Changes are instantly reflected on your WordPress site.
*   **Automatic backups**: Every modification is safely backed up.

= 🔧 How It Works =

This plugin creates a secure REST API that allows compatible IDE extensions to:

1.  **Retrieve** all your existing code snippets (from Code Snippets or FluentSnippets).
2.  **Create** new snippets directly from your IDE.
3.  **Update** snippet content with AI-powered modifications.
4.  **Delete** snippets you no longer need.
5.  **Toggle** snippet activation status.

= 📋 Requirements =

*   **WordPress**: 5.0 or higher
*   **PHP**: 7.4 or higher
*   **Snippet Plugin**: Either [Code Snippets](https://wordpress.org/plugins/code-snippets/) OR [FluentSnippets](https://fluentsnippets.com/) must be installed and active.
*   **Administrator Access**: Required for API authentication.

= 🔒 Security Features =

*   **Application Passwords**: Supports and recommends WordPress Application Passwords for secure authentication.
*   **Administrator Only**: Requires `manage_options` capability.
*   **Secure API**: All endpoints are properly sanitized and validated.
*   **No External Dependencies**: Works entirely within WordPress security framework.

== Installation ==

= Automatic Installation =

1.  Log in to your WordPress admin dashboard.
2.  Go to **Plugins > Add New**.
3.  Upload the `ide-snippets-bridge.zip` file.
4.  Click **Install Now** and then **Activate**.

= Manual Installation =

1.  Download the plugin zip file.
2.  Unzip and upload the `ide-snippets-bridge` folder to your `/wp-content/plugins/` directory.
3.  Activate the plugin through the 'Plugins' menu in WordPress.

= Setup =

1.  **Install a Snippet Plugin**: Ensure you have **Code Snippets** or **FluentSnippets** active.
2.  **Generate Application Password**: Go to **Users > Profile**, scroll to "Application Passwords", create a new one, and copy it.
3.  **Configure IDE Extension**: In VS Code/Trae, add a new connection using your site URL, username, and the Application Password.

== Frequently Asked Questions ==

= Do I need a snippet plugin? =

Yes, this plugin acts as a bridge. You must have either **Code Snippets** (free/pro) or **FluentSnippets** installed to actually store and execute the code.

= Is this plugin secure? =

Absolutely. It uses WordPress's native REST API authentication. We strongly recommend using **Application Passwords** rather than your main password for granular security control.

= Does this work with any IDE? =

This plugin provides a standard REST API, but it is optimized for the **WordPress Snippets Manager** extension for VS Code and Trae AI.
