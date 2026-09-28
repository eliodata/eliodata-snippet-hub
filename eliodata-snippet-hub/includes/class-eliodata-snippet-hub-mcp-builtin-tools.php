<?php
if (!defined('ABSPATH')) {
    exit;
}

class Eliodata_Snippet_Hub_MCP_Builtin_Tools {
    public static function get_definitions() {
        return [
            [
                'name' => 'snippet_list',
                'description' => 'List snippets with optional status filter',
                'readOnlyHint' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => [
                            'type' => 'string',
                            'enum' => ['active', 'inactive', 'all'],
                        ],
                        'engine' => [
                            'type' => 'string',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'snippet_get',
                'description' => 'Get one snippet by id',
                'readOnlyHint' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => [
                        'id' => [
                            'type' => 'integer',
                            'minimum' => 1,
                        ],
                        'engine' => [
                            'type' => 'string',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'snippet_create',
                'description' => 'Create a snippet',
                'readOnlyHint' => false,
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['name', 'code'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'code' => ['type' => 'string'],
                        'tags' => ['type' => 'string'],
                        'scope' => ['type' => 'string'],
                        'priority' => ['type' => 'integer'],
                        'active' => ['type' => 'boolean'],
                        'target_mode' => ['type' => 'string'],
                        'target_post_types' => ['type' => 'string'],
                        'target_post_ids' => ['type' => 'string'],
                        'engine' => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'name' => 'snippet_update',
                'description' => 'Update a snippet by id',
                'readOnlyHint' => false,
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => [
                        'id' => ['type' => 'integer', 'minimum' => 1],
                        'name' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'code' => ['type' => 'string'],
                        'tags' => ['type' => 'string'],
                        'scope' => ['type' => 'string'],
                        'priority' => ['type' => 'integer'],
                        'active' => ['type' => 'boolean'],
                        'target_mode' => ['type' => 'string'],
                        'target_post_types' => ['type' => 'string'],
                        'target_post_ids' => ['type' => 'string'],
                        'engine' => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'name' => 'snippet_delete',
                'description' => 'Delete a snippet by id',
                'readOnlyHint' => false,
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => [
                        'id' => ['type' => 'integer', 'minimum' => 1],
                        'engine' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }
}
