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
    buildSnippetsUrl(id, status, engine) {
        const suffix = typeof id === 'number' ? `/${id}` : '';
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
    async createSnippet(data, engine) {
        const payload = engine ? { ...data, engine } : data;
        const response = await axios_1.default.post(`${this.apiUrl}wp-json/ide/v1/snippets`, payload, {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }
    async updateSnippet(id, data, engine) {
        const payload = engine ? { ...data, engine } : data;
        const response = await axios_1.default.put(this.buildSnippetsUrl(id, undefined, engine), payload, {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
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
    async getFluentSnippets() {
        const response = await axios_1.default.get(`${this.apiUrl}wp-json/ide/v1/fluent-snippets`, {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }
    async updateFluentSnippet(id, data) {
        const response = await axios_1.default.put(`${this.apiUrl}wp-json/ide/v1/fluent-snippets/${id}`, data, {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }
    async toggleFluentSnippet(id, active) {
        const url = `${this.apiUrl}wp-json/ide/v1/fluent-snippets/${id}/toggle`;
        const response = await axios_1.default.put(url, { active: active }, {
            headers: this.getAuthHeaders(),
            timeout: this.timeout
        });
        return response.data;
    }
}
exports.ApiConnector = ApiConnector;
//# sourceMappingURL=ApiConnector.js.map