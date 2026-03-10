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
        this.engine = 'native';
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
    async _ensureBackupDir() {
        try {
            await fs.mkdir(this.backupPath, { recursive: true });
        }
        catch (error) {
            console.error("Failed to create snippet backup directory", error);
        }
    }
    async _ensureCacheDir() {
        try {
            await fs.mkdir(this.cachePath, { recursive: true });
        }
        catch (error) {
            console.error("Failed to create snippet cache directory", error);
        }
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
        cleaned = cleaned.replace(/^\uFEFF?\s*<\?(?:php)?\s*/i, '');
        cleaned = cleaned.replace(/^\s*\/\*\*[\s\S]*?\*\/\s*/, '');
        cleaned = cleaned.replace(/^\uFEFF?\s*<\?(?:php)?\s*/i, '');
        cleaned = cleaned.replace(/\?>\s*$/, '');
        return cleaned.trim();
    }
    sanitizeDescriptionForHeader(description) {
        let cleaned = (description || '').replace(/\r?\n/g, ' ').trim();
        cleaned = cleaned.replace(/^\*+\s*/, '');
        if (/^@(?:active|status)\b/i.test(cleaned)) {
            return '';
        }
        return cleaned;
    }
    /**
     * Build the cache file content from a snippet.
     * Single source of truth for cache file format.
     */
    buildCacheContent(snippet) {
        const cleanCode = this.stripHeaderAndPhpTag(snippet.code);
        const tags = snippet.tags ? `\n * Tags: ${snippet.tags}` : '';
        const description = this.sanitizeDescriptionForHeader(snippet.description || '');
        return `<?php
/**
 * Snippet ID: ${snippet.id}
 * Name: ${snippet.name}
 * Description: ${description}${tags}
 * @active ${snippet.active}
 */

${cleanCode}`;
    }
    /**
     * Write snippet to cache file
     */
    async writeCacheFile(snippet) {
        const filePath = this.getSnippetCachePath(snippet.id);
        const content = this.buildCacheContent(snippet);
        await fs.writeFile(filePath, content);
    }
    async initialize() {
        await this._ensureCacheDir();
        await this._ensureBackupDir();
        const config = await this.configManager.getConfig();
        if (!config) {
            const newConfig = await this.configManager.promptForConfig();
            if (!newConfig) {
                return false;
            }
        }
        const currentConfig = await this.configManager.getConfig();
        if (!currentConfig) {
            return false;
        }
        this.apiConnector = new ApiConnector_1.ApiConnector(currentConfig.siteUrl, currentConfig.username, currentConfig.applicationPassword);
        this.engine = currentConfig.plugin === 'Code Snippets' ? 'code_snippets' : 'native';
        return true;
    }
    async getSnippets(status = 'all') {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        try {
            const snippets = await this.apiConnector.getSnippets(status, this.engine);
            if (!Array.isArray(snippets)) {
                console.error('API response is not an array:', typeof snippets);
                throw new Error('La réponse de l\'API n\'est pas un tableau de snippets');
            }
            for (const snippet of snippets) {
                await this.writeCacheFile(snippet);
            }
            return snippets;
        }
        catch (error) {
            vscode.window.showErrorMessage('Erreur lors de la récupération des snippets: ' + (error?.message || 'Erreur inconnue'));
            return [];
        }
    }
    async getSnippet(id) {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        if (typeof id === 'string' && id.startsWith('FS')) {
            return null;
        }
        try {
            const snippet = await this.apiConnector.getSnippet(id, this.engine);
            if (snippet) {
                await this.writeCacheFile(snippet);
            }
            return snippet;
        }
        catch (error) {
            vscode.window.showErrorMessage('Erreur lors de la récupération du snippet: ' + (error?.message || 'Erreur inconnue'));
            return null;
        }
    }
    async createSnippet(data) {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        try {
            const result = await this.apiConnector.createSnippet(data, this.engine);
            this._onDidChangeSnippets.fire();
            return result;
        }
        catch (error) {
            vscode.window.showErrorMessage('Erreur lors de la création du snippet: ' + (error?.message || 'Erreur inconnue'));
            return null;
        }
    }
    async updateSnippet(data) {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        if (typeof data.id === 'string' && data.id.startsWith('FS')) {
            return false;
        }
        try {
            await this.apiConnector.updateSnippet(data.id, data, this.engine);
            this._onDidChangeSnippets.fire();
            return true;
        }
        catch (error) {
            vscode.window.showErrorMessage('Erreur lors de la mise à jour du snippet: ' + (error?.message || 'Erreur inconnue'));
            return false;
        }
    }
    async toggleSnippet(id, active) {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        if (typeof id === 'string' && id.startsWith('FS')) {
            return false;
        }
        try {
            await this.apiConnector.updateSnippet(id, { active }, this.engine);
            // Re-fetch and rewrite cache to ensure consistency
            const snippet = await this.apiConnector.getSnippet(id, this.engine);
            if (snippet) {
                await this.writeCacheFile(snippet);
            }
            this._onDidChangeSnippets.fire();
            return true;
        }
        catch (error) {
            console.error(`Error toggling snippet ${id}:`, error);
            vscode.window.showErrorMessage('Erreur lors du changement de statut: ' + (error?.message || 'Erreur inconnue'));
            return false;
        }
    }
    async deleteSnippet(id) {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        if (typeof id === 'string' && id.startsWith('FS')) {
            return false;
        }
        try {
            await this.apiConnector.deleteSnippet(id, this.engine);
            try {
                const filePath = this.getSnippetCachePath(id);
                await fs.unlink(filePath);
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
            vscode.window.showErrorMessage('Erreur lors de la suppression du snippet: ' + (error?.message || 'Erreur inconnue'));
            return false;
        }
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
        const descMatch = content.match(/^\s*\*\s*Description:\s*(.*)$/m);
        if (descMatch) {
            result.description = this.sanitizeDescriptionForHeader(descMatch[1]);
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
    async updateSnippetFromFile(filePath) {
        if (!this.isSnippetFile(filePath)) {
            return;
        }
        if (!this.apiConnector) {
            vscode.window.showErrorMessage('Cannot update snippet, API is not connected.');
            return;
        }
        try {
            const content = await fs.readFile(filePath, 'utf-8');
            const parsed = this.parseSnippetCacheFile(content);
            if (!parsed.id) {
                vscode.window.showWarningMessage(`Could not determine Snippet ID for ${path.basename(filePath)}.`);
                return;
            }
            const id = parsed.id;
            // Fetch original from API for backup
            const originalSnippet = await this.apiConnector.getSnippet(id, this.engine);
            if (!originalSnippet) {
                vscode.window.showErrorMessage(`Snippet with ID ${id} no longer exists on server.`);
                return;
            }
            // Create backup before syncing
            const backupDir = path.join(this.backupPath, `snippet-${id}`);
            await fs.mkdir(backupDir, { recursive: true });
            const now = new Date();
            const ts = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}_${String(now.getHours()).padStart(2, '0')}-${String(now.getMinutes()).padStart(2, '0')}-${String(now.getSeconds()).padStart(2, '0')}`;
            await fs.writeFile(path.join(backupDir, `backup-${ts}-pre-sync.json`), JSON.stringify(originalSnippet, null, 2));
            // Limit backups
            const backups = await fs.readdir(backupDir);
            const sorted = backups.filter(f => f.endsWith('.json')).sort().reverse();
            if (sorted.length > 20) {
                for (const old of sorted.slice(20)) {
                    await fs.unlink(path.join(backupDir, old));
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
            await this.updateSnippet(updateData);
            // Re-fetch from API and rewrite cache to ensure clean header
            const updatedSnippet = await this.apiConnector.getSnippet(id, this.engine);
            if (updatedSnippet) {
                await this.writeCacheFile(updatedSnippet);
            }
            vscode.window.setStatusBarMessage(`Snippet "${updateData.name}" synced!`, 3000);
        }
        catch (error) {
            console.error(`Failed to update snippet from file ${filePath}`, error);
            vscode.window.showErrorMessage(`Failed to sync snippet: ${error.message}`);
        }
    }
    /**
     * Rename a snippet on the WordPress server
     */
    async renameSnippet(id, newName) {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        if (typeof id === 'string' && id.startsWith('FS')) {
            return false;
        }
        try {
            await this.apiConnector.updateSnippet(id, { name: newName }, this.engine);
            const snippet = await this.apiConnector.getSnippet(id, this.engine);
            if (snippet) {
                await this.writeCacheFile(snippet);
            }
            this._onDidChangeSnippets.fire();
            return true;
        }
        catch (error) {
            vscode.window.showErrorMessage('Erreur lors du renommage: ' + (error?.message || 'Erreur inconnue'));
            return false;
        }
    }
    /**
     * Update snippet description on the WordPress server
     */
    async updateDescription(id, newDescription) {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        if (typeof id === 'string' && id.startsWith('FS')) {
            return false;
        }
        try {
            await this.apiConnector.updateSnippet(id, { description: newDescription }, this.engine);
            const snippet = await this.apiConnector.getSnippet(id, this.engine);
            if (snippet) {
                await this.writeCacheFile(snippet);
            }
            this._onDidChangeSnippets.fire();
            return true;
        }
        catch (error) {
            vscode.window.showErrorMessage('Erreur lors de la mise à jour de la description: ' + (error?.message || 'Erreur inconnue'));
            return false;
        }
    }
    /**
     * Update snippet tags on the WordPress server
     */
    async updateTags(id, newTags) {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        if (typeof id === 'string' && id.startsWith('FS')) {
            return false;
        }
        try {
            await this.apiConnector.updateSnippet(id, { tags: newTags }, this.engine);
            const snippet = await this.apiConnector.getSnippet(id, this.engine);
            if (snippet) {
                await this.writeCacheFile(snippet);
            }
            this._onDidChangeSnippets.fire();
            return true;
        }
        catch (error) {
            vscode.window.showErrorMessage('Erreur lors de la mise à jour des tags: ' + (error?.message || 'Erreur inconnue'));
            return false;
        }
    }
    async updateAttribution(id, targetMode, targetPostTypes, targetPostIds) {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        if (typeof id === 'string' && id.startsWith('FS')) {
            return false;
        }
        try {
            await this.apiConnector.updateSnippet(id, {
                target_mode: targetMode,
                target_post_types: targetPostTypes,
                target_post_ids: targetPostIds,
            }, this.engine);
            const snippet = await this.apiConnector.getSnippet(id, this.engine);
            if (snippet) {
                await this.writeCacheFile(snippet);
            }
            this._onDidChangeSnippets.fire();
            return true;
        }
        catch (error) {
            vscode.window.showErrorMessage('Erreur lors de la mise à jour des attributions: ' + (error?.message || 'Erreur inconnue'));
            return false;
        }
    }
    async getBackups(snippetId) {
        const backupDir = path.join(this.backupPath, `snippet-${snippetId}`);
        try {
            const files = await fs.readdir(backupDir);
            return files.filter(f => f.endsWith('.json')).sort().reverse();
        }
        catch (error) {
            if (error.code === 'ENOENT') {
                return [];
            }
            console.error(`Failed to read backups for snippet ${snippetId}`, error);
            return [];
        }
    }
    async restoreBackup(snippetId, backupFile) {
        const backupFilePath = path.join(this.backupPath, `snippet-${snippetId}`, backupFile);
        try {
            const backupContent = await fs.readFile(backupFilePath, 'utf-8');
            const snippetData = JSON.parse(backupContent);
            const updateData = {
                id: snippetData.id,
                name: snippetData.name,
                description: snippetData.description,
                code: snippetData.code,
                active: snippetData.active,
                tags: snippetData.tags,
            };
            return await this.updateSnippet(updateData);
        }
        catch (error) {
            console.error(`Failed to restore backup ${backupFile}`, error);
            vscode.window.showErrorMessage(`Failed to restore backup: ${error.message}`);
            return false;
        }
    }
    dispose() {
        this._onDidChangeSnippets.dispose();
    }
}
exports.SnippetProvider = SnippetProvider;
//# sourceMappingURL=SnippetProvider.js.map