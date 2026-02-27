"use strict";
var __awaiter = (this && this.__awaiter) || function (thisArg, _arguments, P, generator) {
    function adopt(value) { return value instanceof P ? value : new P(function (resolve) { resolve(value); }); }
    return new (P || (P = Promise))(function (resolve, reject) {
        function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
        function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
        function step(result) { result.done ? resolve(result.value) : adopt(result.value).then(fulfilled, rejected); }
        step((generator = generator.apply(thisArg, _arguments || [])).next());
    });
};
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
        this.applicationPassword = applicationPassword;
    }
    getAuthHeaders() {
        return {
            'Authorization': 'Basic ' + Buffer.from(this.username + ':' + this.applicationPassword).toString('base64'),
            'Content-Type': 'application/json'
        };
    }
    getSnippets(status = 'all') {
        return __awaiter(this, void 0, void 0, function* () {
            let url = `${this.apiUrl}wp-json/ide/v1/snippets`;
            if (status !== 'all') {
                url += `?status=${status}`;
            }
            const response = yield axios_1.default.get(url, {
                headers: this.getAuthHeaders(),
                timeout: this.timeout
            });
            return response.data;
        });
    }
    getSnippet(id) {
        return __awaiter(this, void 0, void 0, function* () {
            const response = yield axios_1.default.get(`${this.apiUrl}wp-json/ide/v1/snippets/${id}`, {
                headers: this.getAuthHeaders(),
                timeout: this.timeout
            });
            return response.data;
        });
    }
    createSnippet(data) {
        return __awaiter(this, void 0, void 0, function* () {
            const response = yield axios_1.default.post(`${this.apiUrl}wp-json/ide/v1/snippets`, data, {
                headers: this.getAuthHeaders(),
                timeout: this.timeout
            });
            return response.data;
        });
    }
    updateSnippet(id, data) {
        return __awaiter(this, void 0, void 0, function* () {
            const response = yield axios_1.default.put(`${this.apiUrl}wp-json/ide/v1/snippets/${id}`, data, {
                headers: this.getAuthHeaders(),
                timeout: this.timeout
            });
            return response.data;
        });
    }
    deleteSnippet(id) {
        return __awaiter(this, void 0, void 0, function* () {
            const response = yield axios_1.default.delete(`${this.apiUrl}wp-json/ide/v1/snippets/${id}`, {
                headers: this.getAuthHeaders(),
                timeout: this.timeout
            });
            return response.data;
        });
    }
    getStatus() {
        return __awaiter(this, void 0, void 0, function* () {
            const response = yield axios_1.default.get(`${this.apiUrl}wp-json/ide/v1/status`, {
                headers: this.getAuthHeaders(),
                timeout: this.timeout
            });
            return response.data;
        });
    }
    getFluentSnippets() {
        return __awaiter(this, void 0, void 0, function* () {
            const response = yield axios_1.default.get(`${this.apiUrl}wp-json/ide/v1/fluent-snippets`, {
                headers: this.getAuthHeaders(),
                timeout: this.timeout
            });
            return response.data;
        });
    }
    updateFluentSnippet(id, data) {
        return __awaiter(this, void 0, void 0, function* () {
            const response = yield axios_1.default.put(`${this.apiUrl}wp-json/ide/v1/fluent-snippets/${id}`, data, {
                headers: this.getAuthHeaders(),
                timeout: this.timeout
            });
            return response.data;
        });
    }
    toggleFluentSnippet(id, active) {
        return __awaiter(this, void 0, void 0, function* () {
            const url = `${this.apiUrl}wp-json/ide/v1/fluent-snippets/${id}/toggle`;
            const response = yield axios_1.default.put(url, { active: active }, {
                headers: this.getAuthHeaders(),
                timeout: this.timeout
            });
            return response.data;
        });
    }
}
exports.ApiConnector = ApiConnector;
//# sourceMappingURL=ApiConnector.js.map