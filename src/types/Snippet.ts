export interface Snippet {
    id: string | number;
    name: string;
    description: string;
    code: string;
    scope: string;
    active: boolean;
    created: string;
    modified: string;
    tags: string;
    target_mode?: 'all' | 'post_types' | 'specific_posts' | string;
    target_post_types?: string;
    target_post_ids?: string;
}

export interface SnippetCreateData {
    name: string;
    description: string;
    code: string;
    active?: boolean;
    tags?: string;
    target_mode?: 'all' | 'post_types' | 'specific_posts';
    target_post_types?: string;
    target_post_ids?: string;
}

export interface SnippetUpdateData extends SnippetCreateData {
    id: string | number;
}

export interface McpToolDefinition {
    name: string;
    description: string;
    readOnlyHint?: boolean;
    inputSchema?: Record<string, unknown>;
}

export interface McpToolsResponse {
    server: string;
    version: string;
    readonly: boolean;
    tools: McpToolDefinition[];
}

export interface McpCustomToolDefinition {
    name: string;
    description: string;
    readOnlyHint: boolean;
    method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    route: string;
    passAs: 'query' | 'json';
    inputSchema?: Record<string, unknown>;
}

export interface McpCustomToolsResponse {
    tools: McpCustomToolDefinition[];
}

export interface WordPressConnectionConfig {
    id: string;
    name: string;
    siteUrl: string;
    vaultSiteFolder?: string;
    username: string;
    applicationPassword: string;
    plugin: 'IDE Snippets' | 'IDE Native' | 'Code Snippets';
    mcpEnabled?: boolean;
    mcpReadonly?: boolean;
    mcpTools?: McpToolDefinition[];
    mcpLastSyncAt?: string;
    isActive?: boolean;
}

export interface MultiSiteConfig {
    connections: WordPressConnectionConfig[];
    activeConnectionId?: string;
}

export type WorkspaceSyncRole = 'owner' | 'editor' | 'off';
