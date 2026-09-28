<?php
if (!defined('ABSPATH')) {
    exit;
}

class Eliodata_Snippet_Hub_MCP {
    protected $namespace = 'eliodata-snippet-hub/v1';
    protected $custom_tools_option_key = 'eliodata_snippet_hub_mcp_custom_tools';
    protected $server_key = 'legacy';
    protected $server_name = 'eliodata-snippet-hub';
    protected $allow_custom_tools = true;
    protected $builtin_tools_cache = null;
    protected $builtin_tool_map_cache = null;

    public function __construct(array $config = []) {
        if (!empty($config['namespace']) && is_string($config['namespace'])) {
            $this->namespace = trim($config['namespace']);
        }

        if (!empty($config['custom_tools_option_key']) && is_string($config['custom_tools_option_key'])) {
            $this->custom_tools_option_key = trim($config['custom_tools_option_key']);
        }

        if (!empty($config['server_key']) && is_string($config['server_key'])) {
            $this->server_key = $this->normalize_server_key($config['server_key']);
        }

        if (!empty($config['server_name']) && is_string($config['server_name'])) {
            $this->server_name = sanitize_key($config['server_name']);
        }

        if (array_key_exists('allow_custom_tools', $config)) {
            $this->allow_custom_tools = (bool) $config['allow_custom_tools'];
        }
    }

    public function register_routes() {
        register_rest_route($this->namespace, '/mcp/tools', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_tools'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/mcp/call', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'call_tool'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        if ($this->allow_custom_tools) {
            register_rest_route($this->namespace, '/mcp/custom-tools', [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_custom_tools'],
                    'permission_callback' => [$this, 'check_permission'],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'create_custom_tool'],
                    'permission_callback' => [$this, 'check_permission'],
                ],
            ]);

            register_rest_route($this->namespace, '/mcp/custom-tools/(?P<name>[a-z0-9._-]+)', [
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [$this, 'update_custom_tool'],
                    'permission_callback' => [$this, 'check_permission'],
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [$this, 'delete_custom_tool'],
                    'permission_callback' => [$this, 'check_permission'],
                ],
            ]);
        }
    }

    public function check_permission() {
        $capability = apply_filters('eliodata_snippet_hub_mcp_capability', 'eliodata_snippet_hub_manage');
        if (!is_string($capability) || $capability === '') {
            $capability = 'eliodata_snippet_hub_manage';
        } else {
            $capability = sanitize_key($capability);
        }

        if ($capability && current_user_can($capability)) {
            return true;
        }

        return Eliodata_Snippet_Hub_Security::current_user_can_manage();
    }

    public function get_tools(WP_REST_Request $request) {
        $readonly = Eliodata_Snippet_Hub_Security::is_readonly_request();
        $profile = $this->resolve_tool_profile($request);
        $builtin_tools = $this->get_builtin_tools_for_profile($profile);
        $custom_tools = $this->get_custom_tools_for_profile($profile);
        $tools = array_merge($builtin_tools, $custom_tools);

        if ($readonly) {
            $tools = array_values(array_filter($tools, function ($tool) {
                return $this->is_tool_allowed_for_readonly($tool);
            }));
        }

        return new WP_REST_Response([
            'server' => $this->server_name,
            'server_key' => $this->server_key,
            'version' => defined('ELIODATA_SNIPPET_HUB_VERSION') ? ELIODATA_SNIPPET_HUB_VERSION : '4.2.0',
            'readonly' => $readonly,
            'profile' => $profile,
            'available_profiles' => $this->get_available_profiles(),
            'builtin_count' => count($builtin_tools),
            'custom_count' => count($custom_tools),
            'tools' => $tools,
        ], 200);
    }

    private function normalize_server_key($server_key) {
        $normalized = sanitize_key((string) $server_key);
        if (!in_array($normalized, ['legacy'], true)) {
            return 'legacy';
        }

        return $normalized;
    }

    private function get_server_profile() {
        return Eliodata_Snippet_Hub_MCP_Tool_Provider::get_locked_profile($this->server_key);
    }

    private function get_available_profiles() {
        return Eliodata_Snippet_Hub_MCP_Tool_Provider::get_available_profiles($this->server_key);
    }

    private function resolve_tool_profile(WP_REST_Request $request) {
        $server_profile = $this->get_server_profile();
        if ($server_profile !== null) {
            return $server_profile;
        }

        $profile = isset($request['profile']) ? sanitize_key((string) $request['profile']) : '';
        if ($profile === '') {
            $profile = apply_filters('eliodata_snippet_hub_mcp_default_profile', 'core', $request);
        }

        return Eliodata_Snippet_Hub_MCP_Tool_Provider::normalize_profile($this->server_key, $profile);
    }

    private function get_builtin_tools_for_profile($profile) {
        return $this->filter_builtin_tools_by_names(
            $this->get_allowed_builtin_tool_names_for_profile($profile)
        );
    }

    private function get_custom_tools_for_profile($profile) {
        if (!$this->allow_custom_tools) {
            return [];
        }

        $tools = $this->read_custom_tools();
        if ($profile === 'write') {
            return $tools;
        }

        // The read profile only lists custom tools that cannot change anything
        return array_values(array_filter($tools, function ($tool) {
            return $this->is_tool_allowed_for_readonly($tool);
        }));
    }

    private function filter_builtin_tools_by_names(array $allowed_names) {
        $tool_map = $this->get_builtin_tool_map();
        $filtered = [];

        foreach ($allowed_names as $name) {
            if (isset($tool_map[$name])) {
                $filtered[] = $tool_map[$name];
            }
        }

        return $filtered;
    }

    public function get_custom_tools(WP_REST_Request $_request) {
        return new WP_REST_Response([
            'tools' => $this->read_custom_tools(),
        ], 200);
    }

    public function create_custom_tool(WP_REST_Request $request) {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }

        $sanitized = $this->sanitize_custom_tool_payload($params);
        if (is_wp_error($sanitized)) {
            return $sanitized;
        }

        if ($this->is_builtin_tool_name($sanitized['name'])) {
            return new WP_Error('tool_name_reserved', __('This tool name is reserved by built-in MCP tools.', 'eliodata-snippet-hub'), ['status' => 409]);
        }

        $tools = $this->read_custom_tools();
        foreach ($tools as $tool) {
            if (!empty($tool['name']) && $tool['name'] === $sanitized['name']) {
                return new WP_Error('tool_name_exists', __('A custom tool with this name already exists.', 'eliodata-snippet-hub'), ['status' => 409]);
            }
        }

        $tools[] = $sanitized;
        $this->write_custom_tools($tools);

        return new WP_REST_Response([
            'ok' => true,
            'tool' => $sanitized,
        ], 201);
    }

    public function update_custom_tool(WP_REST_Request $request) {
        $name = isset($request['name']) ? sanitize_text_field((string) $request['name']) : '';
        if ($name === '') {
            return new WP_Error('missing_tool_name', __('Tool name is required.', 'eliodata-snippet-hub'), ['status' => 400]);
        }

        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }
        $params['name'] = $name;

        $sanitized = $this->sanitize_custom_tool_payload($params);
        if (is_wp_error($sanitized)) {
            return $sanitized;
        }

        $tools = $this->read_custom_tools();
        $updated = false;
        foreach ($tools as $index => $tool) {
            if (!empty($tool['name']) && $tool['name'] === $name) {
                $tools[$index] = $sanitized;
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            return new WP_Error('tool_not_found', __('Custom tool not found.', 'eliodata-snippet-hub'), ['status' => 404]);
        }

        $this->write_custom_tools($tools);
        return new WP_REST_Response([
            'ok' => true,
            'tool' => $sanitized,
        ], 200);
    }

    public function delete_custom_tool(WP_REST_Request $request) {
        $name = isset($request['name']) ? sanitize_text_field((string) $request['name']) : '';
        if ($name === '') {
            return new WP_Error('missing_tool_name', __('Tool name is required.', 'eliodata-snippet-hub'), ['status' => 400]);
        }

        $tools = $this->read_custom_tools();
        $remaining = array_values(array_filter($tools, static function ($tool) use ($name) {
            return empty($tool['name']) || $tool['name'] !== $name;
        }));

        if (count($remaining) === count($tools)) {
            return new WP_Error('tool_not_found', __('Custom tool not found.', 'eliodata-snippet-hub'), ['status' => 404]);
        }

        $this->write_custom_tools($remaining);
        return new WP_REST_Response(['ok' => true], 200);
    }

    public function call_tool(WP_REST_Request $request) {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }

        $tool_name = isset($params['name']) ? sanitize_text_field((string) $params['name']) : '';
        $arguments = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];
        $readonly = Eliodata_Snippet_Hub_Security::is_readonly_request();
        $profile = $this->resolve_tool_profile($request);
        if ($tool_name === '') {
            return new WP_Error('missing_tool_name', __('Tool name is required.', 'eliodata-snippet-hub'), ['status' => 400]);
        }

        $builtin_tool = $this->get_builtin_tool_definition($tool_name);
        if ($builtin_tool !== null) {
            if (!$this->is_builtin_tool_allowed_for_profile($tool_name, $profile)) {
                return new WP_Error('unknown_tool', __('This MCP tool is not available for the current server/profile.', 'eliodata-snippet-hub'), ['status' => 404]);
            }

            if ($readonly && empty($builtin_tool['readOnlyHint'])) {
                return new WP_Error('readonly_forbidden', __('This application password is read-only for MCP write tools.', 'eliodata-snippet-hub'), ['status' => 403]);
            }

            return $this->call_builtin_tool($tool_name, $arguments);
        }

        // Block legacy aliases or hidden dispatcher routes that are not part of the exposed builtin catalog.
        if ($this->has_builtin_route($tool_name)) {
            return new WP_Error('unknown_tool', __('Unknown MCP tool name.', 'eliodata-snippet-hub'), ['status' => 404]);
        }

        if (!$this->allow_custom_tools) {
            return new WP_Error('unknown_tool', __('Unknown MCP tool name.', 'eliodata-snippet-hub'), ['status' => 404]);
        }

        $custom_tool = $this->find_custom_tool($tool_name);
        if (!$custom_tool) {
            return new WP_Error('unknown_tool', __('Unknown MCP tool name.', 'eliodata-snippet-hub'), ['status' => 404]);
        }

        if ($profile !== 'write' && !$this->is_tool_allowed_for_readonly($custom_tool)) {
            return new WP_Error('unknown_tool', __('This MCP tool is not available for the current server/profile.', 'eliodata-snippet-hub'), ['status' => 404]);
        }

        if ($readonly && !$this->is_tool_allowed_for_readonly($custom_tool)) {
            return new WP_Error('readonly_forbidden', __('This application password is read-only for MCP write tools.', 'eliodata-snippet-hub'), ['status' => 403]);
        }

        return $this->dispatch_custom_tool($custom_tool, $arguments);
    }

    private function dispatch_to_snippet_api($method, $route, array $arguments, $json_body) {
        if (strpos($route, '/ide/v1/snippets/0') === 0) {
            return new WP_Error('invalid_id', __('Valid snippet id is required.', 'eliodata-snippet-hub'), ['status' => 400]);
        }

        return $this->dispatch_rest_request($method, $route, $arguments, $json_body, $route);
    }

    private function dispatch_rest_request($method, $route, array $arguments, $json_body, $tool_name) {
        if (!is_string($route) || $route === '' || $route[0] !== '/') {
            return new WP_Error('invalid_route', __('A valid REST route is required.', 'eliodata-snippet-hub'), ['status' => 400]);
        }

        $request = new WP_REST_Request($method, $route);
        if ($json_body) {
            $request->set_header('content-type', 'application/json');
            $request->set_body(wp_json_encode($arguments));
        } else {
            foreach ($arguments as $key => $value) {
                $request->set_param($key, $value);
            }
        }

        $response = rest_do_request($request);
        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int) $response->get_status();
        $data = $response->get_data();
        if ($response->is_error()) {
            $error_code = is_array($data) && isset($data['code']) ? (string) $data['code'] : 'mcp_tool_failed';
            $message = is_array($data) && isset($data['message']) ? (string) $data['message'] : __('MCP tool execution failed.', 'eliodata-snippet-hub');
            return new WP_Error($error_code, $message, ['status' => $status, 'details' => $data]);
        }

        return new WP_REST_Response([
            'tool' => $tool_name,
            'ok' => true,
            'result' => $data,
        ], $status);
    }

    private function get_builtin_tools() {
        if (!is_array($this->builtin_tools_cache)) {
            $this->builtin_tools_cache = Eliodata_Snippet_Hub_MCP_Builtin_Tools::get_definitions();
        }

        return $this->builtin_tools_cache;
    }

    private function get_builtin_tool_map() {
        if (!is_array($this->builtin_tool_map_cache)) {
            $this->builtin_tool_map_cache = [];
            foreach ($this->get_builtin_tools() as $tool) {
                $name = isset($tool['name']) ? (string) $tool['name'] : '';
                if ($name === '') {
                    continue;
                }
                $this->builtin_tool_map_cache[$name] = $tool;
            }
        }

        return $this->builtin_tool_map_cache;
    }

    private function get_builtin_tool_definition($name) {
        $tool_map = $this->get_builtin_tool_map();
        return isset($tool_map[$name]) ? $tool_map[$name] : null;
    }

    private function get_allowed_builtin_tool_names_for_profile($profile) {
        return Eliodata_Snippet_Hub_MCP_Tool_Provider::get_allowed_builtin_tool_names($this->server_key, $profile);
    }

    private function has_builtin_route($name) {
        return is_array(Eliodata_Snippet_Hub_MCP_Builtin_Dispatcher::get_route($name));
    }

    private function call_builtin_tool($tool_name, array $arguments) {
        $route = Eliodata_Snippet_Hub_MCP_Builtin_Dispatcher::get_route($tool_name);
        if (!is_array($route)) {
            return null;
        }

        if (!empty($route['argument_overrides']) && is_array($route['argument_overrides'])) {
            $arguments = array_merge($arguments, $route['argument_overrides']);
        }

        $route_type = isset($route['type']) ? (string) $route['type'] : '';
        if ($route_type === 'snippet_api') {
            return $this->execute_builtin_snippet_api_route($route, $arguments);
        }

        if ($route_type === 'handler') {
            return $this->execute_builtin_handler_route($tool_name, $route, $arguments);
        }

        if ($route_type === 'ajax') {
            return $this->execute_builtin_ajax_route($tool_name, $route, $arguments);
        }

        return null;
    }

    private function execute_builtin_ajax_route($tool_name, array $route, array $arguments) {
        $path = isset($route['path']) ? (string) $route['path'] : '';
        if ($path === '') {
            return new WP_Error('invalid_builtin_route', __('Invalid MCP builtin AJAX route path.', 'eliodata-snippet-hub'), ['status' => 500]);
        }

        $http_method = isset($route['http_method']) ? strtoupper((string) $route['http_method']) : 'POST';
        
        // Simuler la requête WordPress vers admin-ajax.php
        $url = admin_url('admin-ajax.php');
        
        // Extraire l'action de l'URL
        $parsed_url = wp_parse_url($path);
        $action = '';
        if (isset($parsed_url['query'])) {
            wp_parse_str($parsed_url['query'], $query_args);
            $action = isset($query_args['action']) ? $query_args['action'] : '';
        }
        
        if ($action === '') {
            return new WP_Error('invalid_builtin_route', __('Missing action in AJAX route path.', 'eliodata-snippet-hub'), ['status' => 500]);
        }

        $args = [
            'method' => $http_method,
            'headers' => isset($route['headers']) ? $route['headers'] : [],
            'timeout' => 45, // Laisser le temps à l'OCR / lecture base64
        ];

        if ($http_method === 'POST') {
            $args['body'] = array_merge(['action' => $action], $arguments);
        } else {
            $url = add_query_arg(array_merge(['action' => $action], $arguments), $url);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_json', __('Invalid JSON response from AJAX endpoint.', 'eliodata-snippet-hub'), ['status' => 500, 'raw_body' => $body]);
        }

        if ($status >= 400 || (isset($data['success']) && $data['success'] === false)) {
            $error_message = isset($data['data']) ? $data['data'] : __('AJAX request failed.', 'eliodata-snippet-hub');
            return new WP_Error('ajax_error', is_string($error_message) ? $error_message : wp_json_encode($error_message), ['status' => $status >= 400 ? $status : 400]);
        }

        return new WP_REST_Response([
            'tool' => $tool_name,
            'ok' => true,
            'result' => isset($data['data']) ? $data['data'] : $data,
        ], 200);
    }

    private function execute_builtin_snippet_api_route(array $route, array $arguments) {
        $http_method = isset($route['http_method']) ? (string) $route['http_method'] : 'GET';
        $path = isset($route['path']) ? (string) $route['path'] : '';
        if ($path === '' && !empty($route['path_template']) && !empty($route['path_argument'])) {
            $path = sprintf((string) $route['path_template'], absint($arguments[(string) $route['path_argument']] ?? 0));
        }
        if ($path === '') {
            return new WP_Error('invalid_builtin_route', __('Invalid MCP builtin route path.', 'eliodata-snippet-hub'), ['status' => 500]);
        }

        $filter_method = isset($route['arguments_filter']) ? (string) $route['arguments_filter'] : '';
        if ($filter_method === '' || !method_exists($this, $filter_method)) {
            return new WP_Error('invalid_builtin_route', __('Invalid MCP builtin route arguments filter.', 'eliodata-snippet-hub'), ['status' => 500]);
        }

        $payload = $this->{$filter_method}($arguments);
        $mutates = !empty($route['mutates']);

        return $this->dispatch_to_snippet_api($http_method, $path, $payload, $mutates);
    }

    private function execute_builtin_handler_route($tool_name, array $route, array $arguments) {
        $handler = isset($route['handler']) ? (string) $route['handler'] : '';
        if ($handler === '' || !method_exists($this, $handler)) {
            return new WP_Error('invalid_builtin_route', sprintf(__('Missing builtin handler for %s.', 'eliodata-snippet-hub'), $tool_name), ['status' => 500]);
        }

        return $this->{$handler}($arguments);
    }

    private function dispatch_custom_tool(array $tool, array $arguments) {
        $method = isset($tool['method']) ? strtoupper((string) $tool['method']) : 'GET';
        $pass_as = isset($tool['passAs']) ? (string) $tool['passAs'] : 'query';
        $raw_route = isset($tool['route']) ? (string) $tool['route'] : '';
        $route = $this->normalize_custom_route($raw_route);

        if ($route === '') {
            return new WP_Error('invalid_custom_route', __('Custom MCP tool route must start with /wp-json/.', 'eliodata-snippet-hub'), ['status' => 400]);
        }

        $json_body = $pass_as === 'json' && !in_array($method, ['GET', 'DELETE'], true);
        return $this->dispatch_rest_request($method, $route, $arguments, $json_body, (string) $tool['name']);
    }

    private function normalize_custom_route($route) {
        if (!is_string($route) || $route === '') {
            return '';
        }
        if (strpos($route, '/wp-json/') !== 0) {
            return '';
        }
        $normalized = '/' . ltrim(substr($route, 8), '/');
        if ($normalized === '/') {
            return '';
        }
        return $normalized;
    }

    /**
     * readOnlyHint is declared by the tool author, so a custom tool also has to
     * use GET to be callable with a read-only application password.
     */
    private function is_tool_allowed_for_readonly(array $tool) {
        if (empty($tool['readOnlyHint'])) {
            return false;
        }
        if (!empty($tool['route'])) {
            return isset($tool['method']) && strtoupper((string) $tool['method']) === 'GET';
        }
        return true;
    }

    private function is_builtin_tool_name($name) {
        return $this->get_builtin_tool_definition($name) !== null;
    }

    private function is_builtin_tool_allowed_for_profile($name, $profile) {
        return in_array($name, $this->get_allowed_builtin_tool_names_for_profile($profile), true);
    }

    private function find_custom_tool($name) {
        $tools = $this->read_custom_tools();
        foreach ($tools as $tool) {
            if (!empty($tool['name']) && $tool['name'] === $name) {
                return $tool;
            }
        }
        return null;
    }

    private function read_custom_tools() {
        $raw = get_option($this->custom_tools_option_key, []);
        if (!is_array($raw)) {
            return [];
        }

        $tools = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sanitized = $this->sanitize_custom_tool_payload($item);
            if (is_wp_error($sanitized)) {
                continue;
            }
            $tools[] = $sanitized;
        }
        return $tools;
    }

    private function write_custom_tools(array $tools) {
        update_option($this->custom_tools_option_key, array_values($tools), false);
    }

    private function sanitize_custom_tool_payload(array $payload) {
        $name = isset($payload['name']) ? sanitize_text_field((string) $payload['name']) : '';
        $name = strtolower($name);
        $name = str_replace('/', '_', $name);
        if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9._-]{2,127}$/', $name)) {
            return new WP_Error('invalid_tool_name', __('Tool name must match [a-z0-9._-] and be at least 3 chars.', 'eliodata-snippet-hub'), ['status' => 400]);
        }

        $description = isset($payload['description']) ? sanitize_textarea_field((string) $payload['description']) : '';
        if ($description === '') {
            return new WP_Error('invalid_description', __('Tool description is required.', 'eliodata-snippet-hub'), ['status' => 400]);
        }

        $method = isset($payload['method']) ? strtoupper(sanitize_text_field((string) $payload['method'])) : 'GET';
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return new WP_Error('invalid_method', __('Tool method must be GET, POST, PUT, PATCH or DELETE.', 'eliodata-snippet-hub'), ['status' => 400]);
        }

        $route = isset($payload['route']) ? sanitize_text_field((string) $payload['route']) : '';
        if ($this->normalize_custom_route($route) === '') {
            return new WP_Error('invalid_route', __('Route must start with /wp-json/.', 'eliodata-snippet-hub'), ['status' => 400]);
        }

        $pass_as = isset($payload['passAs']) ? sanitize_text_field((string) $payload['passAs']) : ($method === 'GET' ? 'query' : 'json');
        if (!in_array($pass_as, ['query', 'json'], true)) {
            return new WP_Error('invalid_pass_mode', __('passAs must be query or json.', 'eliodata-snippet-hub'), ['status' => 400]);
        }

        $input_schema = [];
        if (isset($payload['inputSchema']) && is_array($payload['inputSchema'])) {
            $input_schema = $payload['inputSchema'];
        }

        return [
            'name' => $name,
            'description' => $description,
            'readOnlyHint' => !empty($payload['readOnlyHint']),
            'method' => $method,
            'route' => $route,
            'passAs' => $pass_as,
            'inputSchema' => $input_schema,
        ];
    }

    private function filter_list_arguments(array $arguments) {
        $payload = [];
        if (isset($arguments['status']) && in_array($arguments['status'], ['active', 'inactive', 'all'], true)) {
            if ($arguments['status'] !== 'all') {
                $payload['status'] = $arguments['status'];
            }
        }
        if (isset($arguments['engine']) && is_string($arguments['engine']) && $arguments['engine'] !== '') {
            $payload['engine'] = sanitize_text_field($arguments['engine']);
        }
        return $payload;
    }

    private function filter_engine_argument(array $arguments) {
        $payload = [];
        if (isset($arguments['engine']) && is_string($arguments['engine']) && $arguments['engine'] !== '') {
            $payload['engine'] = sanitize_text_field($arguments['engine']);
        }
        return $payload;
    }

    private function filter_create_arguments(array $arguments) {
        $payload = [];
        $text_fields = ['name', 'description', 'tags', 'scope', 'target_mode', 'target_post_types', 'target_post_ids', 'engine'];
        foreach ($text_fields as $field) {
            if (isset($arguments[$field]) && is_string($arguments[$field])) {
                $payload[$field] = $arguments[$field];
            }
        }
        if (isset($arguments['code_b64']) && is_string($arguments['code_b64'])) {
            $payload['code_b64'] = $arguments['code_b64'];
        } elseif (isset($arguments['code']) && is_string($arguments['code'])) {
            $payload['code'] = $arguments['code'];
        }
        if (isset($arguments['priority'])) {
            $payload['priority'] = (int) $arguments['priority'];
        }
        if (isset($arguments['active'])) {
            $payload['active'] = (bool) $arguments['active'];
        }
        return $payload;
    }

    private function filter_update_arguments(array $arguments) {
        $payload = $this->filter_create_arguments($arguments);
        $payload['id'] = absint($arguments['id'] ?? 0);
        return $payload;
    }
}
