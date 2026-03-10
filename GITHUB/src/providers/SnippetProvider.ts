import * as vscode from 'vscode';
import * as fs from 'fs/promises';
import * as path from 'path';
import { ApiConnector } from '../core/ApiConnector';
import { ConfigManager } from '../core/ConfigManager';
import { Snippet, SnippetCreateData, SnippetUpdateData } from '../types/Snippet';
import { SnippetPluginProvider } from './SnippetPluginProvider';

export class SnippetProvider implements vscode.Disposable, SnippetPluginProvider {
    private _onDidChangeSnippets: vscode.EventEmitter<void> = new vscode.EventEmitter<void>();
    public readonly onDidChangeSnippets: vscode.Event<void> = this._onDidChangeSnippets.event;

    private apiConnector: ApiConnector | null = null;
    private configManager: ConfigManager;
    private cachePath: string;
    private backupPath: string;
    private engine: 'native' | 'code_snippets' = 'native';

    constructor(context: vscode.ExtensionContext) {
        this.configManager = new ConfigManager(context);
        const workspaceFolders = vscode.workspace.workspaceFolders;
        if (workspaceFolders && workspaceFolders.length > 0) {
            this.cachePath = path.join(workspaceFolders[0].uri.fsPath, '.snippet_cache');
            this.backupPath = path.join(workspaceFolders[0].uri.fsPath, '.snippet_backups');
        } else {
            this.cachePath = path.join(context.globalStorageUri.fsPath, 'snippet_cache');
            this.backupPath = path.join(context.globalStorageUri.fsPath, 'snippet_backups');
            vscode.window.showWarningMessage('No workspace folder is open. Snippet cache and backups will be stored globally.');
        }
    }

    private async _ensureBackupDir(): Promise<void> {
        try {
            await fs.mkdir(this.backupPath, { recursive: true });
        } catch (error) {
            console.error("Failed to create snippet backup directory", error);
        }
    }

    private async _ensureCacheDir(): Promise<void> {
        try {
            await fs.mkdir(this.cachePath, { recursive: true });
        } catch (error) {
            console.error("Failed to create snippet cache directory", error);
        }
    }

    /**
     * Validate snippet ID to prevent path traversal
     */
    private validateSnippetId(id: string | number): boolean {
        if (typeof id === 'number') {
            return Number.isInteger(id) && id > 0;
        }
        return /^\d+$/.test(id);
    }

    public getSnippetCachePath(id: string | number): string {
        if (!this.validateSnippetId(id)) {
            throw new Error(`Invalid snippet ID: ${id}`);
        }
        return path.join(this.cachePath, `snippet-${id}.php`);
    }

    public isSnippetFile(filePath: string): boolean {
        return path.dirname(filePath) === this.cachePath && path.basename(filePath).startsWith('snippet-');
    }

    /**
     * Strip any existing header block and <?php tag from code.
     * Returns only the raw PHP code content.
     */
    private stripHeaderAndPhpTag(code: string): string {
        let cleaned = code;
        cleaned = cleaned.replace(/^\uFEFF?\s*<\?(?:php)?\s*/i, '');
        cleaned = cleaned.replace(/^\s*\/\*\*[\s\S]*?\*\/\s*/, '');
        cleaned = cleaned.replace(/^\uFEFF?\s*<\?(?:php)?\s*/i, '');
        cleaned = cleaned.replace(/\?>\s*$/, '');
        return cleaned.trim();
    }

    private sanitizeDescriptionForHeader(description: string): string {
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
    private buildCacheContent(snippet: Snippet): string {
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
    private async writeCacheFile(snippet: Snippet): Promise<void> {
        const filePath = this.getSnippetCachePath(snippet.id);
        const content = this.buildCacheContent(snippet);
        await fs.writeFile(filePath, content);
    }

    public async initialize(): Promise<boolean> {
        await this._ensureCacheDir();
        await this._ensureBackupDir();
        const config = await this.configManager.getConfig();
        if (!config) {
            const newConfig = await this.configManager.promptForConfig();
            if (!newConfig) { return false; }
        }

        const currentConfig = await this.configManager.getConfig();
        if (!currentConfig) { return false; }

        this.apiConnector = new ApiConnector(
            currentConfig.siteUrl,
            currentConfig.username,
            currentConfig.applicationPassword
        );
        this.engine = currentConfig.plugin === 'Code Snippets' ? 'code_snippets' : 'native';

        return true;
    }

    public async getSnippets(status: 'all' | 'active' | 'inactive' = 'all'): Promise<Snippet[]> {
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
        } catch (error: any) {
            vscode.window.showErrorMessage('Erreur lors de la récupération des snippets: ' + (error?.message || 'Erreur inconnue'));
            return [];
        }
    }

    public async getSnippet(id: string | number): Promise<Snippet | null> {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }

        if (typeof id === 'string' && id.startsWith('FS')) {
            return null;
        }

        try {
            const snippet = await this.apiConnector.getSnippet(id as number, this.engine);
            if (snippet) {
                await this.writeCacheFile(snippet);
            }
            return snippet;
        } catch (error: any) {
            vscode.window.showErrorMessage('Erreur lors de la récupération du snippet: ' + (error?.message || 'Erreur inconnue'));
            return null;
        }
    }

    public async createSnippet(data: SnippetCreateData): Promise<Snippet | null> {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }

        try {
            const result = await this.apiConnector.createSnippet(data, this.engine);
            this._onDidChangeSnippets.fire();
            return result;
        } catch (error: any) {
            vscode.window.showErrorMessage('Erreur lors de la création du snippet: ' + (error?.message || 'Erreur inconnue'));
            return null;
        }
    }

    public async updateSnippet(data: SnippetUpdateData): Promise<boolean> {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }

        if (typeof data.id === 'string' && data.id.startsWith('FS')) {
            return false;
        }

        try {
            await this.apiConnector.updateSnippet(data.id as number, data, this.engine);
            this._onDidChangeSnippets.fire();
            return true;
        } catch (error: any) {
            vscode.window.showErrorMessage('Erreur lors de la mise à jour du snippet: ' + (error?.message || 'Erreur inconnue'));
            return false;
        }
    }

    public async toggleSnippet(id: string | number, active: boolean): Promise<boolean> {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }

        if (typeof id === 'string' && id.startsWith('FS')) {
            return false;
        }

        try {
            await this.apiConnector.updateSnippet(id as number, { active }, this.engine);
            // Re-fetch and rewrite cache to ensure consistency
            const snippet = await this.apiConnector.getSnippet(id as number, this.engine);
            if (snippet) {
                await this.writeCacheFile(snippet);
            }
            this._onDidChangeSnippets.fire();
            return true;
        } catch (error: any) {
            console.error(`Error toggling snippet ${id}:`, error);
            vscode.window.showErrorMessage('Erreur lors du changement de statut: ' + (error?.message || 'Erreur inconnue'));
            return false;
        }
    }

    public async deleteSnippet(id: string | number): Promise<boolean> {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }

        if (typeof id === 'string' && id.startsWith('FS')) {
            return false;
        }

        try {
            await this.apiConnector.deleteSnippet(id as number, this.engine);
            try {
                const filePath = this.getSnippetCachePath(id);
                await fs.unlink(filePath);
            } catch (e: any) {
                if (e.code !== 'ENOENT') {
                    console.error(`Failed to delete cached snippet file`, e);
                }
            }
            this._onDidChangeSnippets.fire();
            return true;
        } catch (error: any) {
            vscode.window.showErrorMessage('Erreur lors de la suppression du snippet: ' + (error?.message || 'Erreur inconnue'));
            return false;
        }
    }

    /**
     * Parse the header from a cache file and extract metadata + code
     */
    private parseSnippetCacheFile(content: string): {
        id: number | null;
        name: string | null;
        description: string | null;
        tags: string | null;
        active: boolean | null;
        code: string;
    } {
        const result = {
            id: null as number | null,
            name: null as string | null,
            description: null as string | null,
            tags: null as string | null,
            active: null as boolean | null,
            code: ''
        };

        const idMatch = content.match(/\*\s*Snippet ID:\s*(\d+)/);
        if (idMatch) { result.id = parseInt(idMatch[1], 10); }

        const nameMatch = content.match(/\*\s*Name:\s*(.*)/);
        if (nameMatch) { result.name = nameMatch[1].trim(); }

        const descMatch = content.match(/^\s*\*\s*Description:\s*(.*)$/m);
        if (descMatch) { result.description = this.sanitizeDescriptionForHeader(descMatch[1]); }

        const tagsMatch = content.match(/\*\s*Tags:\s*(.*)/);
        if (tagsMatch) { result.tags = tagsMatch[1].trim(); }

        const activeMatch = content.match(/@active\s+(true|false)/);
        if (activeMatch) { result.active = activeMatch[1] === 'true'; }

        // Extract code: everything after the header block closing */
        const headerEndIndex = content.indexOf('*/');
        if (headerEndIndex !== -1) {
            result.code = content.substring(headerEndIndex + 2).trim();
        }

        return result;
    }

    public async updateSnippetFromFile(filePath: string): Promise<void> {
        if (!this.isSnippetFile(filePath)) { return; }

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

            const updateData: SnippetUpdateData = {
                id: originalSnippet.id,
                name: parsed.name || originalSnippet.name,
                description: parsed.description !== null ? parsed.description : originalSnippet.description,
                code: cleanCode,
                active: originalSnippet.active, // Keep active status from server
                tags: parsed.tags || originalSnippet.tags,
            };

            await this.updateSnippet(updateData);

            // Re-fetch from API and rewrite cache to ensure clean header
            const updatedSnippet = await this.apiConnector.getSnippet(id, this.engine);
            if (updatedSnippet) {
                await this.writeCacheFile(updatedSnippet);
            }

            vscode.window.setStatusBarMessage(`Snippet "${updateData.name}" synced!`, 3000);

        } catch (error: any) {
            console.error(`Failed to update snippet from file ${filePath}`, error);
            vscode.window.showErrorMessage(`Failed to sync snippet: ${error.message}`);
        }
    }

    /**
     * Rename a snippet on the WordPress server
     */
    public async renameSnippet(id: string | number, newName: string): Promise<boolean> {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        if (typeof id === 'string' && id.startsWith('FS')) { return false; }

        try {
            await this.apiConnector.updateSnippet(id as number, { name: newName }, this.engine);
            const snippet = await this.apiConnector.getSnippet(id as number, this.engine);
            if (snippet) { await this.writeCacheFile(snippet); }
            this._onDidChangeSnippets.fire();
            return true;
        } catch (error: any) {
            vscode.window.showErrorMessage('Erreur lors du renommage: ' + (error?.message || 'Erreur inconnue'));
            return false;
        }
    }

    /**
     * Update snippet description on the WordPress server
     */
    public async updateDescription(id: string | number, newDescription: string): Promise<boolean> {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        if (typeof id === 'string' && id.startsWith('FS')) { return false; }

        try {
            await this.apiConnector.updateSnippet(id as number, { description: newDescription }, this.engine);
            const snippet = await this.apiConnector.getSnippet(id as number, this.engine);
            if (snippet) { await this.writeCacheFile(snippet); }
            this._onDidChangeSnippets.fire();
            return true;
        } catch (error: any) {
            vscode.window.showErrorMessage('Erreur lors de la mise à jour de la description: ' + (error?.message || 'Erreur inconnue'));
            return false;
        }
    }

    /**
     * Update snippet tags on the WordPress server
     */
    public async updateTags(id: string | number, newTags: string): Promise<boolean> {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        if (typeof id === 'string' && id.startsWith('FS')) { return false; }

        try {
            await this.apiConnector.updateSnippet(id as number, { tags: newTags }, this.engine);
            const snippet = await this.apiConnector.getSnippet(id as number, this.engine);
            if (snippet) { await this.writeCacheFile(snippet); }
            this._onDidChangeSnippets.fire();
            return true;
        } catch (error: any) {
            vscode.window.showErrorMessage('Erreur lors de la mise à jour des tags: ' + (error?.message || 'Erreur inconnue'));
            return false;
        }
    }

    public async updateAttribution(
        id: string | number,
        targetMode: 'all' | 'post_types' | 'specific_posts',
        targetPostTypes: string,
        targetPostIds: string
    ): Promise<boolean> {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        if (typeof id === 'string' && id.startsWith('FS')) { return false; }

        try {
            await this.apiConnector.updateSnippet(
                id as number,
                {
                    target_mode: targetMode,
                    target_post_types: targetPostTypes,
                    target_post_ids: targetPostIds,
                },
                this.engine
            );
            const snippet = await this.apiConnector.getSnippet(id as number, this.engine);
            if (snippet) { await this.writeCacheFile(snippet); }
            this._onDidChangeSnippets.fire();
            return true;
        } catch (error: any) {
            vscode.window.showErrorMessage('Erreur lors de la mise à jour des attributions: ' + (error?.message || 'Erreur inconnue'));
            return false;
        }
    }

    public async getBackups(snippetId: string | number): Promise<string[]> {
        const backupDir = path.join(this.backupPath, `snippet-${snippetId}`);
        try {
            const files = await fs.readdir(backupDir);
            return files.filter(f => f.endsWith('.json')).sort().reverse();
        } catch (error: any) {
            if (error.code === 'ENOENT') { return []; }
            console.error(`Failed to read backups for snippet ${snippetId}`, error);
            return [];
        }
    }

    public async restoreBackup(snippetId: string | number, backupFile: string): Promise<boolean> {
        const backupFilePath = path.join(this.backupPath, `snippet-${snippetId}`, backupFile);
        try {
            const backupContent = await fs.readFile(backupFilePath, 'utf-8');
            const snippetData = JSON.parse(backupContent) as Snippet;

            const updateData: SnippetUpdateData = {
                id: snippetData.id,
                name: snippetData.name,
                description: snippetData.description,
                code: snippetData.code,
                active: snippetData.active,
                tags: snippetData.tags,
            };

            return await this.updateSnippet(updateData);
        } catch (error: any) {
            console.error(`Failed to restore backup ${backupFile}`, error);
            vscode.window.showErrorMessage(`Failed to restore backup: ${error.message}`);
            return false;
        }
    }

    dispose() {
        this._onDidChangeSnippets.dispose();
    }
}
