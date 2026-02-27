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
exports.FluentSnippetProvider = void 0;
const vscode = __importStar(require("vscode"));
const fs = __importStar(require("fs/promises"));
const path = __importStar(require("path"));
const ConfigManager_1 = require("../core/ConfigManager");
const ApiConnector_1 = require("../core/ApiConnector");
class FluentSnippetProvider {
    constructor(context) {
        this.context = context;
        this._onDidChangeSnippets = new vscode.EventEmitter();
        this.onDidChangeSnippets = this._onDidChangeSnippets.event;
        this.configManager = new ConfigManager_1.ConfigManager(context);
        // Initialize cache path - use workspace folder like SnippetProvider
        const workspaceFolders = vscode.workspace.workspaceFolders;
        if (workspaceFolders && workspaceFolders.length > 0) {
            this.cachePath = path.join(workspaceFolders[0].uri.fsPath, '.snippet_cache');
        }
        else {
            // Fallback to global storage if no workspace is open
            this.cachePath = path.join(context.globalStorageUri.fsPath, 'snippet_cache');
            vscode.window.showWarningMessage('No workspace folder is open. Snippet cache will be stored globally.');
        }
        this._ensureCacheDir();
    }
    initialize() {
        return __awaiter(this, void 0, void 0, function* () {
            const config = yield this.configManager.getConfig();
            if (!config || !config.siteUrl) {
                vscode.window.showErrorMessage('WordPress site URL is not configured.');
                return false;
            }
            if (!config.username || !config.applicationPassword) {
                vscode.window.showErrorMessage('WordPress credentials are not configured.');
                return false;
            }
            this.apiConnector = new ApiConnector_1.ApiConnector(config.siteUrl, config.username, config.applicationPassword);
            this.snippetsPath = config.fluentSnippetsPath;
            return true;
        });
    }
    dispose() {
        this._onDidChangeSnippets.dispose();
    }
    // ... implementation of SnippetPluginProvider methods ...
    // These will be implemented in the next steps.
    getSnippets(status = 'all') {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.apiConnector) {
                return [];
            }
            const snippets = [];
            try {
                // Use API to get FluentSnippets data
                const response = yield this.apiConnector.getFluentSnippets();
                if (response && response.snippets) {
                    // Vérifier que response.snippets est bien un tableau
                    if (!Array.isArray(response.snippets)) {
                        console.error('FluentSnippets API response.snippets is not an array:', response.snippets);
                        throw new Error('La réponse de l\'API FluentSnippets n\'est pas un tableau de snippets');
                    }
                    for (const snippetData of response.snippets) {
                        const apiSnippet = this.parseFluentSnippetFromApi(snippetData);
                        if (apiSnippet) {
                            // Check if we have a cached version first (it might have more recent status)
                            const cacheFilePath = this.getSnippetCachePath(apiSnippet.id);
                            let finalSnippet = apiSnippet;
                            try {
                                const cacheContent = yield fs.readFile(cacheFilePath, 'utf8');
                                const cachedSnippet = this.parseSnippetFromCache(cacheContent, apiSnippet.id);
                                if (cachedSnippet) {
                                    // Use cached snippet but ALWAYS prioritize API status from Internal Doc
                                    finalSnippet = Object.assign(Object.assign({}, apiSnippet), { active: apiSnippet.active, code: cachedSnippet.code || apiSnippet.code // Use cached code if available
                                     });
                                    console.log(`Using API status for snippet ${apiSnippet.id}: active = ${apiSnippet.active} (from Internal Doc)`);
                                    // Update cache with fresh API status
                                    yield this.createCacheFile(finalSnippet);
                                }
                            }
                            catch (cacheError) {
                                // No cache file exists, create one with API data
                                yield this.createCacheFile(apiSnippet);
                                console.log(`Created new cache for snippet ${apiSnippet.id}: active = ${apiSnippet.active}`);
                            }
                            const isActive = finalSnippet.active;
                            if (status === 'all' || (status === 'active' && isActive) || (status === 'inactive' && !isActive)) {
                                snippets.push(finalSnippet);
                            }
                        }
                    }
                }
            }
            catch (error) {
                vscode.window.showErrorMessage('Error fetching FluentSnippets: ' + error);
            }
            return snippets;
        });
    }
    parseIndexFile(content) {
        try {
            // Remove PHP opening tag and comments
            let cleanContent = content.replace(/<\?php[\s\S]*?\*\//, '');
            cleanContent = cleanContent.replace(/if \(!defined\("ABSPATH"\)\) \{return;\}/, '');
            cleanContent = cleanContent.replace(/return\s+/, '');
            cleanContent = cleanContent.replace(/;\s*$/, '');
            // Convert PHP array syntax to JSON-like format
            cleanContent = cleanContent.replace(/array\s*\(/g, '{');
            cleanContent = cleanContent.replace(/\)/g, '}');
            cleanContent = cleanContent.replace(/'([^']+)'\s*=>/g, '"$1":');
            cleanContent = cleanContent.replace(/=>/g, ':');
            cleanContent = cleanContent.replace(/,\s*}/g, '}');
            // Try to parse as JSON (this is a simplified approach)
            // For a more robust solution, we would need a proper PHP parser
            return eval('(' + cleanContent + ')');
        }
        catch (error) {
            console.error('Error parsing index.php:', error);
            return null;
        }
    }
    parseFluentSnippetFromApi(snippetData) {
        try {
            const numericId = snippetData.id || Math.floor(Math.random() * 10000);
            // Add FS prefix to avoid conflicts with CodeSnippets
            const id = `FS${numericId}`;
            // Extract the real name from the Internal Doc section in the code
            const nameFromDoc = this.extractNameFromInternalDoc(snippetData.code || '');
            const displayName = nameFromDoc || snippetData.name || 'Unnamed Snippet';
            // IMPORTANT: Read the real status from Internal Doc @status: published/draft
            // This is the authoritative source for FluentSnippets status
            const isActive = this.extractStatusFromInternalDoc(snippetData.code || '');
            return {
                id: id,
                name: displayName,
                description: snippetData.description || '',
                code: snippetData.code || '',
                active: isActive,
                scope: snippetData.scope || snippetData.run_at || 'backend',
                created: snippetData.created || snippetData.created_at || '',
                modified: snippetData.modified || snippetData.updated_at || '',
                tags: snippetData.tags || snippetData.group || ''
            };
        }
        catch (error) {
            console.error('Error parsing FluentSnippet from API:', error);
            return null;
        }
    }
    toggleSnippet(id, active) {
        return __awaiter(this, void 0, void 0, function* () {
            console.log(`Toggling Fluent snippet ${id} to ${active}`);
            if (!this.apiConnector) {
                throw new Error('Le fournisseur n\'est pas initialisé');
            }
            try {
                const response = yield this.apiConnector.toggleFluentSnippet(id, active);
                console.log(`API response for toggle Fluent snippet ${id}:`, response);
                if (response && response.success) {
                    // Supprimer le cache pour forcer la régénération avec les nouvelles données de l'API
                    const cacheFilePath = this.getSnippetCachePath(id);
                    try {
                        yield fs.unlink(cacheFilePath);
                        console.log(`Cache file deleted for snippet ${id} to force refresh`);
                    }
                    catch (error) {
                        // Cache file might not exist, that's ok
                    }
                    // Attendre un peu pour que l'API WordPress mette à jour le fichier
                    setTimeout(() => {
                        this._onDidChangeSnippets.fire();
                    }, 500);
                    return true;
                }
                else {
                    vscode.window.showErrorMessage(response.message || 'Failed to toggle FluentSnippet.');
                    return false;
                }
            }
            catch (error) {
                console.error(`Error toggling Fluent snippet ${id}:`, error);
                vscode.window.showErrorMessage('Erreur lors du changement de statut du snippet Fluent: ' + ((error === null || error === void 0 ? void 0 : error.message) || 'Erreur inconnue'));
                return false;
            }
        });
    }
    extractNameFromInternalDoc(content) {
        try {
            // Look for the @name: field in the Internal Doc section
            const nameMatch = content.match(/@name:\s*([^\n\r]+)/);
            if (nameMatch && nameMatch[1]) {
                return nameMatch[1].trim();
            }
            return null;
        }
        catch (error) {
            console.error('Error extracting name from Internal Doc:', error);
            return null;
        }
    }
    extractStatusFromInternalDoc(content) {
        try {
            // Look for the @status: field in the Internal Doc section, accounting for optional asterisk
            const statusMatch = content.match(/(?:\/\/|\*)?\s*@status:\s*([^\n\r]+)/);
            if (statusMatch && statusMatch[1]) {
                const status = statusMatch[1].trim().toLowerCase();
                console.log(`DEBUG: Found @status: '${status}' in content`);
                // FluentSnippets uses 'published' for active, 'draft' for inactive
                const isActive = status === 'published';
                console.log(`DEBUG: Status '${status}' -> active: ${isActive}`);
                return isActive;
            }
            console.log('DEBUG: No @status found in Internal Doc, defaulting to false');
            // If no @status found in Internal Doc, fallback to false (draft)
            return false;
        }
        catch (error) {
            console.error('Error extracting status from Internal Doc:', error);
            return false;
        }
    }
    parseSnippetFromCache(content, id) {
        try {
            // Extract metadata from cache file header
            const idMatch = content.match(/\* Snippet ID: (.+)/);
            const nameMatch = content.match(/\* Name: (.+)/);
            const descriptionMatch = content.match(/\* Description: (.+)/);
            // Extract the actual code (everything after the header)
            const codeStartIndex = content.indexOf('*/\n\n');
            const code = codeStartIndex !== -1 ? content.substring(codeStartIndex + 4).trim() : '';
            // Try to get the real name from Internal Doc section if available
            const nameFromDoc = this.extractNameFromInternalDoc(content);
            const displayName = nameFromDoc || (nameMatch ? nameMatch[1].trim() : 'Unnamed Snippet');
            // IMPORTANT: Read the real status from Internal Doc @status: published/draft
            // This is the authoritative source for FluentSnippets status
            const isActive = this.extractStatusFromInternalDoc(content);
            console.log(`Parsing snippet ${id} from cache: active = ${isActive} (from Internal Doc @status)`);
            return {
                id: id,
                name: displayName,
                description: descriptionMatch ? descriptionMatch[1].trim() : '',
                code: code,
                active: isActive,
                scope: 'backend',
                created: '',
                modified: '',
                tags: ''
            };
        }
        catch (error) {
            console.error('Error parsing snippet from cache:', error);
            return null;
        }
    }
    parseFluentSnippet(metadata, content, fileName) {
        try {
            // Extract ID from filename (e.g., "1-pilotage-par-sessions-tableau.php" -> 1)
            const idMatch = fileName.match(/^(\d+)-/);
            const numericId = idMatch ? parseInt(idMatch[1], 10) : Math.floor(Math.random() * 10000);
            // Add FS prefix to avoid conflicts with CodeSnippets
            const id = `FS${numericId}`;
            // Extract the real name from the Internal Doc section
            const nameFromDoc = this.extractNameFromInternalDoc(content);
            const displayName = nameFromDoc || metadata.name || 'Unnamed Snippet';
            // FluentSnippets uses different status values: 'published' = active, 'draft' = inactive
            const isActive = metadata.status === 'published' || metadata.status === 'active' || metadata.status === '1' || metadata.status === 1;
            return {
                id: id,
                name: displayName,
                description: metadata.description || '',
                code: content,
                active: isActive,
                scope: metadata.run_at || 'backend',
                created: metadata.created_at || '',
                modified: metadata.updated_at || '',
                tags: metadata.tags || metadata.group || ''
            };
        }
        catch (error) {
            console.error('Error parsing FluentSnippet:', error);
            return null;
        }
    }
    parseSnippet(content, filePath) {
        const idMatch = content.match(/@id\s+(\d+)/);
        const nameMatch = content.match(/@name\s+(.*)/);
        const descriptionMatch = content.match(/@description\s+(.*)/);
        const activeMatch = content.match(/@status\s+active/);
        const codeStartIndex = content.indexOf('?>');
        const code = codeStartIndex !== -1 ? content.substring(codeStartIndex + 2).trim() : '';
        if (nameMatch) {
            const numericId = idMatch ? parseInt(idMatch[1], 10) : 0;
            // Add FS prefix to avoid conflicts with CodeSnippets
            const id = `FS${numericId}`;
            return {
                id: id,
                name: nameMatch[1].trim(),
                description: descriptionMatch ? descriptionMatch[1].trim() : '',
                code: code,
                active: !!activeMatch,
                scope: '',
                created: '',
                modified: '',
                tags: '' // Not available in file
            };
        }
        return null;
    }
    getSnippet(id) {
        return __awaiter(this, void 0, void 0, function* () {
            // First try to find in cache
            const cacheFilePath = this.getSnippetCachePath(id);
            try {
                const content = yield fs.readFile(cacheFilePath, 'utf8');
                const cachedSnippet = this.parseSnippetFromCache(content, id);
                console.log(`Retrieved snippet ${id} from cache: active = ${cachedSnippet === null || cachedSnippet === void 0 ? void 0 : cachedSnippet.active}`);
                return cachedSnippet;
            }
            catch (error) {
                // Cache file doesn't exist, search in all snippets
                console.log(`Cache miss for snippet ${id}, searching in all snippets`);
                const snippets = yield this.getSnippets();
                const snippet = snippets.find(snippet => snippet.id === id) || null;
                if (snippet) {
                    yield this.createCacheFile(snippet);
                    console.log(`Created cache for snippet ${id}: active = ${snippet.active}`);
                }
                return snippet;
            }
        });
    }
    createSnippet(data) {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.snippetsPath) {
                return null;
            }
            const numericId = Date.now(); // Generate a unique numeric ID
            const id = `FS${numericId}`; // Add FS prefix
            const fileName = `snippet-${id}.php`;
            const filePath = path.join(this.snippetsPath, fileName);
            const content = `<?php
/**
 * @name ${data.name}
 * @description ${data.description}
 * @status ${data.active ? 'active' : 'inactive'}
 * @id ${numericId}
 */

?>${data.code}`;
            try {
                yield fs.writeFile(filePath, content);
                this._onDidChangeSnippets.fire();
                return this.parseSnippet(content, filePath);
            }
            catch (error) {
                vscode.window.showErrorMessage('Error creating FluentSnippet: ' + error);
                return null;
            }
        });
    }
    updateSnippet(data) {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.snippetsPath) {
                return false;
            }
            // To update, we need to find the file associated with the snippet ID.
            // This is inefficient and a limitation of not having a proper mapping.
            const snippets = yield this.getSnippets();
            const snippet = snippets.find(s => s.id === data.id);
            if (!snippet) {
                return false;
            }
            // Extract numeric ID from FS prefixed ID
            const numericId = typeof data.id === 'string' && data.id.startsWith('FS')
                ? data.id.substring(2)
                : data.id;
            const fileName = `snippet-${data.id}.php`;
            const filePath = path.join(this.snippetsPath, fileName);
            const content = `<?php
/**
 * @name ${data.name}
 * @description ${data.description}
 * @status ${data.active ? 'active' : 'inactive'}
 * @id ${numericId}
 */

?>${data.code}`;
            try {
                yield fs.writeFile(filePath, content);
                this._onDidChangeSnippets.fire();
                return true;
            }
            catch (error) {
                vscode.window.showErrorMessage('Error updating FluentSnippet: ' + error);
                return false;
            }
        });
    }
    deleteSnippet(id) {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.snippetsPath) {
                return false;
            }
            const fileName = `snippet-${id}.php`;
            const filePath = path.join(this.snippetsPath, fileName);
            try {
                yield fs.unlink(filePath);
                this._onDidChangeSnippets.fire();
                return true;
            }
            catch (error) {
                vscode.window.showErrorMessage('Error deleting FluentSnippet: ' + error);
                return false;
            }
        });
    }
    updateSnippetFromFile(filePath) {
        return __awaiter(this, void 0, void 0, function* () {
            // Check if this is a cache file that needs to be synced to FluentSnippets
            if (filePath.includes('.snippet_cache') && filePath.endsWith('.php')) {
                try {
                    // Extract snippet ID from cache file path
                    const fileName = path.basename(filePath);
                    const idMatch = fileName.match(/snippet-(.+)\.php$/);
                    if (!idMatch) {
                        this._onDidChangeSnippets.fire();
                        return;
                    }
                    const snippetId = idMatch[1];
                    // Read the modified cache file
                    const cacheContent = yield fs.readFile(filePath, 'utf8');
                    const snippet = this.parseSnippetFromCache(cacheContent, snippetId);
                    if (snippet && this.apiConnector) {
                        // Find the real FluentSnippets storage path
                        const response = yield this.apiConnector.getFluentSnippets();
                        if (response && response.snippets) {
                            // Find the corresponding snippet in FluentSnippets
                            const numericId = typeof snippetId === 'string' && snippetId.startsWith('FS')
                                ? snippetId.substring(2)
                                : snippetId;
                            const fluentSnippet = response.snippets.find((s) => s.id.toString() === numericId.toString());
                            if (fluentSnippet) {
                                // Update the real FluentSnippets file
                                yield this.syncCacheToFluentSnippets(snippet, numericId);
                                vscode.window.showInformationMessage(`Snippet ${snippet.name} synchronized to FluentSnippets!`);
                            }
                        }
                    }
                }
                catch (error) {
                    console.error('Error syncing cache to FluentSnippets:', error);
                    vscode.window.showErrorMessage('Failed to sync changes to FluentSnippets: ' + error);
                }
            }
            // Refresh the view
            this._onDidChangeSnippets.fire();
        });
    }
    isSnippetFile(filePath) {
        // Check if it's a real FluentSnippets file
        if (this.snippetsPath && filePath.startsWith(this.snippetsPath)) {
            return true;
        }
        // Check if it's a cache file that needs to be synced
        return filePath.includes('.snippet_cache') && filePath.endsWith('.php');
    }
    getSnippetCachePath(id) {
        return path.join(this.cachePath, `snippet-${id}.php`);
    }
    _ensureCacheDir() {
        return __awaiter(this, void 0, void 0, function* () {
            try {
                yield fs.mkdir(this.cachePath, { recursive: true });
            }
            catch (error) {
                console.error("Failed to create snippet cache directory", error);
                vscode.window.showErrorMessage("Failed to create snippet cache directory.");
            }
        });
    }
    createCacheFile(snippet) {
        return __awaiter(this, void 0, void 0, function* () {
            try {
                yield this._ensureCacheDir();
                const filePath = this.getSnippetCachePath(snippet.id);
                // Extract the Internal Doc section and the actual PHP code separately
                let internalDoc = '';
                let actualCode = '';
                const originalCode = snippet.code;
                // Look for Internal Doc section
                const internalDocStart = originalCode.indexOf('// <Internal Doc Start>');
                const internalDocEnd = originalCode.indexOf('// <Internal Doc End>');
                if (internalDocStart !== -1 && internalDocEnd !== -1) {
                    // Extract Internal Doc section
                    internalDoc = originalCode.substring(internalDocStart, internalDocEnd + '// <Internal Doc End>'.length);
                    // Extract code after Internal Doc
                    const codeAfterDoc = originalCode.substring(internalDocEnd + '// <Internal Doc End>'.length);
                    actualCode = codeAfterDoc.replace(/^\s*\?>\s*/, '').trim();
                }
                else {
                    // Fallback: extract code after first comment block
                    if (originalCode.includes('<?php') && originalCode.includes('*/')) {
                        const headerEndIndex = originalCode.indexOf('*/');
                        if (headerEndIndex !== -1) {
                            actualCode = originalCode.substring(headerEndIndex + 2).trim();
                            actualCode = actualCode.replace(/^<\?php\s*/g, '');
                        }
                    }
                    else {
                        actualCode = originalCode;
                    }
                }
                // Remove any remaining <?php tags from actual code
                actualCode = actualCode.replace(/^<\?php\s*/g, '');
                // Extract the real status from Internal Doc to set @active correctly
                const realStatus = this.extractStatusFromInternalDoc(originalCode);
                // Update the @status in Internal Doc to match the real status
                let updatedInternalDoc = internalDoc;
                if (internalDoc) {
                    const statusValue = realStatus ? 'published' : 'draft';
                    updatedInternalDoc = internalDoc.replace(/(?:\/\/|\*)?\s*@status:\s*[^\n\r]+/, `* @status: ${statusValue}`);
                }
                const content = `<?php
/**
 * Snippet ID: ${snippet.id}
 * Name: ${snippet.name}
 * Description: ${snippet.description}
 * @active ${realStatus ? 'true' : 'false'}
 */

${updatedInternalDoc}
${actualCode}`;
                yield fs.writeFile(filePath, content);
                console.log(`Cache file updated for snippet ${snippet.id} - Internal Doc preserved, clean code extracted`);
            }
            catch (error) {
                console.error(`Failed to create cache file for snippet ${snippet.id}`, error);
            }
        });
    }
    getBackups(snippetId) {
        return __awaiter(this, void 0, void 0, function* () {
            vscode.window.showInformationMessage('Backup and restore is not supported for FluentSnippets in this extension.');
            return [];
        });
    }
    restoreBackup(snippetId, backupFile) {
        return __awaiter(this, void 0, void 0, function* () {
            vscode.window.showInformationMessage('Backup and restore is not supported for FluentSnippets in this extension.');
            return false;
        });
    }
    /**
     * Synchronize cache changes to the real FluentSnippets file
     */
    syncCacheToFluentSnippets(snippet, numericId) {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.apiConnector) {
                throw new Error('API connector not available');
            }
            try {
                // Get the FluentSnippets storage path from WordPress
                const response = yield this.apiConnector.getFluentSnippets();
                if (!response || !response.snippets) {
                    throw new Error('Could not retrieve FluentSnippets data');
                }
                // Find the snippet file name pattern
                const fluentSnippet = response.snippets.find((s) => s.id.toString() === numericId.toString());
                if (!fluentSnippet) {
                    throw new Error(`FluentSnippet with ID ${numericId} not found`);
                }
                // Create the updated content in FluentSnippets format
                const fluentContent = `<?php\n${snippet.code}`;
                // Use WordPress API to update the file
                // Since we don't have direct file system access, we'll need to create an API endpoint
                // For now, we'll use a workaround by calling a custom endpoint
                const updateData = {
                    id: numericId,
                    content: fluentContent,
                    name: snippet.name
                };
                // Make API call to update FluentSnippets file
                yield this.updateFluentSnippetFile(updateData);
            }
            catch (error) {
                console.error('Error syncing to FluentSnippets:', error);
                throw error;
            }
        });
    }
    /**
     * Update FluentSnippets file via API
     */
    updateFluentSnippetFile(data) {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.apiConnector) {
                throw new Error('API connector not available');
            }
            try {
                // Use the new API endpoint to update FluentSnippets file
                const response = yield this.apiConnector.updateFluentSnippet(data.id, {
                    content: data.content
                });
                if (response && response.success) {
                    console.log(`FluentSnippet ${data.id} updated successfully:`, response.message);
                }
                else {
                    throw new Error('Failed to update FluentSnippets file');
                }
            }
            catch (error) {
                console.error('Error updating FluentSnippets file:', error);
                // Fallback to manual sync message
                vscode.window.showWarningMessage(`Failed to automatically sync FluentSnippets file. ` +
                    `Error: ${error}. ` +
                    `Please manually copy the changes from the cache file to your FluentSnippets file.`);
                throw error;
            }
        });
    }
}
exports.FluentSnippetProvider = FluentSnippetProvider;
//# sourceMappingURL=FluentSnippetProvider.js.map