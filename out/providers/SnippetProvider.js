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
const ObsidianVaultStorage_1 = require("../core/ObsidianVaultStorage");
const FrontmatterEnrichmentService_1 = require("../services/FrontmatterEnrichmentService");
const PhpLinter_1 = require("../utils/PhpLinter");
class SnippetProvider {
    constructor(context) {
        this.context = context;
        this._onDidChangeSnippets = new vscode.EventEmitter();
        this.onDidChangeSnippets = this._onDidChangeSnippets.event;
        this.apiConnector = null;
        this.storage = null;
        this.engine = 'native';
        this.syncingFiles = new Set();
        this.enrichmentService = new FrontmatterEnrichmentService_1.FrontmatterEnrichmentService();
        this.syncOptions = {
            syncRole: 'owner',
            canSyncToWordPress: () => true,
            canWriteVaultFromRemote: () => true,
            getStatusMessage: () => 'Ce workspace pilote la synchronisation.'
        };
        this.warnedUnsynced = new Set();
        this.configManager = new ConfigManager_1.ConfigManager(context);
    }
    getSnippetCachePath(id) {
        return this.storage ? this.storage.getSnippetFilePath(id) : '';
    }
    configureSync(options) {
        this.syncOptions = options;
    }
    /**
     * Snippets are sent to WordPress only from a trusted workspace, so that a
     * cloned repository cannot push PHP code to a site through its settings.
     */
    canSyncToWordPress() {
        return vscode.workspace.isTrusted && this.syncOptions.canSyncToWordPress();
    }
    canWriteVaultFromRemote() {
        return this.syncOptions.canWriteVaultFromRemote();
    }
    getSyncStatusMessage() {
        if (!vscode.workspace.isTrusted) {
            return 'Workspace non approuvé : aucune modification n’est envoyée à WordPress.';
        }
        return this.syncOptions.getStatusMessage();
    }
    isSnippetFile(filePath) {
        return this.storage ? this.storage.isSnippetFile(filePath) : false;
    }
    /**
     * Strip any existing header block and <?php tag from code.
     * Returns only the raw PHP code content.
     */
    stripHeaderAndPhpTag(code) {
        let cleaned = code;
        cleaned = cleaned.replace(/^\uFEFF?\s*<\?(?:php)?\s*/i, '');
        // Only the header written by the v3 cache format (" * Snippet ID: N"); a doc comment
        // written by the user is code and must reach WordPress unchanged
        cleaned = cleaned.replace(/^\s*\/\*\*(?:(?!\*\/)[\s\S])*?\*\s*Snippet ID:\s*\d+[\s\S]*?\*\/\s*/, '');
        cleaned = cleaned.replace(/^\uFEFF?\s*<\?(?:php)?\s*/i, '');
        cleaned = cleaned.replace(/\?>\s*$/, '');
        return cleaned.trim();
    }
    async writeCacheFile(snippet) {
        if (!this.storage)
            return;
        const filePath = this.storage.getSnippetFilePath(snippet.id);
        const openDocument = vscode.workspace.textDocuments.find(document => document.uri.fsPath === filePath);
        if (openDocument?.isDirty) {
            return;
        }
        // A file changed outside the editor since the last sync keeps its changes: the
        // server version would silently replace them (see hasUnsyncedLocalChanges).
        if (this.storage.hasUnsyncedLocalChanges
            && await this.storage.hasUnsyncedLocalChanges(snippet.id, snippet.code || '')) {
            this.warnUnsyncedLocalChanges(snippet, filePath);
            return;
        }
        await this.storage.write(snippet);
    }
    warnUnsyncedLocalChanges(snippet, filePath) {
        const key = String(snippet.id);
        if (this.warnedUnsynced.has(key)) {
            return;
        }
        this.warnedUnsynced.add(key);
        const label = `snippet-${snippet.id}`;
        vscode.window.showWarningMessage(`${label} a été modifié hors de l’éditeur et diffère de WordPress : la version du serveur ne l’a pas écrasé. Ouvrez-le et enregistrez-le pour le publier.`, 'Ouvrir').then(choice => {
            if (choice === 'Ouvrir') {
                vscode.window.showTextDocument(vscode.Uri.file(filePath));
            }
        });
    }
    async ensureLocalSnippetFile(snippet) {
        if (!this.storage) {
            return;
        }
        const filePath = this.storage.getSnippetFilePath(snippet.id);
        try {
            await fs.access(filePath);
            return;
        }
        catch {
            await this.storage.write(snippet);
        }
    }
    /**
     * Refuse to send code with a PHP syntax error: on WordPress it would run on every request.
     */
    async ensureValidPhp(code, label) {
        if (typeof code !== 'string' || code.trim() === '' || this.engine !== 'native') {
            return true;
        }
        const result = await (0, PhpLinter_1.lintPhpCode)(code);
        if (result.status !== 'error') {
            return true;
        }
        const where = result.line ? ` (ligne ${result.line} du code)` : '';
        vscode.window.showErrorMessage(`${label} non envoyé : erreur de syntaxe PHP${where}. ${result.message}`);
        return false;
    }
    ensureWordPressWriteAllowed(actionLabel) {
        if (this.canSyncToWordPress()) {
            return true;
        }
        vscode.window.showWarningMessage(`${actionLabel} indisponible dans ce workspace. ${this.getSyncStatusMessage()}`);
        return false;
    }
    async initialize() {
        const config = await this.configManager.getActiveConnection();
        if (!config) {
            return false;
        }
        const currentConfig = await this.configManager.getActiveConnection();
        if (!currentConfig) {
            return false;
        }
        this.storage = new ObsidianVaultStorage_1.ObsidianVaultStorage(this.context, currentConfig.siteUrl, currentConfig.vaultSiteFolder);
        await this.storage.initialize();
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
            if (this.canWriteVaultFromRemote()) {
                for (const snippet of snippets) {
                    await this.writeCacheFile(snippet);
                }
            }
            return snippets;
        }
        catch (error) {
            vscode.window.showErrorMessage('Erreur lors de la récupération des snippets : ' + (error?.message || 'Erreur inconnue'));
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
                if (this.canWriteVaultFromRemote()) {
                    await this.writeCacheFile(snippet);
                }
                else {
                    await this.ensureLocalSnippetFile(snippet);
                }
            }
            return snippet;
        }
        catch (error) {
            vscode.window.showErrorMessage('Erreur lors de la récupération du snippet : ' + (error?.message || 'Erreur inconnue'));
            return null;
        }
    }
    async createSnippet(data) {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        if (!this.ensureWordPressWriteAllowed('Creation de snippet')) {
            return null;
        }
        if (!await this.ensureValidPhp(data.code, 'Snippet')) {
            return null;
        }
        try {
            const result = await this.apiConnector.createSnippet(data, this.engine);
            this._onDidChangeSnippets.fire();
            return result;
        }
        catch (error) {
            vscode.window.showErrorMessage('Erreur lors de la création du snippet : ' + (error?.message || 'Erreur inconnue'));
            return null;
        }
    }
    async updateSnippet(data) {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        if (!this.ensureWordPressWriteAllowed('Mise a jour du snippet')) {
            return false;
        }
        if (typeof data.id === 'string' && data.id.startsWith('FS')) {
            return false;
        }
        if (!await this.ensureValidPhp(data.code, `Snippet ${data.id}`)) {
            return false;
        }
        try {
            await this.apiConnector.updateSnippet(data.id, data, this.engine);
            this._onDidChangeSnippets.fire();
            return true;
        }
        catch (error) {
            vscode.window.showErrorMessage('Erreur lors de la mise à jour du snippet : ' + (error?.message || 'Erreur inconnue'));
            return false;
        }
    }
    async toggleSnippet(id, active) {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        if (!this.ensureWordPressWriteAllowed('Changement de statut du snippet')) {
            return false;
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
            vscode.window.showErrorMessage('Erreur lors du changement de statut : ' + (error?.message || 'Erreur inconnue'));
            return false;
        }
    }
    async deleteSnippet(id) {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        if (!this.ensureWordPressWriteAllowed('Suppression du snippet')) {
            return false;
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
            vscode.window.showErrorMessage('Erreur lors de la suppression du snippet : ' + (error?.message || 'Erreur inconnue'));
            return false;
        }
    }
    async updateSnippetFromFile(filePath) {
        if (!this.isSnippetFile(filePath) || !this.storage) {
            return;
        }
        if (!this.canSyncToWordPress()) {
            return;
        }
        if (!this.apiConnector) {
            vscode.window.showErrorMessage('Impossible de synchroniser ce snippet : l’API WordPress n’est pas connectée.');
            return;
        }
        if (this.syncingFiles.has(filePath)) {
            return;
        }
        this.syncingFiles.add(filePath);
        try {
            // Read from storage using id parsed from filename
            const idMatch = path.basename(filePath).match(/snippet-(.+)\.md$/);
            if (!idMatch)
                return;
            const id = idMatch[1];
            const parsed = await this.storage.read(id);
            if (!parsed) {
                vscode.window.showWarningMessage(`Impossible de lire le snippet local ${path.basename(filePath)}.`);
                return;
            }
            // Fetch original from API for backup
            const originalSnippet = await this.apiConnector.getSnippet(id, this.engine);
            if (!originalSnippet) {
                vscode.window.showErrorMessage(`Le snippet ${id} n’existe plus sur le site WordPress.`);
                return;
            }
            // Create backup before syncing
            await this.storage.createBackup(originalSnippet);
            // Checked before stripping so that reported lines match the file
            if (!await this.ensureValidPhp(parsed.code, path.basename(filePath))) {
                return;
            }
            // Strip any header artifacts from extracted code
            const cleanCode = this.stripHeaderAndPhpTag(parsed.code);
            const updateData = {
                id: originalSnippet.id,
                name: parsed.name || originalSnippet.name,
                description: parsed.description !== null ? parsed.description : originalSnippet.description,
                code: cleanCode,
                active: parsed.active ?? originalSnippet.active,
                tags: parsed.tags || originalSnippet.tags,
            };
            if (!await this.updateSnippet(updateData)) {
                return;
            }
            // Re-fetch from API and rewrite cache to ensure clean header
            const updatedSnippet = await this.apiConnector.getSnippet(id, this.engine);
            if (updatedSnippet) {
                await this.writeCacheFile(updatedSnippet);
                await this.enrichmentService.enrichSnippetRelationsForFile(filePath);
            }
            vscode.window.setStatusBarMessage(`Snippet synchronisé : ${updateData.name}`, 3000);
        }
        catch (error) {
            console.error(`Failed to update snippet from file ${filePath}`, error);
            vscode.window.showErrorMessage(`Impossible de synchroniser le snippet : ${error.message}`);
        }
        finally {
            this.syncingFiles.delete(filePath);
        }
    }
    /**
     * Rename a snippet on the WordPress server
     */
    async renameSnippet(id, newName) {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        if (!this.ensureWordPressWriteAllowed('Renommage du snippet')) {
            return false;
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
            vscode.window.showErrorMessage('Erreur lors du renommage : ' + (error?.message || 'Erreur inconnue'));
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
        if (!this.ensureWordPressWriteAllowed('Mise a jour de la description')) {
            return false;
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
            vscode.window.showErrorMessage('Erreur lors de la mise à jour de la description : ' + (error?.message || 'Erreur inconnue'));
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
        if (!this.ensureWordPressWriteAllowed('Mise a jour des tags')) {
            return false;
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
            vscode.window.showErrorMessage('Erreur lors de la mise à jour des mots-clés : ' + (error?.message || 'Erreur inconnue'));
            return false;
        }
    }
    async updateAttribution(id, targetMode, targetPostTypes, targetPostIds) {
        if (!this.apiConnector) {
            throw new Error('Le fournisseur n\'est pas initialisé');
        }
        if (!this.ensureWordPressWriteAllowed('Mise a jour des attributions')) {
            return false;
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
            vscode.window.showErrorMessage('Erreur lors de la mise à jour des attributions : ' + (error?.message || 'Erreur inconnue'));
            return false;
        }
    }
    async getBackups(snippetId) {
        return this.storage ? this.storage.getBackups(snippetId) : [];
    }
    async restoreBackup(snippetId, backupFile) {
        if (!this.storage)
            return false;
        if (!this.ensureWordPressWriteAllowed('Restauration de sauvegarde')) {
            return false;
        }
        try {
            const snippetData = await this.storage.restoreBackup(snippetId, backupFile);
            if (!snippetData)
                return false;
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
            vscode.window.showErrorMessage(`Impossible de restaurer la sauvegarde : ${error.message}`);
            return false;
        }
    }
    dispose() {
        this._onDidChangeSnippets.dispose();
    }
}
exports.SnippetProvider = SnippetProvider;
//# sourceMappingURL=SnippetProvider.js.map