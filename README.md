# WordPress Snippet Manager (v3.0.0)

Manage your WordPress code snippets directly from your IDE (VS Code, Trae AI) with full AI integration. This extension works in tandem with a lightweight WordPress "Bridge" plugin to securely edit, create, and manage your snippets without leaving your editor.

![Icon](assets/icon.png)

## ✨ What's New in Version 3.0.0

-   **Multi-Site Management**: Connect to and switch between multiple WordPress sites instantly.
-   **Multi-Plugin Support**: Full compatibility with both **Code Snippets** and **FluentSnippets**.
-   **Enhanced Search**: Search by name, description, content, or ID (e.g., "FS2" for FluentSnippet #2).
-   **Secure Authentication**: Uses WordPress Application Passwords for robust security.
-   **Improved UI**: Better connection management and snippet organization.

## 🚀 Key Features

-   **AI-Powered Editing**: Describe changes in natural language, and let the AI handle the code.
-   **Live Synchronization**: Edits are instantly synced to your WordPress site.
-   **Automatic Backups**: Every change is backed up, allowing for easy restoration.
-   **Visual Previews**: See the impact of your changes (requires supported setup).

## 🛠️ Installation & Setup

This solution consists of two parts: the **IDE Extension** and the **WordPress Bridge Plugin**.

### Step 1: Install the WordPress Bridge Plugin

1.  Download the `ide-snippets-bridge` plugin folder from this repository (located in `wordpress-plugin/`).
2.  Zip the folder (`ide-snippets-bridge.zip`).
3.  Go to your WordPress Admin Dashboard: **Plugins > Add New > Upload Plugin**.
4.  Upload and activate the plugin.
5.  **Requirement**: Ensure you have either the **Code Snippets** or **FluentSnippets** plugin installed and active.

### Step 2: Configure WordPress Authentication

To securely connect your IDE to WordPress, you must use an **Application Password**:

1.  In WordPress, go to **Users > Profile** (or edit your user).
2.  Scroll down to the **Application Passwords** section.
3.  Enter a name (e.g., "Trae IDE") and click **Add New Application Password**.
4.  **Copy the generated password immediately** (you won't see it again).

### Step 3: Connect the Extension

1.  Open the **WordPress Snippets** view in your IDE.
2.  Click on **Manage Connections** (or the 🌍/🖥️ icon).
3.  Click **Add New Connection**.
4.  Enter your details:
    -   **Name**: A label for this site (e.g., "My Blog").
    -   **URL**: Your site's URL (e.g., `https://mysite.com`).
    -   **Username**: Your WordPress username.
    -   **Application Password**: The password you generated in Step 2.
5.  Save the connection.

## 📖 Usage Guide

-   **Browsing**: Snippets are listed in the sidebar. Use the refresh button to sync.
-   **Editing**: Click a snippet to open it. Changes are saved locally and can be synced back.
-   **Creating**: Use the "New Snippet" command to start from scratch.
-   **Switching Sites**: Use the site switcher in the top bar to change the active WordPress connection.

## 🔒 Security

-   All communication is done via the WordPress REST API.
-   Authentication is handled via **Application Passwords**, which can be revoked at any time from your WordPress profile.
-   The bridge plugin respects standard WordPress capabilities (requires `manage_options` or similar admin rights).

## License

MIT License. See [LICENSE](LICENSE) for details.

---
Made with ❤️ by [eliodata.com](https://eliodata.com)
