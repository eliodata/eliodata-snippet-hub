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
exports.ConfigManager = void 0;
const vscode = __importStar(require("vscode"));
const ApiConnector_1 = require("./ApiConnector");
class ConfigManager {
    constructor(context) {
        this.context = context;
    }
    saveConfig(config) {
        return __awaiter(this, void 0, void 0, function* () {
            yield this.context.secrets.store(ConfigManager.CONFIG_KEY, JSON.stringify(config));
        });
    }
    getConfig() {
        return __awaiter(this, void 0, void 0, function* () {
            const configStr = yield this.context.secrets.get(ConfigManager.CONFIG_KEY);
            if (!configStr) {
                return null;
            }
            return JSON.parse(configStr);
        });
    }
    clearConfig() {
        return __awaiter(this, void 0, void 0, function* () {
            yield this.context.secrets.delete(ConfigManager.CONFIG_KEY);
        });
    }
    // Nouvelles méthodes pour la gestion multi-sites
    getMultiSiteConfig() {
        return __awaiter(this, void 0, void 0, function* () {
            const configStr = yield this.context.secrets.get(ConfigManager.MULTI_SITE_CONFIG_KEY);
            if (!configStr) {
                return { connections: [] };
            }
            return JSON.parse(configStr);
        });
    }
    saveMultiSiteConfig(config) {
        return __awaiter(this, void 0, void 0, function* () {
            yield this.context.secrets.store(ConfigManager.MULTI_SITE_CONFIG_KEY, JSON.stringify(config));
        });
    }
    addConnection(connection) {
        return __awaiter(this, void 0, void 0, function* () {
            const multiConfig = yield this.getMultiSiteConfig();
            // Générer un ID unique si pas fourni
            if (!connection.id) {
                connection.id = `wp_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
            }
            // Vérifier si une connexion avec cette URL existe déjà
            const existingIndex = multiConfig.connections.findIndex(c => c.siteUrl === connection.siteUrl);
            if (existingIndex >= 0) {
                multiConfig.connections[existingIndex] = connection;
            }
            else {
                multiConfig.connections.push(connection);
            }
            // Si c'est la première connexion, la marquer comme active
            if (multiConfig.connections.length === 1) {
                multiConfig.activeConnectionId = connection.id;
            }
            yield this.saveMultiSiteConfig(multiConfig);
        });
    }
    removeConnection(connectionId) {
        return __awaiter(this, void 0, void 0, function* () {
            const multiConfig = yield this.getMultiSiteConfig();
            multiConfig.connections = multiConfig.connections.filter(c => c.id !== connectionId);
            // Si la connexion supprimée était active, choisir une nouvelle connexion active
            if (multiConfig.activeConnectionId === connectionId) {
                multiConfig.activeConnectionId = multiConfig.connections.length > 0 ? multiConfig.connections[0].id : undefined;
            }
            yield this.saveMultiSiteConfig(multiConfig);
        });
    }
    setActiveConnection(connectionId) {
        return __awaiter(this, void 0, void 0, function* () {
            const multiConfig = yield this.getMultiSiteConfig();
            const connection = multiConfig.connections.find(c => c.id === connectionId);
            if (connection) {
                multiConfig.activeConnectionId = connectionId;
                yield this.saveMultiSiteConfig(multiConfig);
                // Maintenir la compatibilité avec l'ancien système
                yield this.saveConfig(connection);
                return connection;
            }
            return null;
        });
    }
    getActiveConnection() {
        return __awaiter(this, void 0, void 0, function* () {
            const multiConfig = yield this.getMultiSiteConfig();
            if (multiConfig.activeConnectionId) {
                const connection = multiConfig.connections.find(c => c.id === multiConfig.activeConnectionId);
                if (connection) {
                    return connection;
                }
            }
            // Fallback vers l'ancien système
            return yield this.getConfig();
        });
    }
    getAllConnections() {
        return __awaiter(this, void 0, void 0, function* () {
            const multiConfig = yield this.getMultiSiteConfig();
            return multiConfig.connections;
        });
    }
    switchPlugin() {
        return __awaiter(this, void 0, void 0, function* () {
            const currentConfig = yield this.getConfig();
            if (!currentConfig) {
                vscode.window.showErrorMessage('Aucune configuration existante. Veuillez d\'abord vous connecter.');
                return this.promptForConfig();
            }
            const apiConnector = new ApiConnector_1.ApiConnector(currentConfig.siteUrl, currentConfig.username, currentConfig.applicationPassword);
            try {
                const status = yield apiConnector.getStatus();
                if (!status.active_plugins || status.active_plugins.length === 0) {
                    vscode.window.showErrorMessage('Aucun plugin de snippet compatible n\'est actif sur votre site.');
                    return currentConfig;
                }
                const newPlugin = yield vscode.window.showQuickPick(status.active_plugins, {
                    placeHolder: `Plugin actuel: ${currentConfig.plugin}. Choisissez un nouveau plugin.`,
                });
                if (!newPlugin || newPlugin === currentConfig.plugin) {
                    return currentConfig;
                }
                const newConfig = Object.assign(Object.assign({}, currentConfig), { plugin: newPlugin, fluentSnippetsPath: newPlugin === 'FluentSnippets' ? status.fluent_snippets_path : undefined });
                yield this.saveConfig(newConfig);
                vscode.window.showInformationMessage(`Passage à ${newPlugin} réussi.`);
                return newConfig;
            }
            catch (error) {
                vscode.window.showErrorMessage(`Échec du changement de plugin: ${error.message}`);
                return currentConfig;
            }
        });
    }
    manageConnections() {
        return __awaiter(this, void 0, void 0, function* () {
            const connections = yield this.getAllConnections();
            const activeConnection = yield this.getActiveConnection();
            const options = [
                '➕ Ajouter une nouvelle connexion',
                ...connections.map(conn => {
                    const isActive = (activeConnection === null || activeConnection === void 0 ? void 0 : activeConnection.id) === conn.id;
                    return `${isActive ? '🟢' : '⚪'} ${conn.name || conn.siteUrl} (${conn.plugin})`;
                }),
                ...(connections.length > 0 ? ['🗑️ Supprimer une connexion'] : [])
            ];
            const selected = yield vscode.window.showQuickPick(options, {
                placeHolder: 'Gérer les connexions WordPress'
            });
            if (!selected)
                return null;
            if (selected.startsWith('➕')) {
                return yield this.promptForNewConnection();
            }
            else if (selected.startsWith('🗑️')) {
                return yield this.promptForConnectionDeletion();
            }
            else {
                // Sélection d'une connexion existante
                const connectionIndex = options.indexOf(selected) - 1;
                const selectedConnection = connections[connectionIndex];
                if (selectedConnection) {
                    yield this.setActiveConnection(selectedConnection.id);
                    vscode.window.showInformationMessage(`Connexion active: ${selectedConnection.name || selectedConnection.siteUrl}`);
                    return selectedConnection;
                }
            }
            return null;
        });
    }
    promptForConnectionDeletion() {
        return __awaiter(this, void 0, void 0, function* () {
            const connections = yield this.getAllConnections();
            if (connections.length === 0) {
                vscode.window.showInformationMessage('Aucune connexion à supprimer.');
                return null;
            }
            const connectionOptions = connections.map(conn => ({
                label: conn.name || conn.siteUrl,
                description: `${conn.siteUrl} (${conn.plugin})`,
                connection: conn
            }));
            const selected = yield vscode.window.showQuickPick(connectionOptions, {
                placeHolder: 'Sélectionner la connexion à supprimer'
            });
            if (selected) {
                const confirm = yield vscode.window.showWarningMessage(`Êtes-vous sûr de vouloir supprimer la connexion "${selected.label}" ?`, { modal: true }, 'Oui');
                if (confirm === 'Oui') {
                    yield this.removeConnection(selected.connection.id);
                    vscode.window.showInformationMessage(`Connexion "${selected.label}" supprimée.`);
                    // Retourner la nouvelle connexion active
                    return yield this.getActiveConnection();
                }
            }
            return null;
        });
    }
    promptForNewConnection() {
        return __awaiter(this, void 0, void 0, function* () {
            const name = yield vscode.window.showInputBox({
                prompt: 'Nom de la connexion (optionnel)',
                placeHolder: 'Mon site WordPress',
                ignoreFocusOut: true
            });
            const config = yield this.promptForConfig();
            if (config) {
                config.name = name || config.siteUrl;
                config.id = `wp_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
                yield this.addConnection(config);
                yield this.setActiveConnection(config.id);
                return config;
            }
            return null;
        });
    }
    promptForConfig() {
        return __awaiter(this, void 0, void 0, function* () {
            const siteUrl = yield vscode.window.showInputBox({
                prompt: 'Entrez l\'URL de votre site WordPress',
                placeHolder: 'https://votresite.com',
                ignoreFocusOut: true,
                validateInput: (value) => {
                    try {
                        new URL(value);
                        return null;
                    }
                    catch (_a) {
                        return 'Veuillez entrer une URL valide';
                    }
                }
            });
            if (!siteUrl)
                return null;
            const username = yield vscode.window.showInputBox({
                prompt: 'Entrez votre nom d\'utilisateur WordPress',
                placeHolder: 'admin',
                ignoreFocusOut: true
            });
            if (!username)
                return null;
            const applicationPassword = yield vscode.window.showInputBox({
                prompt: 'Entrez votre mot de passe d\'application WordPress',
                password: true,
                ignoreFocusOut: true
            });
            if (!applicationPassword)
                return null;
            const apiConnector = new ApiConnector_1.ApiConnector(siteUrl, username, applicationPassword);
            try {
                const status = yield apiConnector.getStatus();
                if (!status.active_plugins || status.active_plugins.length === 0) {
                    vscode.window.showErrorMessage(status.message);
                    return null;
                }
                let selectedPlugin;
                if (status.active_plugins.length > 1) {
                    selectedPlugin = yield vscode.window.showQuickPick(status.active_plugins, {
                        placeHolder: 'Plusieurs plugins de snippets sont actifs. Veuillez en choisir un.',
                    });
                }
                else {
                    selectedPlugin = status.active_plugins[0];
                }
                if (!selectedPlugin) {
                    return null;
                }
                const config = {
                    id: `wp_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`,
                    name: siteUrl,
                    siteUrl,
                    username,
                    applicationPassword,
                    plugin: selectedPlugin,
                    fluentSnippetsPath: selectedPlugin === 'FluentSnippets' ? status.fluent_snippets_path : undefined
                };
                yield this.saveConfig(config);
                vscode.window.showInformationMessage(`Connecté avec succès à ${siteUrl} en utilisant ${status.active_plugin}.`);
                return config;
            }
            catch (error) {
                vscode.window.showErrorMessage(`Échec de la connexion : ${error.message}`);
                return null;
            }
        });
    }
}
exports.ConfigManager = ConfigManager;
ConfigManager.CONFIG_KEY = 'wordpressSnippets.connection';
ConfigManager.MULTI_SITE_CONFIG_KEY = 'wordpressSnippets.multiSiteConfig';
//# sourceMappingURL=ConfigManager.js.map