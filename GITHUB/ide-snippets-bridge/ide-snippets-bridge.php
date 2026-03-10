<?php
/**
 * Plugin Name: Eliodata Snippet Hub
 * Plugin URI: https://wordpress.org/plugins/eliodata-snippet-hub/
 * Description: Native snippet engine and secure IDE bridge for remote snippet management from Trae AI and VS Code.
 * Version: 2.0.0
 * Author: Eliodata
 * Author URI: https://eliodata.com
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Text Domain: ide-snippets-bridge
 *
 * @package IDESnippets
 * @subpackage Bridge
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('IDE_SNIPPETS_BRIDGE_VERSION', '2.0.0');
define('IDE_SNIPPETS_BRIDGE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('IDE_SNIPPETS_BRIDGE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('IDE_SNIPPETS_BRIDGE_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Include the API endpoint class
require_once IDE_SNIPPETS_BRIDGE_PLUGIN_DIR . 'includes/class-ide-snippets-api.php';

/**
 * Main plugin class
 *
 * @since 1.0.0
 */
class IDE_Snippets_Bridge {

    /** @var IDE_Snippets_Bridge|null */
    private static $instance = null;
    private $runtime_executed = false;

    /**
     * Get plugin instance (singleton)
     *
     * @return IDE_Snippets_Bridge
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('rest_api_init', [$this, 'init_api']);
        add_action('admin_notices', [$this, 'check_dependencies']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'handle_admin_requests']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('init', [$this, 'maybe_upgrade_native_schema'], 0);
        add_action('init', [$this, 'execute_active_snippets'], 1);
        add_action('wp', [$this, 'execute_active_snippets'], 1);
        add_action('add_meta_boxes', [$this, 'register_post_snippets_metabox']);
        add_action('save_post', [$this, 'save_post_snippets_assignments'], 10, 2);

        // Plugin activation/deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    /**
     * Initialize the API endpoints
     */
    public function init_api() {
        $api = new IDE_Snippets_API();
        $api->register_routes();
    }

    /**
     * Check for required dependencies and display admin notice if missing
     */
    public function check_dependencies() {
        if (!is_admin()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && isset($screen->id) && is_string($screen->id) && strpos($screen->id, 'ide-snippets-bridge') === false) {
            return;
        }
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>' . esc_html__('Eliodata Snippet Hub:', 'ide-snippets-bridge') . '</strong> ';
        echo esc_html__('The native Eliodata Snippet Hub engine is active. Additional compatibility can be enabled through addons.', 'ide-snippets-bridge');
        echo '</p>';
        echo '</div>';
    }

    /**
     * Plugin activation
     */
    public function activate() {
        if (!function_exists('deactivate_plugins')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (version_compare(get_bloginfo('version'), '5.0', '<')) {
            deactivate_plugins(IDE_SNIPPETS_BRIDGE_PLUGIN_BASENAME);
            wp_die(esc_html__('Eliodata Snippet Hub requires WordPress 5.0 or higher.', 'ide-snippets-bridge'));
        }

        if (version_compare(PHP_VERSION, '7.4', '<')) {
            deactivate_plugins(IDE_SNIPPETS_BRIDGE_PLUGIN_BASENAME);
            wp_die(esc_html__('Eliodata Snippet Hub requires PHP 7.4 or higher.', 'ide-snippets-bridge'));
        }

        IDE_Snippets_API::create_native_table();
        update_option('ide_snippets_bridge_schema_version', IDE_SNIPPETS_BRIDGE_VERSION);
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }

    public function maybe_upgrade_native_schema() {
        $stored_version = get_option('ide_snippets_bridge_schema_version', '');
        if ((string) $stored_version === (string) IDE_SNIPPETS_BRIDGE_VERSION) {
            return;
        }
        IDE_Snippets_API::create_native_table();
        update_option('ide_snippets_bridge_schema_version', IDE_SNIPPETS_BRIDGE_VERSION);
    }

    private function get_admin_menu_icon_data_uri() {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="20" height="20"><path fill="#111111" d="M10 1l3 3-3 3-3-3 3-3zm-4 8h8l-4 4-4-4zm4 4l3 3-3 3-3-3 3-3zM1 10l4-4 2 2-2 2 2 2-2 2-4-4zm18 0l-4-4-2 2 2 2-2 2 2 2 4-4z"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public function register_admin_menu() {
        add_menu_page(
            esc_html__('Eliodata Snippet Hub', 'ide-snippets-bridge'),
            esc_html__('Eliodata Snippet Hub', 'ide-snippets-bridge'),
            'manage_options',
            'ide-snippets-bridge',
            [$this, 'render_admin_page'],
            $this->get_admin_menu_icon_data_uri(),
            58
        );

        add_submenu_page(
            'ide-snippets-bridge',
            esc_html__('All snippets', 'ide-snippets-bridge'),
            esc_html__('All snippets', 'ide-snippets-bridge'),
            'manage_options',
            'ide-snippets-bridge',
            [$this, 'render_admin_page']
        );

        add_submenu_page(
            'ide-snippets-bridge',
            esc_html__('New snippet', 'ide-snippets-bridge'),
            esc_html__('New snippet', 'ide-snippets-bridge'),
            'manage_options',
            'ide-snippets-bridge-new',
            [$this, 'render_edit_page']
        );

        add_submenu_page(
            'ide-snippets-bridge',
            esc_html__('Edit snippet', 'ide-snippets-bridge'),
            '',
            'manage_options',
            'ide-snippets-bridge-edit',
            [$this, 'render_edit_page']
        );

        add_submenu_page(
            'ide-snippets-bridge',
            esc_html__('Content targeting', 'ide-snippets-bridge'),
            esc_html__('Content targeting', 'ide-snippets-bridge'),
            'manage_options',
            'ide-snippets-bridge-assignments',
            [$this, 'render_assignments_page']
        );

        add_submenu_page(
            'ide-snippets-bridge',
            esc_html__('Import / Export', 'ide-snippets-bridge'),
            esc_html__('Import / Export', 'ide-snippets-bridge'),
            'manage_options',
            'ide-snippets-bridge-import-export',
            [$this, 'render_import_export_page']
        );
    }

    public function enqueue_admin_assets($hook_suffix) {
        if (strpos((string) $hook_suffix, 'ide-snippets-bridge') === false) {
            return;
        }

        $code_editor_settings = wp_enqueue_code_editor(['type' => 'text/x-php']);
        wp_enqueue_style('code-editor');
        wp_enqueue_script('code-editor');
        wp_enqueue_script('wp-theme-plugin-editor');
        wp_enqueue_style('wp-codemirror');
        if (is_array($code_editor_settings)) {
            wp_add_inline_script(
                'code-editor',
                'window.ideSnippetsCodeEditorSettings = ' . wp_json_encode($code_editor_settings) . ';',
                'before'
            );
        }
        wp_add_inline_script(
            'code-editor',
            'window.ideSnippetsI18n = ' . wp_json_encode([
                'enterFullscreen' => __('Plein écran', 'ide-snippets-bridge'),
                'exitFullscreen' => __('Quitter plein écran', 'ide-snippets-bridge'),
            ]) . ';',
            'before'
        );

        wp_add_inline_style('wp-codemirror', '
            .ide-snippets-admin .ide-snippets-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:14px 0 18px;}
            .ide-snippets-admin .ide-snippets-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:12px 14px;}
            .ide-snippets-admin .ide-snippets-card strong{display:block;font-size:20px;line-height:1.2;margin-bottom:3px;}
            .ide-snippets-admin .ide-snippets-layout{display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start;}
            .ide-snippets-admin .ide-snippets-layout.ide-snippets-layout-full{grid-template-columns:1fr;}
            .ide-snippets-admin .ide-snippets-layout > div{min-width:0;}
            .ide-snippets-admin .ide-snippets-panel{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;overflow:visible;max-width:100%;box-sizing:border-box;}
            .ide-snippets-admin .ide-snippets-panel h2{margin-top:0;margin-bottom:14px;}
            .ide-snippets-admin .ide-snippets-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:0 0 12px;}
            .ide-snippets-admin .ide-snippets-toolbar input[type="search"]{width:320px;max-width:100%;}
            .ide-snippets-admin .ide-snippets-actions{display:flex;gap:8px;flex-wrap:wrap;}
            .ide-snippets-admin .ide-snippets-bulk-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 12px;}
            .ide-snippets-admin .ide-snippets-bulk-bar .description{margin:0;}
            .ide-snippets-admin .ide-snippets-bulk-bar select{max-width:280px;}
            .ide-snippets-admin .ide-snippets-bulk-export-wrap{display:none;}
            .ide-snippets-admin .ide-badge{display:inline-flex;align-items:center;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:600;}
            .ide-snippets-admin .ide-badge-active{background:#d1f3d8;color:#0a6b22;}
            .ide-snippets-admin .ide-badge-inactive{background:#f5d9d9;color:#8a1f1f;}
            .ide-snippets-admin .ide-badge-premium-ready{background:#ede7ff;color:#4e2ea8;}
            .ide-snippets-admin .ide-editor-toolbar{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;}
            .ide-snippets-admin .ide-editor-meta{font-size:12px;color:#646970;white-space:nowrap;}
            .ide-snippets-admin .ide-editor-wrap{position:relative;width:100%;max-width:100%;overflow:visible;box-sizing:border-box;}
            .ide-snippets-admin .ide-editor-submitbar{position:sticky;bottom:10px;display:flex;align-items:center;gap:10px;padding:10px 12px;background:#fff;border:1px solid #dcdcde;border-radius:8px;margin-top:10px;z-index:2;}
            .ide-snippets-admin .ide-editor-submitbar .ide-snippets-actions{margin-left:auto;}
            .ide-snippets-admin .ide-editor-submitbar .description{margin:0;color:#646970;}
            .ide-snippets-admin #ide_snippet_code{width:100%;max-width:100%;box-sizing:border-box;}
            .ide-snippets-admin .CodeMirror{border:1px solid #dcdcde;border-radius:8px;height:360px;width:100% !important;max-width:100% !important;box-sizing:border-box;overflow:hidden !important;}
            .ide-snippets-admin .CodeMirror-scroll{overflow-x:scroll !important;overflow-y:auto !important;min-height:300px;margin:0 !important;padding:0 !important;scrollbar-gutter:stable both-edges;}
            .ide-snippets-admin .CodeMirror-sizer{margin-right:0 !important;padding-right:0 !important;}
            .ide-snippets-admin .CodeMirror-hscrollbar{display:block !important;}
            .ide-snippets-admin .CodeMirror-vscrollbar{display:block !important;}
            body.ide-snippets-editor-fullscreen{overflow:hidden;}
            body.ide-snippets-editor-fullscreen .ide-snippets-admin .ide-editor-wrap{position:fixed;inset:32px 24px 24px 24px;z-index:100000;background:#fff;padding:12px;border-radius:10px;box-shadow:0 20px 50px rgba(0,0,0,.25);box-sizing:border-box;max-width:calc(100vw - 48px);width:calc(100vw - 48px) !important;}
            body.ide-snippets-editor-fullscreen .ide-snippets-admin .ide-editor-wrap .CodeMirror{height:calc(100vh - 120px);width:100% !important;max-width:100% !important;}
            body.ide-snippets-editor-fullscreen .ide-snippets-admin #ide_snippet_code{height:calc(100vh - 120px);width:100% !important;}
            .ide-snippets-admin .ide-snippet-fields{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:10px 12px;margin:0 0 12px;}
            .ide-snippets-admin .ide-field label{display:block;font-weight:600;margin:0 0 4px;}
            .ide-snippets-admin .ide-field input[type="text"],.ide-snippets-admin .ide-field input[type="number"],.ide-snippets-admin .ide-field select,.ide-snippets-admin .ide-field textarea{width:100%;max-width:100%;}
            .ide-snippets-admin .ide-editor-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:0 0 10px;}
            .ide-snippets-admin .ide-editor-header-left{display:flex;align-items:center;gap:10px;flex-wrap:wrap;min-width:0;flex:1;}
            .ide-snippets-admin .ide-editor-title-input{display:flex;align-items:center;gap:6px;min-width:260px;max-width:420px;flex:1;}
            .ide-snippets-admin .ide-editor-title-input input{width:100%;}
            .ide-snippets-admin .ide-editor-header-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end;}
            .ide-snippets-admin .ide-active-switch{display:inline-flex;align-items:center;gap:8px;padding:5px 10px;border:1px solid #dcdcde;border-radius:999px;background:#fff;}
            .ide-snippets-admin .ide-active-switch input{position:absolute;opacity:0;pointer-events:none;}
            .ide-snippets-admin .ide-active-slider{position:relative;width:42px;height:22px;border-radius:999px;background:#d63638;transition:background .2s ease;display:inline-block;vertical-align:middle;}
            .ide-snippets-admin .ide-active-slider:before{content:"";position:absolute;width:18px;height:18px;left:2px;top:2px;border-radius:50%;background:#fff;transition:transform .2s ease;}
            .ide-snippets-admin .ide-active-switch input:checked + .ide-active-slider{background:#00a32a;}
            .ide-snippets-admin .ide-active-switch input:checked + .ide-active-slider:before{transform:translateX(20px);}
            .ide-snippets-admin .ide-active-switch-label{font-weight:600;font-size:12px;white-space:nowrap;}
            .ide-snippets-admin .ide-field-description{grid-column:1 / span 5;}
            .ide-snippets-admin .ide-field-tags{grid-column:6 / span 5;}
            .ide-snippets-admin .ide-field-scope{grid-column:11 / span 1;}
            .ide-snippets-admin .ide-field-priority{grid-column:12 / span 1;max-width:none;}
            .ide-snippets-admin .ide-field-target-mode{grid-column:1 / span 2;max-width:280px;}
            .ide-snippets-admin .ide-field-target-post-types{grid-column:3 / span 10;}
            .ide-snippets-admin .ide-field-target-post-ids{grid-column:3 / span 10;}
            .ide-snippets-admin .ide-target-hidden{display:none !important;}
            .ide-snippets-admin .ide-target-types-picker{display:grid;grid-template-columns:minmax(300px,420px) minmax(0,1fr);gap:8px;align-items:start;width:100%;}
            .ide-snippets-admin .ide-types-control{position:relative;}
            .ide-snippets-admin .ide-types-toggle{width:100%;justify-content:space-between;}
            .ide-snippets-admin .ide-types-dropdown{position:absolute;left:0;top:calc(100% + 4px);z-index:20;background:#fff;border:1px solid #c3c4c7;border-radius:6px;box-shadow:0 6px 20px rgba(0,0,0,.12);max-height:260px;overflow:auto;padding:8px;min-width:420px;max-width:min(760px,calc(100vw - 80px));}
            .ide-snippets-admin .ide-types-dropdown.ide-target-hidden{display:none !important;}
            .ide-snippets-admin .ide-types-search{margin:0 0 8px;}
            .ide-snippets-admin .ide-types-search input{width:100%;}
            .ide-snippets-admin .ide-types-option{display:flex;align-items:flex-start;gap:8px;padding:4px 2px;line-height:1.25;white-space:nowrap;}
            .ide-snippets-admin .ide-types-option input{margin-top:2px;}
            .ide-snippets-admin .ide-types-summary{min-height:34px;border:1px dashed #c3c4c7;border-radius:6px;padding:4px;display:flex;flex-wrap:nowrap;gap:4px;align-items:center;background:#fafafa;white-space:nowrap;overflow-x:auto;width:100%;}
            .ide-snippets-admin .ide-types-summary-empty{font-size:12px;color:#646970;padding:4px;}
            .ide-snippets-admin .ide-types-pill{display:inline-flex;align-items:center;border-radius:999px;background:#eef2ff;color:#2e2a85;padding:3px 8px;font-size:12px;line-height:1.2;flex:0 0 auto;}
            .ide-snippets-admin .ide-types-pill-remove{margin-left:6px;border:0;background:transparent;color:#2e2a85;cursor:pointer;font-size:14px;line-height:1;padding:0;}
            .ide-snippets-admin .ide-target-ids-input{display:none !important;}
            .ide-snippets-admin .ide-assignments-table td[data-target-types],.ide-snippets-admin .ide-assignments-table td[data-target-ids]{min-width:280px;}
            .ide-snippets-admin .ide-field-active label + label{margin-top:6px;font-weight:400;}
            .ide-snippets-admin .ide-premium-ready{display:flex;align-items:center;gap:8px;margin:0 0 8px;}
            @media (max-width: 1200px){.ide-snippets-admin .ide-snippets-layout{grid-template-columns:1fr;}}
            @media (max-width: 782px){body.ide-snippets-editor-fullscreen .ide-snippets-admin .ide-editor-wrap{inset:46px 10px 10px 10px;}}
            @media (max-width: 782px){
                .ide-snippets-admin .ide-snippet-fields{grid-template-columns:repeat(6,minmax(0,1fr));}
                .ide-snippets-admin .ide-field-title{grid-column:1 / span 6;}
                .ide-snippets-admin .ide-field-description{grid-column:1 / span 6;}
                .ide-snippets-admin .ide-field-tags{grid-column:1 / span 6;}
                .ide-snippets-admin .ide-field-scope{grid-column:1 / span 3;}
                .ide-snippets-admin .ide-field-priority{grid-column:4 / span 2;}
                .ide-snippets-admin .ide-field-target-mode{grid-column:1 / span 6;}
                .ide-snippets-admin .ide-field-target-post-types{grid-column:1 / span 6;}
                .ide-snippets-admin .ide-field-target-post-ids{grid-column:1 / span 6;}
                .ide-snippets-admin .ide-target-types-picker{grid-template-columns:1fr;}
                .ide-snippets-admin .ide-editor-header{flex-direction:column;align-items:stretch;}
                .ide-snippets-admin .ide-editor-header-right{justify-content:flex-start;}
                .ide-snippets-admin .ide-editor-title-input{max-width:none;}
            }
        ');

        wp_add_inline_script('code-editor', '(function($){
            function updateCodeMeta(value){
                var linesEl = document.getElementById("ide-snippet-code-lines");
                var charsEl = document.getElementById("ide-snippet-code-chars");
                if(!linesEl || !charsEl){return;}
                var safeValue = value || "";
                var lines = safeValue === "" ? 0 : safeValue.split(/\r\n|\r|\n/).length;
                linesEl.textContent = String(lines);
                charsEl.textContent = String(safeValue.length);
            }

            function initFullscreenToggle(editor, textarea){
                var button = document.getElementById("ide-snippet-fullscreen-toggle");
                if(!button){return;}
                var body = document.body;
                var labels = window.ideSnippetsI18n || {};
                var enterFullscreenLabel = labels.enterFullscreen || "Plein écran";
                var exitFullscreenLabel = labels.exitFullscreen || "Quitter plein écran";
                function syncUi(){
                    var isActive = body.classList.contains("ide-snippets-editor-fullscreen");
                    button.setAttribute("aria-pressed", isActive ? "true" : "false");
                    button.textContent = isActive ? exitFullscreenLabel : enterFullscreenLabel;
                    if(editor && editor.refresh){
                        setTimeout(function(){ editor.refresh(); }, 50);
                    }
                }
                button.addEventListener("click", function(event){
                    event.preventDefault();
                    body.classList.toggle("ide-snippets-editor-fullscreen");
                    syncUi();
                });
                document.addEventListener("keydown", function(event){
                    if(event.key === "Escape" && body.classList.contains("ide-snippets-editor-fullscreen")){
                        body.classList.remove("ide-snippets-editor-fullscreen");
                        syncUi();
                    }
                });
                syncUi();
            }

            function initEditor(){
                var textarea = document.getElementById("ide_snippet_code");
                if(!textarea){return;}
                var editor = null;
                var form = textarea.form;
                if(window.wp && wp.codeEditor){
                    var settings = window.ideSnippetsCodeEditorSettings ? $.extend(true, {}, window.ideSnippetsCodeEditorSettings) : {};
                    settings.codemirror = settings.codemirror || {};
                    settings.codemirror.mode = "application/x-httpd-php";
                    settings.codemirror.lineNumbers = true;
                    settings.codemirror.lineWrapping = false;
                    settings.codemirror.matchBrackets = true;
                    settings.codemirror.autoCloseBrackets = true;
                    settings.codemirror.styleActiveLine = true;
                    var instance = wp.codeEditor.initialize(textarea, settings);
                    if(instance && instance.codemirror){
                        editor = instance.codemirror;
                        textarea.removeAttribute("required");
                        editor.save();
                        updateCodeMeta(editor.getValue());
                        editor.on("change", function(cm){
                            if(editor && editor.save){
                                editor.save();
                            }
                            updateCodeMeta(cm.getValue());
                        });
                    }
                }
                if(!editor){
                    updateCodeMeta(textarea.value || "");
                    textarea.addEventListener("input", function(){ updateCodeMeta(textarea.value || ""); });
                }
                if(form){
                    form.addEventListener("submit", function(){
                        if(editor && editor.save){
                            editor.save();
                        }
                    });
                }
                document.addEventListener("keydown", function(event){
                    var isSave = (event.key === "s" || event.key === "S") && (event.metaKey || event.ctrlKey);
                    if(!isSave || !form){return;}
                    event.preventDefault();
                    if(editor && editor.save){
                        editor.save();
                    }
                    if(form.requestSubmit){
                        form.requestSubmit();
                        return;
                    }
                    form.submit();
                });
                initFullscreenToggle(editor, textarea);
            }

            function initTableSearch(){
                var searchInput = document.getElementById("ide-snippets-search");
                var table = document.getElementById("ide-snippets-table");
                if(!searchInput || !table){return;}
                var rows = table.querySelectorAll("tbody tr[data-snippet-row=\'1\']");
                searchInput.addEventListener("input", function(){
                    var term = (searchInput.value || "").toLowerCase().trim();
                    rows.forEach(function(row){
                        var text = (row.textContent || "").toLowerCase();
                        row.style.display = term === "" || text.indexOf(term) !== -1 ? "" : "none";
                    });
                });
            }

            function syncTargetVisibility(modeField){
                if(!modeField){return;}
                var container = modeField.closest("tr") || modeField.closest(".ide-snippet-fields") || modeField.form || document;
                var typeField = container.querySelector("[data-target-types]");
                var idField = container.querySelector("[data-target-ids]");
                if(!typeField || !idField){return;}
                var mode = modeField.value || "post_types";
                typeField.classList.toggle("ide-target-hidden", mode !== "post_types");
                idField.classList.toggle("ide-target-hidden", mode !== "specific_posts");
            }

            function initTargetingUi(){
                var modeFields = document.querySelectorAll("[data-target-mode]");
                modeFields.forEach(function(modeField){
                    modeField.addEventListener("change", function(){ syncTargetVisibility(modeField); });
                    syncTargetVisibility(modeField);
                });
            }

            function parseCsvValues(value, numericOnly){
                var values = String(value || "").split(",");
                var normalized = [];
                values.forEach(function(raw){
                    var current = String(raw || "").trim();
                    if(current === ""){return;}
                    if(numericOnly){
                        var parsed = parseInt(current, 10);
                        if(parsed < 1){return;}
                        current = String(parsed);
                    }
                    if(normalized.indexOf(current) === -1){
                        normalized.push(current);
                    }
                });
                return normalized;
            }

            function initTargetPickers(){
                var pickers = document.querySelectorAll("[data-types-picker]");
                pickers.forEach(function(picker){
                    var hidden = picker.querySelector("[data-types-hidden]");
                    var summary = picker.querySelector("[data-types-summary]");
                    var toggle = picker.querySelector("[data-types-toggle]");
                    var dropdown = picker.querySelector("[data-types-dropdown]");
                    var boxes = picker.querySelectorAll("[data-types-checkbox]");
                    var searchInput = picker.querySelector("[data-types-search]");
                    var emptyText = picker.getAttribute("data-empty-text") || "Aucun élément sélectionné";
                    var numericMode = picker.getAttribute("data-values-mode") === "numeric";
                    if(!hidden || !summary || !toggle || !dropdown){return;}

                    function renderSummary(values){
                        summary.innerHTML = "";
                        if(!values.length){
                            var empty = document.createElement("span");
                            empty.className = "ide-types-summary-empty";
                            empty.textContent = emptyText;
                            summary.appendChild(empty);
                            return;
                        }
                        values.forEach(function(value){
                            var label = "";
                            boxes.forEach(function(box){
                                if(box.value === value){
                                    label = box.getAttribute("data-types-summary-label") || box.getAttribute("data-types-label") || value;
                                }
                            });
                            var pill = document.createElement("span");
                            pill.className = "ide-types-pill";
                            var text = document.createElement("span");
                            text.textContent = label || value;
                            var remove = document.createElement("button");
                            remove.type = "button";
                            remove.className = "ide-types-pill-remove";
                            remove.setAttribute("data-types-remove", value);
                            remove.textContent = "×";
                            pill.appendChild(text);
                            pill.appendChild(remove);
                            summary.appendChild(pill);
                        });
                    }

                    function syncFromBoxes(){
                        var selected = [];
                        boxes.forEach(function(box){
                            if(box.checked){
                                selected.push(box.value);
                            }
                        });
                        hidden.value = selected.join(",");
                        renderSummary(selected);
                    }

                    function applyFilter(){
                        if(!searchInput){return;}
                        var query = String(searchInput.value || "").toLowerCase().trim();
                        boxes.forEach(function(box){
                            var row = box.closest(".ide-types-option");
                            if(!row){return;}
                            if(query === ""){
                                row.style.display = "";
                                return;
                            }
                            var label = String(box.getAttribute("data-types-label") || "").toLowerCase();
                            row.style.display = label.indexOf(query) !== -1 ? "" : "none";
                        });
                    }

                    function reorderOptions(){
                        var rows = [];
                        boxes.forEach(function(box, index){
                            var row = box.closest(".ide-types-option");
                            if(!row){return;}
                            if(!row.hasAttribute("data-types-order")){
                                row.setAttribute("data-types-order", String(index));
                            }
                            if(rows.indexOf(row) === -1){
                                rows.push(row);
                            }
                        });
                        rows.sort(function(a, b){
                            var aBox = a.querySelector("[data-types-checkbox]");
                            var bBox = b.querySelector("[data-types-checkbox]");
                            var aRank = aBox && aBox.checked ? 0 : 1;
                            var bRank = bBox && bBox.checked ? 0 : 1;
                            if(aRank !== bRank){
                                return aRank - bRank;
                            }
                            var aOrder = parseInt(a.getAttribute("data-types-order") || "0", 10);
                            var bOrder = parseInt(b.getAttribute("data-types-order") || "0", 10);
                            return aOrder - bOrder;
                        });
                        rows.forEach(function(row){
                            dropdown.appendChild(row);
                        });
                    }

                    toggle.addEventListener("click", function(event){
                        event.preventDefault();
                        var isOpen = !dropdown.classList.contains("ide-target-hidden");
                        document.querySelectorAll("[data-types-dropdown]").forEach(function(node){
                            node.classList.add("ide-target-hidden");
                        });
                        if(!isOpen){
                            reorderOptions();
                            dropdown.classList.remove("ide-target-hidden");
                            if(searchInput){
                                searchInput.focus();
                                applyFilter();
                            }
                        }
                    });

                    boxes.forEach(function(box){
                        box.addEventListener("change", syncFromBoxes);
                    });

                    if(searchInput){
                        searchInput.addEventListener("input", applyFilter);
                    }
                    summary.addEventListener("click", function(event){
                        var button = event.target && event.target.closest ? event.target.closest("[data-types-remove]") : null;
                        if(!button){return;}
                        event.preventDefault();
                        var removeValue = button.getAttribute("data-types-remove") || "";
                        boxes.forEach(function(box){
                            if(box.value === removeValue){
                                box.checked = false;
                            }
                        });
                        syncFromBoxes();
                    });

                    var raw = parseCsvValues(hidden.value, numericMode);
                    boxes.forEach(function(box){
                        box.checked = raw.indexOf(box.value) !== -1;
                    });
                    hidden.value = raw.join(",");
                    renderSummary(raw);
                });

                document.addEventListener("click", function(event){
                    var inside = event.target && event.target.closest ? event.target.closest("[data-types-picker]") : null;
                    if(!inside){
                        document.querySelectorAll("[data-types-dropdown]").forEach(function(node){
                            node.classList.add("ide-target-hidden");
                        });
                    }
                });
            }

            function initBulkSelection(){
                var form = document.getElementById("ide-snippets-bulk-form");
                if(!form){return;}
                var selectAll = document.getElementById("ide-snippets-select-all");
                var actionField = document.getElementById("ide-snippets-bulk-action");
                var exportWrap = document.getElementById("ide-snippets-bulk-export-wrap");
                var applyButton = document.getElementById("ide-snippets-bulk-apply");
                var countLabel = document.getElementById("ide-snippets-selected-count");
                var rowBoxes = document.querySelectorAll("input[name=\"snippet_ids[]\"][form=\"ide-snippets-bulk-form\"]");
                if(!rowBoxes.length){
                    if(applyButton){applyButton.disabled = true;}
                    if(selectAll){selectAll.disabled = true;}
                    return;
                }

                function getSelectedCount(){
                    var count = 0;
                    rowBoxes.forEach(function(box){
                        if(box.checked){
                            count++;
                        }
                    });
                    return count;
                }

                function syncUi(){
                    var selectedCount = getSelectedCount();
                    var actionValue = actionField ? actionField.value : "";
                    if(selectAll){
                        selectAll.indeterminate = selectedCount > 0 && selectedCount < rowBoxes.length;
                        selectAll.checked = rowBoxes.length > 0 && selectedCount === rowBoxes.length;
                    }
                    if(countLabel){
                        countLabel.textContent = selectedCount + " sélectionné(s)";
                    }
                    if(exportWrap){
                        exportWrap.style.display = actionValue === "export" ? "" : "none";
                    }
                    if(applyButton){
                        applyButton.disabled = selectedCount === 0 || actionValue === "";
                    }
                }

                if(selectAll){
                    selectAll.addEventListener("change", function(){
                        rowBoxes.forEach(function(box){
                            box.checked = !!selectAll.checked;
                        });
                        syncUi();
                    });
                }

                rowBoxes.forEach(function(box){
                    box.addEventListener("change", syncUi);
                });

                if(actionField){
                    actionField.addEventListener("change", syncUi);
                }

                form.addEventListener("submit", function(event){
                    var selectedCount = getSelectedCount();
                    var actionValue = actionField ? actionField.value : "";
                    if(selectedCount === 0 || actionValue === ""){
                        event.preventDefault();
                        return;
                    }
                    if(actionValue === "delete" && !window.confirm("Supprimer les snippets sélectionnés ?")){
                        event.preventDefault();
                    }
                });

                syncUi();
            }

            $(function(){
                initEditor();
                initTableSearch();
                initTargetingUi();
                initTargetPickers();
                initBulkSelection();
            });
        })(jQuery);');
    }

    private function get_native_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ide_snippets';
    }

    private function get_native_snippet($id) {
        global $wpdb;
        $table = $this->get_native_table_name();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", absint($id)));
    }

    private function get_native_snippets() {
        global $wpdb;
        $table = $this->get_native_table_name();
        return $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY id DESC");
    }

    private function get_snippet_stats($snippets) {
        $total = is_array($snippets) ? count($snippets) : 0;
        $active = 0;
        if (!empty($snippets)) {
            foreach ($snippets as $snippet) {
                if (isset($snippet->active) && (int) $snippet->active === 1) {
                    $active++;
                }
            }
        }
        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
        ];
    }

    private function render_stats_grid($stats) {
        ?>
        <div class="ide-snippets-grid">
            <div class="ide-snippets-card"><strong><?php echo (int) $stats['total']; ?></strong><?php esc_html_e('Total snippets', 'ide-snippets-bridge'); ?></div>
            <div class="ide-snippets-card"><strong><?php echo (int) $stats['active']; ?></strong><?php esc_html_e('Active', 'ide-snippets-bridge'); ?></div>
            <div class="ide-snippets-card"><strong><?php echo (int) $stats['inactive']; ?></strong><?php esc_html_e('Inactive', 'ide-snippets-bridge'); ?></div>
        </div>
        <?php
    }

    private function get_active_native_snippets() {
        global $wpdb;
        $table = $this->get_native_table_name();
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE active = %d ORDER BY priority ASC, id ASC",
                1
            )
        );
    }

    private function get_targetable_post_types() {
        $admin_types = get_post_types(['show_ui' => true], 'objects');
        $front_types = get_post_types(['public' => true], 'objects');
        $types = [];
        if (is_array($admin_types)) {
            $types = $admin_types;
        }
        if (is_array($front_types)) {
            $types = array_merge($types, $front_types);
        }

        $blocked = ['attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_global_styles', 'wp_navigation', 'wp_template', 'wp_template_part'];
        foreach ($types as $post_type => $type_object) {
            if (in_array($post_type, $blocked, true)) {
                unset($types[$post_type]);
                continue;
            }
            if (!is_object($type_object)) {
                unset($types[$post_type]);
            }
        }

        return $types;
    }

    private function get_post_type_target_label($post_type, $type_object) {
        $label = isset($type_object->labels->singular_name) && is_string($type_object->labels->singular_name)
            ? $type_object->labels->singular_name
            : $post_type;
        $areas = [];
        if (!empty($type_object->show_ui)) {
            $areas[] = 'admin';
        }
        if (!empty($type_object->public)) {
            $areas[] = 'front';
        }
        if (empty($areas)) {
            $areas[] = 'admin';
        }
        return sprintf('%s (%s · %s)', $label, $post_type, implode('+', $areas));
    }

    private function get_post_type_summary_label($post_type, $type_object) {
        $label = isset($type_object->labels->singular_name) && is_string($type_object->labels->singular_name)
            ? $type_object->labels->singular_name
            : $post_type;
        return sprintf('%s (%s)', $label, $post_type);
    }

    private function get_targetable_posts_for_picker($targetable_post_types, $required_ids = []) {
        $post_types = array_keys($targetable_post_types);
        if (empty($post_types)) {
            return [];
        }

        $posts = get_posts([
            'post_type' => $post_types,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'numberposts' => 500,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
            'suppress_filters' => false,
        ]);
        if (!is_array($posts)) {
            $posts = [];
        }

        $required = [];
        foreach ((array) $required_ids as $required_id) {
            $post_id = absint($required_id);
            if ($post_id > 0) {
                $required[] = $post_id;
            }
        }
        $ids = array_values(array_unique(array_merge($required, $posts)));
        if (empty($ids)) {
            return [];
        }

        $items = [];
        foreach ($ids as $post_id) {
            $post = get_post($post_id);
            if (!$post || !is_object($post)) {
                continue;
            }
            $post_type = isset($post->post_type) ? (string) $post->post_type : '';
            if ($post_type === '' || !isset($targetable_post_types[$post_type])) {
                continue;
            }
            $type_object = $targetable_post_types[$post_type];
            $type_label = isset($type_object->labels->singular_name) && is_string($type_object->labels->singular_name)
                ? $type_object->labels->singular_name
                : $post_type;
            $title = get_the_title($post_id);
            if (!is_string($title) || trim($title) === '') {
                $title = sprintf('Sans titre #%d', $post_id);
            }
            $items[] = [
                'id' => $post_id,
                'label' => sprintf('#%d · %s · %s (%s)', $post_id, $title, $type_label, $post_type),
                'summary_label' => sprintf('#%d · %s · (%s)', $post_id, $title, $post_type),
            ];
        }

        return $items;
    }

    private function sanitize_target_mode($value) {
        $mode = strtolower(trim((string) $value));
        if (!in_array($mode, ['all', 'post_types', 'specific_posts'], true)) {
            return 'all';
        }
        return $mode;
    }

    private function normalize_import_scope($value) {
        $scope = strtolower(trim((string) $value));
        if ($scope === '' || in_array($scope, ['global', 'both', 'all', 'everywhere'], true)) {
            return 'global';
        }
        if (in_array($scope, ['admin', 'back', 'backend', 'back-end', 'dashboard', 'wp-admin'], true)) {
            return 'admin';
        }
        if (in_array($scope, ['front', 'frontend', 'front_end', 'front-end', 'public', 'site'], true)) {
            return 'front-end';
        }
        return sanitize_text_field($scope);
    }

    private function normalize_import_active($value) {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_numeric($value)) {
            return absint($value) > 0 ? 1 : 0;
        }
        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled', 'active', 'actif', 'publish', 'published'], true)) {
            return 1;
        }
        return 0;
    }

    private function normalize_import_datetime($value) {
        if (!is_string($value)) {
            return '';
        }
        $timestamp = strtotime(trim($value));
        if ($timestamp === false) {
            return '';
        }
        return wp_date('Y-m-d H:i:s', $timestamp);
    }

    private function get_import_source_plugins() {
        $sources = [
            'native' => __('Snippet Hub (Eliodata Native)', 'ide-snippets-bridge'),
        ];
        $sources = apply_filters('ide_snippets_bridge_import_sources', $sources, $this);
        if (!is_array($sources)) {
            $sources = [];
        }
        $normalized = [];
        foreach ($sources as $key => $label) {
            $source_key = sanitize_key((string) $key);
            if ($source_key === '') {
                continue;
            }
            $normalized[$source_key] = is_string($label) && $label !== '' ? $label : strtoupper($source_key);
        }
        if (!isset($normalized['native'])) {
            $normalized['native'] = __('Snippet Hub (Eliodata Native)', 'ide-snippets-bridge');
        }
        return $normalized;
    }

    private function sanitize_import_source_plugin($value) {
        $plugin = sanitize_key((string) $value);
        if (in_array($plugin, ['eliodata', 'eliodata_snippets', 'ide_snippets_bridge', 'ide_snippets'], true)) {
            $plugin = 'native';
        }
        $allowed = array_merge(['auto'], array_keys($this->get_import_source_plugins()));
        if (!in_array($plugin, $allowed, true)) {
            return 'native';
        }
        return $plugin;
    }

    private function apply_import_activation_mode($active, $mode) {
        if ($mode === 'activate_all') {
            return 1;
        }
        if ($mode === 'deactivate_all') {
            return 0;
        }
        return absint($active) > 0 ? 1 : 0;
    }

    private function normalize_import_item($item, $source_plugin = 'auto') {
        if (!is_array($item)) {
            return [];
        }
        $source_plugin = $this->sanitize_import_source_plugin($source_plugin);
        $item = apply_filters('ide_snippets_bridge_import_item_payload', $item, $source_plugin, $this);
        if (!is_array($item)) {
            return [];
        }

        $name = '';
        if (isset($item['name'])) {
            $name = sanitize_text_field($item['name']);
        } elseif (isset($item['title'])) {
            $name = sanitize_text_field($item['title']);
        }

        $description = '';
        if (isset($item['description'])) {
            $description = sanitize_textarea_field($item['description']);
        } elseif (isset($item['desc'])) {
            $description = sanitize_textarea_field($item['desc']);
        } elseif (isset($item['notes'])) {
            $description = sanitize_textarea_field($item['notes']);
        }

        $tags_raw = isset($item['tags']) ? $item['tags'] : '';
        if ($tags_raw === '' && isset($item['tag'])) {
            $tags_raw = $item['tag'];
        }
        $tags = '';
        if (is_array($tags_raw)) {
            $flat_tags = [];
            foreach ($tags_raw as $tag_item) {
                if (is_array($tag_item) && isset($tag_item['name'])) {
                    $flat_tags[] = sanitize_text_field((string) $tag_item['name']);
                    continue;
                }
                $flat_tags[] = sanitize_text_field((string) $tag_item);
            }
            $tags = implode(', ', array_filter($flat_tags));
        } else {
            $tags = sanitize_text_field((string) $tags_raw);
        }

        $target_mode_raw = isset($item['target_mode']) ? $item['target_mode'] : (isset($item['mode']) ? $item['mode'] : 'all');
        $target_post_types_raw = isset($item['target_post_types']) ? $item['target_post_types'] : (isset($item['post_types']) ? $item['post_types'] : '');
        $target_post_ids_raw = isset($item['target_post_ids']) ? $item['target_post_ids'] : (isset($item['post_ids']) ? $item['post_ids'] : '');
        if (isset($item['attribution']) && is_array($item['attribution'])) {
            if (isset($item['attribution']['mode'])) {
                $target_mode_raw = $item['attribution']['mode'];
            }
            if (isset($item['attribution']['target_mode'])) {
                $target_mode_raw = $item['attribution']['target_mode'];
            }
            if (isset($item['attribution']['post_types'])) {
                $target_post_types_raw = $item['attribution']['post_types'];
            }
            if (isset($item['attribution']['target_post_types'])) {
                $target_post_types_raw = $item['attribution']['target_post_types'];
            }
            if (isset($item['attribution']['post_ids'])) {
                $target_post_ids_raw = $item['attribution']['post_ids'];
            }
            if (isset($item['attribution']['target_post_ids'])) {
                $target_post_ids_raw = $item['attribution']['target_post_ids'];
            }
            if (isset($item['attribution']['post_type'])) {
                $target_post_types_raw = $item['attribution']['post_type'];
            }
            if (isset($item['attribution']['post_id'])) {
                $target_post_ids_raw = $item['attribution']['post_id'];
            }
        }

        $target_mode = $this->sanitize_target_mode($target_mode_raw);
        $target_post_types = $this->sanitize_target_post_types_csv($target_post_types_raw);
        $target_post_ids = $this->sanitize_target_post_ids_csv(is_array($target_post_ids_raw) ? implode(',', $target_post_ids_raw) : $target_post_ids_raw);
        $target_payload = $this->sanitize_target_payload($target_mode, $target_post_types, $target_post_ids);

        $scope_raw = isset($item['scope']) ? $item['scope'] : (isset($item['context']) ? $item['context'] : 'global');

        $active_raw = isset($item['active']) ? $item['active'] : (isset($item['enabled']) ? $item['enabled'] : (isset($item['status']) ? $item['status'] : 0));

        $code = '';
        if (isset($item['code'])) {
            $code = (string) $item['code'];
        } elseif (isset($item['content'])) {
            $code = (string) $item['content'];
        } elseif (isset($item['snippet'])) {
            $code = (string) $item['snippet'];
        }

        $created = '';
        if (isset($item['created'])) {
            $created = $this->normalize_import_datetime($item['created']);
        } elseif (isset($item['date_created'])) {
            $created = $this->normalize_import_datetime($item['date_created']);
        }
        $modified = '';
        if (isset($item['modified'])) {
            $modified = $this->normalize_import_datetime($item['modified']);
        } elseif (isset($item['date_modified'])) {
            $modified = $this->normalize_import_datetime($item['date_modified']);
        }

        return [
            'id' => isset($item['id']) ? absint($item['id']) : 0,
            'name' => $name,
            'description' => $description,
            'code' => $code,
            'tags' => $tags,
            'scope' => $this->normalize_import_scope($scope_raw),
            'priority' => isset($item['priority']) ? absint($item['priority']) : 10,
            'active' => $this->normalize_import_active($active_raw),
            'target_mode' => $target_payload['target_post_types'] === '' && $target_payload['target_post_ids'] === '' ? $this->sanitize_target_mode($target_mode) : $target_mode,
            'target_post_types' => $target_payload['target_post_types'],
            'target_post_ids' => $target_payload['target_post_ids'],
            'created' => $created,
            'modified' => $modified,
        ];
    }

    private function extract_bulk_snippet_ids($raw_values) {
        if (!is_array($raw_values)) {
            return [];
        }
        $ids = [];
        foreach ($raw_values as $value) {
            $id = absint($value);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    private function sanitize_target_post_types_csv($value) {
        $available = $this->get_targetable_post_types();
        $values = [];
        if (is_array($value)) {
            foreach ($value as $entry) {
                if (!is_string($entry) && !is_numeric($entry)) {
                    continue;
                }
                $values[] = trim((string) $entry);
            }
        } else {
            $values = array_filter(array_map('trim', explode(',', (string) $value)));
        }
        $clean = [];
        foreach ($values as $item) {
            $post_type = sanitize_key($item);
            if ($post_type !== '' && isset($available[$post_type])) {
                $clean[] = $post_type;
            }
        }
        $clean = array_values(array_unique($clean));
        return implode(',', $clean);
    }

    private function sanitize_target_post_ids_csv($value) {
        $values = array_filter(array_map('trim', explode(',', (string) $value)));
        $clean = [];
        foreach ($values as $item) {
            $post_id = absint($item);
            if ($post_id > 0) {
                $clean[] = $post_id;
            }
        }
        $clean = array_values(array_unique($clean));
        return implode(',', $clean);
    }

    private function sanitize_target_payload($mode, $target_post_types, $target_post_ids) {
        if ($mode === 'post_types') {
            return [
                'target_post_types' => $target_post_types,
                'target_post_ids' => '',
            ];
        }
        if ($mode === 'specific_posts') {
            return [
                'target_post_types' => '',
                'target_post_ids' => $target_post_ids,
            ];
        }
        return [
            'target_post_types' => $target_post_types,
            'target_post_ids' => $target_post_ids,
        ];
    }

    private function get_snippet_target_mode($snippet) {
        if (!isset($snippet->target_mode)) {
            return 'all';
        }
        return $this->sanitize_target_mode($snippet->target_mode);
    }

    private function get_snippet_target_post_types($snippet) {
        $raw = isset($snippet->target_post_types) ? (string) $snippet->target_post_types : '';
        $values = array_filter(array_map('trim', explode(',', $raw)));
        return array_values(array_unique(array_map('sanitize_key', $values)));
    }

    private function get_snippet_target_post_ids($snippet) {
        $raw = isset($snippet->target_post_ids) ? (string) $snippet->target_post_ids : '';
        $values = array_filter(array_map('trim', explode(',', $raw)));
        $ids = [];
        foreach ($values as $value) {
            $post_id = absint($value);
            if ($post_id > 0) {
                $ids[] = $post_id;
            }
        }
        return array_values(array_unique($ids));
    }

    private function get_current_request_post_id($is_admin_request) {
        if ($is_admin_request) {
            $admin_post_id = filter_input(INPUT_POST, 'post_ID', FILTER_SANITIZE_NUMBER_INT);
            if ($admin_post_id !== null && $admin_post_id !== false) {
                return absint($admin_post_id);
            }
            $admin_get_post_id = filter_input(INPUT_GET, 'post', FILTER_SANITIZE_NUMBER_INT);
            if ($admin_get_post_id !== null && $admin_get_post_id !== false) {
                return absint($admin_get_post_id);
            }
            return 0;
        }

        $post_id = get_queried_object_id();
        if ($post_id > 0) {
            return (int) $post_id;
        }
        $query_post_id = filter_input(INPUT_GET, 'p', FILTER_SANITIZE_NUMBER_INT);
        if ($query_post_id !== null && $query_post_id !== false) {
            return absint($query_post_id);
        }
        $query_page_id = filter_input(INPUT_GET, 'page_id', FILTER_SANITIZE_NUMBER_INT);
        if ($query_page_id !== null && $query_page_id !== false) {
            return absint($query_page_id);
        }
        return 0;
    }

    private function get_current_execution_context() {
        $is_admin_request = is_admin();
        $query_ready = $is_admin_request || did_action('wp') > 0;
        $post_id = $query_ready ? $this->get_current_request_post_id($is_admin_request) : 0;
        $post_type = '';
        if ($post_id > 0) {
            $resolved_post_type = get_post_type($post_id);
            $post_type = is_string($resolved_post_type) ? $resolved_post_type : '';
        }
        return [
            'query_ready' => $query_ready,
            'post_id' => $post_id,
            'post_type' => $post_type,
        ];
    }

    private function should_execute_for_content_target($snippet, $context) {
        $mode = $this->get_snippet_target_mode($snippet);
        if ($mode === 'all') {
            return true;
        }

        $post_id = isset($context['post_id']) ? absint($context['post_id']) : 0;
        $post_type = isset($context['post_type']) ? (string) $context['post_type'] : '';

        if ($mode === 'post_types') {
            $allowed_post_types = $this->get_snippet_target_post_types($snippet);
            if (empty($allowed_post_types) || $post_type === '') {
                return false;
            }
            return in_array($post_type, $allowed_post_types, true);
        }

        if ($mode === 'specific_posts') {
            $allowed_post_ids = $this->get_snippet_target_post_ids($snippet);
            if (empty($allowed_post_ids) || $post_id <= 0) {
                return false;
            }
            return in_array($post_id, $allowed_post_ids, true);
        }

        return true;
    }

    private function should_execute_snippet_for_request($scope) {
        $normalized_scope = strtolower(trim((string) $scope));
        if ($normalized_scope === '' || $normalized_scope === 'global') {
            return true;
        }

        if ($normalized_scope === 'admin') {
            return is_admin();
        }

        if (in_array($normalized_scope, ['front-end', 'frontend', 'front_end', 'front'], true)) {
            return !is_admin();
        }

        return true;
    }

    private function normalize_runtime_snippet_code($code) {
        $clean = ltrim((string) $code);
        if (strpos($clean, "\xEF\xBB\xBF") === 0) {
            $clean = substr($clean, 3);
        }
        $clean = (string) preg_replace('/^\s*<\?(?:php)?\s*/i', '', $clean, 1);
        $clean = (string) preg_replace('/\?>\s*$/', '', $clean, 1);
        return trim($clean);
    }

    private function execute_runtime_snippet_code($code) {
        $tmp_file = wp_tempnam('ide-snippet-');
        if (!is_string($tmp_file) || $tmp_file === '') {
            return;
        }
        $payload = "<?php\n" . $code . "\n";
        $bytes = file_put_contents($tmp_file, $payload);
        if ($bytes === false) {
            return;
        }
        include $tmp_file;
        wp_delete_file($tmp_file);
    }

    public function execute_active_snippets() {
        if ($this->runtime_executed) {
            return;
        }

        if (defined('WP_CLI') && WP_CLI) {
            return;
        }

        $context = $this->get_current_execution_context();
        if (empty($context['query_ready'])) {
            return;
        }

        $this->runtime_executed = true;
        $snippets = $this->get_active_native_snippets();
        if (empty($snippets)) {
            return;
        }

        foreach ($snippets as $snippet) {
            if (!$this->should_execute_snippet_for_request(isset($snippet->scope) ? $snippet->scope : 'global')) {
                continue;
            }
            if (!$this->should_execute_for_content_target($snippet, $context)) {
                continue;
            }

            $code = isset($snippet->code) ? $this->normalize_runtime_snippet_code($snippet->code) : '';
            if ($code === '') {
                continue;
            }

            try {
                $this->execute_runtime_snippet_code($code);
            } catch (Throwable $e) {
                error_log('Eliodata Snippet Hub runtime error (snippet #' . (int) $snippet->id . '): ' . $e->getMessage());
            }
        }
    }

    private function add_admin_notice($type, $message) {
        set_transient('ide_snippets_admin_notice_' . get_current_user_id(), [
            'type' => $type,
            'message' => $message,
        ], 60);
    }

    private function redirect_admin_page($page_slug = 'ide-snippets-bridge', $args = []) {
        $query = array_merge(['page' => $page_slug], is_array($args) ? $args : []);
        $url = add_query_arg($query, admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    private function get_premium_feature_map() {
        return [
            'content_targeting' => [
                'label' => __('Attribution par contenu', 'ide-snippets-bridge'),
                'upgrade_key' => 'content_targeting',
            ],
        ];
    }

    private function is_feature_enabled($feature_key) {
        $enabled = apply_filters('ide_snippets_bridge_feature_enabled', true, $feature_key);
        return (bool) $enabled;
    }

    private function is_feature_premium_flagged($feature_key) {
        $flagged = apply_filters('ide_snippets_bridge_feature_premium_flagged', true, $feature_key);
        return (bool) $flagged;
    }

    private function get_target_label($snippet) {
        $mode = $this->get_snippet_target_mode($snippet);
        if ($mode === 'post_types') {
            $post_types = $this->get_snippet_target_post_types($snippet);
            if (empty($post_types)) {
                return __('Types de contenu (non configuré)', 'ide-snippets-bridge');
            }
            return __('Types de contenu:', 'ide-snippets-bridge') . ' ' . implode(', ', $post_types);
        }
        if ($mode === 'specific_posts') {
            $post_ids = $this->get_snippet_target_post_ids($snippet);
            if (empty($post_ids)) {
                return __('Posts spécifiques (non configuré)', 'ide-snippets-bridge');
            }
            return __('Posts spécifiques:', 'ide-snippets-bridge') . ' ' . implode(', ', $post_ids);
        }
        return __('Tous les contenus', 'ide-snippets-bridge');
    }

    public function register_post_snippets_metabox() {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!$this->is_feature_enabled('content_targeting')) {
            return;
        }

        $post_types = $this->get_targetable_post_types();
        foreach ($post_types as $post_type => $type_object) {
            add_meta_box(
                'ide_snippets_assignments',
                esc_html__('Eliodata Snippet Hub', 'ide-snippets-bridge'),
                [$this, 'render_post_snippets_metabox'],
                $post_type,
                'side',
                'default'
            );
        }
    }

    public function render_post_snippets_metabox($post) {
        $snippets = $this->get_native_snippets();
        wp_nonce_field('ide_snippets_post_assignments', 'ide_snippets_post_assignments_nonce');
        if (empty($snippets)) {
            echo '<p>' . esc_html__('Aucun snippet disponible.', 'ide-snippets-bridge') . '</p>';
            return;
        }
        echo '<p>' . esc_html__('Attribuer des snippets à ce contenu.', 'ide-snippets-bridge') . '</p>';
        echo '<div style="max-height:260px;overflow:auto;">';
        foreach ($snippets as $snippet) {
            $assigned_ids = $this->get_snippet_target_post_ids($snippet);
            $is_assigned = in_array((int) $post->ID, $assigned_ids, true);
            echo '<label style="display:block;margin-bottom:6px;">';
            echo '<input type="checkbox" name="ide_snippet_assignments[]" value="' . (int) $snippet->id . '" ' . checked($is_assigned, true, false) . '> ';
            echo esc_html($snippet->name);
            echo '</label>';
        }
        echo '</div>';
        echo '<p><span class="ide-badge ide-badge-premium-ready">Premium-ready</span></p>';
    }

    private function get_snippets_stats($snippets = null) {
        if (!is_array($snippets)) {
            $snippets = $this->get_native_snippets();
        }
        $total_snippets = count($snippets);
        $active_snippets = 0;
        foreach ($snippets as $snippet) {
            if ((int) $snippet->active === 1) {
                $active_snippets++;
            }
        }
        return [
            'total' => $total_snippets,
            'active' => $active_snippets,
            'inactive' => $total_snippets - $active_snippets,
        ];
    }

    private function render_stats_cards($stats) {
        $total = isset($stats['total']) ? (int) $stats['total'] : 0;
        $active = isset($stats['active']) ? (int) $stats['active'] : 0;
        $inactive = isset($stats['inactive']) ? (int) $stats['inactive'] : 0;
        echo '<div class="ide-snippets-grid">';
        echo '<div class="ide-snippets-card"><strong>' . esc_html((string) $total) . '</strong>' . esc_html__('Total snippets', 'ide-snippets-bridge') . '</div>';
        echo '<div class="ide-snippets-card"><strong>' . esc_html((string) $active) . '</strong>' . esc_html__('Actifs', 'ide-snippets-bridge') . '</div>';
        echo '<div class="ide-snippets-card"><strong>' . esc_html((string) $inactive) . '</strong>' . esc_html__('Inactifs', 'ide-snippets-bridge') . '</div>';
        echo '</div>';
    }

    private function update_snippet_assignment_for_post($snippet, $post_id, $should_assign) {
        global $wpdb;
        $table = $this->get_native_table_name();
        $current_ids = $this->get_snippet_target_post_ids($snippet);
        if ($should_assign) {
            if (!in_array($post_id, $current_ids, true)) {
                $current_ids[] = $post_id;
            }
        } else {
            $current_ids = array_values(array_diff($current_ids, [$post_id]));
        }

        $mode = $should_assign || $this->get_snippet_target_mode($snippet) === 'specific_posts'
            ? 'specific_posts'
            : $this->get_snippet_target_mode($snippet);

        $wpdb->update(
            $table,
            [
                'target_mode' => $mode,
                'target_post_ids' => implode(',', $current_ids),
                'modified' => current_time('mysql'),
            ],
            ['id' => (int) $snippet->id],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    public function save_post_snippets_assignments($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!$post || !isset($post->post_type)) {
            return;
        }
        if (!current_user_can('manage_options') || !current_user_can('edit_post', $post_id)) {
            return;
        }
        if (!isset($_POST['ide_snippets_post_assignments_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ide_snippets_post_assignments_nonce'])), 'ide_snippets_post_assignments')) {
            return;
        }
        if (!$this->is_feature_enabled('content_targeting')) {
            return;
        }

        $selected_ids = [];
        if (isset($_POST['ide_snippet_assignments']) && is_array($_POST['ide_snippet_assignments'])) {
            foreach (wp_unslash($_POST['ide_snippet_assignments']) as $snippet_id) {
                $id = absint($snippet_id);
                if ($id > 0) {
                    $selected_ids[] = $id;
                }
            }
        }
        $selected_ids = array_values(array_unique($selected_ids));

        $snippets = $this->get_native_snippets();
        foreach ($snippets as $snippet) {
            $snippet_id = (int) $snippet->id;
            $this->update_snippet_assignment_for_post($snippet, (int) $post_id, in_array($snippet_id, $selected_ids, true));
        }
    }

    public function handle_admin_requests() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $page = isset($_REQUEST['page']) ? sanitize_text_field(wp_unslash($_REQUEST['page'])) : '';
        if (!in_array($page, ['ide-snippets-bridge', 'ide-snippets-bridge-new', 'ide-snippets-bridge-edit', 'ide-snippets-bridge-assignments', 'ide-snippets-bridge-import-export'], true)) {
            return;
        }

        $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if ($request_method !== 'POST') {
            return;
        }

        check_admin_referer('ide_snippets_admin_action', 'ide_snippets_admin_nonce');

        $op = isset($_POST['op']) ? sanitize_text_field(wp_unslash($_POST['op'])) : '';
        if (!$op) {
            return;
        }

        global $wpdb;
        $table = $this->get_native_table_name();

        if ($op === 'save_snippet') {
            $id = isset($_POST['snippet_id']) ? absint($_POST['snippet_id']) : 0;
            $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
            $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
            $code = isset($_POST['code']) ? wp_unslash($_POST['code']) : '';
            $tags = isset($_POST['tags']) ? sanitize_text_field(wp_unslash($_POST['tags'])) : '';
            $scope = isset($_POST['scope']) ? sanitize_text_field(wp_unslash($_POST['scope'])) : 'global';
            $priority = isset($_POST['priority']) ? absint($_POST['priority']) : 10;
            $active = isset($_POST['active']) ? 1 : 0;
            $target_mode = isset($_POST['target_mode']) ? $this->sanitize_target_mode(wp_unslash($_POST['target_mode'])) : 'all';
            $target_post_types = isset($_POST['target_post_types']) ? $this->sanitize_target_post_types_csv(wp_unslash($_POST['target_post_types'])) : '';
            $target_post_ids = isset($_POST['target_post_ids']) ? $this->sanitize_target_post_ids_csv(wp_unslash($_POST['target_post_ids'])) : '';
            $target_payload = $this->sanitize_target_payload($target_mode, $target_post_types, $target_post_ids);
            $target_post_types = $target_payload['target_post_types'];
            $target_post_ids = $target_payload['target_post_ids'];

            if ($name === '' || $code === '') {
                $this->add_admin_notice('error', 'Le titre et le code sont obligatoires.');
                $this->redirect_admin_page($id > 0 ? 'ide-snippets-bridge-edit' : 'ide-snippets-bridge-new', $id > 0 ? ['snippet_id' => $id] : []);
            }

            $data = [
                'name' => $name,
                'description' => $description,
                'code' => $code,
                'tags' => $tags,
                'scope' => $scope,
                'priority' => $priority,
                'active' => $active,
                'target_mode' => $target_mode,
                'target_post_types' => $target_post_types,
                'target_post_ids' => $target_post_ids,
                'modified' => current_time('mysql'),
            ];

            if ($id > 0) {
                $result = $wpdb->update($table, $data, ['id' => $id], ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s'], ['%d']);
                if ($result === false) {
                    $this->add_admin_notice('error', 'Erreur lors de la mise à jour du snippet.');
                } else {
                    $this->add_admin_notice('success', 'Snippet mis à jour.');
                }
                $this->redirect_admin_page('ide-snippets-bridge-edit', ['snippet_id' => $id]);
            } else {
                $data['created'] = current_time('mysql');
                $result = $wpdb->insert($table, $data, ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s']);
                if ($result === false) {
                    $this->add_admin_notice('error', 'Erreur lors de la création du snippet.');
                    $this->redirect_admin_page('ide-snippets-bridge-new');
                } else {
                    $this->add_admin_notice('success', 'Snippet créé.');
                    $new_id = (int) $wpdb->insert_id;
                    if ($new_id > 0) {
                        $this->redirect_admin_page('ide-snippets-bridge-edit', ['snippet_id' => $new_id]);
                    }
                    $this->redirect_admin_page('ide-snippets-bridge');
                }
            }
        }

        if ($op === 'delete_snippet') {
            $id = isset($_POST['snippet_id']) ? absint($_POST['snippet_id']) : 0;
            if ($id > 0) {
                $result = $wpdb->delete($table, ['id' => $id], ['%d']);
                if ($result === false) {
                    $this->add_admin_notice('error', 'Erreur lors de la suppression.');
                } else {
                    $this->add_admin_notice('success', 'Snippet supprimé.');
                }
            }
            $this->redirect_admin_page('ide-snippets-bridge');
        }

        if ($op === 'toggle_snippet') {
            $id = isset($_POST['snippet_id']) ? absint($_POST['snippet_id']) : 0;
            $target = isset($_POST['target_active']) ? (absint($_POST['target_active']) ? 1 : 0) : 0;
            if ($id > 0) {
                $result = $wpdb->update($table, ['active' => $target, 'modified' => current_time('mysql')], ['id' => $id], ['%d', '%s'], ['%d']);
                if ($result === false) {
                    $this->add_admin_notice('error', 'Erreur lors du changement d’état.');
                } else {
                    $this->add_admin_notice('success', $target ? 'Snippet activé.' : 'Snippet désactivé.');
                }
            }
            $this->redirect_admin_page('ide-snippets-bridge');
        }

        if ($op === 'save_assignments_bulk') {
            $snippet_ids = isset($_POST['snippet_ids']) && is_array($_POST['snippet_ids']) ? wp_unslash($_POST['snippet_ids']) : [];
            if (empty($snippet_ids)) {
                $this->add_admin_notice('error', 'Aucun snippet à mettre à jour.');
                $this->redirect_admin_page('ide-snippets-bridge-assignments');
            }

            $updated = 0;
            foreach ($snippet_ids as $raw_id) {
                $snippet_id = absint($raw_id);
                if ($snippet_id <= 0) {
                    continue;
                }
                $mode = isset($_POST['target_mode'][$snippet_id]) ? $this->sanitize_target_mode(wp_unslash($_POST['target_mode'][$snippet_id])) : 'all';
                $types_value = isset($_POST['target_post_types'][$snippet_id]) ? $this->sanitize_target_post_types_csv(wp_unslash($_POST['target_post_types'][$snippet_id])) : '';
                $ids_value = isset($_POST['target_post_ids'][$snippet_id]) ? $this->sanitize_target_post_ids_csv(wp_unslash($_POST['target_post_ids'][$snippet_id])) : '';
                $target_payload = $this->sanitize_target_payload($mode, $types_value, $ids_value);
                $types_value = $target_payload['target_post_types'];
                $ids_value = $target_payload['target_post_ids'];
                $result = $wpdb->update(
                    $table,
                    [
                        'target_mode' => $mode,
                        'target_post_types' => $types_value,
                        'target_post_ids' => $ids_value,
                        'modified' => current_time('mysql'),
                    ],
                    ['id' => $snippet_id],
                    ['%s', '%s', '%s', '%s'],
                    ['%d']
                );
                if ($result !== false) {
                    $updated++;
                }
            }

            $this->add_admin_notice('success', sprintf('Attributions mises à jour: %d snippet(s).', $updated));
            $this->redirect_admin_page('ide-snippets-bridge-assignments');
        }

        if ($op === 'import_snippets') {
            $allowed_import_formats = ['json', 'ndjson'];
            $allowed_import_modes = ['overwrite', 'overwrite_id', 'skip'];
            $allowed_import_activation_modes = ['keep', 'activate_all', 'deactivate_all'];
            $max_import_size = 6 * 1024 * 1024;

            $format = isset($_POST['import_format']) ? sanitize_text_field(wp_unslash($_POST['import_format'])) : 'json';
            if (!in_array($format, $allowed_import_formats, true)) {
                $format = 'json';
            }
            $mode = isset($_POST['import_mode']) ? sanitize_text_field(wp_unslash($_POST['import_mode'])) : 'overwrite';
            if (!in_array($mode, $allowed_import_modes, true)) {
                $mode = 'overwrite';
            }
            $source_plugin = isset($_POST['import_source_plugin']) ? $this->sanitize_import_source_plugin(wp_unslash($_POST['import_source_plugin'])) : 'native';
            $import_activation_mode = isset($_POST['import_activation_mode']) ? sanitize_text_field(wp_unslash($_POST['import_activation_mode'])) : 'keep';
            if (!in_array($import_activation_mode, $allowed_import_activation_modes, true)) {
                $import_activation_mode = 'keep';
            }
            $raw = isset($_POST['import_payload']) ? trim(wp_unslash($_POST['import_payload'])) : '';

            if (!empty($_FILES['import_file']['tmp_name']) && is_uploaded_file($_FILES['import_file']['tmp_name'])) {
                $upload_error = isset($_FILES['import_file']['error']) ? (int) $_FILES['import_file']['error'] : UPLOAD_ERR_NO_FILE;
                if ($upload_error !== UPLOAD_ERR_OK) {
                    $this->add_admin_notice('error', __('Le fichier importé est invalide.', 'ide-snippets-bridge'));
                    $this->redirect_admin_page('ide-snippets-bridge-import-export');
                }

                $upload_size = isset($_FILES['import_file']['size']) ? absint($_FILES['import_file']['size']) : 0;
                if ($upload_size <= 0 || $upload_size > $max_import_size) {
                    $this->add_admin_notice('error', __('Le fichier doit faire entre 1 octet et 6 Mo.', 'ide-snippets-bridge'));
                    $this->redirect_admin_page('ide-snippets-bridge-import-export');
                }

                $upload_name = isset($_FILES['import_file']['name']) ? sanitize_file_name(wp_unslash($_FILES['import_file']['name'])) : '';
                $upload_ext = strtolower(pathinfo($upload_name, PATHINFO_EXTENSION));
                if (!in_array($upload_ext, ['json', 'ndjson', 'txt'], true)) {
                    $this->add_admin_notice('error', __('Extension de fichier non autorisée.', 'ide-snippets-bridge'));
                    $this->redirect_admin_page('ide-snippets-bridge-import-export');
                }

                $file_content = file_get_contents($_FILES['import_file']['tmp_name']);
                if (is_string($file_content) && $file_content !== '') {
                    $raw = $file_content;
                }
            }

            if ($raw !== '' && strlen($raw) > $max_import_size) {
                $this->add_admin_notice('error', __('Le payload import dépasse 6 Mo.', 'ide-snippets-bridge'));
                $this->redirect_admin_page('ide-snippets-bridge-import-export');
            }

            if ($raw === '') {
                $this->add_admin_notice('error', __('Aucune donnée d’import fournie.', 'ide-snippets-bridge'));
                $this->redirect_admin_page('ide-snippets-bridge-import-export');
            }

            $items = [];
            if ($format === 'json') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['snippets']) && is_array($decoded['snippets'])) {
                    $items = $decoded['snippets'];
                    if ($source_plugin === 'auto') {
                        if (isset($decoded['source_plugin'])) {
                            $source_plugin = $this->sanitize_import_source_plugin($decoded['source_plugin']);
                        } elseif (isset($decoded['plugin'])) {
                            $source_plugin = $this->sanitize_import_source_plugin($decoded['plugin']);
                        }
                    }
                } elseif (is_array($decoded)) {
                    $items = array_values($decoded);
                }
                if (!is_array($decoded)) {
                    $this->add_admin_notice('error', __('JSON invalide.', 'ide-snippets-bridge'));
                    $this->redirect_admin_page('ide-snippets-bridge-import-export');
                }
            } elseif ($format === 'ndjson') {
                $lines = preg_split('/\r\n|\r|\n/', $raw);
                if (is_array($lines)) {
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '') {
                            continue;
                        }
                        $entry = json_decode($line, true);
                        if (is_array($entry)) {
                            $items[] = $entry;
                        }
                    }
                }
            }

            if (empty($items)) {
                $this->add_admin_notice('error', __('Format d’import invalide ou vide.', 'ide-snippets-bridge'));
                $this->redirect_admin_page('ide-snippets-bridge-import-export');
            }

            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($items as $item) {
                $entry_source_plugin = $source_plugin;
                if ($entry_source_plugin === 'auto' && is_array($item)) {
                    if (isset($item['source_plugin'])) {
                        $entry_source_plugin = $this->sanitize_import_source_plugin($item['source_plugin']);
                    } elseif (isset($item['plugin'])) {
                        $entry_source_plugin = $this->sanitize_import_source_plugin($item['plugin']);
                    }
                }
                $normalized = $this->normalize_import_item($item, $entry_source_plugin);
                if (empty($normalized)) {
                    $errors++;
                    continue;
                }
                $name = $normalized['name'];
                $code = $normalized['code'];
                if ($name === '' || $code === '') {
                    $errors++;
                    continue;
                }

                $description = $normalized['description'];
                $tags = $normalized['tags'];
                $scope = $normalized['scope'];
                $priority = $normalized['priority'];
                $active = $this->apply_import_activation_mode($normalized['active'], $import_activation_mode);
                $target_mode = $normalized['target_mode'];
                $target_post_types = $normalized['target_post_types'];
                $target_post_ids = $normalized['target_post_ids'];
                $created = $normalized['created'] !== '' ? $normalized['created'] : current_time('mysql');
                $modified = $normalized['modified'] !== '' ? $normalized['modified'] : current_time('mysql');
                $imported_id = $normalized['id'];

                $existing_id = 0;
                if ($imported_id > 0) {
                    $existing_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE id = %d LIMIT 1", $imported_id));
                }
                if ($existing_id <= 0 && $mode !== 'overwrite_id') {
                    $existing_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE name = %s LIMIT 1", $name));
                }
                if ($existing_id > 0) {
                    if ($mode === 'skip') {
                        $skipped++;
                        continue;
                    }
                    $result = $wpdb->update(
                        $table,
                        [
                            'name' => $name,
                            'description' => $description,
                            'code' => $code,
                            'tags' => $tags,
                            'scope' => $scope,
                            'priority' => $priority,
                            'active' => $active,
                            'target_mode' => $target_mode,
                            'target_post_types' => $target_post_types,
                            'target_post_ids' => $target_post_ids,
                            'modified' => $modified,
                        ],
                        ['id' => $existing_id],
                        ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s'],
                        ['%d']
                    );
                    if ($result === false) {
                        $errors++;
                    } else {
                        $updated++;
                    }
                } else {
                    $insert_data = [
                        'name' => $name,
                        'description' => $description,
                        'code' => $code,
                        'tags' => $tags,
                        'scope' => $scope,
                        'priority' => $priority,
                        'active' => $active,
                        'target_mode' => $target_mode,
                        'target_post_types' => $target_post_types,
                        'target_post_ids' => $target_post_ids,
                        'created' => $created,
                        'modified' => $modified,
                    ];
                    $insert_format = ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s'];
                    if ($imported_id > 0) {
                        $id_exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE id = %d LIMIT 1", $imported_id));
                        if ($id_exists <= 0) {
                            $insert_data = array_merge(['id' => $imported_id], $insert_data);
                            $insert_format = array_merge(['%d'], $insert_format);
                        }
                    }
                    $result = $wpdb->insert($table, $insert_data, $insert_format);
                    if ($result === false) {
                        $errors++;
                    } else {
                        $created++;
                    }
                }
            }

            $message = __('Import terminé.', 'ide-snippets-bridge')
                . ' ' . __('Créés:', 'ide-snippets-bridge') . ' ' . (int) $created
                . ', ' . __('Mis à jour:', 'ide-snippets-bridge') . ' ' . (int) $updated
                . ', ' . __('Ignorés:', 'ide-snippets-bridge') . ' ' . (int) $skipped
                . ', ' . __('Erreurs:', 'ide-snippets-bridge') . ' ' . (int) $errors . '.';
            $this->add_admin_notice('success', $message);
            $this->redirect_admin_page('ide-snippets-bridge-import-export');
        }

        if ($op === 'bulk_snippets_action') {
            $bulk_action = isset($_POST['bulk_action']) ? sanitize_text_field(wp_unslash($_POST['bulk_action'])) : '';
            $snippet_ids = isset($_POST['snippet_ids']) ? $this->extract_bulk_snippet_ids(wp_unslash($_POST['snippet_ids'])) : [];

            if (empty($snippet_ids)) {
                $this->add_admin_notice('error', 'Aucun snippet sélectionné.');
                $this->redirect_admin_page('ide-snippets-bridge');
            }

            if ($bulk_action === 'delete') {
                $deleted = 0;
                foreach ($snippet_ids as $snippet_id) {
                    $result = $wpdb->delete($table, ['id' => (int) $snippet_id], ['%d']);
                    if ($result !== false) {
                        $deleted += (int) $result;
                    }
                }
                if ($deleted <= 0) {
                    $this->add_admin_notice('error', __('Erreur lors de la suppression groupée.', 'ide-snippets-bridge'));
                } else {
                    $this->add_admin_notice('success', (int) $deleted . ' ' . __('snippet(s) supprimé(s).', 'ide-snippets-bridge'));
                }
                $this->redirect_admin_page('ide-snippets-bridge');
            }

            if (in_array($bulk_action, ['activate', 'deactivate'], true)) {
                $target = $bulk_action === 'activate' ? 1 : 0;
                $updated = 0;
                foreach ($snippet_ids as $snippet_id) {
                    $result = $wpdb->update(
                        $table,
                        ['active' => $target, 'modified' => current_time('mysql')],
                        ['id' => (int) $snippet_id],
                        ['%d', '%s'],
                        ['%d']
                    );
                    if ($result !== false) {
                        $updated += (int) $result;
                    }
                }
                if ($updated <= 0) {
                    $this->add_admin_notice('error', __('Erreur lors de la mise à jour groupée.', 'ide-snippets-bridge'));
                } else {
                    $this->add_admin_notice('success', (int) $updated . ' ' . __('snippet(s) mis à jour.', 'ide-snippets-bridge'));
                }
                $this->redirect_admin_page('ide-snippets-bridge');
            }

            if ($bulk_action === 'export') {
                $allowed_export_formats = ['json', 'ndjson'];
                $format = isset($_POST['export_format']) ? sanitize_text_field(wp_unslash($_POST['export_format'])) : 'json';
                if (!in_array($format, $allowed_export_formats, true)) {
                    $format = 'json';
                }
                $all_rows = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY id ASC", ARRAY_A);
                $rows = [];
                $selected_map = array_fill_keys(array_map('absint', $snippet_ids), true);
                foreach ((array) $all_rows as $row) {
                    $row_id = isset($row['id']) ? absint($row['id']) : 0;
                    if ($row_id > 0 && isset($selected_map[$row_id])) {
                        $rows[] = $row;
                    }
                }

                if ($format === 'ndjson') {
                    nocache_headers();
                    header('Content-Type: application/x-ndjson; charset=utf-8');
                    header('Content-Disposition: attachment; filename=ide-native-snippets-selected.ndjson');
                    foreach ($rows as $row) {
                        echo wp_json_encode($row) . "\n";
                    }
                    exit;
                }

                nocache_headers();
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename=ide-native-snippets-selected.json');
                echo wp_json_encode([
                    'exported_at' => current_time('mysql'),
                    'selection_count' => count($snippet_ids),
                    'count' => count($rows),
                    'snippets' => $rows,
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }

            $this->add_admin_notice('error', 'Action groupée invalide.');
            $this->redirect_admin_page('ide-snippets-bridge');
        }

        if ($op === 'export_snippets') {
            $allowed_export_formats = ['json', 'ndjson'];
            $allowed_export_status = ['all', 'active', 'inactive'];
            $format = isset($_POST['export_format']) ? sanitize_text_field(wp_unslash($_POST['export_format'])) : 'json';
            if (!in_array($format, $allowed_export_formats, true)) {
                $format = 'json';
            }
            $status = isset($_POST['export_status']) ? sanitize_text_field(wp_unslash($_POST['export_status'])) : 'all';
            if (!in_array($status, $allowed_export_status, true)) {
                $status = 'all';
            }
            $where = '';
            if ($status === 'active') {
                $where = ' WHERE active = 1';
            } elseif ($status === 'inactive') {
                $where = ' WHERE active = 0';
            }
            $rows = $wpdb->get_results("SELECT * FROM `{$table}`{$where} ORDER BY id ASC", ARRAY_A);

            if ($format === 'ndjson') {
                nocache_headers();
                header('Content-Type: application/x-ndjson; charset=utf-8');
                header('Content-Disposition: attachment; filename=ide-native-snippets.ndjson');
                foreach ($rows as $row) {
                    echo wp_json_encode($row) . "\n";
                }
                exit;
            }

            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename=ide-native-snippets.json');
            echo wp_json_encode([
                'exported_at' => current_time('mysql'),
                'status_filter' => $status,
                'count' => count($rows),
                'snippets' => $rows,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }
    }

    private function render_snippet_editor_panel($editing, $page_slug) {
        $targetable_post_types = $this->get_targetable_post_types();
        $target_mode = $editing ? $this->get_snippet_target_mode($editing) : 'all';
        $target_post_types = $editing && isset($editing->target_post_types) ? (string) $editing->target_post_types : '';
        $target_post_ids = $editing && isset($editing->target_post_ids) ? (string) $editing->target_post_ids : '';
        $selected_post_types = $editing ? $this->get_snippet_target_post_types($editing) : [];
        $selected_post_ids = $editing ? $this->get_snippet_target_post_ids($editing) : [];
        $targetable_posts = $this->get_targetable_posts_for_picker($targetable_post_types, $selected_post_ids);
        $feature_label = $this->get_premium_feature_map()['content_targeting']['label'];
        ?>
        <div class="ide-snippets-panel">
            <form method="post" id="ide-snippet-form">
                <div class="ide-editor-header">
                    <div class="ide-editor-header-left">
                        <h2><?php echo esc_html($editing ? __('Modifier le snippet', 'ide-snippets-bridge') : __('Créer un snippet', 'ide-snippets-bridge')); ?></h2>
                        <label class="ide-editor-title-input" for="ide_snippet_name">
                            <span><?php esc_html_e('Titre', 'ide-snippets-bridge'); ?></span>
                            <input class="regular-text" type="text" id="ide_snippet_name" name="name" required value="<?php echo $editing ? esc_attr($editing->name) : ''; ?>">
                        </label>
                        <label class="ide-active-switch" for="ide_snippet_active">
                            <input type="checkbox" id="ide_snippet_active" name="active" value="1" <?php checked($editing ? (int) $editing->active : 0, 1); ?>>
                            <span class="ide-active-slider"></span>
                            <span class="ide-active-switch-label"><?php esc_html_e('Actif', 'ide-snippets-bridge'); ?></span>
                        </label>
                    </div>
                    <div class="ide-editor-header-right">
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ide-snippets-bridge')); ?>"><?php esc_html_e('Retour à la liste', 'ide-snippets-bridge'); ?></a>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ide-snippets-bridge-new')); ?>"><?php esc_html_e('Nouveau snippet', 'ide-snippets-bridge'); ?></a>
                    </div>
                </div>
                <?php wp_nonce_field('ide_snippets_admin_action', 'ide_snippets_admin_nonce'); ?>
                <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">
                <input type="hidden" name="op" value="save_snippet">
                <input type="hidden" name="snippet_id" value="<?php echo $editing ? esc_attr($editing->id) : 0; ?>">
                <div class="ide-snippet-fields">
                    <div class="ide-field ide-field-description">
                        <label for="ide_snippet_description"><?php esc_html_e('Description', 'ide-snippets-bridge'); ?></label>
                        <textarea class="large-text" id="ide_snippet_description" name="description" rows="2"><?php echo $editing ? esc_textarea($editing->description) : ''; ?></textarea>
                    </div>
                    <div class="ide-field ide-field-tags">
                        <label for="ide_snippet_tags"><?php esc_html_e('Mots-clés', 'ide-snippets-bridge'); ?></label>
                        <input class="regular-text" type="text" id="ide_snippet_tags" name="tags" value="<?php echo $editing ? esc_attr($editing->tags) : ''; ?>" placeholder="<?php echo esc_attr__('woocommerce, checkout', 'ide-snippets-bridge'); ?>">
                    </div>
                    <div class="ide-field ide-field-scope">
                        <label for="ide_snippet_scope"><?php esc_html_e('Cible', 'ide-snippets-bridge'); ?></label>
                        <select id="ide_snippet_scope" name="scope">
                            <?php $scope = $editing ? $editing->scope : 'global'; ?>
                            <option value="global" <?php selected($scope, 'global'); ?>><?php esc_html_e('Global', 'ide-snippets-bridge'); ?></option>
                            <option value="admin" <?php selected($scope, 'admin'); ?>><?php esc_html_e('Admin', 'ide-snippets-bridge'); ?></option>
                            <option value="front-end" <?php selected($scope, 'front-end'); ?>><?php esc_html_e('Front-end', 'ide-snippets-bridge'); ?></option>
                        </select>
                    </div>
                    <div class="ide-field ide-field-priority">
                        <label for="ide_snippet_priority"><?php esc_html_e('Priorité', 'ide-snippets-bridge'); ?></label>
                        <input type="number" min="0" id="ide_snippet_priority" name="priority" value="<?php echo $editing ? esc_attr((string) $editing->priority) : '10'; ?>">
                    </div>
                    <div class="ide-field ide-field-target-mode">
                        <label for="ide_snippet_target_mode"><?php esc_html_e('Attribution', 'ide-snippets-bridge'); ?></label>
                        <select id="ide_snippet_target_mode" name="target_mode" data-target-mode>
                            <option value="all" <?php selected($target_mode, 'all'); ?>><?php esc_html_e('Général', 'ide-snippets-bridge'); ?></option>
                            <option value="post_types" <?php selected($target_mode, 'post_types'); ?>><?php esc_html_e('Type de contenu', 'ide-snippets-bridge'); ?></option>
                            <option value="specific_posts" <?php selected($target_mode, 'specific_posts'); ?>><?php esc_html_e('ID cibles', 'ide-snippets-bridge'); ?></option>
                        </select>
                    </div>
                    <div class="ide-field ide-field-target-post-types" data-target-types>
                        <label for="ide_snippet_target_post_types"><?php esc_html_e('Post types', 'ide-snippets-bridge'); ?></label>
                        <div class="ide-target-types-picker" data-types-picker data-empty-text="<?php echo esc_attr__('Aucun type sélectionné', 'ide-snippets-bridge'); ?>" data-values-mode="text">
                            <div class="ide-types-control">
                                <button type="button" class="button ide-types-toggle" data-types-toggle><?php esc_html_e('Choisir les types', 'ide-snippets-bridge'); ?></button>
                                <div class="ide-types-dropdown ide-target-hidden" data-types-dropdown>
                                    <div class="ide-types-search">
                                        <input type="search" class="regular-text" placeholder="<?php echo esc_attr__('Rechercher un type...', 'ide-snippets-bridge'); ?>" data-types-search>
                                    </div>
                                    <?php foreach ($targetable_post_types as $post_type_key => $post_type_object) : ?>
                                        <?php $type_label = $this->get_post_type_target_label($post_type_key, $post_type_object); ?>
                                        <label class="ide-types-option">
                                            <input type="checkbox" value="<?php echo esc_attr($post_type_key); ?>" data-types-checkbox data-types-label="<?php echo esc_attr($type_label); ?>" data-types-summary-label="<?php echo esc_attr($this->get_post_type_summary_label($post_type_key, $post_type_object)); ?>" <?php checked(in_array($post_type_key, $selected_post_types, true), true); ?>>
                                            <span><?php echo esc_html($type_label); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="ide-types-summary" data-types-summary></div>
                            <input type="hidden" id="ide_snippet_target_post_types" name="target_post_types" value="<?php echo esc_attr($target_post_types); ?>" data-types-hidden>
                        </div>
                    </div>
                    <div class="ide-field ide-field-target-post-ids" data-target-ids>
                        <label for="ide_snippet_target_post_ids"><?php esc_html_e('Post IDs', 'ide-snippets-bridge'); ?></label>
                        <div class="ide-target-types-picker" data-types-picker data-empty-text="<?php echo esc_attr__('Aucun post sélectionné', 'ide-snippets-bridge'); ?>" data-values-mode="numeric">
                            <div class="ide-types-control">
                                <button type="button" class="button ide-types-toggle" data-types-toggle><?php esc_html_e('Choisir les IDs', 'ide-snippets-bridge'); ?></button>
                                <div class="ide-types-dropdown ide-target-hidden" data-types-dropdown>
                                    <div class="ide-types-search">
                                        <input type="search" class="regular-text" placeholder="<?php echo esc_attr__('Rechercher un ID, titre, type...', 'ide-snippets-bridge'); ?>" data-types-search>
                                    </div>
                                    <?php foreach ($targetable_posts as $post_item) : ?>
                                        <label class="ide-types-option">
                                            <input type="checkbox" value="<?php echo esc_attr((string) $post_item['id']); ?>" data-types-checkbox data-types-label="<?php echo esc_attr($post_item['label']); ?>" data-types-summary-label="<?php echo esc_attr($post_item['summary_label']); ?>" <?php checked(in_array((int) $post_item['id'], $selected_post_ids, true), true); ?>>
                                            <span><?php echo esc_html($post_item['label']); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="ide-types-summary" data-types-summary></div>
                            <input class="regular-text ide-target-ids-input" type="text" id="ide_snippet_target_post_ids" name="target_post_ids" value="<?php echo esc_attr($target_post_ids); ?>" placeholder="<?php echo esc_attr__('12,34,56', 'ide-snippets-bridge'); ?>" data-types-hidden>
                        </div>
                    </div>
                </div>
                <p class="ide-premium-ready"><span class="ide-badge ide-badge-premium-ready">Premium-ready</span><span><?php echo esc_html($feature_label); ?></span></p>
                <div class="ide-editor-wrap">
                    <div class="ide-editor-toolbar">
                        <button type="button" class="button button-secondary button-small" id="ide-snippet-fullscreen-toggle" aria-pressed="false"><?php esc_html_e('Plein écran', 'ide-snippets-bridge'); ?></button>
                        <div class="ide-editor-meta"><span id="ide-snippet-code-lines">0</span> <?php esc_html_e('lignes', 'ide-snippets-bridge'); ?> · <span id="ide-snippet-code-chars">0</span> <?php esc_html_e('caractères', 'ide-snippets-bridge'); ?></div>
                    </div>
                    <textarea class="large-text code" id="ide_snippet_code" name="code" rows="12" required><?php echo $editing ? esc_textarea($editing->code) : ''; ?></textarea>
                </div>
                <div class="ide-editor-submitbar">
                    <button type="submit" class="button button-primary"><?php echo esc_html($editing ? __('Enregistrer les modifications', 'ide-snippets-bridge') : __('Créer le snippet', 'ide-snippets-bridge')); ?></button>
                    <div class="ide-snippets-actions">
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ide-snippets-bridge')); ?>"><?php esc_html_e('Retour à la liste', 'ide-snippets-bridge'); ?></a>
                    </div>
                    <p class="description"><?php esc_html_e('Raccourci: ⌘/Ctrl+S', 'ide-snippets-bridge'); ?></p>
                </div>
            </form>
        </div>
        <?php
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $notice = get_transient('ide_snippets_admin_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('ide_snippets_admin_notice_' . get_current_user_id());
        }

        $snippets = $this->get_native_snippets();
        $stats = $this->get_snippets_stats($snippets);
        ?>
        <div class="wrap ide-snippets-admin">
            <h1><?php esc_html_e('Eliodata Snippet Hub', 'ide-snippets-bridge'); ?></h1>
            <?php $this->render_stats_cards($stats); ?>

            <?php if ($notice && !empty($notice['message'])) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type'] === 'error' ? 'error' : 'success'); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <div class="ide-snippets-layout ide-snippets-layout-full">
                <div class="ide-snippets-panel">
                    <div class="ide-snippets-toolbar">
                        <h2><?php esc_html_e('Snippets', 'ide-snippets-bridge'); ?></h2>
                        <div class="ide-snippets-actions">
                            <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=ide-snippets-bridge-new')); ?>"><?php esc_html_e('Nouveau snippet', 'ide-snippets-bridge'); ?></a>
                            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ide-snippets-bridge-import-export')); ?>"><?php esc_html_e('Import / Export', 'ide-snippets-bridge'); ?></a>
                            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ide-snippets-bridge-assignments')); ?>"><?php esc_html_e('Attributions', 'ide-snippets-bridge'); ?></a>
                            <input type="search" id="ide-snippets-search" class="regular-text" placeholder="<?php echo esc_attr__('Filtrer par titre, description, tags...', 'ide-snippets-bridge'); ?>">
                        </div>
                    </div>
                    <form id="ide-snippets-bulk-form" method="post" class="ide-snippets-bulk-bar">
                        <?php wp_nonce_field('ide_snippets_admin_action', 'ide_snippets_admin_nonce'); ?>
                        <input type="hidden" name="page" value="ide-snippets-bridge">
                        <input type="hidden" name="op" value="bulk_snippets_action">
                        <label for="ide-snippets-bulk-action"><strong><?php esc_html_e('Action groupée', 'ide-snippets-bridge'); ?></strong></label>
                        <select id="ide-snippets-bulk-action" name="bulk_action">
                            <option value=""><?php esc_html_e('Choisir...', 'ide-snippets-bridge'); ?></option>
                            <option value="activate"><?php esc_html_e('Activer', 'ide-snippets-bridge'); ?></option>
                            <option value="deactivate"><?php esc_html_e('Désactiver', 'ide-snippets-bridge'); ?></option>
                            <option value="export"><?php esc_html_e('Exporter', 'ide-snippets-bridge'); ?></option>
                            <option value="delete"><?php esc_html_e('Supprimer', 'ide-snippets-bridge'); ?></option>
                        </select>
                        <span id="ide-snippets-bulk-export-wrap" class="ide-snippets-bulk-export-wrap">
                            <label for="ide-snippets-bulk-export-format"><strong><?php esc_html_e('Format export', 'ide-snippets-bridge'); ?></strong></label>
                            <select id="ide-snippets-bulk-export-format" name="export_format">
                                <option value="json">JSON</option>
                                <option value="ndjson">NDJSON</option>
                            </select>
                        </span>
                        <button id="ide-snippets-bulk-apply" class="button" type="submit"><?php esc_html_e('Appliquer', 'ide-snippets-bridge'); ?></button>
                        <span id="ide-snippets-selected-count" class="description">0 sélectionné(s)</span>
                    </form>
                    <table id="ide-snippets-table" class="widefat striped">
                        <thead>
                            <tr>
                                <th class="check-column"><input type="checkbox" id="ide-snippets-select-all"></th>
                                <th>ID</th>
                                <th><?php esc_html_e('Titre', 'ide-snippets-bridge'); ?></th>
                                <th><?php esc_html_e('Description', 'ide-snippets-bridge'); ?></th>
                                <th><?php esc_html_e('Mots-clés', 'ide-snippets-bridge'); ?></th>
                                <th><?php esc_html_e('Cible', 'ide-snippets-bridge'); ?></th>
                                <th><?php esc_html_e('Attribution', 'ide-snippets-bridge'); ?></th>
                                <th><?php esc_html_e('Priorité', 'ide-snippets-bridge'); ?></th>
                                <th><?php esc_html_e('Statut', 'ide-snippets-bridge'); ?></th>
                                <th><?php esc_html_e('Actions', 'ide-snippets-bridge'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($snippets)) : ?>
                                <tr><td colspan="10"><?php esc_html_e('Aucun snippet trouvé.', 'ide-snippets-bridge'); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ($snippets as $snippet) : ?>
                                    <tr data-snippet-row="1">
                                        <td class="check-column"><input type="checkbox" name="snippet_ids[]" value="<?php echo (int) $snippet->id; ?>" form="ide-snippets-bulk-form"></td>
                                        <td><?php echo (int) $snippet->id; ?></td>
                                        <td><?php echo esc_html($snippet->name); ?></td>
                                        <td><?php echo esc_html($snippet->description); ?></td>
                                        <td><?php echo esc_html($snippet->tags); ?></td>
                                        <td><?php echo esc_html($snippet->scope); ?></td>
                                        <td><?php echo esc_html($this->get_target_label($snippet)); ?></td>
                                        <td><?php echo (int) $snippet->priority; ?></td>
                                        <td>
                                            <?php if ((int) $snippet->active === 1) : ?>
                                                <span class="ide-badge ide-badge-active"><?php esc_html_e('Actif', 'ide-snippets-bridge'); ?></span>
                                            <?php else : ?>
                                                <span class="ide-badge ide-badge-inactive"><?php esc_html_e('Inactif', 'ide-snippets-bridge'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="ide-snippets-actions">
                                            <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=ide-snippets-bridge-edit&snippet_id=' . (int) $snippet->id)); ?>"><?php esc_html_e('Éditer', 'ide-snippets-bridge'); ?></a>
                                            <form method="post">
                                                <?php wp_nonce_field('ide_snippets_admin_action', 'ide_snippets_admin_nonce'); ?>
                                                <input type="hidden" name="page" value="ide-snippets-bridge">
                                                <input type="hidden" name="op" value="toggle_snippet">
                                                <input type="hidden" name="snippet_id" value="<?php echo (int) $snippet->id; ?>">
                                                <input type="hidden" name="target_active" value="<?php echo (int) $snippet->active === 1 ? 0 : 1; ?>">
                                                <button class="button button-small" type="submit"><?php echo esc_html((int) $snippet->active === 1 ? __('Désactiver', 'ide-snippets-bridge') : __('Activer', 'ide-snippets-bridge')); ?></button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Supprimer ce snippet ?', 'ide-snippets-bridge')); ?>');">
                                                <?php wp_nonce_field('ide_snippets_admin_action', 'ide_snippets_admin_nonce'); ?>
                                                <input type="hidden" name="page" value="ide-snippets-bridge">
                                                <input type="hidden" name="op" value="delete_snippet">
                                                <input type="hidden" name="snippet_id" value="<?php echo (int) $snippet->id; ?>">
                                                <button class="button button-small button-link-delete" type="submit"><?php esc_html_e('Supprimer', 'ide-snippets-bridge'); ?></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
        <?php
    }

    public function render_import_export_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $notice = get_transient('ide_snippets_admin_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('ide_snippets_admin_notice_' . get_current_user_id());
        }
        $stats = $this->get_snippets_stats();

        ?>
        <div class="wrap ide-snippets-admin">
            <h1><?php esc_html_e('Import / Export', 'ide-snippets-bridge'); ?></h1>
            <?php $this->render_stats_cards($stats); ?>
            <?php if ($notice && !empty($notice['message'])) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type'] === 'error' ? 'error' : 'success'); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>
            <div class="ide-snippets-layout">
                <div class="ide-snippets-panel">
                    <?php
                    $import_sources = $this->get_import_source_plugins();
                    $show_source_auto = count($import_sources) > 1;
                    ?>
                    <h2><?php esc_html_e('Importer', 'ide-snippets-bridge'); ?></h2>
                    <p><?php esc_html_e('Importez un fichier JSON/NDJSON ou collez un payload brut.', 'ide-snippets-bridge'); ?></p>
                    <p class="description"><?php esc_html_e('Limite maximale: 6 Mo. En cas de doublon sur le titre, appliquez la stratégie de conflit choisie.', 'ide-snippets-bridge'); ?></p>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('ide_snippets_admin_action', 'ide_snippets_admin_nonce'); ?>
                        <input type="hidden" name="page" value="ide-snippets-bridge-import-export">
                        <input type="hidden" name="op" value="import_snippets">
                        <p>
                            <label for="import_format"><strong><?php esc_html_e('Format', 'ide-snippets-bridge'); ?></strong></label><br>
                            <select id="import_format" name="import_format">
                                <option value="json">JSON</option>
                                <option value="ndjson">NDJSON</option>
                            </select>
                        </p>
                        <p>
                            <label for="import_mode"><strong><?php esc_html_e('Conflits', 'ide-snippets-bridge'); ?></strong></label><br>
                            <select id="import_mode" name="import_mode">
                                <option value="overwrite"><?php esc_html_e('Écraser les snippets existants (même titre)', 'ide-snippets-bridge'); ?></option>
                                <option value="overwrite_id"><?php esc_html_e('Écraser les snippets existants (détection par ID)', 'ide-snippets-bridge'); ?></option>
                                <option value="skip"><?php esc_html_e('Ignorer les snippets existants (même titre)', 'ide-snippets-bridge'); ?></option>
                            </select>
                        </p>
                        <p>
                            <label for="import_source_plugin"><strong><?php esc_html_e('Plugin source', 'ide-snippets-bridge'); ?></strong></label><br>
                            <select id="import_source_plugin" name="import_source_plugin">
                                <?php if ($show_source_auto) : ?>
                                    <option value="auto"><?php esc_html_e('Auto-détection', 'ide-snippets-bridge'); ?></option>
                                <?php endif; ?>
                                <?php foreach ($import_sources as $source_key => $source_label) : ?>
                                    <option value="<?php echo esc_attr($source_key); ?>"><?php echo esc_html($source_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p>
                            <label for="import_activation_mode"><strong><?php esc_html_e('Activation à l’import', 'ide-snippets-bridge'); ?></strong></label><br>
                            <select id="import_activation_mode" name="import_activation_mode">
                                <option value="keep"><?php esc_html_e('Conserver l’état importé', 'ide-snippets-bridge'); ?></option>
                                <option value="activate_all"><?php esc_html_e('Activer tous les snippets importés', 'ide-snippets-bridge'); ?></option>
                                <option value="deactivate_all"><?php esc_html_e('Désactiver tous les snippets importés', 'ide-snippets-bridge'); ?></option>
                            </select>
                        </p>
                        <p>
                            <label for="import_file"><strong><?php esc_html_e('Fichier', 'ide-snippets-bridge'); ?></strong></label><br>
                            <input type="file" id="import_file" name="import_file" accept=".json,.ndjson,.txt">
                        </p>
                        <p>
                            <label for="import_payload"><strong><?php esc_html_e('Ou coller les données', 'ide-snippets-bridge'); ?></strong></label>
                            <textarea class="large-text code" id="import_payload" name="import_payload" rows="10" placeholder="<?php echo esc_attr__('Si un fichier est fourni, son contenu est prioritaire.', 'ide-snippets-bridge'); ?>"></textarea>
                        </p>
                        <p><button type="submit" class="button button-primary"><?php esc_html_e('Importer', 'ide-snippets-bridge'); ?></button></p>
                    </form>
                </div>
                <div class="ide-snippets-panel">
                    <h2><?php esc_html_e('Exporter', 'ide-snippets-bridge'); ?></h2>
                    <p><?php esc_html_e('Téléchargez tous les snippets ou uniquement un sous-ensemble.', 'ide-snippets-bridge'); ?></p>
                    <form method="post">
                        <?php wp_nonce_field('ide_snippets_admin_action', 'ide_snippets_admin_nonce'); ?>
                        <input type="hidden" name="page" value="ide-snippets-bridge-import-export">
                        <input type="hidden" name="op" value="export_snippets">
                        <p>
                            <label for="export_format"><strong><?php esc_html_e('Format', 'ide-snippets-bridge'); ?></strong></label><br>
                            <select id="export_format" name="export_format">
                                <option value="json">JSON</option>
                                <option value="ndjson">NDJSON</option>
                            </select>
                        </p>
                        <p>
                            <label for="export_status"><strong><?php esc_html_e('Filtre', 'ide-snippets-bridge'); ?></strong></label><br>
                            <select id="export_status" name="export_status">
                                <option value="all"><?php esc_html_e('Tous', 'ide-snippets-bridge'); ?></option>
                                <option value="active"><?php esc_html_e('Actifs', 'ide-snippets-bridge'); ?></option>
                                <option value="inactive"><?php esc_html_e('Inactifs', 'ide-snippets-bridge'); ?></option>
                            </select>
                        </p>
                        <p><button type="submit" class="button button-primary"><?php esc_html_e('Télécharger l’export', 'ide-snippets-bridge'); ?></button></p>
                    </form>
                    <hr>
                    <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ide-snippets-bridge')); ?>"><?php esc_html_e('Retour à la liste', 'ide-snippets-bridge'); ?></a></p>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_assignments_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $notice = get_transient('ide_snippets_admin_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('ide_snippets_admin_notice_' . get_current_user_id());
        }

        $snippets = $this->get_native_snippets();
        $targetable_post_types = $this->get_targetable_post_types();
        $required_post_ids = [];
        if (!empty($snippets)) {
            foreach ($snippets as $snippet) {
                $required_post_ids = array_merge($required_post_ids, $this->get_snippet_target_post_ids($snippet));
            }
        }
        $targetable_posts = $this->get_targetable_posts_for_picker($targetable_post_types, $required_post_ids);
        $stats = $this->get_snippets_stats($snippets);
        ?>
        <div class="wrap ide-snippets-admin">
            <h1><?php esc_html_e('Attributions des snippets', 'ide-snippets-bridge'); ?></h1>
            <?php $this->render_stats_cards($stats); ?>
            <?php if ($notice && !empty($notice['message'])) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type'] === 'error' ? 'error' : 'success'); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>
            <div class="ide-snippets-panel">
                <p class="ide-premium-ready"><span class="ide-badge ide-badge-premium-ready">Premium-ready</span><span><?php esc_html_e('Attribution par contenu', 'ide-snippets-bridge'); ?></span></p>
                <form method="post">
                    <?php wp_nonce_field('ide_snippets_admin_action', 'ide_snippets_admin_nonce'); ?>
                    <input type="hidden" name="page" value="ide-snippets-bridge-assignments">
                    <input type="hidden" name="op" value="save_assignments_bulk">
                    <table class="widefat striped ide-assignments-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th><?php esc_html_e('Snippet', 'ide-snippets-bridge'); ?></th>
                                <th><?php esc_html_e('Mode', 'ide-snippets-bridge'); ?></th>
                                <th><?php esc_html_e('Types de contenu', 'ide-snippets-bridge'); ?></th>
                                <th><?php esc_html_e('IDs de contenu', 'ide-snippets-bridge'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($snippets)) : ?>
                                <tr><td colspan="5"><?php esc_html_e('Aucun snippet trouvé.', 'ide-snippets-bridge'); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ($snippets as $snippet) : ?>
                                    <?php $snippet_id = (int) $snippet->id; ?>
                                    <tr>
                                        <td><?php echo esc_html((string) $snippet_id); ?><input type="hidden" name="snippet_ids[]" value="<?php echo esc_attr((string) $snippet_id); ?>"></td>
                                        <td><?php echo esc_html($snippet->name); ?></td>
                                        <?php
                                        $snippet_mode = $this->get_snippet_target_mode($snippet);
                                        $snippet_post_types = $this->get_snippet_target_post_types($snippet);
                                        $snippet_post_ids = $this->get_snippet_target_post_ids($snippet);
                                        ?>
                                        <td>
                                            <select name="target_mode[<?php echo esc_attr((string) $snippet_id); ?>]" data-target-mode>
                                                <option value="all" <?php selected($snippet_mode, 'all'); ?>><?php esc_html_e('Général', 'ide-snippets-bridge'); ?></option>
                                                <option value="post_types" <?php selected($snippet_mode, 'post_types'); ?>><?php esc_html_e('Type de contenu', 'ide-snippets-bridge'); ?></option>
                                                <option value="specific_posts" <?php selected($snippet_mode, 'specific_posts'); ?>><?php esc_html_e('ID cibles', 'ide-snippets-bridge'); ?></option>
                                            </select>
                                        </td>
                                        <td data-target-types>
                                            <div class="ide-target-types-picker" data-types-picker data-empty-text="<?php echo esc_attr__('Aucun type sélectionné', 'ide-snippets-bridge'); ?>" data-values-mode="text">
                                                <div class="ide-types-control">
                                                    <button type="button" class="button ide-types-toggle" data-types-toggle><?php esc_html_e('Choisir les types', 'ide-snippets-bridge'); ?></button>
                                                    <div class="ide-types-dropdown ide-target-hidden" data-types-dropdown>
                                                        <div class="ide-types-search">
                                                            <input type="search" class="regular-text" placeholder="<?php echo esc_attr__('Rechercher un type...', 'ide-snippets-bridge'); ?>" data-types-search>
                                                        </div>
                                                        <?php foreach ($targetable_post_types as $post_type_key => $post_type_object) : ?>
                                                            <?php $type_label = $this->get_post_type_target_label($post_type_key, $post_type_object); ?>
                                                            <label class="ide-types-option">
                                                                <input type="checkbox" value="<?php echo esc_attr($post_type_key); ?>" data-types-checkbox data-types-label="<?php echo esc_attr($type_label); ?>" data-types-summary-label="<?php echo esc_attr($this->get_post_type_summary_label($post_type_key, $post_type_object)); ?>" <?php checked(in_array($post_type_key, $snippet_post_types, true), true); ?>>
                                                                <span><?php echo esc_html($type_label); ?></span>
                                                            </label>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                                <div class="ide-types-summary" data-types-summary></div>
                                                <input type="hidden" name="target_post_types[<?php echo esc_attr((string) $snippet_id); ?>]" value="<?php echo esc_attr(implode(',', $snippet_post_types)); ?>" data-types-hidden>
                                            </div>
                                        </td>
                                        <td data-target-ids>
                                            <div class="ide-target-types-picker" data-types-picker data-empty-text="<?php echo esc_attr__('Aucun contenu sélectionné', 'ide-snippets-bridge'); ?>" data-values-mode="numeric">
                                                <div class="ide-types-control">
                                                    <button type="button" class="button ide-types-toggle" data-types-toggle><?php esc_html_e('Choisir les IDs', 'ide-snippets-bridge'); ?></button>
                                                    <div class="ide-types-dropdown ide-target-hidden" data-types-dropdown>
                                                        <div class="ide-types-search">
                                                            <input type="search" class="regular-text" placeholder="<?php echo esc_attr__('Rechercher un ID, titre, type...', 'ide-snippets-bridge'); ?>" data-types-search>
                                                        </div>
                                                        <?php foreach ($targetable_posts as $post_item) : ?>
                                                            <label class="ide-types-option">
                                                                <input type="checkbox" value="<?php echo esc_attr((string) $post_item['id']); ?>" data-types-checkbox data-types-label="<?php echo esc_attr($post_item['label']); ?>" data-types-summary-label="<?php echo esc_attr($post_item['summary_label']); ?>" <?php checked(in_array((int) $post_item['id'], $snippet_post_ids, true), true); ?>>
                                                                <span><?php echo esc_html($post_item['label']); ?></span>
                                                            </label>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                                <div class="ide-types-summary" data-types-summary></div>
                                                <input class="regular-text ide-target-ids-input" type="text" name="target_post_ids[<?php echo esc_attr((string) $snippet_id); ?>]" value="<?php echo isset($snippet->target_post_ids) ? esc_attr($snippet->target_post_ids) : ''; ?>" placeholder="<?php echo esc_attr__('12,34,56', 'ide-snippets-bridge'); ?>" data-types-hidden>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <p style="margin-top:12px;">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Enregistrer les attributions', 'ide-snippets-bridge'); ?></button>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ide-snippets-bridge')); ?>"><?php esc_html_e('Retour à la liste', 'ide-snippets-bridge'); ?></a>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    public function render_edit_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $notice = get_transient('ide_snippets_admin_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('ide_snippets_admin_notice_' . get_current_user_id());
        }

        $page_slug = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $page_slug = is_string($page_slug) && $page_slug !== '' ? sanitize_text_field(wp_unslash($page_slug)) : 'ide-snippets-bridge-edit';
        if (!in_array($page_slug, ['ide-snippets-bridge-new', 'ide-snippets-bridge-edit'], true)) {
            $page_slug = 'ide-snippets-bridge-edit';
        }

        $snippet_id_input = filter_input(INPUT_GET, 'snippet_id', FILTER_SANITIZE_NUMBER_INT);
        $snippet_id = $snippet_id_input !== null && $snippet_id_input !== false ? absint($snippet_id_input) : 0;
        $editing = $snippet_id > 0 ? $this->get_native_snippet($snippet_id) : null;
        ?>
        <div class="wrap ide-snippets-admin">
            <h1><?php esc_html_e('Eliodata Snippet Hub (Native)', 'ide-snippets-bridge'); ?></h1>

            <?php if ($notice && !empty($notice['message'])) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type'] === 'error' ? 'error' : 'success'); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <?php $this->render_snippet_editor_panel($editing, $page_slug); ?>
        </div>
        <?php
    }
}

/**
 * Initialize the plugin
 *
 * @return IDE_Snippets_Bridge
 */
function ide_snippets_bridge_init() {
    return IDE_Snippets_Bridge::get_instance();
}

// Go!
ide_snippets_bridge_init();
