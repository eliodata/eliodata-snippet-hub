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
exports.SnippetController = void 0;
const vscode = __importStar(require("vscode"));
const ConfigManager_1 = require("../core/ConfigManager");
class SnippetController {
    constructor(snippetProvider, snippetTreeDataProvider, context) {
        this.snippetProvider = snippetProvider;
        this.snippetTreeDataProvider = snippetTreeDataProvider;
        this.configManager = new ConfigManager_1.ConfigManager(context);
    }
    createSnippet() {
        return __awaiter(this, void 0, void 0, function* () {
            const name = yield vscode.window.showInputBox({ prompt: 'Nom du snippet' });
            if (!name) {
                return;
            }
            const description = yield vscode.window.showInputBox({ prompt: 'Description du snippet' });
            if (description === undefined) {
                return;
            }
            const content = yield vscode.window.showInputBox({ prompt: 'Contenu du snippet (code PHP)' });
            if (!content) {
                return;
            }
            yield this.snippetProvider.createSnippet({ name, code: content, description });
        });
    }
    deleteSnippet(snippet) {
        return __awaiter(this, void 0, void 0, function* () {
            if (!snippet) {
                const snippets = yield this.snippetProvider.getSnippets();
                const snippetItems = snippets.map(s => ({ label: s.name, snippet: s }));
                const selected = yield vscode.window.showQuickPick(snippetItems);
                if (selected) {
                    snippet = selected.snippet;
                }
                else {
                    return;
                }
            }
            const confirm = yield vscode.window.showWarningMessage(`Supprimer le snippet "${snippet.name}" ?`, { modal: true }, 'Oui');
            if (confirm === 'Oui') {
                yield this.snippetProvider.deleteSnippet(snippet.id);
            }
        });
    }
    reconfigure() {
        return __awaiter(this, void 0, void 0, function* () {
            const newConfig = yield this.configManager.manageConnections();
            if (newConfig) {
                vscode.commands.executeCommand('wordpressSnippets.refresh');
            }
            else {
                vscode.window.showInformationMessage('Configuration annulée.');
            }
        });
    }
    switchPlugin() {
        return __awaiter(this, void 0, void 0, function* () {
            const newConfig = yield this.configManager.switchPlugin();
            if (newConfig) {
                vscode.commands.executeCommand('wordpressSnippets.refresh');
            }
            else {
                vscode.window.showInformationMessage('Changement de plugin annulé.');
            }
        });
    }
    openSnippet(snippet) {
        return __awaiter(this, void 0, void 0, function* () {
            if (!snippet) {
                return;
            }
            const filePath = this.snippetProvider.getSnippetCachePath(snippet.id);
            try {
                const fs = require('fs').promises;
                yield fs.access(filePath);
                const doc = yield vscode.workspace.openTextDocument(filePath);
                yield vscode.window.showTextDocument(doc, { preview: false });
            }
            catch (error) {
                // Try to fetch the snippet and cache it
                try {
                    const fetchedSnippet = yield this.snippetProvider.getSnippet(snippet.id);
                    if (fetchedSnippet) {
                        const doc = yield vscode.workspace.openTextDocument(filePath);
                        yield vscode.window.showTextDocument(doc, { preview: false });
                    }
                    else {
                        vscode.window.showErrorMessage(`Snippet ${snippet.id} introuvable sur le serveur.`);
                    }
                }
                catch (fetchError) {
                    vscode.window.showErrorMessage(`Impossible d'ouvrir le snippet ${snippet.id}: ${fetchError.message}`);
                }
            }
        });
    }
    toggleSnippet(snippet) {
        return __awaiter(this, void 0, void 0, function* () {
            if (!snippet) {
                return;
            }
            yield this.snippetTreeDataProvider.toggleSnippet(snippet);
        });
    }
    // === Metadata management commands ===
    renameSnippet(item) {
        return __awaiter(this, void 0, void 0, function* () {
            const snippet = (item === null || item === void 0 ? void 0 : item.snippet) || item;
            if (!snippet || !snippet.id) {
                vscode.window.showWarningMessage('Aucun snippet sélectionné.');
                return;
            }
            const newName = yield vscode.window.showInputBox({
                prompt: 'Nouveau nom du snippet',
                value: snippet.name,
                placeHolder: 'Entrez le nouveau nom...',
                validateInput: (v) => v.trim() ? null : 'Le nom ne peut pas être vide'
            });
            if (newName && newName !== snippet.name) {
                if ('renameSnippet' in this.snippetProvider) {
                    const provider = this.snippetProvider;
                    const success = yield provider.renameSnippet(snippet.id, newName);
                    if (success) {
                        vscode.window.showInformationMessage(`Snippet renommé: "${newName}"`);
                    }
                }
                else {
                    vscode.window.showWarningMessage('Le renommage n\'est pas supporté pour ce type de plugin.');
                }
            }
        });
    }
    editDescription(item) {
        return __awaiter(this, void 0, void 0, function* () {
            const snippet = (item === null || item === void 0 ? void 0 : item.snippet) || item;
            if (!snippet || !snippet.id) {
                vscode.window.showWarningMessage('Aucun snippet sélectionné.');
                return;
            }
            // Fetch fresh data from server
            const freshSnippet = yield this.snippetProvider.getSnippet(snippet.id);
            const currentDesc = (freshSnippet === null || freshSnippet === void 0 ? void 0 : freshSnippet.description) || snippet.description || '';
            const newDescription = yield vscode.window.showInputBox({
                prompt: 'Description du snippet',
                value: currentDesc,
                placeHolder: 'Entrez la description...'
            });
            if (newDescription !== undefined && newDescription !== currentDesc) {
                if ('updateDescription' in this.snippetProvider) {
                    const provider = this.snippetProvider;
                    const success = yield provider.updateDescription(snippet.id, newDescription);
                    if (success) {
                        vscode.window.showInformationMessage('Description mise à jour.');
                    }
                }
                else {
                    vscode.window.showWarningMessage('La modification de description n\'est pas supportée pour ce type de plugin.');
                }
            }
        });
    }
    editTags(item) {
        return __awaiter(this, void 0, void 0, function* () {
            const snippet = (item === null || item === void 0 ? void 0 : item.snippet) || item;
            if (!snippet || !snippet.id) {
                vscode.window.showWarningMessage('Aucun snippet sélectionné.');
                return;
            }
            // Fetch fresh data from server
            const freshSnippet = yield this.snippetProvider.getSnippet(snippet.id);
            const currentTags = (freshSnippet === null || freshSnippet === void 0 ? void 0 : freshSnippet.tags) || snippet.tags || '';
            const newTags = yield vscode.window.showInputBox({
                prompt: 'Tags/mots-clés du snippet (séparés par des virgules)',
                value: currentTags,
                placeHolder: 'woocommerce, checkout, panier...'
            });
            if (newTags !== undefined && newTags !== currentTags) {
                if ('updateTags' in this.snippetProvider) {
                    const provider = this.snippetProvider;
                    const success = yield provider.updateTags(snippet.id, newTags);
                    if (success) {
                        vscode.window.showInformationMessage('Tags mis à jour.');
                    }
                }
                else {
                    vscode.window.showWarningMessage('La modification des tags n\'est pas supportée pour ce type de plugin.');
                }
            }
        });
    }
    restoreBackup(item) {
        return __awaiter(this, void 0, void 0, function* () {
            let snippetId;
            if (item && item.snippet) {
                snippetId = item.snippet.id;
            }
            else {
                const idStr = yield vscode.window.showInputBox({ prompt: 'Entrez l\'ID du snippet à restaurer' });
                if (!idStr) {
                    return;
                }
                const numericId = parseInt(idStr, 10);
                snippetId = isNaN(numericId) ? idStr : numericId;
            }
            const backups = yield this.snippetProvider.getBackups(snippetId);
            if (backups.length === 0) {
                vscode.window.showInformationMessage('Aucune sauvegarde trouvée pour ce snippet.');
                return;
            }
            const selectedBackup = yield vscode.window.showQuickPick(backups, {
                placeHolder: 'Sélectionner une sauvegarde à restaurer',
            });
            if (selectedBackup) {
                const success = yield this.snippetProvider.restoreBackup(snippetId, selectedBackup);
                if (success) {
                    vscode.window.showInformationMessage(`Snippet ${snippetId} restauré depuis ${selectedBackup}.`);
                    const snippet = yield this.snippetProvider.getSnippet(snippetId);
                    if (snippet) {
                        yield this.openSnippet(snippet);
                    }
                }
                else {
                    vscode.window.showErrorMessage('Échec de la restauration.');
                }
            }
        });
    }
    analyzeSnippet(id) {
        return __awaiter(this, void 0, void 0, function* () {
            let snippetId = id;
            if (!snippetId) {
                const editor = vscode.window.activeTextEditor;
                if (editor) {
                    const text = editor.document.getText();
                    const numericMatch = text.match(/\*\s*Snippet ID:\s*(\d+)/);
                    const fsMatch = text.match(/\*\s*Snippet ID:\s*(FS\d+)/);
                    if (fsMatch && fsMatch[1]) {
                        snippetId = fsMatch[1];
                    }
                    else if (numericMatch && numericMatch[1]) {
                        snippetId = parseInt(numericMatch[1], 10);
                    }
                }
            }
            if (!snippetId) {
                const idStr = yield vscode.window.showInputBox({ prompt: 'Entrez l\'ID du snippet à analyser' });
                if (idStr) {
                    const numericId = parseInt(idStr, 10);
                    snippetId = isNaN(numericId) ? idStr : numericId;
                }
                else {
                    return;
                }
            }
            if (snippetId) {
                const snippet = yield this.snippetProvider.getSnippet(snippetId);
                if (snippet) {
                    const analysis = `ID: ${snippet.id}\nNom: ${snippet.name}\nDescription: ${snippet.description}\nActif: ${snippet.active}\nTags: ${snippet.tags || 'aucun'}\n\nCode:\n---\n${snippet.code}`;
                    vscode.window.showInformationMessage(`Analyse: ${snippet.name}`, { modal: true, detail: analysis });
                }
                else {
                    vscode.window.showErrorMessage(`Snippet ID ${snippetId} introuvable.`);
                }
            }
        });
    }
}
exports.SnippetController = SnippetController;
//# sourceMappingURL=SnippetController.js.map