<?php
/**
 * IDE Snippets API Class
 *
 * Handles REST API endpoints for code snippet management
 * in conjunction with IDE extensions (like Trae AI, VS Code).
 *
 * @package IDESnippets
 * @subpackage API
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main API class for Eliodata Snippet Hub
 *
 * Provides secure REST API endpoints for communication
 * between WordPress snippets and IDE extensions.
 *
 * @since 1.0.0
 */
class IDE_Snippets_API {

    /**
     * API namespace
     *
     * @since 1.0.0
     * @var string
     */
    protected $namespace = 'ide/v1';

    /**
     * Supported storage engines.
     *
     * @since 2.0.0
     * @var array
     */
    protected $supported_engines = ['native', 'code_snippets'];

    /**
     * Register REST API routes
     *
     * @since 1.0.0
     * @return void
     */
    public function register_routes() {

        // === Status endpoint ===
        register_rest_route($this->namespace, '/status', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_status'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        // === Snippets endpoints ===
        register_rest_route($this->namespace, '/snippets', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_snippets'],
                'permission_callback' => [$this, 'check_permission'],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create_snippet'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/snippets/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_snippet'],
                'permission_callback' => [$this, 'check_permission'],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update_snippet'],
                'permission_callback' => [$this, 'check_permission'],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete_snippet'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);
    }

    /**
     * Check if current user has permission
     *
     * @since 1.0.0
     * @return bool
     */
    public function check_permission($request = null) {
        if (!$this->current_user_can_access_api()) {
            return false;
        }

        if ($request instanceof WP_REST_Request && $this->request_mutates_snippets($request) && Eliodata_Snippet_Hub_Security::is_readonly_request()) {
            return new WP_Error(
                'readonly_forbidden',
                __('This application password is read-only for snippet write operations.', 'eliodata-snippet-hub'),
                ['status' => 403]
            );
        }

        return true;
    }

    private function get_api_capability() {
        $capability = apply_filters('eliodata_snippet_hub_api_capability', 'eliodata_snippet_hub_manage');
        if (!is_string($capability) || $capability === '') {
            return 'eliodata_snippet_hub_manage';
        }

        return sanitize_key($capability);
    }

    private function current_user_can_access_api() {
        $capability = $this->get_api_capability();

        if ($capability && current_user_can($capability)) {
            return true;
        }

        return Eliodata_Snippet_Hub_Security::current_user_can_manage();
    }

    private function request_mutates_snippets(WP_REST_Request $request) {
        return in_array($request->get_method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /**
     * Get native snippets table name with proper prefix.
     *
     * @since 2.0.0
     * @return string
     */
    private function get_native_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ide_snippets';
    }

    /**
     * Check whether a table exists.
     *
     * @since 2.0.0
     * @param string $table Table name.
     * @return bool
     */
    private function table_exists_by_name($table) {
        global $wpdb;
        $cache_key = 'table_exists:' . $table;

        $cached = wp_cache_get($cache_key, 'eliodata_snippet_hub');
        if ($cached !== false) {
            return (bool) $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
        wp_cache_set($cache_key, $exists ? 1 : 0, 'eliodata_snippet_hub', 300);

        return $exists;
    }

    /**
     * Check if native table exists.
     *
     * @since 2.0.0
     * @return bool
     */
    private function native_table_exists() {
        return $this->table_exists_by_name($this->get_native_table_name());
    }

    private function ensure_plugin_functions_loaded() {
        if (function_exists('is_plugin_active') && function_exists('is_plugin_active_for_network')) {
            return;
        }

        if (defined('ABSPATH')) {
            $plugin_file = ABSPATH . 'wp-admin/includes/plugin.php';
            if (file_exists($plugin_file)) {
                require_once $plugin_file;
            }
        }
    }

    private function is_plugin_file_active($plugin_file) {
        $this->ensure_plugin_functions_loaded();

        if (function_exists('is_plugin_active') && is_plugin_active($plugin_file)) {
            return true;
        }

        return function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($plugin_file);
    }

    private function is_code_snippets_plugin_active() {
        return $this->is_plugin_file_active('code-snippets/code-snippets.php')
            || $this->is_plugin_file_active('code-snippets-pro/code-snippets.php');
    }

    private function get_code_snippets_table_candidates() {
        global $wpdb;

        $candidates = [$wpdb->prefix . 'snippets'];

        if (is_multisite()) {
            $candidates[] = $wpdb->base_prefix . 'snippets';
            $candidates[] = $wpdb->base_prefix . 'ms_snippets';
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function get_code_snippets_table_name() {
        foreach ($this->get_code_snippets_table_candidates() as $candidate) {
            if ($this->table_exists_by_name($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function get_table_columns($table) {
        global $wpdb;

        if (!$table || !$this->table_exists_by_name($table)) {
            return [];
        }

        $cache_key = 'table_columns:' . $table;
        $cached = wp_cache_get($cache_key, 'eliodata_snippet_hub');
        if (is_array($cached)) {
            return $cached;
        }

        $columns = [];
        $results = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`");
        if (is_array($results)) {
            foreach ($results as $column) {
                if (!isset($column->Field) || !is_string($column->Field)) {
                    continue;
                }
                $columns[$column->Field] = $column;
            }
        }

        wp_cache_set($cache_key, $columns, 'eliodata_snippet_hub', 300);

        return $columns;
    }

    private function get_existing_table_column($table, array $candidates) {
        $columns = $this->get_table_columns($table);
        foreach ($candidates as $candidate) {
            if (isset($columns[$candidate])) {
                return $candidate;
            }
        }
        return null;
    }

    private function get_active_column_for_engine($engine, $table) {
        if ($engine === 'code_snippets') {
            return $this->get_existing_table_column($table, ['active', 'enabled']);
        }

        return 'active';
    }

    private function prepare_data_for_engine($engine, $table, array $data, $is_create = false) {
        if ($engine !== 'code_snippets') {
            return $data;
        }

        $mapped = [];
        $column_map = [
            'name' => ['name', 'display_name', 'title'],
            'description' => ['description', 'desc', 'notes'],
            'code' => ['code', 'content', 'snippet'],
            'tags' => ['tags'],
            'scope' => ['scope', 'context'],
            'priority' => ['priority'],
            'active' => ['active', 'enabled', 'status'],
            'modified' => ['modified', 'updated', 'last_modified', 'modified_at', 'updated_at'],
            'created' => ['created', 'created_at', 'date_created'],
        ];

        foreach ($column_map as $source_key => $candidates) {
            if (!array_key_exists($source_key, $data)) {
                continue;
            }

            $target_column = $this->get_existing_table_column($table, $candidates);
            if ($target_column) {
                $mapped[$target_column] = $data[$source_key];
            }
        }

        if ($is_create) {
            $type_column = $this->get_existing_table_column($table, ['type', 'snippet_type']);
            if ($type_column && !isset($mapped[$type_column])) {
                $mapped[$type_column] = 'php';
            }
        }

        return $mapped;
    }

    private function get_row_value($row, array $candidates, $default = null) {
        foreach ($candidates as $candidate) {
            if (is_object($row) && isset($row->{$candidate})) {
                return $row->{$candidate};
            }

            if (is_array($row) && array_key_exists($candidate, $row)) {
                return $row[$candidate];
            }
        }

        return $default;
    }

    private function normalize_boolean_value($value) {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value > 0;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled', 'active', 'publish', 'published'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled', 'inactive', 'draft'], true)) {
            return false;
        }

        return (bool) $value;
    }

    private function get_registered_engines() {
        $engines = [
            'native' => [
                'label' => 'IDE Snippets',
                'table' => $this->get_native_table_name(),
            ],
        ];
        $code_snippets_table = $this->get_code_snippets_table_name();
        if ($code_snippets_table) {
            $engines['code_snippets'] = [
                'label' => 'Code Snippets',
                'table' => $code_snippets_table,
            ];
        }
        $engines = apply_filters('eliodata_snippet_hub_registered_engines', $engines, $this);
        if (!is_array($engines)) {
            $engines = [];
        }
        if (!isset($engines['native']) || !is_array($engines['native'])) {
            $engines['native'] = [
                'label' => 'IDE Snippets',
                'table' => $this->get_native_table_name(),
            ];
        }
        if (empty($engines['native']['table']) || !is_string($engines['native']['table'])) {
            $engines['native']['table'] = $this->get_native_table_name();
        }
        if (empty($engines['native']['label']) || !is_string($engines['native']['label'])) {
            $engines['native']['label'] = 'IDE Snippets';
        }
        return $engines;
    }

    private function get_enabled_engines() {
        $registered = $this->get_registered_engines();
        $enabled = apply_filters('eliodata_snippet_hub_enabled_engines', $this->supported_engines, $registered, $this);
        if (!is_array($enabled)) {
            $enabled = ['native'];
        }
        $enabled = array_map(
            static function ($engine) {
                return str_replace('-', '_', strtolower(sanitize_key((string) $engine)));
            },
            $enabled
        );
        $enabled = array_values(array_unique(array_filter($enabled)));
        $enabled = array_values(array_intersect($enabled, array_keys($registered)));
        if (!in_array('native', $enabled, true)) {
            $enabled[] = 'native';
        }
        return $enabled;
    }

    /**
     * Create native storage table.
     *
     * @since 2.0.0
     * @return void
     */
    public static function create_native_table() {
        global $wpdb;
        $table_name      = $wpdb->prefix . 'ide_snippets';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            description text NULL,
            code longtext NOT NULL,
            tags text NULL,
            scope varchar(50) NOT NULL DEFAULT 'global',
            target_mode varchar(50) NOT NULL DEFAULT 'all',
            target_post_types text NULL,
            target_post_ids longtext NULL,
            priority int(11) NOT NULL DEFAULT 10,
            active tinyint(1) NOT NULL DEFAULT 0,
            created datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            modified datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY active (active),
            KEY scope (scope),
            KEY target_mode (target_mode),
            KEY modified (modified)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * Ensure native storage exists.
     *
     * @since 2.0.0
     * @return bool
     */
    private function ensure_native_table() {
        if ($this->native_table_exists()) {
            return true;
        }

        self::create_native_table();
        wp_cache_delete('table_exists:' . $this->get_native_table_name(), 'eliodata_snippet_hub');

        return $this->native_table_exists();
    }

    /**
     * Normalize and validate requested engine.
     *
     * @since 2.0.0
     * @param mixed $value Engine value.
     * @return string|null
     */
    private function normalize_engine($value) {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $engine = strtolower(sanitize_text_field($value));
        $engine = str_replace('-', '_', $engine);

        if (!in_array($engine, $this->get_enabled_engines(), true)) {
            return null;
        }

        return $engine;
    }

    /**
     * Resolve effective engine for a request.
     *
     * @since 2.0.0
     * @param WP_REST_Request $request Request object.
     * @return string
     */
    private function resolve_engine(WP_REST_Request $request) {
        $params = $request->get_json_params();
        $engine = $this->normalize_engine($request->get_param('engine'));

        if (!$engine && is_array($params) && isset($params['engine'])) {
            $engine = $this->normalize_engine($params['engine']);
        }

        if ($engine) {
            return $engine;
        }

        return 'native';
    }

    /**
     * Resolve table name from engine.
     *
     * @since 2.0.0
     * @param string $engine Engine key.
     * @return string|null
     */
    private function get_table_name_for_engine($engine) {
        $registered = $this->get_registered_engines();
        if (!isset($registered[$engine]) || !is_array($registered[$engine])) {
            return null;
        }
        if (empty($registered[$engine]['table']) || !is_string($registered[$engine]['table'])) {
            return null;
        }
        return $registered[$engine]['table'];
    }

    /**
     * Ensure engine storage is available.
     *
     * @since 2.0.0
     * @param string $engine Engine key.
     * @return bool
     */
    private function ensure_engine_ready($engine) {
        if ($engine === 'native') {
            return $this->ensure_native_table();
        }

        if ($engine === 'code_snippets') {
            return (bool) $this->get_code_snippets_table_name();
        }

        return (bool) apply_filters('eliodata_snippet_hub_engine_is_ready', false, $engine, $this->get_registered_engines(), $this);
    }

    private function engine_supports_targeting($engine) {
        if ($engine === 'native') {
            return true;
        }
        return (bool) apply_filters('eliodata_snippet_hub_engine_supports_targeting', false, $engine, $this->get_registered_engines(), $this);
    }

    /**
     * Format a snippet row from DB into a clean response object
     *
     * @since 1.2.0
     * @param object $row Database row
     * @return object Formatted snippet
     */
    private function format_snippet($row, $engine = 'native') {
        if (!$row) {
            return null;
        }
        if ($engine === 'code_snippets') {
            $tags = $this->get_row_value($row, ['tags'], '');
            if (is_array($tags)) {
                $tags = implode(', ', array_map('strval', $tags));
            }

            return (object) [
                'id' => (int) $this->get_row_value($row, ['id'], 0),
                'name' => (string) $this->get_row_value($row, ['name', 'display_name', 'title'], ''),
                'description' => (string) $this->get_row_value($row, ['description', 'desc', 'notes'], ''),
                'code' => (string) $this->get_row_value($row, ['code', 'content', 'snippet'], ''),
                'tags' => is_string($tags) ? $tags : '',
                'scope' => (string) $this->get_row_value($row, ['scope', 'context'], 'global'),
                'target_mode' => 'all',
                'target_post_types' => '',
                'target_post_ids' => '',
                'priority' => (int) $this->get_row_value($row, ['priority'], 10),
                'active' => $this->normalize_boolean_value($this->get_row_value($row, ['active', 'enabled', 'status'], 0)),
                'created' => (string) $this->get_row_value($row, ['created', 'created_at', 'date_created'], ''),
                'modified' => (string) $this->get_row_value($row, ['modified', 'updated', 'last_modified', 'modified_at', 'updated_at'], ''),
            ];
        }
        $row->id       = (int) $row->id;
        $row->active   = (bool) $row->active;
        $row->priority = isset($row->priority) ? (int) $row->priority : 10;
        $row->target_mode = isset($row->target_mode) ? (string) $row->target_mode : 'all';
        $row->target_post_types = isset($row->target_post_types) && is_string($row->target_post_types) ? $row->target_post_types : '';
        $row->target_post_ids = isset($row->target_post_ids) && is_string($row->target_post_ids) ? $row->target_post_ids : '';

        if (!empty($row->tags)) {
            $decoded = json_decode($row->tags, true);
            if (is_array($decoded)) {
                $row->tags = implode(', ', $decoded);
            }
        } else {
            $row->tags = '';
        }

        return $row;
    }

    /**
     * Code Snippets stores PHP, HTML, CSS and JS in one table; the scope tells them apart.
     */
    private function is_php_snippet_scope($scope) {
        $scope = strtolower(trim((string) $scope));
        return $scope === '' || in_array($scope, ['global', 'admin', 'front-end', 'single-use'], true);
    }

    /**
     * Reject PHP snippets with a syntax error before they are stored. Code Snippets
     * only validates code saved from its own screen, and this API writes to its table directly.
     */
    private function validate_snippet_code($engine, $code, $scope = '') {
        if ($engine === 'native' || ($engine === 'code_snippets' && $this->is_php_snippet_scope($scope))) {
            return Eliodata_Snippet_Hub_Security::validate_php_code($code);
        }
        return true;
    }

    private function after_snippet_change($engine, $id) {
        if ($engine === 'code_snippets') {
            // Code Snippets caches its snippet lists; a direct table write must invalidate them
            $table = $this->get_table_name_for_engine($engine);
            if ($table && function_exists('Code_Snippets\\clean_snippets_cache')) {
                \Code_Snippets\clean_snippets_cache($table);
            }
            return;
        }
        if ($engine !== 'native') {
            return;
        }
        Eliodata_Snippet_Hub_Security::clear_snippet_cache($id);
        Eliodata_Snippet_Hub_Security::clear_runtime_error($id);
    }

    private function extract_snippet_code(array $params) {
        if (isset($params['code_b64']) && is_string($params['code_b64']) && $params['code_b64'] !== '') {
            $decoded = base64_decode($params['code_b64'], true);
            if ($decoded === false) {
                return new WP_Error('invalid_code_b64', __('Invalid base64-encoded snippet payload.', 'eliodata-snippet-hub'), ['status' => 400]);
            }
            return $decoded;
        }

        if (isset($params['code']) && is_string($params['code'])) {
            return $params['code'];
        }

        return '';
    }

    // =========================================================================
    //  STATUS
    // =========================================================================

    /**
     * GET /ide/v1/status
     *
     * Returns bridge plugin info.
     *
     * @since 1.2.0
     * @return WP_REST_Response
     */
    public function get_status(WP_REST_Request $request) {
        $native_ok     = $this->ensure_native_table();
        $registered    = $this->get_registered_engines();
        $enabled       = $this->get_enabled_engines();
        $active_plugins = [];
        foreach ($enabled as $engine_key) {
            if (!isset($registered[$engine_key]) || !is_array($registered[$engine_key])) {
                continue;
            }
            if ($engine_key === 'native' && !$native_ok) {
                continue;
            }
            $label = isset($registered[$engine_key]['label']) && is_string($registered[$engine_key]['label'])
                ? $registered[$engine_key]['label']
                : $engine_key;
            $active_plugins[] = $label;
        }

        return new WP_REST_Response([
            'plugin'          => 'Eliodata MCP Bridge',
            'version'         => defined('ELIODATA_SNIPPET_HUB_VERSION') ? ELIODATA_SNIPPET_HUB_VERSION : '4.2.0',
            'wordpress'       => get_bloginfo('version'),
            'php'             => PHP_VERSION,
            'native'          => $native_ok,
            'active_plugins'  => $active_plugins,
            'table_exists'    => $native_ok,
            'native_table_exists' => $native_ok,
            'engines_enabled' => $enabled,
            'engine_default'  => 'native',
            'site_url'        => get_site_url(),
            'timezone'        => wp_timezone_string(),
        ], 200);
    }

    // =========================================================================
    //  GET SNIPPETS
    // =========================================================================

    /**
     * GET /ide/v1/snippets
     *
     * Returns all snippets, optionally filtered by status.
     *
     * @since 1.0.0
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_snippets(WP_REST_Request $request) {
        global $wpdb;

        $engine = $this->resolve_engine($request);
        if (!$this->ensure_engine_ready($engine)) {
            return new WP_Error('table_missing', __('IDE Snippets table not found.', 'eliodata-snippet-hub'), ['status' => 500]);
        }

        $table  = $this->get_table_name_for_engine($engine);
        $status = $request->get_param('status');

        $active_column = $this->get_active_column_for_engine($engine, $table);

        if ($status === 'active' && $active_column) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $results = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->prepare("SELECT * FROM `{$table}` WHERE `{$active_column}` = %d ORDER BY id ASC", 1)
            );
        } elseif ($status === 'inactive' && $active_column) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $results = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->prepare("SELECT * FROM `{$table}` WHERE `{$active_column}` = %d ORDER BY id ASC", 0)
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $results = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY id ASC");
        }

        if ($results === null) {
            return new WP_Error('db_error', __('Database query failed', 'eliodata-snippet-hub'), ['status' => 500]);
        }

        $results = array_map(function ($row) use ($engine) {
            return $this->format_snippet($row, $engine);
        }, $results);

        return new WP_REST_Response($results, 200);
    }

    // =========================================================================
    //  GET SINGLE SNIPPET
    // =========================================================================

    /**
     * GET /ide/v1/snippets/{id}
     *
     * @since 1.0.0
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_snippet(WP_REST_Request $request) {
        global $wpdb;

        $engine = $this->resolve_engine($request);
        if (!$this->ensure_engine_ready($engine)) {
            return new WP_Error('table_missing', __('Snippet storage is not available for selected engine.', 'eliodata-snippet-hub'), ['status' => 500]);
        }

        $id    = absint($request['id']);
        $table = $this->get_table_name_for_engine($engine);

        if (!$id) {
            return new WP_Error('invalid_id', __('Invalid snippet ID', 'eliodata-snippet-hub'), ['status' => 400]);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $snippet = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id)
        );

        if (!$snippet) {
            return new WP_Error('not_found', __('Snippet not found', 'eliodata-snippet-hub'), ['status' => 404]);
        }

        return new WP_REST_Response($this->format_snippet($snippet, $engine), 200);
    }

    // =========================================================================
    //  CREATE SNIPPET
    // =========================================================================

    /**
     * POST /ide/v1/snippets
     *
     * Creates a new snippet.
     *
     * IMPORTANT: The `code` field is NOT sanitized with wp_kses because
     * it contains raw PHP code that must be preserved exactly.
     *
     * @since 1.0.0
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function create_snippet(WP_REST_Request $request) {
        global $wpdb;

        $engine = $this->resolve_engine($request);
        if (!$this->ensure_engine_ready($engine)) {
            return new WP_Error('table_missing', __('Snippet storage is not available for selected engine.', 'eliodata-snippet-hub'), ['status' => 500]);
        }

        $params = $request->get_json_params();
        $table  = $this->get_table_name_for_engine($engine);
        $code   = $this->extract_snippet_code($params);

        if (empty($params['name'])) {
            return new WP_Error('missing_name', __('Snippet name is required', 'eliodata-snippet-hub'), ['status' => 400]);
        }

        if (is_wp_error($code)) {
            return $code;
        }

        $syntax_check = $this->validate_snippet_code($engine, $code, isset($params['scope']) ? $params['scope'] : 'global');
        if (is_wp_error($syntax_check)) {
            return $syntax_check;
        }

        $data = [
            'name'        => sanitize_text_field($params['name']),
            'description' => isset($params['description']) ? sanitize_textarea_field($params['description']) : '',
            'code'        => $code,
            'tags'        => isset($params['tags']) ? sanitize_text_field($params['tags']) : '',
            'scope'       => isset($params['scope']) ? sanitize_text_field($params['scope']) : 'global',
            'priority'    => isset($params['priority']) ? absint($params['priority']) : 10,
            'active'      => isset($params['active']) ? (int)(bool)$params['active'] : 0,
            'modified'    => current_time('mysql'),
        ];

        if ($this->engine_supports_targeting($engine)) {
            $data['target_mode'] = isset($params['target_mode']) ? sanitize_text_field($params['target_mode']) : 'all';
            $data['target_post_types'] = isset($params['target_post_types']) ? sanitize_text_field($params['target_post_types']) : '';
            $data['target_post_ids'] = isset($params['target_post_ids']) ? sanitize_text_field($params['target_post_ids']) : '';
        }

        $data = $this->prepare_data_for_engine($engine, $table, $data, true);

        if (empty($data)) {
            return new WP_Error('invalid_schema', __('Snippet storage schema is not compatible with selected engine.', 'eliodata-snippet-hub'), ['status' => 500]);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert($table, $data);

        if (false === $result) {
            return new WP_Error('db_error', __('Could not create snippet:', 'eliodata-snippet-hub') . ' ' . $wpdb->last_error, ['status' => 500]);
        }

        $new_id      = $wpdb->insert_id;
        $this->after_snippet_change($engine, $new_id);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $new_snippet = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $new_id)
        );

        return new WP_REST_Response($this->format_snippet($new_snippet, $engine), 201);
    }

    // =========================================================================
    //  UPDATE SNIPPET
    // =========================================================================

    /**
     * PUT /ide/v1/snippets/{id}
     *
     * Updates an existing snippet. Only provided fields are updated.
     *
     * @since 1.0.0
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function update_snippet(WP_REST_Request $request) {
        global $wpdb;

        $engine = $this->resolve_engine($request);
        if (!$this->ensure_engine_ready($engine)) {
            return new WP_Error('table_missing', __('Snippet storage is not available for selected engine.', 'eliodata-snippet-hub'), ['status' => 500]);
        }

        $id     = absint($request['id']);
        $params = $request->get_json_params();
        $table  = $this->get_table_name_for_engine($engine);
        $code   = $this->extract_snippet_code($params);

        if (!$id) {
            return new WP_Error('invalid_id', __('Invalid snippet ID', 'eliodata-snippet-hub'), ['status' => 400]);
        }

        if (is_wp_error($code)) {
            return $code;
        }

        // Verify snippet exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id)
        );
        if (!$existing) {
            return new WP_Error('not_found', __('Snippet not found', 'eliodata-snippet-hub'), ['status' => 404]);
        }
        $effective_scope = isset($params['scope']) ? $params['scope'] : $this->get_row_value($existing, ['scope', 'context'], 'global');

        $data = [];

        if (isset($params['name'])) {
            $data['name'] = sanitize_text_field($params['name']);
        }
        if (isset($params['description'])) {
            $data['description'] = sanitize_textarea_field($params['description']);
        }
        if (isset($params['code']) || isset($params['code_b64'])) {
            $syntax_check = $this->validate_snippet_code($engine, $code, $effective_scope);
            if (is_wp_error($syntax_check)) {
                return $syntax_check;
            }
            $data['code'] = $code;
        }
        if (isset($params['tags'])) {
            $data['tags'] = sanitize_text_field($params['tags']);
        }
        if (isset($params['scope'])) {
            $data['scope'] = sanitize_text_field($params['scope']);
        }
        if (isset($params['priority'])) {
            $data['priority'] = absint($params['priority']);
        }
        if (isset($params['active'])) {
            $data['active'] = (int)(bool)$params['active'];
        }
        if ($this->engine_supports_targeting($engine) && isset($params['target_mode'])) {
            $data['target_mode'] = sanitize_text_field($params['target_mode']);
        }
        if ($this->engine_supports_targeting($engine) && isset($params['target_post_types'])) {
            $data['target_post_types'] = sanitize_text_field($params['target_post_types']);
        }
        if ($this->engine_supports_targeting($engine) && isset($params['target_post_ids'])) {
            $data['target_post_ids'] = sanitize_text_field($params['target_post_ids']);
        }

        $data['modified'] = current_time('mysql');
        $data = $this->prepare_data_for_engine($engine, $table, $data);

        if (empty($data)) {
            return new WP_Error('no_data', __('No fields to update', 'eliodata-snippet-hub'), ['status' => 400]);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->update($table, $data, ['id' => $id]);

        if (false === $result) {
            return new WP_Error('db_error', __('Could not update snippet:', 'eliodata-snippet-hub') . ' ' . $wpdb->last_error, ['status' => 500]);
        }

        $this->after_snippet_change($engine, $id);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $updated = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id)
        );

        return new WP_REST_Response($this->format_snippet($updated, $engine), 200);
    }

    // =========================================================================
    //  DELETE SNIPPET
    // =========================================================================

    /**
     * DELETE /ide/v1/snippets/{id}
     *
     * @since 1.0.0
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function delete_snippet(WP_REST_Request $request) {
        global $wpdb;

        $engine = $this->resolve_engine($request);
        if (!$this->ensure_engine_ready($engine)) {
            return new WP_Error('table_missing', __('Snippet storage is not available for selected engine.', 'eliodata-snippet-hub'), ['status' => 500]);
        }

        $id    = absint($request['id']);
        $table = $this->get_table_name_for_engine($engine);

        if (!$id) {
            return new WP_Error('invalid_id', __('Invalid snippet ID', 'eliodata-snippet-hub'), ['status' => 400]);
        }

        // Verify snippet exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare("SELECT id FROM `{$table}` WHERE id = %d", $id)
        );
        if (!$existing) {
            return new WP_Error('not_found', __('Snippet not found', 'eliodata-snippet-hub'), ['status' => 404]);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete($table, ['id' => $id], ['%d']);

        if (false === $result) {
            return new WP_Error('db_error', __('Could not delete snippet:', 'eliodata-snippet-hub') . ' ' . $wpdb->last_error, ['status' => 500]);
        }

        $this->after_snippet_change($engine, $id);
        if ($engine === 'native') {
            Eliodata_Snippet_Hub_Security::delete_runtime_files($id);
        }

        return new WP_REST_Response(['deleted' => true, 'id' => $id], 200);
    }
}
