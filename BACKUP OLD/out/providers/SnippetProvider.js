"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || function (mod) {
    if (mod && mod.__esModule) return mod;
    var result = {};
    if (mod != null) for (var k in mod) if (k !== "default" && Object.prototype.hasOwnProperty.call(mod, k)) __createBinding(result, mod, k);
    __setModuleDefault(result, mod);
    return result;
};
var __awaiter = (this && this.__awaiter) || function (thisArg, _arguments, P, generator) {
    function adopt(value) { return value instanceof P ? value : new P(function (resolve) { resolve(value); }); }
    return new (P || (P = Promise))(function (resolve, reject) {
        function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
        function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
        function step(result) { result.done ? resolve(result.value) : adopt(result.value).then(fulfilled, rejected); }
        step((generator = generator.apply(thisArg, _arguments || [])).next());
    });
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.SnippetProvider = void 0;
const vscode = __importStar(require("vscode"));
const fs = __importStar(require("fs/promises"));
const path = __importStar(require("path"));
const ApiConnector_1 = require("../core/ApiConnector");
const ConfigManager_1 = require("../core/ConfigManager");
class SnippetProvider {
    constructor(context) {
        this._onDidChangeSnippets = new vscode.EventEmitter();
        this.onDidChangeSnippets = this._onDidChangeSnippets.event;
        this.apiConnector = null;
        this.configManager = new ConfigManager_1.ConfigManager(context);
        const workspaceFolders = vscode.workspace.workspaceFolders;
        if (workspaceFolders && workspaceFolders.length > 0) {
            this.cachePath = path.join(workspaceFolders[0].uri.fsPath, '.snippet_cache');
            this.backupPath = path.join(workspaceFolders[0].uri.fsPath, '.snippet_backups');
        }
        else {
            this.cachePath = path.join(context.globalStorageUri.fsPath, 'snippet_cache');
            this.backupPath = path.join(context.globalStorageUri.fsPath, 'snippet_backups');
            vscode.window.showWarningMessage('No workspace folder is open. Snippet cache and backups will be stored globally.');
        }
    }
    _ensureBackupDir() {
        return __awaiter(this, void 0, void 0, function* () {
            try {
                yield fs.mkdir(this.backupPath, { recursive: true });
            }
            catch (error) {
                console.error("Failed to create snippet backup directory", error);
            }
        });
    }
    _ensureCacheDir() {
        return __awaiter(this, void 0, void 0, function* () {
            try {
                yield fs.mkdir(this.cachePath, { recursive: true });
            }
            catch (error) {
                console.error("Failed to create snippet cache directory", error);
            }
        });
    }
    /**
     * Validate snippet ID to prevent path traversal
     */
    validateSnippetId(id) {
        if (typeof id === 'number') {
            return Number.isInteger(id) && id > 0;
        }
        return /^\d+$/.test(id);
    }
    getSnippetCachePath(id) {
        if (!this.validateSnippetId(id)) {
            throw new Error(`Invalid snippet ID: ${id}`);
        }
        return path.join(this.cachePath, `snippet-${id}.php`);
    }
    isSnippetFile(filePath) {
        return path.dirname(filePath) === this.cachePath && path.basename(filePath).startsWith('snippet-');
    }
    /**
     * Strip any existing header block and <?php tag from code.
     * Returns only the raw PHP code content.
     */
    stripHeaderAndPhpTag(code) {
        let cleaned = code;
        // Remove <?php at the very start
        cleaned = cleaned.replace(/^<\?php\s*/, '');
        // Remove the header block /** ... */ at the beginning if present
        cleaned = cleaned.replace(/^\s*\/\*\*[\s\S]*?\*\/\s*/, '');
        // Remove any remaining <?php tag after header removal
        cleaned = cleaned.replace(/^<\?php\s*/, '');
        return cleaned.trim();
    }
    /**
     * Build the cache file content from a snippet.
     * Single source of truth for cache file format.
     */
    buildCacheContent(snippet) {
        const cleanCode = this.stripHeaderAndPhpTag(snippet.code);
        const tags = snippet.tags ? `\n * Tags: ${snippet.tags}` : '';
        return `<?php
/**
 * Snippet ID: ${snippet.id}
 * Name: ${snippet.name}
 * Description: ${snippet.description || ''}${tags}
 * @active ${snippet.active}
 */

${cleanCode}`;
    }
    /**
     * Write snippet to cache file
     */
    writeCacheFile(snippet) {
        return __awaiter(this, void 0, void 0, function* () {
            const filePath = this.getSnippetCachePath(snippet.id);
            const content = this.buildCacheContent(snippet);
            yield fs.writeFile(filePath, content);
        });
    }
    initialize() {
        return __awaiter(this, void 0, void 0, function* () {
            yield this._ensureCacheDir();
            yield this._ensureBackupDir();
            const config = yield this.configManager.getConfig();
            if (!config) {
                const newConfig = yield this.configManager.promptForConfig();
                if (!newConfig) {
                    return false;
                }
            }
            const currentConfig = yield this.configManager.getConfig();
            if (!currentConfig) {
                return false;
            }
            this.apiConnector = new ApiConnector_1.ApiConnector(currentConfig.siteUrl, currentConfig.username, currentConfig.applicationPassword);
            return true;
        });
    }
    getSnippets(status = 'all') {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.apiConnector) {
                throw new Error('Le fournisseur n\'est pas initialisé');
            }
            try {
                const snippets = yield this.apiConnector.getSnippets(status);
                if (!Array.isArray(snippets)) {
                    console.error('API response is not an array:', typeof snippets);
                    throw new Error('La réponse de l\'API n\'est pas un tableau de snippets');
                }
                for (const snippet of snippets) {
                    yield this.writeCacheFile(snippet);
                }
                return snippets;
            }
            catch (error) {
                vscode.window.showErrorMessage('Erreur lors de la récupération des snippets: ' + ((error === null || error === void 0 ? void 0 : error.message) || 'Erreur inconnue'));
                return [];
            }
        });
    }
    getSnippet(id) {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.apiConnector) {
                throw new Error('Le fournisseur n\'est pas initialisé');
            }
            if (typeof id === 'string' && id.startsWith('FS')) {
                return null;
            }
            try {
                const snippet = yield this.apiConnector.getSnippet(id);
                if (snippet) {
                    yield this.writeCacheFile(snippet);
                }
                return snippet;
            }
            catch (error) {
                vscode.window.showErrorMessage('Erreur lors de la récupération du snippet: ' + ((error === null || error === void 0 ? void 0 : error.message) || 'Erreur inconnue'));
                return null;
            }
        });
    }
    createSnippet(data) {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.apiConnector) {
                throw new Error('Le fournisseur n\'est pas initialisé');
            }
            try {
                const result = yield this.apiConnector.createSnippet(data);
                this._onDidChangeSnippets.fire();
                return result;
            }
            catch (error) {
                vscode.window.showErrorMessage('Erreur lors de la création du snippet: ' + ((error === null || error === void 0 ? void 0 : error.message) || 'Erreur inconnue'));
                return null;
            }
        });
    }
    updateSnippet(data) {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.apiConnector) {
                throw new Error('Le fournisseur n\'est pas initialisé');
            }
            if (typeof data.id === 'string' && data.id.startsWith('FS')) {
                return false;
            }
            try {
                yield this.apiConnector.updateSnippet(data.id, data);
                this._onDidChangeSnippets.fire();
                return true;
            }
            catch (error) {
                vscode.window.showErrorMessage('Erreur lors de la mise à jour du snippet: ' + ((error === null || error === void 0 ? void 0 : error.message) || 'Erreur inconnue'));
                return false;
            }
        });
    }
    toggleSnippet(id, active) {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.apiConnector) {
                throw new Error('Le fournisseur n\'est pas initialisé');
            }
            if (typeof id === 'string' && id.startsWith('FS')) {
                return false;
            }
            try {
                yield this.apiConnector.updateSnippet(id, { active });
                // Re-fetch and rewrite cache to ensure consistency
                const snippet = yield this.apiConnector.getSnippet(id);
                if (snippet) {
                    yield this.writeCacheFile(snippet);
                }
                this._onDidChangeSnippets.fire();
                return true;
            }
            catch (error) {
                console.error(`Error toggling snippet ${id}:`, error);
                vscode.window.showErrorMessage('Erreur lors du changement de statut: ' + ((error === null || error === void 0 ? void 0 : error.message) || 'Erreur inconnue'));
                return false;
            }
        });
    }
    deleteSnippet(id) {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.apiConnector) {
                throw new Error('Le fournisseur n\'est pas initialisé');
            }
            if (typeof id === 'string' && id.startsWith('FS')) {
                return false;
            }
            try {
                yield this.apiConnector.deleteSnippet(id);
                try {
                    const filePath = this.getSnippetCachePath(id);
                    yield fs.unlink(filePath);
                }
                catch (e) {
                    if (e.code !== 'ENOENT') {
                        console.error(`Failed to delete cached snippet file`, e);
                    }
                }
                this._onDidChangeSnippets.fire();
                return true;
            }
            catch (error) {
                vscode.window.showErrorMessage('Erreur lors de la suppression du snippet: ' + ((error === null || error === void 0 ? void 0 : error.message) || 'Erreur inconnue'));
                return false;
            }
        });
    }
    /**
     * Parse the header from a cache file and extract metadata + code
     */
    parseSnippetCacheFile(content) {
        const result = {
            id: null,
            name: null,
            description: null,
            tags: null,
            active: null,
            code: ''
        };
        const idMatch = content.match(/\*\s*Snippet ID:\s*(\d+)/);
        if (idMatch) {
            result.id = parseInt(idMatch[1], 10);
        }
        const nameMatch = content.match(/\*\s*Name:\s*(.*)/);
        if (nameMatch) {
            result.name = nameMatch[1].trim();
        }
        const descMatch = content.match(/\*\s*Description:\s*(.*?)(?=\n\s*\*\s*(?:@|Tags:|Version:|Author:)|\n\s*\*\/|$)/s);
        if (descMatch) {
            result.description = descMatch[1].replace(/\n\s*\*/g, ' ').trim();
        }
        const tagsMatch = content.match(/\*\s*Tags:\s*(.*)/);
        if (tagsMatch) {
            result.tags = tagsMatch[1].trim();
        }
        const activeMatch = content.match(/@active\s+(true|false)/);
        if (activeMatch) {
            result.active = activeMatch[1] === 'true';
        }
        // Extract code: everything after the header block closing */
        const headerEndIndex = content.indexOf('*/');
        if (headerEndIndex !== -1) {
            result.code = content.substring(headerEndIndex + 2).trim();
        }
        return result;
    }
    updateSnippetFromFile(filePath) {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.isSnippetFile(filePath)) {
                return;
            }
            if (!this.apiConnector) {
                vscode.window.showErrorMessage('Cannot update snippet, API is not connected.');
                return;
            }
            try {
                const content = yield fs.readFile(filePath, 'utf-8');
                const parsed = this.parseSnippetCacheFile(content);
                if (!parsed.id) {
                    vscode.window.showWarningMessage(`Could not determine Snippet ID for ${path.basename(filePath)}.`);
                    return;
                }
                const id = parsed.id;
                // Fetch original from API for backup
                const originalSnippet = yield this.apiConnector.getSnippet(id);
                if (!originalSnippet) {
                    vscode.window.showErrorMessage(`Snippet with ID ${id} no longer exists on server.`);
                    return;
                }
                // Create backup before syncing
                const backupDir = path.join(this.backupPath, `snippet-${id}`);
                yield fs.mkdir(backupDir, { recursive: true });
                const now = new Date();
                const ts = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}_${String(now.getHours()).padStart(2, '0')}-${String(now.getMinutes()).padStart(2, '0')}-${String(now.getSeconds()).padStart(2, '0')}`;
                yield fs.writeFile(path.join(backupDir, `backup-${ts}-pre-sync.json`), JSON.stringify(originalSnippet, null, 2));
                // Limit backups
                const backups = yield fs.readdir(backupDir);
                const sorted = backups.filter(f => f.endsWith('.json')).sort().reverse();
                if (sorted.length > 20) {
                    for (const old of sorted.slice(20)) {
                        yield fs.unlink(path.join(backupDir, old));
                    }
                }
                // Strip any header artifacts from extracted code
                const cleanCode = this.stripHeaderAndPhpTag(parsed.code);
                const updateData = {
                    id: originalSnippet.id,
                    name: parsed.name || originalSnippet.name,
                    description: parsed.description !== null ? parsed.description : originalSnippet.description,
                    code: cleanCode,
                    active: originalSnippet.active,
                    tags: parsed.tags || originalSnippet.tags,
                };
                yield this.updateSnippet(updateData);
                // Re-fetch from API and rewrite cache to ensure clean header
                const updatedSnippet = yield this.apiConnector.getSnippet(id);
                if (updatedSnippet) {
                    yield this.writeCacheFile(updatedSnippet);
                }
                vscode.window.setStatusBarMessage(`Snippet "${updateData.name}" synced!`, 3000);
            }
            catch (error) {
                console.error(`Failed to update snippet from file ${filePath}`, error);
                vscode.window.showErrorMessage(`Failed to sync snippet: ${error.message}`);
            }
        });
    }
    /**
     * Rename a snippet on the WordPress server
     */
    renameSnippet(id, newName) {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.apiConnector) {
                throw new Error('Le fournisseur n\'est pas initialisé');
            }
            if (typeof id === 'string' && id.startsWith('FS')) {
                return false;
            }
            try {
                yield this.apiConnector.updateSnippet(id, { name: newName });
                const snippet = yield this.apiConnector.getSnippet(id);
                if (snippet) {
                    yield this.writeCacheFile(snippet);
                }
                this._onDidChangeSnippets.fire();
                return true;
            }
            catch (error) {
                vscode.window.showErrorMessage('Erreur lors du renommage: ' + ((error === null || error === void 0 ? void 0 : error.message) || 'Erreur inconnue'));
                return false;
            }
        });
    }
    /**
     * Update snippet description on the WordPress server
     */
    updateDescription(id, newDescription) {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.apiConnector) {
                throw new Error('Le fournisseur n\'est pas initialisé');
            }
            if (typeof id === 'string' && id.startsWith('FS')) {
                return false;
            }
            try {
                yield this.apiConnector.updateSnippet(id, { description: newDescription });
                const snippet = yield this.apiConnector.getSnippet(id);
                if (snippet) {
                    yield this.writeCacheFile(snippet);
                }
                this._onDidChangeSnippets.fire();
                return true;
            }
            catch (error) {
                vscode.window.showErrorMessage('Erreur lors de la mise à jour de la description: ' + ((error === null || error === void 0 ? void 0 : error.message) || 'Erreur inconnue'));
                return false;
            }
        });
    }
    /**
     * Update snippet tags on the WordPress server
     */
    updateTags(id, newTags) {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.apiConnector) {
                throw new Error('Le fournisseur n\'est pas initialisé');
            }
            if (typeof id === 'string' && id.startsWith('FS')) {
                return false;
            }
            try {
                yield this.apiConnector.updateSnippet(id, { tags: newTags });
                const snippet = yield this.apiConnector.getSnippet(id);
                if (snippet) {
                    yield this.writeCacheFile(snippet);
                }
                this._onDidChangeSnippets.fire();
                return true;
            }
            catch (error) {
                vscode.window.showErrorMessage('Erreur lors de la mise à jour des tags: ' + ((error === null || error === void 0 ? void 0 : error.message) || 'Erreur inconnue'));
                return false;
            }
        });
    }
    getBackups(snippetId) {
        return __awaiter(this, void 0, void 0, function* () {
            const backupDir = path.join(this.backupPath, `snippet-${snippetId}`);
            try {
                const files = yield fs.readdir(backupDir);
                return files.filter(f => f.endsWith('.json')).sort().reverse();
            }
            catch (error) {
                if (error.code === 'ENOENT') {
                    return [];
                }
                console.error(`Failed to read backups for snippet ${snippetId}`, error);
                return [];
            }
        });
    }
    restoreBackup(snippetId, backupFile) {
        return __awaiter(this, void 0, void 0, function* () {
            const backupFilePath = path.join(this.backupPath, `snippet-${snippetId}`, backupFile);
            try {
                const backupContent = yield fs.readFile(backupFilePath, 'utf-8');
                const snippetData = JSON.parse(backupContent);
                const updateData = {
                    id: snippetData.id,
                    name: snippetData.name,
                    description: snippetData.description,
                    code: snippetData.code,
                    active: snippetData.active,
                    tags: snippetData.tags,
                };
                return yield this.updateSnippet(updateData);
            }
            catch (error) {
                console.error(`Failed to restore backup ${backupFile}`, error);
                vscode.window.showErrorMessage(`Failed to restore backup: ${error.message}`);
                return false;
            }
        });
    }
    dispose() {
        this._onDidChangeSnippets.dispose();
    }
}
exports.SnippetProvider = SnippetProvider;
//# sourceMappingURL=SnippetProvider.js.map