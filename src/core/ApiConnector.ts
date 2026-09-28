import axios from 'axios';
import { McpToolsResponse, McpCustomToolDefinition, McpCustomToolsResponse } from '../types/Snippet';

export class ApiConnector {
    private apiUrl: string;
    private username: string;
    private applicationPassword: string;
    private readonly timeout = 15000; // 15 seconds

    constructor(apiUrl: string, username: string, applicationPassword: string) {
        this.apiUrl = apiUrl.endsWith('/') ? apiUrl : apiUrl + '/';
        this.username = username;
        // Clean up application password by removing spaces that WordPress UI adds for readability
        this.applicationPassword = applicationPassword.replace(/\s+/g, '');
    }

    private getAuthHeaders() {
        return {
            'Authorization': 'Basic ' + Buffer.from(this.username + ':' + this.applicationPassword).toString('base64'),
            'Content-Type': 'application/json'
        };
    }

    private encodeSnippetPayload<T extends Record<string, any>>(data: T): T {
        if (typeof data.code !== 'string' || data.code.length === 0) {
            return data;
        }

        const payload = { ...data } as T & { code_b64?: string };
        payload.code_b64 = Buffer.from(data.code, 'utf8').toString('base64');
        delete payload.code;
        return payload;
    }

    private buildSnippetsUrl(id?: number | string, status?: 'all' | 'active' | 'inactive', engine?: 'native' | 'code_snippets') {
        const suffix = (id !== undefined && id !== null && id !== '') ? `/${id}` : '';
        const url = new URL(`${this.apiUrl}wp-json/ide/v1/snippets${suffix}`);
        if (status && status !== 'all') {
            url.searchParams.set('status', status);
        }
        if (engine) {
            url.searchParams.set('engine', engine);
        }
        return url.toString();
    }

    public async getSnippets(status: 'all' | 'active' | 'inactive' = 'all', engine?: 'native' | 'code_snippets') {
        const url = this.buildSnippetsUrl(undefined, status, engine);
        const response = await axios.get(url, {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }

    public async getSnippet(id: number | string, engine?: 'native' | 'code_snippets') {
        const response = await axios.get(this.buildSnippetsUrl(id, undefined, engine), {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }

    /**
     * Use the WordPress error message (for example a PHP syntax error) instead of the generic axios one.
     */
    private withServerMessage(error: any): Error {
        const serverMessage = error?.response?.data?.message;
        return typeof serverMessage === 'string' && serverMessage !== '' ? new Error(serverMessage) : error;
    }

    public async createSnippet(data: any, engine?: 'native' | 'code_snippets') {
        const payload = this.encodeSnippetPayload(engine ? { ...data, engine } : data);
        try {
            const response = await axios.post(`${this.apiUrl}wp-json/ide/v1/snippets`, payload, {
                headers: this.getAuthHeaders(),
                timeout: this.timeout
            });
            return response.data;
        } catch (error: any) {
            throw this.withServerMessage(error);
        }
    }

    public async updateSnippet(id: number | string, data: any, engine?: 'native' | 'code_snippets') {
        const payload = this.encodeSnippetPayload(engine ? { ...data, engine } : data);
        try {
            const response = await axios.put(this.buildSnippetsUrl(id, undefined, engine), payload, {
                headers: this.getAuthHeaders(),
                timeout: this.timeout
            });
            return response.data;
        } catch (error: any) {
            throw this.withServerMessage(error);
        }
    }

    public async deleteSnippet(id: number | string, engine?: 'native' | 'code_snippets') {
        const response = await axios.delete(this.buildSnippetsUrl(id, undefined, engine), {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }

    public async getStatus() {
        const response = await axios.get(`${this.apiUrl}wp-json/ide/v1/status`, {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }

    // The IDE uses the full catalog; a read-only application password is still filtered by the site
    private readonly mcpProfile = 'write';

    public async getMcpTools(): Promise<McpToolsResponse> {
        const response = await axios.get(`${this.apiUrl}wp-json/eliodata-snippet-hub/v1/mcp/tools?profile=${this.mcpProfile}`, {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }

    public async callMcpTool(name: string, args: Record<string, unknown> = {}) {
        const normalizedArgs = ['snippet_create', 'snippet_update', 'snippet/create', 'snippet/update'].includes(name)
            ? this.encodeSnippetPayload(args)
            : args;
        const response = await axios.post(
            `${this.apiUrl}wp-json/eliodata-snippet-hub/v1/mcp/call?profile=${this.mcpProfile}`,
            {
                name,
                arguments: normalizedArgs
            },
            {
                headers: this.getAuthHeaders(),
                timeout: this.timeout
            }
        );
        return response.data;
    }

    public async getCustomMcpTools(): Promise<McpCustomToolsResponse> {
        const response = await axios.get(`${this.apiUrl}wp-json/eliodata-snippet-hub/v1/mcp/custom-tools`, {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }

    public async createCustomMcpTool(tool: McpCustomToolDefinition) {
        const response = await axios.post(`${this.apiUrl}wp-json/eliodata-snippet-hub/v1/mcp/custom-tools`, tool, {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }

    public async updateCustomMcpTool(name: string, tool: Omit<McpCustomToolDefinition, 'name'>) {
        const response = await axios.put(
            `${this.apiUrl}wp-json/eliodata-snippet-hub/v1/mcp/custom-tools/${encodeURIComponent(name)}`,
            tool,
            {
                headers: this.getAuthHeaders(),
                timeout: this.timeout
            }
        );
        return response.data;
    }

    public async deleteCustomMcpTool(name: string) {
        const response = await axios.delete(
            `${this.apiUrl}wp-json/eliodata-snippet-hub/v1/mcp/custom-tools/${encodeURIComponent(name)}`,
            {
                headers: this.getAuthHeaders(),
                timeout: this.timeout
            }
        );
        return response.data;
    }
}
