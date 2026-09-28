<?php
if (!defined('ABSPATH')) {
    exit;
}

class Eliodata_Snippet_Hub_MCP_Builtin_Dispatcher {
    public static function get_route($tool_name) {
        $routes = [
            'snippet_list' => [
                'type' => 'snippet_api',
                'http_method' => 'GET',
                'path' => '/ide/v1/snippets',
                'arguments_filter' => 'filter_list_arguments',
                'mutates' => false,
            ],
            'snippet_get' => [
                'type' => 'snippet_api',
                'http_method' => 'GET',
                'path_template' => '/ide/v1/snippets/%d',
                'path_argument' => 'id',
                'arguments_filter' => 'filter_engine_argument',
                'mutates' => false,
            ],
            'snippet_create' => [
                'type' => 'snippet_api',
                'http_method' => 'POST',
                'path' => '/ide/v1/snippets',
                'arguments_filter' => 'filter_create_arguments',
                'mutates' => true,
            ],
            'snippet_update' => [
                'type' => 'snippet_api',
                'http_method' => 'PUT',
                'path_template' => '/ide/v1/snippets/%d',
                'path_argument' => 'id',
                'arguments_filter' => 'filter_update_arguments',
                'mutates' => true,
            ],
            'snippet_delete' => [
                'type' => 'snippet_api',
                'http_method' => 'DELETE',
                'path_template' => '/ide/v1/snippets/%d',
                'path_argument' => 'id',
                'arguments_filter' => 'filter_engine_argument',
                'mutates' => false,
            ],
        ];

        return isset($routes[$tool_name]) ? $routes[$tool_name] : null;
    }
}
