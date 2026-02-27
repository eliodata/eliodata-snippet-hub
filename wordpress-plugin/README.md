# IDE Code Snippets Bridge

*Bridge your WordPress development with AI-powered snippet management*

This WordPress plugin serves as the essential bridge between your WordPress site and IDE extensions (like [Trae AI](https://github.com/trae-ai/wordpress-snippets-manager) or VS Code extensions), enabling seamless code snippet management with cutting-edge AI assistance.

## 🚀 Compatible with

This plugin works as a bridge for:
-   **Code Snippets**: The popular snippet management plugin.
-   **FluentSnippets**: The advanced snippet manager.

It connects these plugins with IDE extensions like **Trae AI - WordPress Snippets Manager** to allow you to:

-   **Edit snippets with natural language**: Simply describe what you want to change
-   **Create complex functionality**: From simple design tweaks to advanced logic
-   **Manage snippets effortlessly**: List, view, edit, and organize all your snippets
-   **Sync in real-time**: Changes are instantly reflected on your WordPress site
-   **Automatic backups**: Every modification is safely backed up with easy restoration

## 🔧 How It Works

This plugin creates a secure REST API that allows compatible IDE extensions to:

1.  **Retrieve** all your existing code snippets (from Code Snippets or FluentSnippets)
2.  **Create** new snippets directly from your IDE
3.  **Update** snippet content with AI-powered modifications
4.  **Delete** snippets you no longer need
5.  **Toggle** snippet activation status

## 📋 Requirements

-   **WordPress**: 5.0 or higher
-   **PHP**: 7.4 or higher
-   **Snippet Plugin**: Either [Code Snippets](https://wordpress.org/plugins/code-snippets/) OR [FluentSnippets](https://fluentsnippets.com/) must be installed and active.
-   **Administrator Access**: Required for API authentication.

## 🛠️ Installation

### Method 1: WordPress Admin (Recommended)

1.  Download the latest release from the [GitHub Repository](https://github.com/eliodata/wordpress-snippets-manager).
2.  In your WordPress admin, go to **Plugins > Add New**.
3.  Click **Upload Plugin** and select the downloaded zip file (`ide-snippets-bridge.zip`).
4.  Click **Install Now** and then **Activate**.

### Method 2: Manual Installation

1.  Download and extract the plugin files.
2.  Upload the `ide-snippets-bridge` folder to `/wp-content/plugins/`.
3.  Activate the plugin through the WordPress admin.

## ⚙️ Configuration

### WordPress Setup

1.  **Install a Snippet Plugin**: Ensure you have **Code Snippets** or **FluentSnippets** active.
2.  **Generate Application Password**:
    -   Go to **Users > Profile**.
    -   Scroll down to **Application Passwords**.
    -   Enter a name (e.g., "Trae IDE") and click **Add New Application Password**.
    -   **Copy the generated password**.

### IDE Extension Setup

1.  **Install Compatible IDE Extension**: Get the extension for VS Code or Trae AI.
2.  **Configure Connection**:
    -   Enter your WordPress site URL.
    -   Enter your WordPress username.
    -   Paste the **Application Password** generated above.

## 🔒 Security Features

-   **Application Passwords**: Supports and recommends WordPress Application Passwords for secure authentication.
-   **Administrator Only**: Requires `manage_options` capability.
-   **Secure API**: All endpoints are properly sanitized and validated.
-   **No External Dependencies**: Works entirely within WordPress security framework.

## 🔌 API Endpoints

The plugin provides the following REST API endpoints:

-   `GET /wp-json/ide/v1/snippets` - List all snippets
-   `POST /wp-json/ide/v1/snippets` - Create new snippet
-   `GET /wp-json/ide/v1/snippets/{id}` - Get specific snippet
-   `PUT /wp-json/ide/v1/snippets/{id}` - Update snippet
-   `DELETE /wp-json/ide/v1/snippets/{id}` - Delete snippet

## 🤝 Compatibility

-   **Code Snippets Plugin**: Full compatibility with all versions
-   **FluentSnippets**: Full compatibility
-   **WordPress Multisite**: Supported
-   **Popular Themes**: Works with any WordPress theme

## License

GPLv3 or later.
