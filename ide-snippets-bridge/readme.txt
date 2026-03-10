=== Eliodata Snippet Hub ===
Contributors: eliodata
Donate link: https://eliodata.com
Tags: snippets, ide, api, wordpress, eliodata
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 2.0.0
Requires PHP: 7.4
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Native snippet engine + secure IDE bridge for remote snippet management with Trae AI and VS Code.

== Description ==

Eliodata Snippet Hub is a complete snippet platform for WordPress:

* Native snippet engine included in the plugin.
* Secure REST API bridge for IDE integrations.
* Remote management from VS Code and Trae AI.
* Import/export, activation, targeting, and bulk actions.
* Authentication via WordPress Application Passwords.

Project links:

* WordPress plugin page: https://wordpress.org/plugins/eliodata-snippet-hub/
* GitHub repository: https://github.com/eliodata/eliodata-snippet-hub

= English =

Use Eliodata Snippet Hub to create, edit, activate, deactivate, and target snippets directly in WordPress or remotely from compatible IDE tools.

Main capabilities:

1. Manage snippets from WordPress admin.
2. Manage snippets remotely through secure API endpoints.
3. Assign snippets globally, by post type, or by specific content IDs.
4. Import and export snippets in JSON and NDJSON.
5. Keep compatibility extensible through addons.

= Français =

Eliodata Snippet Hub permet de créer, modifier, activer, désactiver et cibler des snippets directement dans WordPress ou à distance depuis des IDE compatibles.

Fonctionnalités principales :

1. Gestion des snippets depuis l’administration WordPress.
2. Gestion distante via des endpoints API sécurisés.
3. Ciblage global, par type de contenu, ou par IDs de contenus.
4. Import et export des snippets en JSON et NDJSON.
5. Compatibilité extensible via addons.

= Requirements / Prérequis =

* WordPress 5.0+
* PHP 7.4+
* Administrator role (`manage_options`) for API access

== Installation ==

= English =

1. Download the latest plugin package.
2. Upload it in WordPress: **Plugins > Add New > Upload Plugin**.
3. Activate **Eliodata Snippet Hub**.
4. Generate an Application Password in **Users > Profile**.
5. Connect your IDE extension with site URL, username, and application password.

= Français =

1. Téléchargez la dernière archive du plugin.
2. Importez-la dans WordPress : **Extensions > Ajouter > Téléverser une extension**.
3. Activez **Eliodata Snippet Hub**.
4. Générez un mot de passe d’application dans **Utilisateurs > Profil**.
5. Connectez l’extension IDE avec l’URL du site, l’identifiant et le mot de passe d’application.

= Security / Sécurité =

* Uses WordPress REST API permissions.
* Supports Application Password authentication.
* Requires administrator capability for management actions.
* Input is sanitized and validated server-side.

== Frequently Asked Questions ==

= English =

= Do I need another snippet plugin? =

No. Eliodata Snippet Hub includes its own native snippet engine.

= Does it work with VS Code and Trae AI? =

Yes. It is designed to connect securely to compatible IDE extensions.

= Is it safe for production sites? =

Yes, when used with standard WordPress security practices and Application Passwords.

= Français =

= Faut-il installer un autre plugin de snippets ? =

Non. Eliodata Snippet Hub intègre son propre moteur natif.

= Est-ce compatible avec VS Code et Trae AI ? =

Oui. Le plugin est conçu pour se connecter de manière sécurisée aux extensions IDE compatibles.

= Est-ce utilisable en production ? =

Oui, en appliquant les bonnes pratiques WordPress et les mots de passe d’application.
