<?php
if (!defined('ABSPATH')) {
    exit;
}

class Eliodata_Snippet_Hub_MCP_Legacy extends Eliodata_Snippet_Hub_MCP {
    public function __construct() {
        parent::__construct();
    }
}

class Eliodata_Snippet_Hub_MCP_Registry {
    public static function build_servers() {
        $servers = [
            new Eliodata_Snippet_Hub_MCP_Legacy(),
        ];

        $servers = apply_filters('eliodata_snippet_hub_mcp_servers', $servers);
        if (!is_array($servers)) {
            return [];
        }

        return array_values(array_filter($servers, static function ($server) {
            return $server instanceof Eliodata_Snippet_Hub_MCP;
        }));
    }
}
