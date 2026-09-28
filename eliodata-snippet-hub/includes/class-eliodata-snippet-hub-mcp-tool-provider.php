<?php
if (!defined('ABSPATH')) {
    exit;
}

class Eliodata_Snippet_Hub_MCP_Tool_Provider {
    public static function get_locked_profile($server_key) {
        return null;
    }

    public static function get_available_profiles($server_key) {
        return ['read', 'write'];
    }

    public static function normalize_profile($server_key, $profile) {
        if (!in_array($profile, self::get_available_profiles($server_key), true)) {
            return 'read';
        }

        return $profile;
    }

    public static function get_allowed_builtin_tool_names($server_key, $profile) {
        if ($profile === 'write') {
            return self::get_write_builtin_tool_names();
        }

        return self::get_read_builtin_tool_names();
    }

    public static function get_server_builtin_tool_names($server_key) {
        return self::get_allowed_builtin_tool_names($server_key, 'write');
    }

    private static function get_read_builtin_tool_names() {
        return [
            'snippet_list',
            'snippet_get',
        ];
    }

    private static function get_write_builtin_tool_names() {
        return [
            'snippet_list',
            'snippet_get',
            'snippet_create',
            'snippet_update',
            'snippet_delete',
        ];
    }
}
