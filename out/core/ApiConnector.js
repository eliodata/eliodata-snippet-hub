"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.ApiConnector = void 0;
const axios_1 = __importDefault(require("axios"));
class ApiConnector {
    constructor(apiUrl, username, applicationPassword) {
        this.timeout = 15000; // 15 seconds
        // The IDE uses the full catalog; a read-only application password is still filtered by the site
        this.mcpProfile = 'write';
        this.apiUrl = apiUrl.endsWith('/') ? apiUrl : apiUrl + '/';
        this.username = username;
        // Clean up application password by removing spaces that WordPress UI adds for readability
        this.applicationPassword = applicationPassword.replace(/\s+/g, '');
    }
    getAuthHeaders() {
        return {
            'Authorization': 'Basic ' + Buffer.from(this.username + ':' + this.applicationPassword).toString('base64'),
            'Content-Type': 'application/json'
        };
    }
    encodeSnippetPayload(data) {
        if (typeof data.code !== 'string' || data.code.length === 0) {
            return data;
        }
        const payload = { ...data };
        payload.code_b64 = Buffer.from(data.code, 'utf8').toString('base64');
        delete payload.code;
        return payload;
    }
    buildSnippetsUrl(id, status, engine) {
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
    async getSnippets(status = 'all', engine) {
        const url = this.buildSnippetsUrl(undefined, status, engine);
        const response = await axios_1.default.get(url, {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }
    async getSnippet(id, engine) {
        const response = await axios_1.default.get(this.buildSnippetsUrl(id, undefined, engine), {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }
    /**
     * Use the WordPress error message (for example a PHP syntax error) instead of the generic axios one.
     */
    withServerMessage(error) {
        const serverMessage = error?.response?.data?.message;
        return typeof serverMessage === 'string' && serverMessage !== '' ? new Error(serverMessage) : error;
    }
    async createSnippet(data, engine) {
        const payload = this.encodeSnippetPayload(engine ? { ...data, engine } : data);
        try {
            const response = await axios_1.default.post(`${this.apiUrl}wp-json/ide/v1/snippets`, payload, {
                headers: this.getAuthHeaders(),
                timeout: this.timeout
            });
            return response.data;
        }
        catch (error) {
            throw this.withServerMessage(error);
        }
    }
    async updateSnippet(id, data, engine) {
        const payload = this.encodeSnippetPayload(engine ? { ...data, engine } : data);
        try {
            const response = await axios_1.default.put(this.buildSnippetsUrl(id, undefined, engine), payload, {
                headers: this.getAuthHeaders(),
                timeout: this.timeout
            });
            return response.data;
        }
        catch (error) {
            throw this.withServerMessage(error);
        }
    }
    async deleteSnippet(id, engine) {
        const response = await axios_1.default.delete(this.buildSnippetsUrl(id, undefined, engine), {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }
    async getStatus() {
        const response = await axios_1.default.get(`${this.apiUrl}wp-json/ide/v1/status`, {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }
    async getMcpTools() {
        const response = await axios_1.default.get(`${this.apiUrl}wp-json/eliodata-snippet-hub/v1/mcp/tools?profile=${this.mcpProfile}`, {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }
    async callMcpTool(name, args = {}) {
        const normalizedArgs = ['snippet_create', 'snippet_update', 'snippet/create', 'snippet/update'].includes(name)
            ? this.encodeSnippetPayload(args)
            : args;
        const response = await axios_1.default.post(`${this.apiUrl}wp-json/eliodata-snippet-hub/v1/mcp/call?profile=${this.mcpProfile}`, {
            name,
            arguments: normalizedArgs
        }, {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }
    async getCustomMcpTools() {
        const response = await axios_1.default.get(`${this.apiUrl}wp-json/eliodata-snippet-hub/v1/mcp/custom-tools`, {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }
    async createCustomMcpTool(tool) {
        const response = await axios_1.default.post(`${this.apiUrl}wp-json/eliodata-snippet-hub/v1/mcp/custom-tools`, tool, {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }
    async updateCustomMcpTool(name, tool) {
        const response = await axios_1.default.put(`${this.apiUrl}wp-json/eliodata-snippet-hub/v1/mcp/custom-tools/${encodeURIComponent(name)}`, tool, {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }
    async deleteCustomMcpTool(name) {
        const response = await axios_1.default.delete(`${this.apiUrl}wp-json/eliodata-snippet-hub/v1/mcp/custom-tools/${encodeURIComponent(name)}`, {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }
}
exports.ApiConnector = ApiConnector;
//# sourceMappingURL=ApiConnector.js.map