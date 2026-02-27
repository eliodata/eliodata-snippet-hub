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
 * Main API class for IDE Snippets Bridge
 *
 * Provides secure REST API endpoints for communication
 * between WordPress Code Snippets and IDE extensions.
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

        // === Code Snippets endpoints ===
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
     * Get the snippets table name with proper prefix
     *
     * @since 1.0.0
     * @return string
     */
    private function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'snippets';
    }

    /**
     * Check if the Code Snippets table exists
     *
     * @since 1.2.0
     * @return bool
     */
    private function table_exists() {
        global $wpdb;
        $table = $this->get_table_name();
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
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

        // Tags: Code Snippets stores them as JSON-encoded array or serialized
        if (!empty($row->tags)) {
            $decoded = json_decode($row->tags, true);
            if (is_array($decoded)) {
                $row->tags = implode(', ', $decoded);
            }
            // If it's already a string, leave as-is
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
     * Returns bridge plugin info and checks Code Snippets availability.
     *
     * @since 1.2.0
     * @return WP_REST_Response
     */
    public function get_status(WP_REST_Request $request) {
        $cs_active     = is_plugin_active('code-snippets/code-snippets.php');
        $cs_pro_active = is_plugin_active('code-snippets-pro/code-snippets.php');
        $table_ok      = $this->table_exists();

        return new WP_REST_Response([
            'plugin'          => 'IDE Code Snippets Bridge',
            'version'         => defined('IDE_SNIPPETS_BRIDGE_VERSION') ? IDE_SNIPPETS_BRIDGE_VERSION : '1.2.0',
            'wordpress'       => get_bloginfo('version'),
            'php'             => PHP_VERSION,
            'code_snippets'   => $cs_active || $cs_pro_active,
            'table_exists'    => $table_ok,
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

        if (!$this->table_exists()) {
            return new WP_Error(
                'table_missing',
                'Code Snippets table not found. Is the Code Snippets plugin active?',
                ['status' => 500]
            );
        }

        $table  = $this->get_table_name();
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
            return new WP_Error('db_error', 'Database query failed', ['status' => 500]);
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

        $id    = absint($request['id']);
        $table = $this->get_table_name();

        if (!$id) {
            return new WP_Error('invalid_id', 'Invalid snippet ID', ['status' => 400]);
        }

        $snippet = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id)
        );

        if (!$snippet) {
            return new WP_Error('not_found', 'Snippet not found', ['status' => 404]);
        }

        return new WP_REST_Response($this->format_snippet($snippet), 200);
    }

    // =========================================================================
    //  CREATE SNIPPET
    // =========================================================================

    /**
     * POST /ide/v1/snippets
     *
     * Creates a new snippet in the Code Snippets table.
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

        $params = $request->get_json_params();
        $table  = $this->get_table_name();

        if (empty($params['name'])) {
            return new WP_Error('missing_name', 'Snippet name is required', ['status' => 400]);
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

        $result = $wpdb->insert($table, $data);

        if (false === $result) {
            return new WP_Error('db_error', 'Could not create snippet: ' . $wpdb->last_error, ['status' => 500]);
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

        $id     = absint($request['id']);
        $params = $request->get_json_params();
        $table  = $this->get_table_name();

        if (!$id) {
            return new WP_Error('invalid_id', 'Invalid snippet ID', ['status' => 400]);
        }

        // Verify snippet exists
        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT id FROM `{$table}` WHERE id = %d", $id)
        );
        if (!$existing) {
            return new WP_Error('not_found', 'Snippet not found', ['status' => 404]);
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

        // Always update modification timestamp
        $data['modified'] = current_time('mysql');

        if (empty($data) || (count($data) === 1 && isset($data['modified']))) {
            return new WP_Error('no_data', 'No fields to update', ['status' => 400]);
        }

        $result = $wpdb->update($table, $data, ['id' => $id]);

        if (false === $result) {
            return new WP_Error('db_error', 'Could not update snippet: ' . $wpdb->last_error, ['status' => 500]);
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

        $id    = absint($request['id']);
        $table = $this->get_table_name();

        if (!$id) {
            return new WP_Error('invalid_id', 'Invalid snippet ID', ['status' => 400]);
        }

        // Verify snippet exists
        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT id FROM `{$table}` WHERE id = %d", $id)
        );
        if (!$existing) {
            return new WP_Error('not_found', 'Snippet not found', ['status' => 404]);
        }

        $result = $wpdb->delete($table, ['id' => $id], ['%d']);

        if (false === $result) {
            return new WP_Error('db_error', 'Could not delete snippet: ' . $wpdb->last_error, ['status' => 500]);
        }

        return new WP_REST_Response(['deleted' => true, 'id' => $id], 200);
    }
}
