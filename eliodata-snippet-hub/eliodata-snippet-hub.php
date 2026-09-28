<?php
/**
 * Plugin Name: Eliodata MCP Bridge
 * Plugin URI: https://wordpress.org/plugins/eliodata-snippet-hub/
 * Description: WordPress MCP bridge with native snippet management, remote IDE control, and per-site custom MCP tools.
 * Version: 4.2.2
 * Author: Eliodata
 * Author URI: https://eliodata.com
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least: 5.6
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Text Domain: eliodata-snippet-hub
 *
 * @package IDESnippets
 * @subpackage Bridge
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ELIODATA_SNIPPET_HUB_VERSION', '4.2.2');
define('ELIODATA_SNIPPET_HUB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ELIODATA_SNIPPET_HUB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ELIODATA_SNIPPET_HUB_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Include the API endpoint classes
require_once ELIODATA_SNIPPET_HUB_PLUGIN_DIR . 'includes/class-eliodata-snippet-hub-security.php';
require_once ELIODATA_SNIPPET_HUB_PLUGIN_DIR . 'includes/class-eliodata-snippet-hub-api.php';
require_once ELIODATA_SNIPPET_HUB_PLUGIN_DIR . 'includes/class-eliodata-snippet-hub-mcp-tool-provider.php';
require_once ELIODATA_SNIPPET_HUB_PLUGIN_DIR . 'includes/class-eliodata-snippet-hub-mcp-builtin-tools.php';
require_once ELIODATA_SNIPPET_HUB_PLUGIN_DIR . 'includes/class-eliodata-snippet-hub-mcp-builtin-dispatcher.php';
require_once ELIODATA_SNIPPET_HUB_PLUGIN_DIR . 'includes/class-eliodata-snippet-hub-mcp.php';
require_once ELIODATA_SNIPPET_HUB_PLUGIN_DIR . 'includes/class-eliodata-snippet-hub-mcp-servers.php';

/**
 * Main plugin class
 *
 * @since 1.0.0
 */
class IDE_Snippets_Bridge {

    /** @var IDE_Snippets_Bridge|null */
    private static $instance = null;
    /** @var array Snippet ids already executed during this request. */
    private $executed_snippet_ids = [];

    /** @var int Snippet whose top-level code is being executed. */
    private $current_snippet_id = 0;

    /** @var array Temporary file path => snippet id, to attribute fatal errors. */
    private $runtime_snippet_files = [];

    /** @var array Snippet id => lines stripped before the code body. */
    private $runtime_line_offsets = [];

    private $shutdown_handler_registered = false;

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
        Eliodata_Snippet_Hub_Security::init();
        add_action('rest_api_init', [$this, 'init_api']);
        add_action('admin_notices', [$this, 'render_runtime_notices']);
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

        foreach (Eliodata_Snippet_Hub_MCP_Registry::build_servers() as $server) {
            $server->register_routes();
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        if (!function_exists('deactivate_plugins')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (version_compare(get_bloginfo('version'), '5.6', '<')) {
            deactivate_plugins(ELIODATA_SNIPPET_HUB_PLUGIN_BASENAME);
            wp_die(esc_html__('Eliodata MCP Bridge requires WordPress 5.6 or higher.', 'eliodata-snippet-hub'));
        }

        if (version_compare(PHP_VERSION, '7.4', '<')) {
            deactivate_plugins(ELIODATA_SNIPPET_HUB_PLUGIN_BASENAME);
            wp_die(esc_html__('Eliodata MCP Bridge requires PHP 7.4 or higher.', 'eliodata-snippet-hub'));
        }

        IDE_Snippets_API::create_native_table();
        update_option('eliodata_snippet_hub_schema_version', ELIODATA_SNIPPET_HUB_VERSION);
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }

    public function maybe_upgrade_native_schema() {
        $stored_version = get_option('eliodata_snippet_hub_schema_version', '');
        if ((string) $stored_version === (string) ELIODATA_SNIPPET_HUB_VERSION) {
            return;
        }
        IDE_Snippets_API::create_native_table();
        update_option('eliodata_snippet_hub_schema_version', ELIODATA_SNIPPET_HUB_VERSION);
    }

    private function get_admin_menu_icon_data_uri() {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="20" height="20"><path fill="#111111" d="M10 1l3 3-3 3-3-3 3-3zm-4 8h8l-4 4-4-4zm4 4l3 3-3 3-3-3 3-3zM1 10l4-4 2 2-2 2 2 2-2 2-4-4zm18 0l-4-4-2 2 2 2-2 2 2 2 4-4z"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public function register_admin_menu() {
        add_menu_page(
            esc_html__('Eliodata MCP Bridge', 'eliodata-snippet-hub'),
            esc_html__('Eliodata MCP Bridge', 'eliodata-snippet-hub'),
            Eliodata_Snippet_Hub_Security::get_required_capability(),
            'eliodata-snippet-hub',
            [$this, 'render_admin_page'],
            $this->get_admin_menu_icon_data_uri(),
            58
        );

        add_submenu_page(
            'eliodata-snippet-hub',
            esc_html__('Snippets', 'eliodata-snippet-hub'),
            esc_html__('Snippets', 'eliodata-snippet-hub'),
            Eliodata_Snippet_Hub_Security::get_required_capability(),
            'eliodata-snippet-hub',
            [$this, 'render_admin_page']
        );

        add_submenu_page(
            'eliodata-snippet-hub',
            esc_html__('Nouveau snippet', 'eliodata-snippet-hub'),
            esc_html__('Nouveau snippet', 'eliodata-snippet-hub'),
            Eliodata_Snippet_Hub_Security::get_required_capability(),
            'eliodata-snippet-hub-new',
            [$this, 'render_edit_page']
        );

        add_submenu_page(
            'eliodata-snippet-hub',
            esc_html__('Modifier le snippet', 'eliodata-snippet-hub'),
            '',
            Eliodata_Snippet_Hub_Security::get_required_capability(),
            'eliodata-snippet-hub-edit',
            [$this, 'render_edit_page']
        );

        add_submenu_page(
            'eliodata-snippet-hub',
            esc_html__('Attributions', 'eliodata-snippet-hub'),
            esc_html__('Attributions', 'eliodata-snippet-hub'),
            Eliodata_Snippet_Hub_Security::get_required_capability(),
            'eliodata-snippet-hub-assignments',
            [$this, 'render_assignments_page']
        );

        add_submenu_page(
            'eliodata-snippet-hub',
            esc_html__('Import / Export', 'eliodata-snippet-hub'),
            esc_html__('Import / Export', 'eliodata-snippet-hub'),
            Eliodata_Snippet_Hub_Security::get_required_capability(),
            'eliodata-snippet-hub-import-export',
            [$this, 'render_import_export_page']
        );

        add_submenu_page(
            'eliodata-snippet-hub',
            esc_html__('Outils MCP', 'eliodata-snippet-hub'),
            esc_html__('Outils MCP', 'eliodata-snippet-hub'),
            Eliodata_Snippet_Hub_Security::get_required_capability(),
            'eliodata-snippet-hub-mcp',
            [$this, 'render_mcp_page']
        );
    }

    public function enqueue_admin_assets($hook_suffix) {
        if (strpos((string) $hook_suffix, 'eliodata-snippet-hub') === false) {
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
                'enterFullscreen' => __('Plein écran', 'eliodata-snippet-hub'),
                'exitFullscreen' => __('Quitter plein écran', 'eliodata-snippet-hub'),
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

        wp_add_inline_style('wp-codemirror', '
            .ide-snippets-admin .ide-admin-header{position:sticky;top:32px;z-index:20;display:flex;align-items:center;gap:8px 20px;flex-wrap:wrap;margin:0 0 14px;padding:8px 14px;background:#fff;border:1px solid #dcdcde;border-radius:10px;box-shadow:0 1px 2px rgba(0,0,0,.04);}
            .ide-snippets-admin .ide-admin-brand{display:flex;align-items:center;gap:8px;font-weight:600;font-size:14px;color:#1d2327;}
            .ide-snippets-admin .ide-admin-brand img{border-radius:6px;}
            .ide-snippets-admin .ide-admin-version{font-weight:400;font-size:11px;color:#646970;background:#f0f0f1;border-radius:999px;padding:1px 7px;}
            .ide-snippets-admin .ide-admin-nav{display:flex;flex-wrap:wrap;gap:4px;margin-left:auto;}
            .ide-snippets-admin .ide-admin-nav a{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:6px;color:#2c3338;text-decoration:none;font-weight:500;}
            .ide-snippets-admin .ide-admin-nav a:hover{background:#f0f0f1;color:#1d2327;}
            .ide-snippets-admin .ide-admin-nav a:focus{box-shadow:0 0 0 2px #2271b1;outline:none;}
            .ide-snippets-admin .ide-admin-nav a.is-current{background:#2271b1;color:#fff;}
            .ide-snippets-admin .ide-admin-nav .dashicons{font-size:16px;width:16px;height:16px;}
            .ide-snippets-admin > h1{margin-bottom:4px;}
            @media (max-width: 782px){
                .ide-snippets-admin .ide-admin-header{position:static;}
                .ide-snippets-admin .ide-admin-nav{margin-left:0;}
            }
            .ide-snippets-admin .ide-mcp-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(340px,420px);gap:20px;align-items:start;}
            .ide-snippets-admin .ide-mcp-stack{display:grid;gap:20px;min-width:0;}
            .ide-snippets-admin .ide-mcp-aside{position:sticky;top:104px;}
            .ide-snippets-admin .ide-mcp-muted{color:#646970;margin-top:0;}
            .ide-snippets-admin .ide-snippets-card .ide-mcp-card-note{display:block;margin-top:4px;font-size:12px;color:#8a5a00;}
            .ide-snippets-admin .ide-mcp-table td,.ide-snippets-admin .ide-mcp-table th{vertical-align:top;}
            .ide-snippets-admin .ide-mcp-table tr.is-selected td{box-shadow:inset 0 0 0 9999px rgba(34,113,177,.06);}
            .ide-snippets-admin .ide-mcp-table tr.is-selected td:first-child{box-shadow:inset 3px 0 0 #2271b1,inset 0 0 0 9999px rgba(34,113,177,.06);}
            .ide-snippets-admin .ide-mcp-col-tool{min-width:220px;}
            .ide-snippets-admin .ide-mcp-tool-name{font-weight:600;font-size:13px;}
            .ide-snippets-admin .ide-mcp-desc{margin:4px 0 0;color:#1d2327;}
            .ide-snippets-admin .ide-mcp-route{margin-top:4px;}
            .ide-snippets-admin .ide-mcp-route code{font-size:11px;color:#50575e;background:transparent;padding:0;}
            .ide-snippets-admin .ide-mcp-warning{margin-top:6px;padding:4px 8px;border-left:3px solid #dba617;background:#fcf9e8;font-size:12px;}
            .ide-snippets-admin .ide-mcp-col-actions{width:1%;white-space:nowrap;}
            .ide-snippets-admin .ide-mcp-col-actions form{display:inline;}
            .ide-snippets-admin .ide-mcp-col-actions .ide-snippets-actions{flex-wrap:nowrap;gap:4px;}
            .ide-snippets-admin .ide-mcp-table .ide-mcp-col-profiles{width:1%;white-space:nowrap;}
            .ide-snippets-admin .ide-mcp-table .ide-mcp-col-origin{width:1%;}
            .ide-snippets-admin .ide-mcp-table .ide-badge{white-space:nowrap;}
                        .ide-snippets-admin .ide-badge-native{background:#f0f0f1;color:#3c434a;}
            .ide-snippets-admin .ide-badge-custom{background:#e5f1fb;color:#135e96;}
            .ide-snippets-admin .ide-badge-write{background:#fcefd9;color:#8a4b00;}
                        .ide-snippets-admin .ide-mcp-params{display:flex;flex-wrap:wrap;gap:4px;}
            .ide-snippets-admin .ide-mcp-param{font-size:11px;padding:1px 6px;border-radius:4px;background:#f6f7f7;border:1px solid #dcdcde;cursor:help;}
            .ide-snippets-admin .ide-mcp-param.is-required{border-color:#2271b1;color:#135e96;}
            .ide-snippets-admin .ide-mcp-schema{margin-top:6px;white-space:normal;}
            .ide-snippets-admin .ide-mcp-schema summary{cursor:pointer;color:#2271b1;font-size:12px;}
            .ide-snippets-admin .ide-mcp-schema .ide-mcp-code{min-width:260px;margin-top:6px;}
            .ide-snippets-admin .ide-mcp-code{display:block;white-space:pre-wrap;word-break:break-word;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;padding:10px;max-height:320px;overflow:auto;font-size:12px;line-height:1.45;margin:0;}
            .ide-snippets-admin details.ide-snippets-panel > summary{cursor:pointer;list-style:none;display:flex;align-items:center;gap:8px;}
            .ide-snippets-admin details.ide-snippets-panel > summary::-webkit-details-marker{display:none;}
            .ide-snippets-admin details.ide-snippets-panel > summary:before{content:"\25B8";color:#646970;transition:transform .15s ease;}
            .ide-snippets-admin details.ide-snippets-panel[open] > summary:before{transform:rotate(90deg);}
            .ide-snippets-admin details.ide-snippets-panel > summary h2{margin:0;}
            .ide-snippets-admin details.ide-snippets-panel[open] > summary{margin-bottom:14px;}
            .ide-snippets-admin .ide-mcp-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px 16px;}
            .ide-snippets-admin .ide-mcp-form-grid .ide-field-full{grid-column:1 / -1;}
            .ide-snippets-admin .ide-field .description{display:block;margin-top:4px;}
            .ide-snippets-admin .ide-mcp-label{display:block;font-weight:600;margin:0 0 4px;}
            .ide-snippets-admin .ide-field .ide-mcp-check{font-weight:400;display:flex;align-items:center;gap:6px;min-height:30px;}
            .ide-snippets-admin .ide-field textarea.code{font-family:Menlo,Consolas,monospace;font-size:12px;}
            .ide-snippets-admin .ide-field .description.is-error{color:#b32d2e;}
            .ide-snippets-admin .ide-field .description.is-ok{color:#007017;}
            .ide-snippets-admin .ide-mcp-tester-info{margin:-4px 0 12px;display:grid;gap:6px;}
            .ide-snippets-admin .ide-mcp-tester-info:empty{display:none;}
            .ide-snippets-admin .ide-mcp-tester-info .ide-mcp-desc{color:#50575e;}
            .ide-snippets-admin .ide-mcp-result{margin-top:16px;padding-top:14px;border-top:1px solid #dcdcde;}
            .ide-snippets-admin .ide-mcp-result-header{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 8px;}
            .ide-snippets-admin .ide-mcp-result-header .button{margin-left:auto;}
            .ide-snippets-admin .ide-mcp-result.is-error .ide-mcp-code{border-color:#f0b8b8;background:#fcf0f1;}
            .ide-snippets-admin .ide-mcp-result-body{max-height:420px;}
            .ide-snippets-admin .ide-mcp-endpoints{margin:0 0 14px;}
            .ide-snippets-admin .ide-mcp-endpoints th{width:160px;font-weight:600;}
            .ide-snippets-admin .ide-mcp-endpoints code{word-break:break-all;}
            .ide-snippets-admin .ide-snippets-toolbar input[type="search"]{width:280px;}
            @media (max-width: 1280px){
                .ide-snippets-admin .ide-mcp-layout{grid-template-columns:1fr;}
                .ide-snippets-admin .ide-mcp-aside{position:static;}
            }
            @media (max-width: 782px){
                .ide-snippets-admin .ide-mcp-form-grid{grid-template-columns:1fr;}
                .ide-snippets-admin .ide-mcp-table thead{display:none;}
                .ide-snippets-admin .ide-mcp-table td{display:block;}
                .ide-snippets-admin .ide-mcp-col-actions{width:auto;white-space:normal;}
            }
        ');

        if (strpos((string) $hook_suffix, 'eliodata-snippet-hub-mcp') !== false) {
            wp_add_inline_script('code-editor', 'window.ideMcpI18n = ' . wp_json_encode([
                'jsonOk' => __('JSON valide.', 'eliodata-snippet-hub'),
                'jsonError' => __('JSON invalide : ', 'eliodata-snippet-hub'),
                'jsonEmpty' => __('Laisser vide pour un outil sans paramètre.', 'eliodata-snippet-hub'),
                'copied' => __('Copié', 'eliodata-snippet-hub'),
                'none' => __('Aucun paramètre.', 'eliodata-snippet-hub'),
                'passAsLocked' => __('GET et DELETE passent toujours leurs arguments dans l’URL.', 'eliodata-snippet-hub'),
                'passAsFree' => __('POST, PUT et PATCH peuvent envoyer un corps JSON.', 'eliodata-snippet-hub'),
                'readonlyOk' => __('Visible en profil lecture.', 'eliodata-snippet-hub'),
                'readonlyNotGet' => __('Lecture seule mais pas en GET : seul le profil écriture le verra.', 'eliodata-snippet-hub'),
                'readonlyOff' => __('Seul le profil écriture le verra.', 'eliodata-snippet-hub'),
            ]) . ';', 'before');
            wp_add_inline_script('code-editor', <<<'JS'
(function () {
    var i18n = window.ideMcpI18n || {};
    function esc(value) {
        return String(value).replace(/[&<>"]/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c];
        });
    }
    function checkJson(field, status, emptyText) {
        if (!field || !status) { return; }
        var value = field.value.trim();
        status.classList.remove('is-error', 'is-ok');
        if (value === '') { status.textContent = emptyText || ''; return; }
        try {
            var parsed = JSON.parse(value);
            if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) { throw new Error('objet attendu'); }
            status.textContent = i18n.jsonOk;
            status.classList.add('is-ok');
        } catch (error) {
            status.textContent = i18n.jsonError + error.message;
            status.classList.add('is-error');
        }
    }
    function sampleValue(definition) {
        definition = definition || {};
        if (Object.prototype.hasOwnProperty.call(definition, 'default')) { return definition['default']; }
        if (Array.isArray(definition['enum']) && definition['enum'].length) { return definition['enum'][0]; }
        var type = Array.isArray(definition.type) ? definition.type[0] : definition.type;
        if (type === 'integer' || type === 'number') { return typeof definition.minimum === 'number' ? definition.minimum : 0; }
        if (type === 'boolean') { return false; }
        if (type === 'array') { return []; }
        if (type === 'object') { return {}; }
        return '';
    }
    function templateFor(tool) {
        var schema = (tool && tool.schema) || {};
        var properties = schema.properties || {};
        var template = {};
        (schema.required || []).forEach(function (name) { template[name] = sampleValue(properties[name]); });
        return JSON.stringify(template, null, 2);
    }
    function paramsHtml(parameters) {
        if (!parameters || !parameters.length) { return '<span class="ide-mcp-muted">' + esc(i18n.none) + '</span>'; }
        return '<span class="ide-mcp-params">' + parameters.map(function (p) {
            var title = p.type + (p['enum'] && p['enum'].length ? ' : ' + p['enum'].join(', ') : '');
            return '<code class="ide-mcp-param' + (p.required ? ' is-required' : '') + '" title="' + esc(title) + '">' + esc(p.name) + (p.required ? '<span aria-hidden="true">*</span>' : '') + '</code>';
        }).join('') + '</span>';
    }

    document.addEventListener('DOMContentLoaded', function () {
        var table = document.getElementById('ide-mcp-table');
        var search = document.getElementById('ide-mcp-search');
        if (search && table) {
            search.addEventListener('input', function () {
                var needle = search.value.trim().toLowerCase();
                table.querySelectorAll('tr[data-mcp-row]').forEach(function (row) {
                    row.style.display = !needle || row.textContent.toLowerCase().indexOf(needle) !== -1 ? '' : 'none';
                });
            });
        }

        var tester = document.querySelector('[data-mcp-tester]');
        if (tester) {
            var tools = {};
            try { tools = JSON.parse(tester.getAttribute('data-mcp-tools') || '{}'); } catch (e) { tools = {}; }
            var select = tester.querySelector('#mcp-test-tool');
            var args = tester.querySelector('#mcp-test-arguments');
            var info = tester.querySelector('[data-mcp-tester-info]');
            var lastTemplate = args ? args.value.trim() : '';
            var markRow = function (name) {
                if (!table) { return; }
                table.querySelectorAll('tr[data-mcp-row]').forEach(function (row) {
                    var button = row.querySelector('[data-mcp-test]');
                    row.classList.toggle('is-selected', !!button && button.getAttribute('data-mcp-test') === name);
                });
            };
            var refresh = function () {
                var tool = tools[select.value] || null;
                if (info) {
                    info.innerHTML = tool ? (tool.description ? '<p class="ide-mcp-desc">' + esc(tool.description) + '</p>' : '') + paramsHtml(tool.parameters) : '';
                }
                // Only replace arguments the user has not edited
                var current = args.value.trim();
                if (current === '' || current === '{}' || current === lastTemplate) {
                    lastTemplate = templateFor(tool);
                    args.value = lastTemplate;
                }
                markRow(select.value);
            };
            select.addEventListener('change', refresh);
            tester.addEventListener('submit', function (event) {
                var tool = tools[select.value];
                if (tool && !tool.read && !window.confirm(tester.getAttribute('data-mcp-confirm'))) {
                    event.preventDefault();
                }
            });
            document.querySelectorAll('[data-mcp-test]').forEach(function (link) {
                link.addEventListener('click', function (event) {
                    event.preventDefault();
                    select.value = link.getAttribute('data-mcp-test');
                    refresh();
                    document.getElementById('mcp-tester').scrollIntoView({behavior: 'smooth', block: 'start'});
                    args.focus({preventScroll: true});
                });
            });
            if (args.value.trim() === '{}') { refresh(); } else { markRow(select.value); }
        }

        document.querySelectorAll('[data-mcp-copy]').forEach(function (button) {
            button.addEventListener('click', function () {
                var target = document.querySelector(button.getAttribute('data-mcp-copy'));
                if (!target || !navigator.clipboard) { return; }
                navigator.clipboard.writeText(target.textContent).then(function () {
                    var label = button.textContent;
                    button.textContent = i18n.copied;
                    setTimeout(function () { button.textContent = label; }, 1500);
                });
            });
        });

        var formPanel = document.getElementById('mcp-tool-form');
        document.querySelectorAll('[data-mcp-open-form]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                if (!formPanel || formPanel.querySelector('input[name="existing_name"]').value !== '') { return; }
                event.preventDefault();
                formPanel.open = true;
                formPanel.scrollIntoView({behavior: 'smooth', block: 'start'});
                var name = formPanel.querySelector('#mcp-tool-name');
                if (name) { name.focus({preventScroll: true}); }
            });
        });

        var toolForm = document.querySelector('[data-mcp-tool-form]');
        if (toolForm) {
            var method = toolForm.querySelector('#mcp-tool-method');
            var passAs = toolForm.querySelector('#mcp-tool-pass-as');
            var passAsHint = toolForm.querySelector('[data-mcp-pass-as-hint]');
            var readOnly = toolForm.querySelector('input[name="read_only_hint"]');
            var readOnlyHint = toolForm.querySelector('[data-mcp-readonly-hint]');
            var schema = toolForm.querySelector('#mcp-tool-input-schema');
            var schemaStatus = toolForm.querySelector('[data-mcp-json-status]');
            var syncMethod = function () {
                var locked = method.value === 'GET' || method.value === 'DELETE';
                if (locked) { passAs.value = 'query'; }
                // A disabled select is not posted: the server then falls back to query for GET
                passAs.disabled = locked;
                passAsHint.textContent = locked ? i18n.passAsLocked : i18n.passAsFree;
                if (!readOnly.checked) {
                    readOnlyHint.textContent = i18n.readonlyOff;
                } else {
                    readOnlyHint.textContent = method.value === 'GET' ? i18n.readonlyOk : i18n.readonlyNotGet;
                }
                readOnlyHint.classList.toggle('is-error', readOnly.checked && method.value !== 'GET');
            };
            method.addEventListener('change', syncMethod);
            readOnly.addEventListener('change', syncMethod);
            toolForm.addEventListener('submit', function () { passAs.disabled = false; });
            syncMethod();
            schema.addEventListener('input', function () { checkJson(schema, schemaStatus, i18n.jsonEmpty); });
            checkJson(schema, schemaStatus, i18n.jsonEmpty);
        }
    });
})();
JS
            );
        }
    }

    private function get_native_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ide_snippets';
    }

    private function clear_snippet_cache($id = 0) {
        Eliodata_Snippet_Hub_Security::clear_snippet_cache($id);
    }

    private function get_native_snippet($id) {
        $id = absint($id);
        if ($id <= 0) {
            return null;
        }

        $cached = wp_cache_get('eliodata_snippet_hub_snippet_' . $id, 'eliodata_snippet_hub');
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $table = $this->get_native_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id));

        wp_cache_set('eliodata_snippet_hub_snippet_' . $id, $row, 'eliodata_snippet_hub');
        return $row;
    }

    private function get_native_snippets() {
        $cached = wp_cache_get('eliodata_snippet_hub_all_snippets', 'eliodata_snippet_hub');
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $table = $this->get_native_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $results = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY id DESC");

        wp_cache_set('eliodata_snippet_hub_all_snippets', $results, 'eliodata_snippet_hub');
        return $results;
    }

    private function get_active_native_snippets() {
        $cached = wp_cache_get('eliodata_snippet_hub_active_snippets', 'eliodata_snippet_hub');
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $table = $this->get_native_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $results = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM `{$table}` WHERE active = %d ORDER BY priority ASC, id ASC",
                1
            )
        );

        wp_cache_set('eliodata_snippet_hub_active_snippets', $results, 'eliodata_snippet_hub');
        return $results;
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
            'native' => __('MCP Bridge (Eliodata Native)', 'eliodata-snippet-hub'),
        ];
        $sources = apply_filters('eliodata_snippet_hub_import_sources', $sources, $this);
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
            $normalized['native'] = __('MCP Bridge (Eliodata Native)', 'eliodata-snippet-hub');
        }
        return $normalized;
    }

    private function sanitize_import_source_plugin($value) {
        $plugin = sanitize_key((string) $value);
        if (in_array($plugin, ['eliodata', 'eliodata_snippets', 'eliodata_snippet_hub', 'ide_snippets'], true)) {
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
        $item = apply_filters('eliodata_snippet_hub_import_item_payload', $item, $source_plugin, $this);
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
        return $this->sanitize_absint_array($raw_values);
    }

    private function sanitize_absint_array($raw_values) {
        if (!is_array($raw_values)) {
            return [];
        }
        $clean_ids = array_map('absint', $raw_values);
        $clean_ids = array_filter($clean_ids, static function ($item) {
            return $item > 0;
        });
        return array_values(array_unique($clean_ids));
    }

    private function sanitize_snippet_code($value) {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }
        return wp_check_invalid_utf8((string) $value);
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
        return Eliodata_Snippet_Hub_Security::normalize_snippet_code($code);
    }

    private function execute_runtime_snippet_code($code, $snippet_id) {
        // Compiled once per code version in a private uploads folder (and cached by OPcache)
        $file = Eliodata_Snippet_Hub_Security::get_runtime_file($snippet_id, $code);
        $is_temporary = false;

        if ($file === '') {
            // Fallback when uploads is not writable: temporary file deleted after use
            $file = wp_tempnam('ide-snippet-');
            if (!is_string($file) || $file === '' || file_put_contents($file, "<?php\n" . $code . "\n") === false) {
                return;
            }
            $is_temporary = true;
        }

        // PHP reports errors with the resolved path, which differs when uploads is a symlink
        $this->runtime_snippet_files[$file] = $snippet_id;
        $real_file = realpath($file);
        if (is_string($real_file) && $real_file !== $file) {
            $this->runtime_snippet_files[$real_file] = $snippet_id;
        }
        $this->current_snippet_id = $snippet_id;
        try {
            include $file;
        } finally {
            $this->current_snippet_id = 0;
            if ($is_temporary) {
                wp_delete_file($file);
            }
        }
    }

    /**
     * Snippet management screens and routes never run snippets, so a broken
     * snippet can always be fixed from the admin or from the IDE.
     */
    private function is_snippet_management_request() {
        if (is_admin()) {
            $page = isset($_REQUEST['page']) ? sanitize_key(wp_unslash($_REQUEST['page'])) : '';
            return strpos($page, 'eliodata-snippet-hub') === 0;
        }

        $rest_route = isset($_GET['rest_route']) ? sanitize_text_field(wp_unslash($_GET['rest_route'])) : '';
        if ($rest_route !== '' && strpos(ltrim($rest_route, '/'), 'ide/v1/') === 0) {
            return true;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $rest_prefix = '/' . trim(rest_get_url_prefix(), '/') . '/ide/v1/';
        return $request_uri !== '' && strpos($request_uri, $rest_prefix) !== false;
    }

    private function deactivate_failed_snippet($snippet_id, $message, $line) {
        global $wpdb;

        $snippet_id = absint($snippet_id);
        if ($snippet_id <= 0) {
            return;
        }

        $table = $this->get_native_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update($table, ['active' => 0], ['id' => $snippet_id], ['%d'], ['%d']);
        $this->clear_snippet_cache($snippet_id);
        Eliodata_Snippet_Hub_Security::record_runtime_error($snippet_id, $message, $line, true);
    }

    /**
     * Deactivate the snippet responsible for a fatal error, including errors
     * raised later by callbacks declared in the snippet.
     */
    public function handle_runtime_shutdown() {
        $error = error_get_last();
        $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
        if (!is_array($error) || !in_array((int) $error['type'], $fatal_types, true)) {
            return;
        }

        $file = isset($error['file']) ? (string) $error['file'] : '';
        $snippet_id = isset($this->runtime_snippet_files[$file]) ? $this->runtime_snippet_files[$file] : $this->current_snippet_id;
        if ($snippet_id <= 0) {
            return;
        }

        $message = $this->hide_runtime_paths((string) $error['message']);
        $line = isset($error['line']) && $this->is_snippet_file($file, $snippet_id)
            ? $this->to_snippet_line($snippet_id, (int) $error['line'])
            : 0;
        $this->deactivate_failed_snippet($snippet_id, $message, $line);
    }

    /**
     * Convert a line of the temporary file into a line of the stored snippet code.
     */
    private function to_snippet_line($snippet_id, $file_line) {
        $offset = isset($this->runtime_line_offsets[$snippet_id]) ? $this->runtime_line_offsets[$snippet_id] : 0;
        return max(1, (int) $file_line - 1 + $offset);
    }

    private function get_throwable_snippet_line(Throwable $e, $snippet_id) {
        return $this->is_snippet_file($e->getFile(), $snippet_id) ? $this->to_snippet_line($snippet_id, $e->getLine()) : 0;
    }

    private function is_snippet_file($file, $snippet_id) {
        return isset($this->runtime_snippet_files[$file]) && $this->runtime_snippet_files[$file] === $snippet_id;
    }

    /**
     * Replace snippet file paths in an error message, longest first so a path
     * never leaves a partial prefix behind.
     */
    private function hide_runtime_paths($message) {
        $paths = array_keys($this->runtime_snippet_files);
        usort($paths, static function ($left, $right) {
            return strlen($right) - strlen($left);
        });
        return str_replace($paths, 'snippet', $message);
    }

    public function render_runtime_notices() {
        if (!Eliodata_Snippet_Hub_Security::current_user_can_manage()) {
            return;
        }

        if (Eliodata_Snippet_Hub_Security::is_safe_mode()) {
            echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Eliodata MCP Bridge:', 'eliodata-snippet-hub') . '</strong> ';
            echo esc_html__('Mode sans échec actif (ELIODATA_SNIPPET_HUB_SAFE_MODE) : aucun snippet n’est exécuté.', 'eliodata-snippet-hub');
            echo '</p></div>';
        }

        $errors = Eliodata_Snippet_Hub_Security::get_runtime_errors();
        if (empty($errors)) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>' . esc_html__('Eliodata MCP Bridge : snippets arrêtés après une erreur', 'eliodata-snippet-hub') . '</strong></p><ul>';
        foreach ($errors as $snippet_id => $error) {
            $edit_url = add_query_arg(['page' => 'eliodata-snippet-hub-edit', 'snippet_id' => absint($snippet_id)], admin_url('admin.php'));
            $status = !empty($error['deactivated']) ? __('désactivé', 'eliodata-snippet-hub') : __('toujours actif', 'eliodata-snippet-hub');
            printf(
                '<li><a href="%1$s">%2$s</a> (%3$s, %4$s) : %5$s</li>',
                esc_url($edit_url),
                esc_html(sprintf(__('Snippet #%d', 'eliodata-snippet-hub'), absint($snippet_id))),
                esc_html($status),
                esc_html(isset($error['time']) ? (string) $error['time'] : ''),
                esc_html(isset($error['message']) ? (string) $error['message'] : '')
            );
        }
        echo '</ul><p>' . esc_html__('Corrigez puis enregistrez le snippet pour effacer ce message.', 'eliodata-snippet-hub') . '</p></div>';
    }

    /**
     * Runs on init (priority 1) and on wp (priority 1).
     *
     * Snippets targeting all content run on init, so they also work in REST,
     * cron and AJAX requests and can register hooks such as init or rest_api_init.
     * Snippets targeting post types or specific posts need the main query:
     * on the front end they run on wp, once the requested post is known.
     */
    public function execute_active_snippets() {
        if (defined('WP_CLI') && WP_CLI) {
            return;
        }

        if (Eliodata_Snippet_Hub_Security::is_safe_mode() || $this->is_snippet_management_request()) {
            return;
        }

        $context = $this->get_current_execution_context();
        $query_ready = !empty($context['query_ready']);

        // Timing before 4.2.0: every front-end snippet waited for the wp hook
        if (!$query_ready && defined('ELIODATA_SNIPPET_HUB_LEGACY_TIMING') && ELIODATA_SNIPPET_HUB_LEGACY_TIMING) {
            return;
        }

        $snippets = $this->get_active_native_snippets();
        if (empty($snippets)) {
            return;
        }

        foreach ($snippets as $snippet) {
            $snippet_id = isset($snippet->id) ? absint($snippet->id) : 0;
            if ($snippet_id <= 0 || isset($this->executed_snippet_ids[$snippet_id])) {
                continue;
            }
            if (!$query_ready && $this->get_snippet_target_mode($snippet) !== 'all') {
                continue;
            }
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

            $this->executed_snippet_ids[$snippet_id] = true;
            $this->runtime_line_offsets[$snippet_id] = Eliodata_Snippet_Hub_Security::get_code_line_offset($snippet->code);
            if (!$this->shutdown_handler_registered) {
                register_shutdown_function([$this, 'handle_runtime_shutdown']);
                $this->shutdown_handler_registered = true;
            }

            try {
                $this->execute_runtime_snippet_code($code, $snippet_id);
            } catch (Error $e) {
                // Engine errors (parse errors, undefined functions...) stop the snippet for good
                $this->deactivate_failed_snippet($snippet_id, $e->getMessage(), $this->get_throwable_snippet_line($e, $snippet_id));
            } catch (Throwable $e) {
                Eliodata_Snippet_Hub_Security::record_runtime_error($snippet_id, $e->getMessage(), $this->get_throwable_snippet_line($e, $snippet_id), false);
            }
        }
    }

    private function add_admin_notice($type, $message) {
        set_transient('ide_snippets_admin_notice_' . get_current_user_id(), [
            'type' => $type,
            'message' => $message,
        ], 60);
    }

    private function redirect_admin_page($page_slug = 'eliodata-snippet-hub', $args = [], $fragment = '') {
        $query = array_merge(['page' => $page_slug], is_array($args) ? $args : []);
        $url = add_query_arg($query, admin_url('admin.php'));
        if ($fragment !== '') {
            $url .= '#' . $fragment;
        }
        wp_safe_redirect($url);
        exit;
    }

    private function set_admin_payload($key, $payload) {
        set_transient('ide_snippets_admin_payload_' . sanitize_key($key) . '_' . get_current_user_id(), $payload, 120);
    }

    private function consume_admin_payload($key) {
        $transient_key = 'ide_snippets_admin_payload_' . sanitize_key($key) . '_' . get_current_user_id();
        $payload = get_transient($transient_key);
        if ($payload !== false) {
            delete_transient($transient_key);
        }
        return $payload;
    }

    private function get_mcp_admin_server() {
        return new Eliodata_Snippet_Hub_MCP();
    }

    private function get_mcp_admin_endpoint($path = '') {
        $base = trailingslashit(rest_url('eliodata-snippet-hub/v1'));
        $path = ltrim((string) $path, '/');
        return $path === '' ? $base : $base . $path;
    }

    private function normalize_rest_admin_response($response) {
        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'status' => (int) ($response->get_error_data()['status'] ?? 500),
                'message' => $response->get_error_message(),
                'data' => $response->get_error_data(),
            ];
        }

        if ($response instanceof WP_REST_Response) {
            return [
                'ok' => !$response->is_error(),
                'status' => (int) $response->get_status(),
                'message' => '',
                'data' => $response->get_data(),
            ];
        }

        return [
            'ok' => false,
            'status' => 500,
            'message' => __('Réponse REST inattendue.', 'eliodata-snippet-hub'),
            'data' => [],
        ];
    }

    private function get_mcp_tools_payload($profile) {
        $request = new WP_REST_Request('GET');
        $request->set_param('profile', $profile);
        return $this->normalize_rest_admin_response($this->get_mcp_admin_server()->get_tools($request));
    }

    private function get_mcp_custom_tools_payload() {
        $request = new WP_REST_Request('GET');
        return $this->normalize_rest_admin_response($this->get_mcp_admin_server()->get_custom_tools($request));
    }

    private function decode_admin_json_object($raw_value, $default = [], &$error_message = '') {
        $error_message = '';
        $raw_value = is_string($raw_value) ? trim($raw_value) : '';
        if ($raw_value === '') {
            return $default;
        }

        $decoded = json_decode($raw_value, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            $error_message = __('Le JSON fourni est invalide.', 'eliodata-snippet-hub');
            return null;
        }

        return $decoded;
    }

    private function format_admin_json($value) {
        $encoded = wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '{}';
    }

    private function get_premium_feature_map() {
        return [
            'content_targeting' => [
                'label' => __('Attribution par contenu', 'eliodata-snippet-hub'),
                'upgrade_key' => 'content_targeting',
            ],
        ];
    }

    private function is_feature_enabled($feature_key) {
        $enabled = apply_filters('eliodata_snippet_hub_feature_enabled', true, $feature_key);
        return (bool) $enabled;
    }

    private function is_feature_premium_flagged($feature_key) {
        $flagged = apply_filters('eliodata_snippet_hub_feature_premium_flagged', true, $feature_key);
        return (bool) $flagged;
    }

    private function get_target_label($snippet) {
        $mode = $this->get_snippet_target_mode($snippet);
        if ($mode === 'post_types') {
            $post_types = $this->get_snippet_target_post_types($snippet);
            if (empty($post_types)) {
                return __('Types de contenu (non configuré)', 'eliodata-snippet-hub');
            }
            return __('Types de contenu:', 'eliodata-snippet-hub') . ' ' . implode(', ', $post_types);
        }
        if ($mode === 'specific_posts') {
            $post_ids = $this->get_snippet_target_post_ids($snippet);
            if (empty($post_ids)) {
                return __('Posts spécifiques (non configuré)', 'eliodata-snippet-hub');
            }
            return __('Posts spécifiques:', 'eliodata-snippet-hub') . ' ' . implode(', ', $post_ids);
        }
        return __('Tous les contenus', 'eliodata-snippet-hub');
    }

    public function register_post_snippets_metabox() {
        if (!Eliodata_Snippet_Hub_Security::current_user_can_manage()) {
            return;
        }
        if (!$this->is_feature_enabled('content_targeting')) {
            return;
        }

        $post_types = $this->get_targetable_post_types();
        foreach ($post_types as $post_type => $type_object) {
            add_meta_box(
                'ide_snippets_assignments',
                esc_html__('Eliodata MCP Bridge', 'eliodata-snippet-hub'),
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
            echo '<p>' . esc_html__('Aucun snippet disponible.', 'eliodata-snippet-hub') . '</p>';
            return;
        }
        echo '<p>' . esc_html__('Attribuer des snippets à ce contenu.', 'eliodata-snippet-hub') . '</p>';
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
        echo '<div class="ide-snippets-card"><strong>' . esc_html((string) $total) . '</strong>' . esc_html__('Snippets au total', 'eliodata-snippet-hub') . '</div>';
        echo '<div class="ide-snippets-card"><strong>' . esc_html((string) $active) . '</strong>' . esc_html__('Actifs', 'eliodata-snippet-hub') . '</div>';
        echo '<div class="ide-snippets-card"><strong>' . esc_html((string) $inactive) . '</strong>' . esc_html__('Inactifs', 'eliodata-snippet-hub') . '</div>';
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
        $this->clear_snippet_cache($snippet->id);
    }

    public function save_post_snippets_assignments($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!$post || !isset($post->post_type)) {
            return;
        }
        if (!Eliodata_Snippet_Hub_Security::current_user_can_manage() || !current_user_can('edit_post', $post_id)) {
            return;
        }
        if (!isset($_POST['ide_snippets_post_assignments_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ide_snippets_post_assignments_nonce'])), 'ide_snippets_post_assignments')) {
            return;
        }
        if (!$this->is_feature_enabled('content_targeting')) {
            return;
        }

        $selected_ids = [];
        // Fix: Sanitize array before usage
        if (isset($_POST['ide_snippet_assignments'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $raw_assignments = wp_unslash($_POST['ide_snippet_assignments']);
            if (is_array($raw_assignments)) {
                $clean_ids = array_map('absint', $raw_assignments);
                foreach ($clean_ids as $id) {
                    if ($id > 0) {
                        $selected_ids[] = $id;
                    }
                }
            }
        }
        $selected_ids = array_values(array_unique($selected_ids));

        $snippets = $this->get_native_snippets();
        foreach ($snippets as $snippet) {
            $snippet_id = (int) $snippet->id;
            $this->update_snippet_assignment_for_post($snippet, (int) $post_id, in_array($snippet_id, $selected_ids, true));
        }
        
        // Cache invalidation not needed here as we are updating post meta, not snippets themselves
    }

    public function handle_admin_requests() {
        if (!is_admin() || !Eliodata_Snippet_Hub_Security::current_user_can_manage()) {
            return;
        }

        $page = isset($_REQUEST['page']) ? sanitize_text_field(wp_unslash($_REQUEST['page'])) : '';
        if (!in_array($page, ['eliodata-snippet-hub', 'eliodata-snippet-hub-new', 'eliodata-snippet-hub-edit', 'eliodata-snippet-hub-assignments', 'eliodata-snippet-hub-import-export', 'eliodata-snippet-hub-mcp'], true)) {
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

        if ($op === 'mcp_save_custom_tool') {
            $existing_name = isset($_POST['existing_name']) ? sanitize_text_field(wp_unslash($_POST['existing_name'])) : '';
            $raw_schema = isset($_POST['input_schema']) ? wp_unslash($_POST['input_schema']) : '';
            $payload = [
                'name' => $existing_name !== '' ? $existing_name : (isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : ''),
                'description' => isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '',
                'method' => isset($_POST['method']) ? sanitize_text_field(wp_unslash($_POST['method'])) : 'GET',
                'route' => isset($_POST['route']) ? sanitize_text_field(wp_unslash($_POST['route'])) : '',
                'passAs' => isset($_POST['pass_as']) ? sanitize_text_field(wp_unslash($_POST['pass_as'])) : 'query',
                'readOnlyHint' => !empty($_POST['read_only_hint']),
            ];
            // On failure the form comes back filled with what was typed
            $keep_draft = function ($message) use ($payload, $existing_name, $raw_schema) {
                $this->add_admin_notice('error', $message);
                $this->set_admin_payload('mcp_tool_draft', array_merge($payload, [
                    'existing_name' => $existing_name,
                    'inputSchema' => is_string($raw_schema) ? $raw_schema : '',
                ]));
                $this->redirect_admin_page('eliodata-snippet-hub-mcp', [], 'mcp-tool-form');
            };

            $input_schema_error = '';
            $input_schema = $this->decode_admin_json_object($raw_schema, [], $input_schema_error);
            if ($input_schema === null) {
                $keep_draft(__('Schéma d’entrée : ', 'eliodata-snippet-hub') . $input_schema_error);
            }
            $payload['inputSchema'] = $input_schema;

            $request = new WP_REST_Request($existing_name !== '' ? 'PUT' : 'POST');
            if ($existing_name !== '') {
                $request->set_url_params(['name' => $existing_name]);
            }
            $request->set_header('content-type', 'application/json');
            $request->set_body(wp_json_encode($payload));
            $server = $this->get_mcp_admin_server();
            $response = $existing_name !== ''
                ? $server->update_custom_tool($request)
                : $server->create_custom_tool($request);
            $result = $this->normalize_rest_admin_response($response);

            if (!$result['ok']) {
                $keep_draft($result['message']);
            }

            $tool_name = isset($result['data']['tool']['name']) ? sanitize_text_field((string) $result['data']['tool']['name']) : $payload['name'];
            $this->add_admin_notice('success', $existing_name !== ''
                ? sprintf(__('Outil %s enregistré.', 'eliodata-snippet-hub'), $tool_name)
                : sprintf(__('Outil %s créé. Il est présélectionné dans le testeur.', 'eliodata-snippet-hub'), $tool_name));
            $this->redirect_admin_page('eliodata-snippet-hub-mcp', ['tool_name' => $tool_name], 'mcp-tester');
        }

        if ($op === 'mcp_delete_custom_tool') {
            $tool_name = isset($_POST['tool_name']) ? sanitize_text_field(wp_unslash($_POST['tool_name'])) : '';
            $request = new WP_REST_Request('DELETE');
            $request->set_url_params(['name' => $tool_name]);
            $result = $this->normalize_rest_admin_response($this->get_mcp_admin_server()->delete_custom_tool($request));
            if (!$result['ok']) {
                $this->add_admin_notice('error', $result['message']);
            } else {
                $this->add_admin_notice('success', sprintf(__('Outil %s supprimé.', 'eliodata-snippet-hub'), $tool_name));
            }
            $this->redirect_admin_page('eliodata-snippet-hub-mcp');
        }

        if ($op === 'mcp_test_tool') {
            $tool_name = isset($_POST['tool_name']) ? sanitize_text_field(wp_unslash($_POST['tool_name'])) : '';
            $raw_arguments = isset($_POST['arguments']) ? wp_unslash($_POST['arguments']) : '';
            $arguments_error = '';
            $arguments = $this->decode_admin_json_object($raw_arguments, [], $arguments_error);

            if ($arguments === null) {
                $this->add_admin_notice('error', __('Arguments : ', 'eliodata-snippet-hub') . $arguments_error);
                $this->redirect_admin_page('eliodata-snippet-hub-mcp', ['tool_name' => $tool_name], 'mcp-tester');
            }

            // The profile follows the tool: read when the read profile exposes it, write otherwise
            $profile = 'write';
            $read_payload = $this->get_mcp_tools_payload('read');
            if ($read_payload['ok'] && !empty($read_payload['data']['tools']) && is_array($read_payload['data']['tools'])) {
                foreach ($read_payload['data']['tools'] as $tool) {
                    if (isset($tool['name']) && $tool['name'] === $tool_name) {
                        $profile = 'read';
                        break;
                    }
                }
            }

            $request = new WP_REST_Request('POST');
            $request->set_param('profile', $profile);
            $request->set_header('content-type', 'application/json');
            $request->set_body(wp_json_encode([
                'name' => $tool_name,
                'arguments' => $arguments,
            ]));
            $started_at = microtime(true);
            $result = $this->normalize_rest_admin_response($this->get_mcp_admin_server()->call_tool($request));
            $this->set_admin_payload('mcp_test_result', [
                'tool_name' => $tool_name,
                'profile' => $profile,
                'result' => $result,
                'arguments' => $arguments,
                'duration_ms' => (int) round((microtime(true) - $started_at) * 1000),
            ]);

            $this->redirect_admin_page('eliodata-snippet-hub-mcp', ['tool_name' => $tool_name], 'mcp-result');
        }

        if ($op === 'save_snippet') {
            $id = isset($_POST['snippet_id']) ? absint($_POST['snippet_id']) : 0;
            $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
            $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
            
            // Code sanitization: allow code but ensure valid UTF-8
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $code = isset($_POST['code']) ? $this->sanitize_snippet_code(wp_unslash($_POST['code'])) : '';
            
            $tags = isset($_POST['tags']) ? sanitize_text_field(wp_unslash($_POST['tags'])) : '';
            $scope = isset($_POST['scope']) ? sanitize_text_field(wp_unslash($_POST['scope'])) : 'global';
            $priority = isset($_POST['priority']) ? absint($_POST['priority']) : 10;
            $active = isset($_POST['active']) ? 1 : 0;
            
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $raw_target_mode = isset($_POST['target_mode']) ? wp_unslash($_POST['target_mode']) : 'all';
            $target_mode = $this->sanitize_target_mode($raw_target_mode);
            
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $raw_target_post_types = isset($_POST['target_post_types']) ? wp_unslash($_POST['target_post_types']) : '';
            $target_post_types = $this->sanitize_target_post_types_csv($raw_target_post_types);
            
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $raw_target_post_ids = isset($_POST['target_post_ids']) ? wp_unslash($_POST['target_post_ids']) : '';
            $target_post_ids = $this->sanitize_target_post_ids_csv($raw_target_post_ids);
            
            $target_payload = $this->sanitize_target_payload($target_mode, $target_post_types, $target_post_ids);
            $target_post_types = $target_payload['target_post_types'];
            $target_post_ids = $target_payload['target_post_ids'];

            if ($name === '' || $code === '') {
                $this->add_admin_notice('error', 'Le titre et le code sont obligatoires.');
                $this->redirect_admin_page($id > 0 ? 'eliodata-snippet-hub-edit' : 'eliodata-snippet-hub-new', $id > 0 ? ['snippet_id' => $id] : []);
            }

            // A snippet with a syntax error is kept (so no work is lost) but never activated
            $syntax_check = Eliodata_Snippet_Hub_Security::validate_php_code($code);
            if (is_wp_error($syntax_check)) {
                $active = 0;
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
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $result = $wpdb->update($table, $data, ['id' => $id], ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s'], ['%d']);
                if ($result === false) {
                    $this->add_admin_notice('error', 'Erreur lors de la mise à jour du snippet.');
                } elseif (is_wp_error($syntax_check)) {
                    $this->clear_snippet_cache($id);
                    Eliodata_Snippet_Hub_Security::clear_runtime_error($id);
                    $this->add_admin_notice('error', __('Snippet enregistré mais désactivé.', 'eliodata-snippet-hub') . ' ' . $syntax_check->get_error_message());
                } else {
                    $this->clear_snippet_cache($id);
                    Eliodata_Snippet_Hub_Security::clear_runtime_error($id);
                    $this->add_admin_notice('success', 'Snippet mis à jour.');
                }
                $this->redirect_admin_page('eliodata-snippet-hub-edit', ['snippet_id' => $id]);
            } else {
                $data['created'] = current_time('mysql');
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $result = $wpdb->insert($table, $data, ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s']);
                if ($result === false) {
                    $this->add_admin_notice('error', 'Erreur lors de la création du snippet.');
                    $this->redirect_admin_page('eliodata-snippet-hub-new');
                } else {
                    $new_id = (int) $wpdb->insert_id;
                    $this->clear_snippet_cache($new_id);
                    if (is_wp_error($syntax_check)) {
                        $this->add_admin_notice('error', __('Snippet enregistré mais désactivé.', 'eliodata-snippet-hub') . ' ' . $syntax_check->get_error_message());
                    } else {
                        $this->add_admin_notice('success', 'Snippet créé.');
                    }
                    if ($new_id > 0) {
                        $this->redirect_admin_page('eliodata-snippet-hub-edit', ['snippet_id' => $new_id]);
                    }
                    $this->redirect_admin_page('eliodata-snippet-hub');
                }
            }
        }

        if ($op === 'delete_snippet') {
            $id = isset($_POST['snippet_id']) ? absint($_POST['snippet_id']) : 0;
            if ($id > 0) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $result = $wpdb->delete($table, ['id' => $id], ['%d']);
                if ($result === false) {
                    $this->add_admin_notice('error', 'Erreur lors de la suppression.');
                } else {
                    $this->clear_snippet_cache($id);
                    Eliodata_Snippet_Hub_Security::clear_runtime_error($id);
                    Eliodata_Snippet_Hub_Security::delete_runtime_files($id);
                    $this->add_admin_notice('success', 'Snippet supprimé.');
                }
            }
            $this->redirect_admin_page('eliodata-snippet-hub');
        }

        if ($op === 'toggle_snippet') {
            $id = isset($_POST['snippet_id']) ? absint($_POST['snippet_id']) : 0;
            $target = isset($_POST['target_active']) ? (absint($_POST['target_active']) ? 1 : 0) : 0;
            if ($id > 0) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $result = $wpdb->update($table, ['active' => $target, 'modified' => current_time('mysql')], ['id' => $id], ['%d', '%s'], ['%d']);
                if ($result === false) {
                    $this->add_admin_notice('error', 'Erreur lors du changement d’état.');
                } else {
                    $this->clear_snippet_cache($id);
                    if ($target) {
                        Eliodata_Snippet_Hub_Security::clear_runtime_error($id);
                    }
                    $this->add_admin_notice('success', $target ? 'Snippet activé.' : 'Snippet désactivé.');
                }
            }
            $this->redirect_admin_page('eliodata-snippet-hub');
        }

        if ($op === 'save_assignments_bulk') {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $snippet_ids = isset($_POST['snippet_ids']) ? $this->sanitize_absint_array(wp_unslash($_POST['snippet_ids'])) : [];
            if (empty($snippet_ids)) {
                $this->add_admin_notice('error', 'Aucun snippet à mettre à jour.');
                $this->redirect_admin_page('eliodata-snippet-hub-assignments');
            }

            $updated = 0;
            foreach ($snippet_ids as $raw_id) {
                $snippet_id = absint($raw_id);
                if ($snippet_id <= 0) {
                    continue;
                }
                
                $raw_mode = isset($_POST['target_mode'][$snippet_id]) ? sanitize_text_field(wp_unslash($_POST['target_mode'][$snippet_id])) : 'all';
                $mode = $this->sanitize_target_mode($raw_mode);
                
                $raw_types = isset($_POST['target_post_types'][$snippet_id]) ? sanitize_text_field(wp_unslash($_POST['target_post_types'][$snippet_id])) : '';
                $types_value = $this->sanitize_target_post_types_csv($raw_types);
                
                $raw_ids = isset($_POST['target_post_ids'][$snippet_id]) ? sanitize_text_field(wp_unslash($_POST['target_post_ids'][$snippet_id])) : '';
                $ids_value = $this->sanitize_target_post_ids_csv($raw_ids);
                
                $target_payload = $this->sanitize_target_payload($mode, $types_value, $ids_value);
                $types_value = $target_payload['target_post_types'];
                $ids_value = $target_payload['target_post_ids'];
                
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
                    $this->clear_snippet_cache($snippet_id);
                    $updated++;
                }
            }

            $this->add_admin_notice('success', sprintf('Attributions mises à jour: %d snippet(s).', $updated));
            $this->redirect_admin_page('eliodata-snippet-hub-assignments');
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
            $source_plugin = isset($_POST['import_source_plugin']) ? $this->sanitize_import_source_plugin(sanitize_text_field(wp_unslash($_POST['import_source_plugin']))) : 'native';
            $import_activation_mode = isset($_POST['import_activation_mode']) ? sanitize_text_field(wp_unslash($_POST['import_activation_mode'])) : 'keep';
            if (!in_array($import_activation_mode, $allowed_import_activation_modes, true)) {
                $import_activation_mode = 'keep';
            }
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $raw = isset($_POST['import_payload']) ? trim(wp_unslash($_POST['import_payload'])) : '';

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            if (!empty($_FILES['import_file']['tmp_name']) && is_uploaded_file($_FILES['import_file']['tmp_name'])) {
                $upload_error = isset($_FILES['import_file']['error']) ? (int) $_FILES['import_file']['error'] : UPLOAD_ERR_NO_FILE;
                if ($upload_error !== UPLOAD_ERR_OK) {
                    $this->add_admin_notice('error', __('Le fichier importé est invalide.', 'eliodata-snippet-hub'));
                    $this->redirect_admin_page('eliodata-snippet-hub-import-export');
                }

                $upload_size = isset($_FILES['import_file']['size']) ? absint($_FILES['import_file']['size']) : 0;
                if ($upload_size <= 0 || $upload_size > $max_import_size) {
                    $this->add_admin_notice('error', __('Le fichier doit faire entre 1 octet et 6 Mo.', 'eliodata-snippet-hub'));
                    $this->redirect_admin_page('eliodata-snippet-hub-import-export');
                }

                $upload_name = isset($_FILES['import_file']['name']) ? sanitize_file_name(wp_unslash($_FILES['import_file']['name'])) : '';
                $upload_ext = strtolower(pathinfo($upload_name, PATHINFO_EXTENSION));
                if (!in_array($upload_ext, ['json', 'ndjson', 'txt'], true)) {
                    $this->add_admin_notice('error', __('Extension de fichier non autorisée.', 'eliodata-snippet-hub'));
                    $this->redirect_admin_page('eliodata-snippet-hub-import-export');
                }

                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $file_content = file_get_contents($_FILES['import_file']['tmp_name']);
                if (is_string($file_content) && $file_content !== '') {
                    $raw = $file_content;
                }
            }

            if ($raw !== '' && strlen($raw) > $max_import_size) {
                $this->add_admin_notice('error', __('Le payload import dépasse 6 Mo.', 'eliodata-snippet-hub'));
                $this->redirect_admin_page('eliodata-snippet-hub-import-export');
            }

            if ($raw === '') {
                $this->add_admin_notice('error', __('Aucune donnée d’import fournie.', 'eliodata-snippet-hub'));
                $this->redirect_admin_page('eliodata-snippet-hub-import-export');
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
                    $this->add_admin_notice('error', __('JSON invalide.', 'eliodata-snippet-hub'));
                    $this->redirect_admin_page('eliodata-snippet-hub-import-export');
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
                $this->add_admin_notice('error', __('Format d’import invalide ou vide.', 'eliodata-snippet-hub'));
                $this->redirect_admin_page('eliodata-snippet-hub-import-export');
            }

            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = 0;
            $invalid_syntax = 0;

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
                if ($active && is_wp_error(Eliodata_Snippet_Hub_Security::validate_php_code($code))) {
                    $active = 0;
                    $invalid_syntax++;
                }
                $target_mode = $normalized['target_mode'];
                $target_post_types = $normalized['target_post_types'];
                $target_post_ids = $normalized['target_post_ids'];
                $created_at = $normalized['created'] !== '' ? $normalized['created'] : current_time('mysql');
                $modified = $normalized['modified'] !== '' ? $normalized['modified'] : current_time('mysql');
                $imported_id = $normalized['id'];

                $existing_id = 0;
                if ($imported_id > 0) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
                    $existing_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE id = %d LIMIT 1", $imported_id));
                }
                if ($existing_id <= 0 && $mode !== 'overwrite_id') {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
                    $existing_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE name = %s LIMIT 1", $name));
                }
                if ($existing_id > 0) {
                    if ($mode === 'skip') {
                        $skipped++;
                        continue;
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
                        $this->clear_snippet_cache($existing_id);
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
                        'created' => $created_at,
                        'modified' => $modified,
                    ];
                    $insert_format = ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s'];
                    if ($imported_id > 0) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
                        $id_exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE id = %d LIMIT 1", $imported_id));
                        if ($id_exists <= 0) {
                            $insert_data = array_merge(['id' => $imported_id], $insert_data);
                            $insert_format = array_merge(['%d'], $insert_format);
                        }
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
                    $result = $wpdb->insert($table, $insert_data, $insert_format);
                    if ($result === false) {
                        $errors++;
                    } else {
                        $created++;
                    }
                }
            }

            $message = __('Import terminé.', 'eliodata-snippet-hub')
                . ' ' . __('Créés:', 'eliodata-snippet-hub') . ' ' . (int) $created
                . ', ' . __('Mis à jour:', 'eliodata-snippet-hub') . ' ' . (int) $updated
                . ', ' . __('Ignorés:', 'eliodata-snippet-hub') . ' ' . (int) $skipped
                . ', ' . __('Erreurs:', 'eliodata-snippet-hub') . ' ' . (int) $errors . '.';
            if ($invalid_syntax > 0) {
                $message .= ' ' . sprintf(
                    /* translators: %d: number of snippets */
                    __('%d snippet(s) with a PHP syntax error were imported deactivated.', 'eliodata-snippet-hub'),
                    $invalid_syntax
                );
            }
            
            if ($created > 0 || $updated > 0) {
                $this->clear_snippet_cache(0);
            }

            $this->add_admin_notice('success', $message);
            $this->redirect_admin_page('eliodata-snippet-hub-import-export');
        }

        if ($op === 'bulk_snippets_action') {
            $bulk_action = isset($_POST['bulk_action']) ? sanitize_text_field(wp_unslash($_POST['bulk_action'])) : '';
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $snippet_ids = isset($_POST['snippet_ids']) ? $this->sanitize_absint_array(wp_unslash($_POST['snippet_ids'])) : [];

            if (empty($snippet_ids)) {
                $this->add_admin_notice('error', 'Aucun snippet sélectionné.');
                $this->redirect_admin_page('eliodata-snippet-hub');
            }

            if ($bulk_action === 'delete') {
                $deleted = 0;
                foreach ($snippet_ids as $snippet_id) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
                    $result = $wpdb->delete($table, ['id' => (int) $snippet_id], ['%d']);
                    if ($result !== false) {
                        $deleted += (int) $result;
                        $this->clear_snippet_cache((int) $snippet_id);
                        Eliodata_Snippet_Hub_Security::clear_runtime_error((int) $snippet_id);
                        Eliodata_Snippet_Hub_Security::delete_runtime_files((int) $snippet_id);
                    }
                }
                if ($deleted <= 0) {
                    $this->add_admin_notice('error', __('Erreur lors de la suppression groupée.', 'eliodata-snippet-hub'));
                } else {
                    $this->add_admin_notice('success', (int) $deleted . ' ' . __('snippet(s) supprimé(s).', 'eliodata-snippet-hub'));
                }
                $this->redirect_admin_page('eliodata-snippet-hub');
            }

            if (in_array($bulk_action, ['activate', 'deactivate'], true)) {
                $target = $bulk_action === 'activate' ? 1 : 0;
                $updated = 0;
                foreach ($snippet_ids as $snippet_id) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
                    $result = $wpdb->update(
                        $table,
                        ['active' => $target, 'modified' => current_time('mysql')],
                        ['id' => (int) $snippet_id],
                        ['%d', '%s'],
                        ['%d']
                    );
                    if ($result !== false) {
                        $updated += (int) $result;
                        $this->clear_snippet_cache((int) $snippet_id);
                        if ($target) {
                            Eliodata_Snippet_Hub_Security::clear_runtime_error((int) $snippet_id);
                        }
                    }
                }
                if ($updated <= 0) {
                    $this->add_admin_notice('error', __('Erreur lors de la mise à jour groupée.', 'eliodata-snippet-hub'));
                } else {
                    $this->add_admin_notice('success', (int) $updated . ' ' . __('snippet(s) mis à jour.', 'eliodata-snippet-hub'));
                }
                $this->redirect_admin_page('eliodata-snippet-hub');
            }

            if ($bulk_action === 'export') {
                $allowed_export_formats = ['json', 'ndjson'];
                $format = isset($_POST['export_format']) ? sanitize_text_field(wp_unslash($_POST['export_format'])) : 'json';
                if (!in_array($format, $allowed_export_formats, true)) {
                    $format = 'json';
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
            $this->redirect_admin_page('eliodata-snippet-hub');
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
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
                        <h2><?php echo esc_html($editing ? __('Modifier le snippet', 'eliodata-snippet-hub') : __('Créer un snippet', 'eliodata-snippet-hub')); ?></h2>
                        <label class="ide-editor-title-input" for="ide_snippet_name">
                            <span><?php esc_html_e('Titre', 'eliodata-snippet-hub'); ?></span>
                            <input class="regular-text" type="text" id="ide_snippet_name" name="name" required value="<?php echo $editing ? esc_attr($editing->name) : ''; ?>">
                        </label>
                        <label class="ide-active-switch" for="ide_snippet_active">
                            <input type="checkbox" id="ide_snippet_active" name="active" value="1" <?php checked($editing ? (int) $editing->active : 0, 1); ?>>
                            <span class="ide-active-slider"></span>
                            <span class="ide-active-switch-label"><?php esc_html_e('Actif', 'eliodata-snippet-hub'); ?></span>
                        </label>
                    </div>
                    <div class="ide-editor-header-right">
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eliodata-snippet-hub')); ?>"><?php esc_html_e('Retour à la liste', 'eliodata-snippet-hub'); ?></a>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eliodata-snippet-hub-new')); ?>"><?php esc_html_e('Nouveau snippet', 'eliodata-snippet-hub'); ?></a>
                    </div>
                </div>
                <?php wp_nonce_field('ide_snippets_admin_action', 'ide_snippets_admin_nonce'); ?>
                <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">
                <input type="hidden" name="op" value="save_snippet">
                <input type="hidden" name="snippet_id" value="<?php echo $editing ? esc_attr($editing->id) : 0; ?>">
                <div class="ide-snippet-fields">
                    <div class="ide-field ide-field-description">
                        <label for="ide_snippet_description"><?php esc_html_e('Description', 'eliodata-snippet-hub'); ?></label>
                        <textarea class="large-text" id="ide_snippet_description" name="description" rows="2"><?php echo $editing ? esc_textarea($editing->description) : ''; ?></textarea>
                    </div>
                    <div class="ide-field ide-field-tags">
                        <label for="ide_snippet_tags"><?php esc_html_e('Mots-clés', 'eliodata-snippet-hub'); ?></label>
                        <input class="regular-text" type="text" id="ide_snippet_tags" name="tags" value="<?php echo $editing ? esc_attr($editing->tags) : ''; ?>" placeholder="<?php echo esc_attr__('woocommerce, checkout', 'eliodata-snippet-hub'); ?>">
                    </div>
                    <div class="ide-field ide-field-scope">
                        <label for="ide_snippet_scope"><?php esc_html_e('Cible', 'eliodata-snippet-hub'); ?></label>
                        <select id="ide_snippet_scope" name="scope">
                            <?php $scope = $editing ? $editing->scope : 'global'; ?>
                            <option value="global" <?php selected($scope, 'global'); ?>><?php esc_html_e('Global', 'eliodata-snippet-hub'); ?></option>
                            <option value="admin" <?php selected($scope, 'admin'); ?>><?php esc_html_e('Admin', 'eliodata-snippet-hub'); ?></option>
                            <option value="front-end" <?php selected($scope, 'front-end'); ?>><?php esc_html_e('Front-end', 'eliodata-snippet-hub'); ?></option>
                        </select>
                    </div>
                    <div class="ide-field ide-field-priority">
                        <label for="ide_snippet_priority"><?php esc_html_e('Priorité', 'eliodata-snippet-hub'); ?></label>
                        <input type="number" min="0" id="ide_snippet_priority" name="priority" value="<?php echo $editing ? esc_attr((string) $editing->priority) : '10'; ?>">
                    </div>
                    <div class="ide-field ide-field-target-mode">
                        <label for="ide_snippet_target_mode"><?php esc_html_e('Attribution', 'eliodata-snippet-hub'); ?></label>
                        <select id="ide_snippet_target_mode" name="target_mode" data-target-mode>
                            <option value="all" <?php selected($target_mode, 'all'); ?>><?php esc_html_e('Général', 'eliodata-snippet-hub'); ?></option>
                            <option value="post_types" <?php selected($target_mode, 'post_types'); ?>><?php esc_html_e('Type de contenu', 'eliodata-snippet-hub'); ?></option>
                            <option value="specific_posts" <?php selected($target_mode, 'specific_posts'); ?>><?php esc_html_e('ID cibles', 'eliodata-snippet-hub'); ?></option>
                        </select>
                    </div>
                    <div class="ide-field ide-field-target-post-types" data-target-types>
                        <label for="ide_snippet_target_post_types"><?php esc_html_e('Post types', 'eliodata-snippet-hub'); ?></label>
                        <div class="ide-target-types-picker" data-types-picker data-empty-text="<?php echo esc_attr__('Aucun type sélectionné', 'eliodata-snippet-hub'); ?>" data-values-mode="text">
                            <div class="ide-types-control">
                                <button type="button" class="button ide-types-toggle" data-types-toggle><?php esc_html_e('Choisir les types', 'eliodata-snippet-hub'); ?></button>
                                <div class="ide-types-dropdown ide-target-hidden" data-types-dropdown>
                                    <div class="ide-types-search">
                                        <input type="search" class="regular-text" placeholder="<?php echo esc_attr__('Rechercher un type...', 'eliodata-snippet-hub'); ?>" data-types-search>
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
                        <label for="ide_snippet_target_post_ids"><?php esc_html_e('Post IDs', 'eliodata-snippet-hub'); ?></label>
                        <div class="ide-target-types-picker" data-types-picker data-empty-text="<?php echo esc_attr__('Aucun post sélectionné', 'eliodata-snippet-hub'); ?>" data-values-mode="numeric">
                            <div class="ide-types-control">
                                <button type="button" class="button ide-types-toggle" data-types-toggle><?php esc_html_e('Choisir les IDs', 'eliodata-snippet-hub'); ?></button>
                                <div class="ide-types-dropdown ide-target-hidden" data-types-dropdown>
                                    <div class="ide-types-search">
                                        <input type="search" class="regular-text" placeholder="<?php echo esc_attr__('Rechercher un ID, titre, type...', 'eliodata-snippet-hub'); ?>" data-types-search>
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
                            <input class="regular-text ide-target-ids-input" type="text" id="ide_snippet_target_post_ids" name="target_post_ids" value="<?php echo esc_attr($target_post_ids); ?>" placeholder="<?php echo esc_attr__('12,34,56', 'eliodata-snippet-hub'); ?>" data-types-hidden>
                        </div>
                    </div>
                </div>
                <p class="ide-premium-ready"><span class="ide-badge ide-badge-premium-ready">Premium-ready</span><span><?php echo esc_html($feature_label); ?></span></p>
                <div class="ide-editor-wrap">
                    <div class="ide-editor-toolbar">
                        <button type="button" class="button button-secondary button-small" id="ide-snippet-fullscreen-toggle" aria-pressed="false"><?php esc_html_e('Plein écran', 'eliodata-snippet-hub'); ?></button>
                        <div class="ide-editor-meta"><span id="ide-snippet-code-lines">0</span> <?php esc_html_e('lignes', 'eliodata-snippet-hub'); ?> · <span id="ide-snippet-code-chars">0</span> <?php esc_html_e('caractères', 'eliodata-snippet-hub'); ?></div>
                    </div>
                    <textarea class="large-text code" id="ide_snippet_code" name="code" rows="12" required><?php echo $editing ? esc_textarea($editing->code) : ''; ?></textarea>
                </div>
                <div class="ide-editor-submitbar">
                    <button type="submit" class="button button-primary"><?php echo esc_html($editing ? __('Enregistrer les modifications', 'eliodata-snippet-hub') : __('Créer le snippet', 'eliodata-snippet-hub')); ?></button>
                    <div class="ide-snippets-actions">
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eliodata-snippet-hub')); ?>"><?php esc_html_e('Retour à la liste', 'eliodata-snippet-hub'); ?></a>
                    </div>
                    <p class="description"><?php esc_html_e('Raccourci: ⌘/Ctrl+S', 'eliodata-snippet-hub'); ?></p>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Header and navigation shared by every screen of the plugin, so that
     * moving between them does not require the WordPress side menu.
     */
    private function render_admin_nav($current) {
        $items = [
            'eliodata-snippet-hub' => [__('Snippets', 'eliodata-snippet-hub'), 'dashicons-editor-code'],
            'eliodata-snippet-hub-new' => [__('Nouveau snippet', 'eliodata-snippet-hub'), 'dashicons-plus-alt2'],
            'eliodata-snippet-hub-assignments' => [__('Attributions', 'eliodata-snippet-hub'), 'dashicons-admin-links'],
            'eliodata-snippet-hub-import-export' => [__('Import / Export', 'eliodata-snippet-hub'), 'dashicons-migrate'],
            'eliodata-snippet-hub-mcp' => [__('Outils MCP', 'eliodata-snippet-hub'), 'dashicons-rest-api'],
        ];
        ?>
        <div class="ide-admin-header">
            <div class="ide-admin-brand">
                <img src="<?php echo esc_url(ELIODATA_SNIPPET_HUB_PLUGIN_URL . 'assets/logo-eliodata.png'); ?>" alt="" width="28" height="28">
                <span class="ide-admin-brand-name"><?php esc_html_e('Eliodata MCP Bridge', 'eliodata-snippet-hub'); ?></span>
                <span class="ide-admin-version">v<?php echo esc_html(ELIODATA_SNIPPET_HUB_VERSION); ?></span>
            </div>
            <nav class="ide-admin-nav" aria-label="<?php echo esc_attr__('Navigation du plugin', 'eliodata-snippet-hub'); ?>">
                <?php foreach ($items as $slug => $item) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . $slug)); ?>" class="<?php echo $slug === $current ? 'is-current' : ''; ?>" <?php echo $slug === $current ? 'aria-current="page"' : ''; ?>>
                        <span class="dashicons <?php echo esc_attr($item[1]); ?>" aria-hidden="true"></span><?php echo esc_html($item[0]); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php
    }

    public function render_admin_page() {
        if (!Eliodata_Snippet_Hub_Security::current_user_can_manage()) {
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
            <?php $this->render_admin_nav('eliodata-snippet-hub'); ?>
            <h1><?php esc_html_e('Snippets', 'eliodata-snippet-hub'); ?></h1>
            <hr class="wp-header-end">
            <?php $this->render_stats_cards($stats); ?>

            <?php if ($notice && !empty($notice['message'])) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type'] === 'error' ? 'error' : 'success'); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <div class="ide-snippets-layout ide-snippets-layout-full">
                <div class="ide-snippets-panel">
                    <div class="ide-snippets-toolbar">
                        <h2><?php esc_html_e('Snippets', 'eliodata-snippet-hub'); ?></h2>
                        <div class="ide-snippets-actions">
                            <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=eliodata-snippet-hub-new')); ?>"><?php esc_html_e('Nouveau snippet', 'eliodata-snippet-hub'); ?></a>
                            <input type="search" id="ide-snippets-search" class="regular-text" placeholder="<?php echo esc_attr__('Filtrer par titre, description, tags...', 'eliodata-snippet-hub'); ?>">
                        </div>
                    </div>
                    <form id="ide-snippets-bulk-form" method="post" class="ide-snippets-bulk-bar">
                        <?php wp_nonce_field('ide_snippets_admin_action', 'ide_snippets_admin_nonce'); ?>
                        <input type="hidden" name="page" value="eliodata-snippet-hub">
                        <input type="hidden" name="op" value="bulk_snippets_action">
                        <label for="ide-snippets-bulk-action"><strong><?php esc_html_e('Action groupée', 'eliodata-snippet-hub'); ?></strong></label>
                        <select id="ide-snippets-bulk-action" name="bulk_action">
                            <option value=""><?php esc_html_e('Choisir...', 'eliodata-snippet-hub'); ?></option>
                            <option value="activate"><?php esc_html_e('Activer', 'eliodata-snippet-hub'); ?></option>
                            <option value="deactivate"><?php esc_html_e('Désactiver', 'eliodata-snippet-hub'); ?></option>
                            <option value="export"><?php esc_html_e('Exporter', 'eliodata-snippet-hub'); ?></option>
                            <option value="delete"><?php esc_html_e('Supprimer', 'eliodata-snippet-hub'); ?></option>
                        </select>
                        <span id="ide-snippets-bulk-export-wrap" class="ide-snippets-bulk-export-wrap">
                            <label for="ide-snippets-bulk-export-format"><strong><?php esc_html_e('Format export', 'eliodata-snippet-hub'); ?></strong></label>
                            <select id="ide-snippets-bulk-export-format" name="export_format">
                                <option value="json">JSON</option>
                                <option value="ndjson">NDJSON</option>
                            </select>
                        </span>
                        <button id="ide-snippets-bulk-apply" class="button" type="submit"><?php esc_html_e('Appliquer', 'eliodata-snippet-hub'); ?></button>
                        <span id="ide-snippets-selected-count" class="description">0 sélectionné(s)</span>
                    </form>
                    <table id="ide-snippets-table" class="widefat striped">
                        <thead>
                            <tr>
                                <th class="check-column"><input type="checkbox" id="ide-snippets-select-all"></th>
                                <th>ID</th>
                                <th><?php esc_html_e('Titre', 'eliodata-snippet-hub'); ?></th>
                                <th><?php esc_html_e('Description', 'eliodata-snippet-hub'); ?></th>
                                <th><?php esc_html_e('Mots-clés', 'eliodata-snippet-hub'); ?></th>
                                <th><?php esc_html_e('Cible', 'eliodata-snippet-hub'); ?></th>
                                <th><?php esc_html_e('Attribution', 'eliodata-snippet-hub'); ?></th>
                                <th><?php esc_html_e('Priorité', 'eliodata-snippet-hub'); ?></th>
                                <th><?php esc_html_e('Statut', 'eliodata-snippet-hub'); ?></th>
                                <th><?php esc_html_e('Actions', 'eliodata-snippet-hub'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($snippets)) : ?>
                                <tr><td colspan="10"><?php esc_html_e('Aucun snippet trouvé.', 'eliodata-snippet-hub'); ?></td></tr>
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
                                                <span class="ide-badge ide-badge-active"><?php esc_html_e('Actif', 'eliodata-snippet-hub'); ?></span>
                                            <?php else : ?>
                                                <span class="ide-badge ide-badge-inactive"><?php esc_html_e('Inactif', 'eliodata-snippet-hub'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="ide-snippets-actions">
                                            <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=eliodata-snippet-hub-edit&snippet_id=' . (int) $snippet->id)); ?>"><?php esc_html_e('Éditer', 'eliodata-snippet-hub'); ?></a>
                                            <form method="post">
                                                <?php wp_nonce_field('ide_snippets_admin_action', 'ide_snippets_admin_nonce'); ?>
                                                <input type="hidden" name="page" value="eliodata-snippet-hub">
                                                <input type="hidden" name="op" value="toggle_snippet">
                                                <input type="hidden" name="snippet_id" value="<?php echo (int) $snippet->id; ?>">
                                                <input type="hidden" name="target_active" value="<?php echo (int) $snippet->active === 1 ? 0 : 1; ?>">
                                                <button class="button button-small" type="submit"><?php echo esc_html((int) $snippet->active === 1 ? __('Désactiver', 'eliodata-snippet-hub') : __('Activer', 'eliodata-snippet-hub')); ?></button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Supprimer ce snippet ?', 'eliodata-snippet-hub')); ?>');">
                                                <?php wp_nonce_field('ide_snippets_admin_action', 'ide_snippets_admin_nonce'); ?>
                                                <input type="hidden" name="page" value="eliodata-snippet-hub">
                                                <input type="hidden" name="op" value="delete_snippet">
                                                <input type="hidden" name="snippet_id" value="<?php echo (int) $snippet->id; ?>">
                                                <button class="button button-small button-link-delete" type="submit"><?php esc_html_e('Supprimer', 'eliodata-snippet-hub'); ?></button>
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
        if (!Eliodata_Snippet_Hub_Security::current_user_can_manage()) {
            return;
        }

        $notice = get_transient('ide_snippets_admin_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('ide_snippets_admin_notice_' . get_current_user_id());
        }
        $stats = $this->get_snippets_stats();

        ?>
        <div class="wrap ide-snippets-admin">
            <?php $this->render_admin_nav('eliodata-snippet-hub-import-export'); ?>
            <h1><?php esc_html_e('Import / Export', 'eliodata-snippet-hub'); ?></h1>
            <hr class="wp-header-end">
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
                    <h2><?php esc_html_e('Importer', 'eliodata-snippet-hub'); ?></h2>
                    <p><?php esc_html_e('Importez un fichier JSON/NDJSON ou collez un payload brut.', 'eliodata-snippet-hub'); ?></p>
                    <p class="description"><?php esc_html_e('Limite maximale: 6 Mo. En cas de doublon sur le titre, appliquez la stratégie de conflit choisie.', 'eliodata-snippet-hub'); ?></p>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('ide_snippets_admin_action', 'ide_snippets_admin_nonce'); ?>
                        <input type="hidden" name="page" value="eliodata-snippet-hub-import-export">
                        <input type="hidden" name="op" value="import_snippets">
                        <p>
                            <label for="import_format"><strong><?php esc_html_e('Format', 'eliodata-snippet-hub'); ?></strong></label><br>
                            <select id="import_format" name="import_format">
                                <option value="json">JSON</option>
                                <option value="ndjson">NDJSON</option>
                            </select>
                        </p>
                        <p>
                            <label for="import_mode"><strong><?php esc_html_e('Conflits', 'eliodata-snippet-hub'); ?></strong></label><br>
                            <select id="import_mode" name="import_mode">
                                <option value="overwrite"><?php esc_html_e('Écraser les snippets existants (même titre)', 'eliodata-snippet-hub'); ?></option>
                                <option value="overwrite_id"><?php esc_html_e('Écraser les snippets existants (détection par ID)', 'eliodata-snippet-hub'); ?></option>
                                <option value="skip"><?php esc_html_e('Ignorer les snippets existants (même titre)', 'eliodata-snippet-hub'); ?></option>
                            </select>
                        </p>
                        <p>
                            <label for="import_source_plugin"><strong><?php esc_html_e('Plugin source', 'eliodata-snippet-hub'); ?></strong></label><br>
                            <select id="import_source_plugin" name="import_source_plugin">
                                <?php if ($show_source_auto) : ?>
                                    <option value="auto"><?php esc_html_e('Auto-détection', 'eliodata-snippet-hub'); ?></option>
                                <?php endif; ?>
                                <?php foreach ($import_sources as $source_key => $source_label) : ?>
                                    <option value="<?php echo esc_attr($source_key); ?>"><?php echo esc_html($source_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p>
                            <label for="import_activation_mode"><strong><?php esc_html_e('Activation à l’import', 'eliodata-snippet-hub'); ?></strong></label><br>
                            <select id="import_activation_mode" name="import_activation_mode">
                                <option value="keep"><?php esc_html_e('Conserver l’état importé', 'eliodata-snippet-hub'); ?></option>
                                <option value="activate_all"><?php esc_html_e('Activer tous les snippets importés', 'eliodata-snippet-hub'); ?></option>
                                <option value="deactivate_all"><?php esc_html_e('Désactiver tous les snippets importés', 'eliodata-snippet-hub'); ?></option>
                            </select>
                        </p>
                        <p>
                            <label for="import_file"><strong><?php esc_html_e('Fichier', 'eliodata-snippet-hub'); ?></strong></label><br>
                            <input type="file" id="import_file" name="import_file" accept=".json,.ndjson,.txt">
                        </p>
                        <p>
                            <label for="import_payload"><strong><?php esc_html_e('Ou coller les données', 'eliodata-snippet-hub'); ?></strong></label>
                            <textarea class="large-text code" id="import_payload" name="import_payload" rows="10" placeholder="<?php echo esc_attr__('Si un fichier est fourni, son contenu est prioritaire.', 'eliodata-snippet-hub'); ?>"></textarea>
                        </p>
                        <p><button type="submit" class="button button-primary"><?php esc_html_e('Importer', 'eliodata-snippet-hub'); ?></button></p>
                    </form>
                </div>
                <div class="ide-snippets-panel">
                    <h2><?php esc_html_e('Exporter', 'eliodata-snippet-hub'); ?></h2>
                    <p><?php esc_html_e('Téléchargez tous les snippets ou uniquement un sous-ensemble.', 'eliodata-snippet-hub'); ?></p>
                    <form method="post">
                        <?php wp_nonce_field('ide_snippets_admin_action', 'ide_snippets_admin_nonce'); ?>
                        <input type="hidden" name="page" value="eliodata-snippet-hub-import-export">
                        <input type="hidden" name="op" value="export_snippets">
                        <p>
                            <label for="export_format"><strong><?php esc_html_e('Format', 'eliodata-snippet-hub'); ?></strong></label><br>
                            <select id="export_format" name="export_format">
                                <option value="json">JSON</option>
                                <option value="ndjson">NDJSON</option>
                            </select>
                        </p>
                        <p>
                            <label for="export_status"><strong><?php esc_html_e('Filtre', 'eliodata-snippet-hub'); ?></strong></label><br>
                            <select id="export_status" name="export_status">
                                <option value="all"><?php esc_html_e('Tous', 'eliodata-snippet-hub'); ?></option>
                                <option value="active"><?php esc_html_e('Actifs', 'eliodata-snippet-hub'); ?></option>
                                <option value="inactive"><?php esc_html_e('Inactifs', 'eliodata-snippet-hub'); ?></option>
                            </select>
                        </p>
                        <p><button type="submit" class="button button-primary"><?php esc_html_e('Télécharger l’export', 'eliodata-snippet-hub'); ?></button></p>
                    </form>
                    <hr>
                    <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eliodata-snippet-hub')); ?>"><?php esc_html_e('Retour à la liste', 'eliodata-snippet-hub'); ?></a></p>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_assignments_page() {
        if (!Eliodata_Snippet_Hub_Security::current_user_can_manage()) {
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
            <?php $this->render_admin_nav('eliodata-snippet-hub-assignments'); ?>
            <h1><?php esc_html_e('Attributions des snippets', 'eliodata-snippet-hub'); ?></h1>
            <hr class="wp-header-end">
            <?php $this->render_stats_cards($stats); ?>
            <?php if ($notice && !empty($notice['message'])) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type'] === 'error' ? 'error' : 'success'); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>
            <div class="ide-snippets-panel">
                <p class="ide-premium-ready"><span class="ide-badge ide-badge-premium-ready">Premium-ready</span><span><?php esc_html_e('Attribution par contenu', 'eliodata-snippet-hub'); ?></span></p>
                <form method="post">
                    <?php wp_nonce_field('ide_snippets_admin_action', 'ide_snippets_admin_nonce'); ?>
                    <input type="hidden" name="page" value="eliodata-snippet-hub-assignments">
                    <input type="hidden" name="op" value="save_assignments_bulk">
                    <table class="widefat striped ide-assignments-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th><?php esc_html_e('Snippet', 'eliodata-snippet-hub'); ?></th>
                                <th><?php esc_html_e('Mode', 'eliodata-snippet-hub'); ?></th>
                                <th><?php esc_html_e('Types de contenu', 'eliodata-snippet-hub'); ?></th>
                                <th><?php esc_html_e('IDs de contenu', 'eliodata-snippet-hub'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($snippets)) : ?>
                                <tr><td colspan="5"><?php esc_html_e('Aucun snippet trouvé.', 'eliodata-snippet-hub'); ?></td></tr>
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
                                                <option value="all" <?php selected($snippet_mode, 'all'); ?>><?php esc_html_e('Général', 'eliodata-snippet-hub'); ?></option>
                                                <option value="post_types" <?php selected($snippet_mode, 'post_types'); ?>><?php esc_html_e('Type de contenu', 'eliodata-snippet-hub'); ?></option>
                                                <option value="specific_posts" <?php selected($snippet_mode, 'specific_posts'); ?>><?php esc_html_e('ID cibles', 'eliodata-snippet-hub'); ?></option>
                                            </select>
                                        </td>
                                        <td data-target-types>
                                            <div class="ide-target-types-picker" data-types-picker data-empty-text="<?php echo esc_attr__('Aucun type sélectionné', 'eliodata-snippet-hub'); ?>" data-values-mode="text">
                                                <div class="ide-types-control">
                                                    <button type="button" class="button ide-types-toggle" data-types-toggle><?php esc_html_e('Choisir les types', 'eliodata-snippet-hub'); ?></button>
                                                    <div class="ide-types-dropdown ide-target-hidden" data-types-dropdown>
                                                        <div class="ide-types-search">
                                                            <input type="search" class="regular-text" placeholder="<?php echo esc_attr__('Rechercher un type...', 'eliodata-snippet-hub'); ?>" data-types-search>
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
                                            <div class="ide-target-types-picker" data-types-picker data-empty-text="<?php echo esc_attr__('Aucun contenu sélectionné', 'eliodata-snippet-hub'); ?>" data-values-mode="numeric">
                                                <div class="ide-types-control">
                                                    <button type="button" class="button ide-types-toggle" data-types-toggle><?php esc_html_e('Choisir les IDs', 'eliodata-snippet-hub'); ?></button>
                                                    <div class="ide-types-dropdown ide-target-hidden" data-types-dropdown>
                                                        <div class="ide-types-search">
                                                            <input type="search" class="regular-text" placeholder="<?php echo esc_attr__('Rechercher un ID, titre, type...', 'eliodata-snippet-hub'); ?>" data-types-search>
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
                                                <input class="regular-text ide-target-ids-input" type="text" name="target_post_ids[<?php echo esc_attr((string) $snippet_id); ?>]" value="<?php echo isset($snippet->target_post_ids) ? esc_attr($snippet->target_post_ids) : ''; ?>" placeholder="<?php echo esc_attr__('12,34,56', 'eliodata-snippet-hub'); ?>" data-types-hidden>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <p style="margin-top:12px;">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Enregistrer les attributions', 'eliodata-snippet-hub'); ?></button>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eliodata-snippet-hub')); ?>"><?php esc_html_e('Retour à la liste', 'eliodata-snippet-hub'); ?></a>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * One entry per tool, whatever the profile: the catalog says where each tool is
     * exposed instead of changing its content when the profile changes.
     */
    private function build_mcp_catalog($read_tools, $write_tools, $custom_tools) {
        $read_names = [];
        foreach ($read_tools as $tool) {
            if (!empty($tool['name'])) {
                $read_names[(string) $tool['name']] = true;
            }
        }

        $catalog = [];
        foreach ($write_tools as $tool) {
            if (empty($tool['name']) || !empty($tool['route'])) {
                continue;
            }
            $name = (string) $tool['name'];
            $catalog[$name] = [
                'tool' => $tool,
                'origin' => 'native',
                'read' => isset($read_names[$name]),
                'write' => true,
            ];
        }
        foreach ($custom_tools as $tool) {
            if (empty($tool['name'])) {
                continue;
            }
            $name = (string) $tool['name'];
            $catalog[$name] = [
                'tool' => $tool,
                'origin' => 'custom',
                'read' => isset($read_names[$name]),
                'write' => true,
            ];
        }

        uasort($catalog, static function ($left, $right) {
            if ($left['origin'] !== $right['origin']) {
                return $left['origin'] === 'native' ? -1 : 1;
            }
            return strcmp((string) $left['tool']['name'], (string) $right['tool']['name']);
        });

        return $catalog;
    }

    private function get_mcp_tool_parameters($tool) {
        $schema = isset($tool['inputSchema']) && is_array($tool['inputSchema']) ? $tool['inputSchema'] : [];
        $properties = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        $required = isset($schema['required']) && is_array($schema['required']) ? $schema['required'] : [];
        $parameters = [];
        foreach ($properties as $name => $definition) {
            $definition = is_array($definition) ? $definition : [];
            $parameters[] = [
                'name' => (string) $name,
                'type' => isset($definition['type']) ? (is_array($definition['type']) ? implode('|', $definition['type']) : (string) $definition['type']) : 'mixed',
                'required' => in_array($name, $required, true),
                'enum' => isset($definition['enum']) && is_array($definition['enum']) ? $definition['enum'] : [],
            ];
        }
        usort($parameters, static function ($left, $right) {
            if ($left['required'] !== $right['required']) {
                return $left['required'] ? -1 : 1;
            }
            return strcmp($left['name'], $right['name']);
        });
        return $parameters;
    }

    /**
     * Explains why a custom tool is missing from the read profile, since
     * that is the usual surprise when a client only sees part of the catalog.
     */
    private function get_mcp_tool_warning($entry) {
        if ($entry['origin'] !== 'custom' || $entry['read']) {
            return '';
        }
        $tool = $entry['tool'];
        $method = isset($tool['method']) ? strtoupper((string) $tool['method']) : 'GET';
        if (!empty($tool['readOnlyHint'])) {
            /* translators: %s: HTTP method */
            return sprintf(__('Déclaré en lecture seule mais appelé en %s : seul le profil écriture le voit.', 'eliodata-snippet-hub'), $method);
        }
        if ($method === 'GET') {
            return __('Non déclaré en lecture seule : seul le profil écriture le voit.', 'eliodata-snippet-hub');
        }
        return '';
    }

    private function render_mcp_parameters($parameters) {
        if (empty($parameters)) {
            echo '<span class="ide-mcp-muted">' . esc_html__('Aucun', 'eliodata-snippet-hub') . '</span>';
            return;
        }
        echo '<span class="ide-mcp-params">';
        foreach ($parameters as $parameter) {
            $title = $parameter['type'];
            if (!empty($parameter['enum'])) {
                $title .= ' : ' . implode(', ', array_map('strval', $parameter['enum']));
            }
            printf(
                '<code class="ide-mcp-param%1$s" title="%2$s">%3$s%4$s</code>',
                $parameter['required'] ? ' is-required' : '',
                esc_attr($title),
                esc_html($parameter['name']),
                $parameter['required'] ? '<span aria-hidden="true">*</span>' : ''
            );
        }
        echo '</span>';
    }

    private function get_mcp_page_url($args = [], $fragment = '') {
        $url = add_query_arg(array_merge(['page' => 'eliodata-snippet-hub-mcp'], $args), admin_url('admin.php'));
        return $fragment !== '' ? $url . '#' . $fragment : $url;
    }

    private function render_mcp_catalog_table($catalog, $selected_tool_name) {
        ?>
        <div class="ide-snippets-toolbar">
            <h2><?php esc_html_e('Catalogue', 'eliodata-snippet-hub'); ?></h2>
            <div class="ide-snippets-actions">
                <a class="button button-primary" href="<?php echo esc_url($this->get_mcp_page_url(['new_tool' => 1], 'mcp-tool-form')); ?>" data-mcp-open-form><?php esc_html_e('Nouvel outil personnalisé', 'eliodata-snippet-hub'); ?></a>
                <input type="search" id="ide-mcp-search" class="regular-text" placeholder="<?php echo esc_attr__('Filtrer par nom, description, paramètre...', 'eliodata-snippet-hub'); ?>">
            </div>
        </div>
        <p class="ide-mcp-muted"><?php esc_html_e('Le profil lecture est celui des clients en consultation (et des mots de passe d’application [readonly]) : il ne voit que les outils qui ne modifient rien. Le profil écriture voit tout.', 'eliodata-snippet-hub'); ?></p>
        <table class="widefat striped ide-mcp-table" id="ide-mcp-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Outil', 'eliodata-snippet-hub'); ?></th>
                    <th class="ide-mcp-col-origin"><?php esc_html_e('Origine', 'eliodata-snippet-hub'); ?></th>
                    <th class="ide-mcp-col-profiles"><?php esc_html_e('Visible en', 'eliodata-snippet-hub'); ?></th>
                    <th><?php esc_html_e('Paramètres', 'eliodata-snippet-hub'); ?></th>
                    <th class="ide-mcp-col-actions"><?php esc_html_e('Actions', 'eliodata-snippet-hub'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($catalog)) : ?>
                    <tr><td colspan="5"><?php esc_html_e('Aucun outil MCP disponible.', 'eliodata-snippet-hub'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($catalog as $tool_name => $entry) : ?>
                        <?php
                        $tool = $entry['tool'];
                        $is_custom = $entry['origin'] === 'custom';
                        $warning = $this->get_mcp_tool_warning($entry);
                        $parameters = $this->get_mcp_tool_parameters($tool);
                        ?>
                        <tr data-mcp-row class="<?php echo $selected_tool_name === $tool_name ? 'is-selected' : ''; ?>">
                            <td class="ide-mcp-col-tool">
                                <code class="ide-mcp-tool-name"><?php echo esc_html($tool_name); ?></code>
                                <?php if (!empty($tool['description'])) : ?>
                                    <div class="ide-mcp-desc"><?php echo esc_html((string) $tool['description']); ?></div>
                                <?php endif; ?>
                                <?php if ($is_custom) : ?>
                                    <div class="ide-mcp-route"><code><?php echo esc_html((isset($tool['method']) ? (string) $tool['method'] : 'GET') . ' ' . (isset($tool['route']) ? (string) $tool['route'] : '')); ?></code></div>
                                <?php endif; ?>
                                <?php if ($warning !== '') : ?>
                                    <div class="ide-mcp-warning"><?php echo esc_html($warning); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="ide-badge <?php echo $is_custom ? 'ide-badge-custom' : 'ide-badge-native'; ?>"><?php echo esc_html($is_custom ? __('Personnalisé', 'eliodata-snippet-hub') : __('Natif', 'eliodata-snippet-hub')); ?></span>
                            </td>
                            <td>
                                <?php if ($entry['read']) : ?>
                                    <span class="ide-badge ide-badge-active" title="<?php echo esc_attr__('Visible des profils lecture et écriture', 'eliodata-snippet-hub'); ?>"><?php esc_html_e('Lecture et écriture', 'eliodata-snippet-hub'); ?></span>
                                <?php else : ?>
                                    <span class="ide-badge ide-badge-write" title="<?php echo esc_attr__('Masqué au profil lecture', 'eliodata-snippet-hub'); ?>"><?php esc_html_e('Écriture seule', 'eliodata-snippet-hub'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $this->render_mcp_parameters($parameters); ?>
                                <details class="ide-mcp-schema">
                                    <summary><?php esc_html_e('Schéma JSON', 'eliodata-snippet-hub'); ?></summary>
                                    <pre class="ide-mcp-code"><?php echo esc_html($this->format_admin_json(isset($tool['inputSchema']) ? $tool['inputSchema'] : new stdClass())); ?></pre>
                                </details>
                            </td>
                            <td class="ide-mcp-col-actions">
                                <div class="ide-snippets-actions">
                                    <a class="button button-small" href="<?php echo esc_url($this->get_mcp_page_url(['tool_name' => $tool_name], 'mcp-tester')); ?>" data-mcp-test="<?php echo esc_attr($tool_name); ?>"><?php esc_html_e('Tester', 'eliodata-snippet-hub'); ?></a>
                                    <?php if ($is_custom) : ?>
                                        <a class="button button-small" href="<?php echo esc_url($this->get_mcp_page_url(['edit_tool' => $tool_name], 'mcp-tool-form')); ?>"><?php esc_html_e('Modifier', 'eliodata-snippet-hub'); ?></a>
                                        <form method="post" action="<?php echo esc_url($this->get_mcp_page_url()); ?>" onsubmit="return confirm('<?php echo esc_js(__('Supprimer cet outil personnalisé ?', 'eliodata-snippet-hub')); ?>');">
                                            <?php wp_nonce_field('ide_snippets_admin_action', 'ide_snippets_admin_nonce'); ?>
                                            <input type="hidden" name="page" value="eliodata-snippet-hub-mcp">
                                            <input type="hidden" name="op" value="mcp_delete_custom_tool">
                                            <input type="hidden" name="tool_name" value="<?php echo esc_attr($tool_name); ?>">
                                            <button type="submit" class="button button-small button-link-delete"><?php esc_html_e('Supprimer', 'eliodata-snippet-hub'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_mcp_custom_tool_form($editing_tool, $existing_name, $open) {
        $tool_name = isset($editing_tool['name']) ? (string) $editing_tool['name'] : '';
        $is_editing = $existing_name !== '';
        $method = isset($editing_tool['method']) ? (string) $editing_tool['method'] : 'GET';
        $pass_as = isset($editing_tool['passAs']) ? (string) $editing_tool['passAs'] : 'query';
        $schema = '';
        if (isset($editing_tool['inputSchema']) && is_string($editing_tool['inputSchema'])) {
            $schema = $editing_tool['inputSchema'];
        } elseif (!empty($editing_tool['inputSchema'])) {
            $schema = $this->format_admin_json($editing_tool['inputSchema']);
        }
        ?>
        <details class="ide-snippets-panel ide-mcp-form-panel" id="mcp-tool-form" <?php echo $open ? 'open' : ''; ?>>
            <summary><h2><?php echo esc_html($is_editing ? sprintf(__('Modifier l’outil %s', 'eliodata-snippet-hub'), $existing_name) : __('Nouvel outil personnalisé', 'eliodata-snippet-hub')); ?></h2></summary>
            <p class="ide-mcp-muted"><?php esc_html_e('Un outil personnalisé expose une route REST du site aux clients MCP (le companion IDE, un agent). Il est propre à ce site et stocké dans WordPress.', 'eliodata-snippet-hub'); ?></p>
            <form method="post" action="<?php echo esc_url($this->get_mcp_page_url()); ?>" data-mcp-tool-form>
                <?php wp_nonce_field('ide_snippets_admin_action', 'ide_snippets_admin_nonce'); ?>
                <input type="hidden" name="page" value="eliodata-snippet-hub-mcp">
                <input type="hidden" name="op" value="mcp_save_custom_tool">
                <input type="hidden" name="existing_name" value="<?php echo esc_attr($existing_name); ?>">
                <div class="ide-mcp-form-grid">
                    <p class="ide-field">
                        <label for="mcp-tool-name"><?php esc_html_e('Nom', 'eliodata-snippet-hub'); ?></label>
                        <input id="mcp-tool-name" type="text" name="name" value="<?php echo esc_attr($tool_name); ?>" placeholder="site_tool_name" pattern="[a-z0-9][a-z0-9._\-]{2,127}" title="<?php echo esc_attr__('Minuscules, chiffres, point, tiret et tiret bas ; 3 caractères minimum.', 'eliodata-snippet-hub'); ?>" required <?php echo $is_editing ? 'readonly' : ''; ?>>
                        <span class="description"><?php echo esc_html($is_editing ? __('Le nom identifie l’outil chez les clients : il ne se change pas.', 'eliodata-snippet-hub') : __('Minuscules, chiffres, « . », « - », « _ ».', 'eliodata-snippet-hub')); ?></span>
                    </p>
                    <p class="ide-field">
                        <label for="mcp-tool-method"><?php esc_html_e('Méthode HTTP', 'eliodata-snippet-hub'); ?></label>
                        <select id="mcp-tool-method" name="method">
                            <?php foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $option) : ?>
                                <option value="<?php echo esc_attr($option); ?>" <?php selected($method, $option); ?>><?php echo esc_html($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p class="ide-field ide-field-full">
                        <label for="mcp-tool-description"><?php esc_html_e('Description', 'eliodata-snippet-hub'); ?></label>
                        <textarea id="mcp-tool-description" name="description" rows="2" required placeholder="<?php echo esc_attr__('Ce que fait l’outil, lu par l’agent pour décider de l’appeler.', 'eliodata-snippet-hub'); ?>"><?php echo esc_textarea(isset($editing_tool['description']) ? (string) $editing_tool['description'] : ''); ?></textarea>
                    </p>
                    <p class="ide-field ide-field-full">
                        <label for="mcp-tool-route"><?php esc_html_e('Route REST', 'eliodata-snippet-hub'); ?></label>
                        <input id="mcp-tool-route" type="text" name="route" value="<?php echo esc_attr(isset($editing_tool['route']) ? (string) $editing_tool['route'] : ''); ?>" placeholder="/wp-json/namespace/v1/resource" pattern="/wp-json/.+" title="<?php echo esc_attr__('La route doit commencer par /wp-json/.', 'eliodata-snippet-hub'); ?>" required>
                    </p>
                    <p class="ide-field">
                        <label for="mcp-tool-pass-as"><?php esc_html_e('Arguments transmis en', 'eliodata-snippet-hub'); ?></label>
                        <select id="mcp-tool-pass-as" name="pass_as">
                            <option value="query" <?php selected($pass_as, 'query'); ?>><?php esc_html_e('paramètres d’URL (query)', 'eliodata-snippet-hub'); ?></option>
                            <option value="json" <?php selected($pass_as, 'json'); ?>><?php esc_html_e('corps JSON', 'eliodata-snippet-hub'); ?></option>
                        </select>
                        <span class="description" data-mcp-pass-as-hint><?php esc_html_e('GET et DELETE passent toujours leurs arguments dans l’URL.', 'eliodata-snippet-hub'); ?></span>
                    </p>
                    <p class="ide-field">
                        <span class="ide-mcp-label"><?php esc_html_e('Accès', 'eliodata-snippet-hub'); ?></span>
                        <label class="ide-mcp-check"><input type="checkbox" name="read_only_hint" value="1" <?php checked(!empty($editing_tool['readOnlyHint'])); ?>> <?php esc_html_e('Lecture seule (ne modifie rien)', 'eliodata-snippet-hub'); ?></label>
                        <span class="description" data-mcp-readonly-hint><?php esc_html_e('Exposé au profil lecture seulement s’il est aussi en GET.', 'eliodata-snippet-hub'); ?></span>
                    </p>
                    <p class="ide-field ide-field-full">
                        <label for="mcp-tool-input-schema"><?php esc_html_e('Schéma d’entrée (JSON Schema)', 'eliodata-snippet-hub'); ?></label>
                        <textarea id="mcp-tool-input-schema" class="code" name="input_schema" rows="8" spellcheck="false" placeholder='{"type":"object","required":["id"],"properties":{"id":{"type":"integer"}}}'><?php echo esc_textarea($schema); ?></textarea>
                        <span class="description" data-mcp-json-status><?php esc_html_e('Laisser vide pour un outil sans paramètre.', 'eliodata-snippet-hub'); ?></span>
                    </p>
                </div>
                <div class="ide-snippets-actions">
                    <button type="submit" class="button button-primary"><?php echo esc_html($is_editing ? __('Enregistrer l’outil', 'eliodata-snippet-hub') : __('Créer l’outil', 'eliodata-snippet-hub')); ?></button>
                    <a class="button" href="<?php echo esc_url($this->get_mcp_page_url()); ?>"><?php esc_html_e('Annuler', 'eliodata-snippet-hub'); ?></a>
                </div>
            </form>
        </details>
        <?php
    }

    private function render_mcp_tester_panel($catalog, $selected_tool_name, $test_result) {
        $arguments = '{}';
        if (is_array($test_result) && isset($test_result['tool_name']) && $test_result['tool_name'] === $selected_tool_name && isset($test_result['arguments'])) {
            $arguments = $this->format_admin_json(empty($test_result['arguments']) ? new stdClass() : $test_result['arguments']);
        }
        $tester_data = [];
        foreach ($catalog as $tool_name => $entry) {
            $tester_data[$tool_name] = [
                'description' => isset($entry['tool']['description']) ? (string) $entry['tool']['description'] : '',
                'read' => $entry['read'],
                'parameters' => $this->get_mcp_tool_parameters($entry['tool']),
                'schema' => isset($entry['tool']['inputSchema']) ? $entry['tool']['inputSchema'] : [],
            ];
        }
        ?>
        <div class="ide-snippets-panel" id="mcp-tester">
            <h2><?php esc_html_e('Tester un outil', 'eliodata-snippet-hub'); ?></h2>
            <form method="post" action="<?php echo esc_url($this->get_mcp_page_url()); ?>" data-mcp-tester data-mcp-tools="<?php echo esc_attr(wp_json_encode($tester_data)); ?>" data-mcp-confirm="<?php echo esc_attr__('Cet outil peut modifier des données du site. L’exécuter quand même ?', 'eliodata-snippet-hub'); ?>">
                <?php wp_nonce_field('ide_snippets_admin_action', 'ide_snippets_admin_nonce'); ?>
                <input type="hidden" name="page" value="eliodata-snippet-hub-mcp">
                <input type="hidden" name="op" value="mcp_test_tool">
                <p class="ide-field">
                    <label for="mcp-test-tool"><?php esc_html_e('Outil', 'eliodata-snippet-hub'); ?></label>
                    <select id="mcp-test-tool" name="tool_name">
                        <?php foreach (['native' => __('Natifs', 'eliodata-snippet-hub'), 'custom' => __('Personnalisés', 'eliodata-snippet-hub')] as $origin => $label) : ?>
                            <optgroup label="<?php echo esc_attr($label); ?>">
                                <?php foreach ($catalog as $tool_name => $entry) : ?>
                                    <?php if ($entry['origin'] === $origin) : ?>
                                        <option value="<?php echo esc_attr($tool_name); ?>" <?php selected($selected_tool_name, $tool_name); ?>><?php echo esc_html($tool_name . ($entry['read'] ? '' : ' ✎')); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <span class="description"><?php esc_html_e('✎ : outil d’écriture, exécuté avec le profil écriture.', 'eliodata-snippet-hub'); ?></span>
                </p>
                <div class="ide-mcp-tester-info" data-mcp-tester-info>
                    <?php if (isset($catalog[$selected_tool_name])) : ?>
                        <p class="ide-mcp-desc"><?php echo esc_html(isset($catalog[$selected_tool_name]['tool']['description']) ? (string) $catalog[$selected_tool_name]['tool']['description'] : ''); ?></p>
                        <?php $this->render_mcp_parameters($this->get_mcp_tool_parameters($catalog[$selected_tool_name]['tool'])); ?>
                    <?php endif; ?>
                </div>
                <p class="ide-field">
                    <label for="mcp-test-arguments"><?php esc_html_e('Arguments (JSON)', 'eliodata-snippet-hub'); ?></label>
                    <textarea id="mcp-test-arguments" class="code" name="arguments" rows="8" spellcheck="false"><?php echo esc_textarea($arguments); ?></textarea>
                    <span class="description"><?php esc_html_e('* : paramètre obligatoire. Choisir un outil préremplit ses paramètres obligatoires.', 'eliodata-snippet-hub'); ?></span>
                </p>
                <div class="ide-snippets-actions">
                    <button type="submit" class="button button-primary" <?php disabled(empty($catalog)); ?>><?php esc_html_e('Exécuter', 'eliodata-snippet-hub'); ?></button>
                </div>
            </form>
            <?php $this->render_mcp_test_result_panel($test_result); ?>
        </div>
        <?php
    }

    private function render_mcp_test_result_panel($test_result) {
        if (!is_array($test_result)) {
            return;
        }
        $result = isset($test_result['result']) && is_array($test_result['result']) ? $test_result['result'] : [];
        $ok = !empty($result['ok']);
        $status = isset($result['status']) ? (int) $result['status'] : 0;
        $body = $ok
            ? (isset($result['data']) ? $result['data'] : [])
            : ['message' => isset($result['message']) ? $result['message'] : '', 'details' => isset($result['data']) ? $result['data'] : []];
        ?>
        <div class="ide-mcp-result <?php echo $ok ? 'is-ok' : 'is-error'; ?>" id="mcp-result">
            <div class="ide-mcp-result-header">
                <strong><?php esc_html_e('Résultat', 'eliodata-snippet-hub'); ?></strong>
                <code><?php echo esc_html(isset($test_result['tool_name']) ? (string) $test_result['tool_name'] : ''); ?></code>
                <span class="ide-badge <?php echo $ok ? 'ide-badge-active' : 'ide-badge-inactive'; ?>"><?php echo esc_html(($ok ? __('Succès', 'eliodata-snippet-hub') : __('Erreur', 'eliodata-snippet-hub')) . ($status ? ' · ' . $status : '')); ?></span>
                <span class="ide-mcp-muted">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: 1: profile, 2: duration in ms */
                        __('profil %1$s · %2$s ms', 'eliodata-snippet-hub'),
                        (isset($test_result['profile']) && $test_result['profile'] === 'write') ? __('écriture', 'eliodata-snippet-hub') : __('lecture', 'eliodata-snippet-hub'),
                        isset($test_result['duration_ms']) ? (string) (int) $test_result['duration_ms'] : '?'
                    ));
                    ?>
                </span>
                <button type="button" class="button button-small" data-mcp-copy="#mcp-result-body"><?php esc_html_e('Copier', 'eliodata-snippet-hub'); ?></button>
            </div>
            <pre class="ide-mcp-code ide-mcp-result-body" id="mcp-result-body"><?php echo esc_html($this->format_admin_json($body)); ?></pre>
        </div>
        <?php
    }

    private function render_mcp_access_panel() {
        $config = $this->format_admin_json([
            'baseUrl' => home_url('/'),
            'namespace' => 'eliodata-snippet-hub/v1',
            'profile' => 'read',
            'customTools' => true,
        ]);
        ?>
        <details class="ide-snippets-panel ide-mcp-access">
            <summary><h2><?php esc_html_e('Connexion d’un client MCP', 'eliodata-snippet-hub'); ?></h2></summary>
            <p class="ide-mcp-muted"><?php esc_html_e('Authentification : URL du site, identifiant WordPress et mot de passe d’application. Mettre [readonly] dans le nom du mot de passe le limite aux outils de lecture, quel que soit le profil demandé.', 'eliodata-snippet-hub'); ?></p>
            <table class="widefat ide-mcp-endpoints">
                <tbody>
                    <tr><th><?php esc_html_e('Catalogue', 'eliodata-snippet-hub'); ?></th><td><code>GET <?php echo esc_html($this->get_mcp_admin_endpoint('mcp/tools')); ?>?profile=read|write</code></td></tr>
                    <tr><th><?php esc_html_e('Exécution', 'eliodata-snippet-hub'); ?></th><td><code>POST <?php echo esc_html($this->get_mcp_admin_endpoint('mcp/call')); ?></code></td></tr>
                    <tr><th><?php esc_html_e('Outils personnalisés', 'eliodata-snippet-hub'); ?></th><td><code><?php echo esc_html($this->get_mcp_admin_endpoint('mcp/custom-tools')); ?></code></td></tr>
                </tbody>
            </table>
            <div class="ide-mcp-result-header">
                <strong><?php esc_html_e('Configuration', 'eliodata-snippet-hub'); ?></strong>
                <button type="button" class="button button-small" data-mcp-copy="#mcp-access-config"><?php esc_html_e('Copier', 'eliodata-snippet-hub'); ?></button>
            </div>
            <pre class="ide-mcp-code" id="mcp-access-config"><?php echo esc_html($config); ?></pre>
        </details>
        <?php
    }

    public function render_mcp_page() {
        if (!Eliodata_Snippet_Hub_Security::current_user_can_manage()) {
            return;
        }

        $notice = get_transient('ide_snippets_admin_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('ide_snippets_admin_notice_' . get_current_user_id());
        }

        $selected_tool_name = isset($_GET['tool_name']) ? sanitize_text_field(wp_unslash($_GET['tool_name'])) : '';
        $edit_tool_name = isset($_GET['edit_tool']) ? sanitize_text_field(wp_unslash($_GET['edit_tool'])) : '';
        $form_requested = !empty($_GET['new_tool']) || $edit_tool_name !== '';

        $read_payload = $this->get_mcp_tools_payload('read');
        $write_payload = $this->get_mcp_tools_payload('write');
        $custom_payload = $this->get_mcp_custom_tools_payload();

        $read_tools = $read_payload['ok'] && !empty($read_payload['data']['tools']) && is_array($read_payload['data']['tools']) ? $read_payload['data']['tools'] : [];
        $write_tools = $write_payload['ok'] && !empty($write_payload['data']['tools']) && is_array($write_payload['data']['tools']) ? $write_payload['data']['tools'] : [];
        $custom_tools = $custom_payload['ok'] && !empty($custom_payload['data']['tools']) && is_array($custom_payload['data']['tools']) ? $custom_payload['data']['tools'] : [];

        $catalog = $this->build_mcp_catalog($read_tools, $write_tools, $custom_tools);

        $editing_tool = [];
        $existing_name = '';
        if ($edit_tool_name !== '' && isset($catalog[$edit_tool_name]) && $catalog[$edit_tool_name]['origin'] === 'custom') {
            $editing_tool = $catalog[$edit_tool_name]['tool'];
            $existing_name = $edit_tool_name;
        }
        // A failed save comes back with the submitted values, so nothing typed is lost
        $draft_tool = $this->consume_admin_payload('mcp_tool_draft');
        if (is_array($draft_tool)) {
            $existing_name = isset($draft_tool['existing_name']) ? (string) $draft_tool['existing_name'] : '';
            unset($draft_tool['existing_name']);
            $editing_tool = $draft_tool;
            $form_requested = true;
        }

        $test_result = $this->consume_admin_payload('mcp_test_result');
        if ($selected_tool_name === '' && is_array($test_result) && !empty($test_result['tool_name'])) {
            $selected_tool_name = (string) $test_result['tool_name'];
        }
        // Default to a read tool, so that a hasty click never runs a write
        if (!isset($catalog[$selected_tool_name])) {
            $selected_tool_name = '';
            foreach ($catalog as $name => $entry) {
                if ($entry['read']) {
                    $selected_tool_name = (string) $name;
                    break;
                }
            }
            if ($selected_tool_name === '' && !empty($catalog)) {
                $selected_tool_name = (string) array_key_first($catalog);
            }
        }

        $native_count = 0;
        $custom_count = 0;
        $read_count = 0;
        $custom_hidden = 0;
        foreach ($catalog as $entry) {
            if ($entry['origin'] === 'native') {
                $native_count++;
            } else {
                $custom_count++;
                if (!$entry['read']) {
                    $custom_hidden++;
                }
            }
            if ($entry['read']) {
                $read_count++;
            }
        }
        ?>
        <div class="wrap ide-snippets-admin ide-mcp-page">
            <?php $this->render_admin_nav('eliodata-snippet-hub-mcp'); ?>
            <h1><?php esc_html_e('Outils MCP', 'eliodata-snippet-hub'); ?></h1>
            <hr class="wp-header-end">
            <p class="ide-mcp-muted"><?php esc_html_e('Les outils que ce site expose aux clients MCP : les natifs gèrent les snippets, les personnalisés exposent des routes REST du site.', 'eliodata-snippet-hub'); ?></p>

            <?php if ($notice && !empty($notice['message'])) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type'] === 'error' ? 'error' : 'success'); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>
            <?php foreach ([$read_payload, $write_payload, $custom_payload] as $payload) : ?>
                <?php if (!$payload['ok']) : ?>
                    <div class="notice notice-error"><p><?php echo esc_html($payload['message']); ?></p></div>
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="ide-snippets-grid">
                <div class="ide-snippets-card"><strong><?php echo esc_html((string) count($catalog)); ?></strong><?php esc_html_e('Outils au total', 'eliodata-snippet-hub'); ?></div>
                <div class="ide-snippets-card"><strong><?php echo esc_html((string) $native_count); ?></strong><?php esc_html_e('Natifs', 'eliodata-snippet-hub'); ?></div>
                <div class="ide-snippets-card"><strong><?php echo esc_html((string) $custom_count); ?></strong><?php esc_html_e('Personnalisés', 'eliodata-snippet-hub'); ?></div>
                <div class="ide-snippets-card">
                    <strong><?php echo esc_html((string) $read_count); ?></strong><?php esc_html_e('Visibles en profil lecture', 'eliodata-snippet-hub'); ?>
                    <?php if ($custom_hidden > 0) : ?>
                        <span class="ide-mcp-card-note"><?php echo esc_html(sprintf(_n('%d personnalisé masqué', '%d personnalisés masqués', $custom_hidden, 'eliodata-snippet-hub'), $custom_hidden)); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ide-mcp-layout">
                <div class="ide-mcp-stack">
                    <div class="ide-snippets-panel">
                        <?php $this->render_mcp_catalog_table($catalog, $selected_tool_name); ?>
                    </div>
                    <?php $this->render_mcp_custom_tool_form($editing_tool, $existing_name, $form_requested); ?>
                    <?php $this->render_mcp_access_panel(); ?>
                </div>
                <div class="ide-mcp-stack ide-mcp-aside">
                    <?php $this->render_mcp_tester_panel($catalog, $selected_tool_name, $test_result); ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_edit_page() {
        if (!Eliodata_Snippet_Hub_Security::current_user_can_manage()) {
            return;
        }

        $notice = get_transient('ide_snippets_admin_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('ide_snippets_admin_notice_' . get_current_user_id());
        }

        $page_slug = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $page_slug = is_string($page_slug) && $page_slug !== '' ? sanitize_text_field(wp_unslash($page_slug)) : 'eliodata-snippet-hub-edit';
        if (!in_array($page_slug, ['eliodata-snippet-hub-new', 'eliodata-snippet-hub-edit'], true)) {
            $page_slug = 'eliodata-snippet-hub-edit';
        }

        $snippet_id_input = filter_input(INPUT_GET, 'snippet_id', FILTER_SANITIZE_NUMBER_INT);
        $snippet_id = $snippet_id_input !== null && $snippet_id_input !== false ? absint($snippet_id_input) : 0;
        $editing = $snippet_id > 0 ? $this->get_native_snippet($snippet_id) : null;
        ?>
        <div class="wrap ide-snippets-admin">
            <?php $this->render_admin_nav($editing ? 'eliodata-snippet-hub' : 'eliodata-snippet-hub-new'); ?>
            <h1><?php echo esc_html($editing ? __('Modifier le snippet', 'eliodata-snippet-hub') : __('Nouveau snippet', 'eliodata-snippet-hub')); ?></h1>
            <hr class="wp-header-end">

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
function eliodata_snippet_hub_init() {
    return IDE_Snippets_Bridge::get_instance();
}

// Go!
eliodata_snippet_hub_init();
