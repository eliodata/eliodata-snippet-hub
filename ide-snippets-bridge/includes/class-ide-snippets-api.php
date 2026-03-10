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
    protected $supported_engines = ['native'];

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
    public function check_permission() {
        return current_user_can('manage_options');
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

        $cached = wp_cache_get($cache_key, 'ide_snippets_bridge');
        if ($cached !== false) {
            return (bool) $cached;
        }

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
        wp_cache_set($cache_key, $exists ? 1 : 0, 'ide_snippets_bridge', 300);

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

    private function get_registered_engines() {
        $engines = [
            'native' => [
                'label' => 'IDE Snippets',
                'table' => $this->get_native_table_name(),
            ],
        ];
        $engines = apply_filters('ide_snippets_bridge_registered_engines', $engines, $this);
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
        $enabled = apply_filters('ide_snippets_bridge_enabled_engines', $this->supported_engines, $registered, $this);
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
        wp_cache_delete('table_exists:' . $this->get_native_table_name(), 'ide_snippets_bridge');

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

        return (bool) apply_filters('ide_snippets_bridge_engine_is_ready', false, $engine, $this->get_registered_engines(), $this);
    }

    private function engine_supports_targeting($engine) {
        if ($engine === 'native') {
            return true;
        }
        return (bool) apply_filters('ide_snippets_bridge_engine_supports_targeting', false, $engine, $this->get_registered_engines(), $this);
    }

    /**
     * Format a snippet row from DB into a clean response object
     *
     * @since 1.2.0
     * @param object $row Database row
     * @return object Formatted snippet
     */
    private function format_snippet($row) {
        if (!$row) {
            return null;
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
            'plugin'          => 'Eliodata Snippet Hub',
            'version'         => defined('IDE_SNIPPETS_BRIDGE_VERSION') ? IDE_SNIPPETS_BRIDGE_VERSION : '2.0.0',
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
            return new WP_Error('table_missing', __('IDE Snippets table not found.', 'ide-snippets-bridge'), ['status' => 500]);
        }

        $table  = $this->get_table_name_for_engine($engine);
        $status = $request->get_param('status');

        if ($status === 'active') {
            $results = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM `{$table}` WHERE active = %d ORDER BY id ASC", 1)
            );
        } elseif ($status === 'inactive') {
            $results = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM `{$table}` WHERE active = %d ORDER BY id ASC", 0)
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $results = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY id ASC");
        }

        if ($results === null) {
            return new WP_Error('db_error', __('Database query failed', 'ide-snippets-bridge'), ['status' => 500]);
        }

        $results = array_map([$this, 'format_snippet'], $results);

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
            return new WP_Error('table_missing', __('Snippet storage is not available for selected engine.', 'ide-snippets-bridge'), ['status' => 500]);
        }

        $id    = absint($request['id']);
        $table = $this->get_table_name_for_engine($engine);

        if (!$id) {
            return new WP_Error('invalid_id', __('Invalid snippet ID', 'ide-snippets-bridge'), ['status' => 400]);
        }

        $snippet = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id)
        );

        if (!$snippet) {
            return new WP_Error('not_found', __('Snippet not found', 'ide-snippets-bridge'), ['status' => 404]);
        }

        return new WP_REST_Response($this->format_snippet($snippet), 200);
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
            return new WP_Error('table_missing', __('Snippet storage is not available for selected engine.', 'ide-snippets-bridge'), ['status' => 500]);
        }

        $params = $request->get_json_params();
        $table  = $this->get_table_name_for_engine($engine);

        if (empty($params['name'])) {
            return new WP_Error('missing_name', __('Snippet name is required', 'ide-snippets-bridge'), ['status' => 400]);
        }

        $data = [
            'name'        => sanitize_text_field($params['name']),
            'description' => isset($params['description']) ? sanitize_textarea_field($params['description']) : '',
            'code'        => isset($params['code']) ? $params['code'] : '',  // Raw PHP — no kses!
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

        $result = $wpdb->insert($table, $data);

        if (false === $result) {
            return new WP_Error('db_error', __('Could not create snippet:', 'ide-snippets-bridge') . ' ' . $wpdb->last_error, ['status' => 500]);
        }

        $new_id      = $wpdb->insert_id;
        $new_snippet = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $new_id)
        );

        return new WP_REST_Response($this->format_snippet($new_snippet), 201);
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
            return new WP_Error('table_missing', __('Snippet storage is not available for selected engine.', 'ide-snippets-bridge'), ['status' => 500]);
        }

        $id     = absint($request['id']);
        $params = $request->get_json_params();
        $table  = $this->get_table_name_for_engine($engine);

        if (!$id) {
            return new WP_Error('invalid_id', __('Invalid snippet ID', 'ide-snippets-bridge'), ['status' => 400]);
        }

        // Verify snippet exists
        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT id FROM `{$table}` WHERE id = %d", $id)
        );
        if (!$existing) {
            return new WP_Error('not_found', __('Snippet not found', 'ide-snippets-bridge'), ['status' => 404]);
        }

        $data = [];

        if (isset($params['name'])) {
            $data['name'] = sanitize_text_field($params['name']);
        }
        if (isset($params['description'])) {
            $data['description'] = sanitize_textarea_field($params['description']);
        }
        if (isset($params['code'])) {
            // Raw PHP code — no wp_kses, no sanitize_text_field!
            $data['code'] = $params['code'];
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

        // Always update modification timestamp
        $data['modified'] = current_time('mysql');

        if (empty($data) || (count($data) === 1 && isset($data['modified']))) {
            return new WP_Error('no_data', __('No fields to update', 'ide-snippets-bridge'), ['status' => 400]);
        }

        $result = $wpdb->update($table, $data, ['id' => $id]);

        if (false === $result) {
            return new WP_Error('db_error', __('Could not update snippet:', 'ide-snippets-bridge') . ' ' . $wpdb->last_error, ['status' => 500]);
        }

        $updated = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id)
        );

        return new WP_REST_Response($this->format_snippet($updated), 200);
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
            return new WP_Error('table_missing', __('Snippet storage is not available for selected engine.', 'ide-snippets-bridge'), ['status' => 500]);
        }

        $id    = absint($request['id']);
        $table = $this->get_table_name_for_engine($engine);

        if (!$id) {
            return new WP_Error('invalid_id', __('Invalid snippet ID', 'ide-snippets-bridge'), ['status' => 400]);
        }

        // Verify snippet exists
        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT id FROM `{$table}` WHERE id = %d", $id)
        );
        if (!$existing) {
            return new WP_Error('not_found', __('Snippet not found', 'ide-snippets-bridge'), ['status' => 404]);
        }

        $result = $wpdb->delete($table, ['id' => $id], ['%d']);

        if (false === $result) {
            return new WP_Error('db_error', __('Could not delete snippet:', 'ide-snippets-bridge') . ' ' . $wpdb->last_error, ['status' => 500]);
        }

        return new WP_REST_Response(['deleted' => true, 'id' => $id], 200);
    }
}
