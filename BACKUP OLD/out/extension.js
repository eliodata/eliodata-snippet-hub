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
exports.deactivate = exports.activate = void 0;
const vscode = __importStar(require("vscode"));
const SnippetController_1 = require("./controllers/SnippetController");
const SnippetTreeDataProvider_1 = require("./providers/SnippetTreeDataProvider");
const SnippetProviderFactory_1 = require("./providers/SnippetProviderFactory");
const ConfigManager_1 = require("./core/ConfigManager");
function activate(context) {
    return __awaiter(this, void 0, void 0, function* () {
        const configManager = new ConfigManager_1.ConfigManager(context);
        let config = yield configManager.getActiveConnection();
        if (!config) {
            const connections = yield configManager.getAllConnections();
            if (connections.length > 0) {
                config = yield configManager.manageConnections();
            }
            else {
                config = yield configManager.promptForConfig();
                if (config) {
                    config.id = `wp_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
                    config.name = config.name || config.siteUrl;
                    yield configManager.addConnection(config);
                    yield configManager.setActiveConnection(config.id);
                }
            }
            if (!config) {
                return;
            }
        }
        let provider = yield (0, SnippetProviderFactory_1.createSnippetProvider)(context, config);
        if (!provider) {
            return;
        }
        yield provider.initialize();
        const snippetTreeDataProvider = new SnippetTreeDataProvider_1.SnippetTreeDataProvider(provider);
        vscode.window.registerTreeDataProvider('wordpress-snippets-view', snippetTreeDataProvider);
        const controller = new SnippetController_1.SnippetController(provider, snippetTreeDataProvider, context);
        // === Core commands ===
        context.subscriptions.push(vscode.commands.registerCommand('wordpressSnippets.list', () => snippetTreeDataProvider.refresh()), vscode.commands.registerCommand('wordpressSnippets.create', () => __awaiter(this, void 0, void 0, function* () {
            yield controller.createSnippet();
            snippetTreeDataProvider.refresh();
        })), vscode.commands.registerCommand('wordpressSnippets.refresh', () => snippetTreeDataProvider.refresh()), vscode.commands.registerCommand('wordpressSnippets.createSnippet', () => __awaiter(this, void 0, void 0, function* () {
            yield controller.createSnippet();
            snippetTreeDataProvider.refresh();
        })), vscode.commands.registerCommand('wordpressSnippets.delete', (item) => __awaiter(this, void 0, void 0, function* () {
            yield controller.deleteSnippet(item);
            snippetTreeDataProvider.refresh();
        })), vscode.commands.registerCommand('wordpressSnippets.configure', () => __awaiter(this, void 0, void 0, function* () {
            yield controller.reconfigure();
            snippetTreeDataProvider.refresh();
        })), vscode.commands.registerCommand('wordpressSnippets.openSnippet', (item) => controller.openSnippet(item)), vscode.commands.registerCommand('wordpressSnippets.toggleSnippet', (item) => __awaiter(this, void 0, void 0, function* () {
            yield controller.toggleSnippet(item);
            snippetTreeDataProvider.refresh();
        })), 
        // === Sort & Filter ===
        vscode.commands.registerCommand('wordpressSnippets.sortAsc', () => snippetTreeDataProvider.setSortOrder('asc')), vscode.commands.registerCommand('wordpressSnippets.sortDesc', () => snippetTreeDataProvider.setSortOrder('desc')), vscode.commands.registerCommand('wordpressSnippets.filterActive', () => snippetTreeDataProvider.setFilter('active')), vscode.commands.registerCommand('wordpressSnippets.filterInactive', () => snippetTreeDataProvider.setFilter('inactive')), vscode.commands.registerCommand('wordpressSnippets.filterAll', () => snippetTreeDataProvider.setFilter('all')), 
        // === Search ===
        vscode.commands.registerCommand('wordpressSnippets.searchSnippets', () => __awaiter(this, void 0, void 0, function* () {
            const currentTerm = snippetTreeDataProvider.getSearchTerm();
            const searchTerm = yield vscode.window.showInputBox({
                prompt: 'Rechercher des snippets (par nom, description, code ou ID)',
                value: currentTerm,
                placeHolder: 'Tapez votre recherche ou un ID de snippet...'
            });
            if (searchTerm !== undefined) {
                snippetTreeDataProvider.setSearchTerm(searchTerm);
                setTimeout(() => {
                    const statusMessage = snippetTreeDataProvider.getStatusMessage();
                    if (statusMessage) {
                        vscode.window.setStatusBarMessage(`🔍 ${statusMessage}`, 5000);
                    }
                    else if (searchTerm.trim() === '') {
                        vscode.window.setStatusBarMessage('🔍 Recherche effacée', 2000);
                    }
                }, 500);
            }
        })), vscode.commands.registerCommand('wordpressSnippets.clearSearch', () => {
            snippetTreeDataProvider.clearSearch();
            vscode.window.setStatusBarMessage('🔍 Recherche effacée', 2000);
        }), 
        // === Snippet metadata management ===
        vscode.commands.registerCommand('wordpressSnippets.renameSnippet', (item) => __awaiter(this, void 0, void 0, function* () {
            yield controller.renameSnippet(item);
            snippetTreeDataProvider.refresh();
        })), vscode.commands.registerCommand('wordpressSnippets.editDescription', (item) => __awaiter(this, void 0, void 0, function* () {
            yield controller.editDescription(item);
            snippetTreeDataProvider.refresh();
        })), vscode.commands.registerCommand('wordpressSnippets.editTags', (item) => __awaiter(this, void 0, void 0, function* () {
            yield controller.editTags(item);
            snippetTreeDataProvider.refresh();
        })), 
        // === Other commands ===
        vscode.commands.registerCommand('wordpressSnippets.analyzeSnippet', () => controller.analyzeSnippet()), vscode.commands.registerCommand('wordpressSnippets.restoreBackup', (item) => controller.restoreBackup(item)), 
        // === Plugin & connection management ===
        vscode.commands.registerCommand('wordpress-snippets.switchPlugin', () => __awaiter(this, void 0, void 0, function* () {
            const newConfig = yield configManager.switchPlugin();
            if (newConfig) {
                const newProvider = yield (0, SnippetProviderFactory_1.createSnippetProvider)(context, newConfig);
                if (newProvider) {
                    yield newProvider.initialize();
                    provider = newProvider;
                    snippetTreeDataProvider.updateProvider(provider);
                    snippetTreeDataProvider.refresh();
                }
            }
        })), vscode.commands.registerCommand('wordpressSnippets.manageConnections', () => __awaiter(this, void 0, void 0, function* () {
            const newConfig = yield configManager.manageConnections();
            if (newConfig) {
                const newProvider = yield (0, SnippetProviderFactory_1.createSnippetProvider)(context, newConfig);
                if (newProvider) {
                    yield newProvider.initialize();
                    provider = newProvider;
                    snippetTreeDataProvider.updateProvider(provider);
                    snippetTreeDataProvider.refresh();
                }
            }
        })), vscode.commands.registerCommand('wordpressSnippets.switchConnection', () => __awaiter(this, void 0, void 0, function* () {
            const connections = yield configManager.getAllConnections();
            if (connections.length === 0) {
                vscode.window.showInformationMessage('Aucune connexion configurée.');
                return;
            }
            const activeConnection = yield configManager.getActiveConnection();
            const connectionOptions = connections.map(conn => ({
                label: conn.name || conn.siteUrl,
                description: `${conn.siteUrl} (${conn.plugin})`,
                detail: (activeConnection === null || activeConnection === void 0 ? void 0 : activeConnection.id) === conn.id ? '🟢 Connexion active' : '',
                connection: conn
            }));
            const selected = yield vscode.window.showQuickPick(connectionOptions, {
                placeHolder: 'Choisir la connexion WordPress active'
            });
            if (selected && selected.connection.id !== (activeConnection === null || activeConnection === void 0 ? void 0 : activeConnection.id)) {
                const newConfig = yield configManager.setActiveConnection(selected.connection.id);
                if (newConfig) {
                    const newProvider = yield (0, SnippetProviderFactory_1.createSnippetProvider)(context, newConfig);
                    if (newProvider) {
                        yield newProvider.initialize();
                        provider = newProvider;
                        snippetTreeDataProvider.updateProvider(provider);
                        snippetTreeDataProvider.refresh();
                        vscode.window.showInformationMessage(`Connexion active: ${selected.label}`);
                    }
                }
            }
        })));
        // === Auto-save on file change ===
        context.subscriptions.push(vscode.workspace.onDidSaveTextDocument((document) => __awaiter(this, void 0, void 0, function* () {
            if (provider && provider.isSnippetFile(document.uri.fsPath)) {
                yield provider.updateSnippetFromFile(document.uri.fsPath);
            }
        })));
    });
}
exports.activate = activate;
function deactivate() { }
exports.deactivate = deactivate;
//# sourceMappingURL=extension.js.map