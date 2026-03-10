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
exports.ConfigManager = void 0;
const vscode = __importStar(require("vscode"));
const ApiConnector_1 = require("./ApiConnector");
class ConfigManager {
    constructor(context) {
        this.context = context;
    }
    normalizePlugin(plugin) {
        if (plugin === 'IDE Native') {
            return 'IDE Snippets';
        }
        if (plugin === 'IDE Snippets' || plugin === 'Code Snippets' || plugin === 'FluentSnippets') {
            return plugin;
        }
        return 'IDE Snippets';
    }
    async saveConfig(config) {
        try {
            await this.context.secrets.store(ConfigManager.CONFIG_KEY, JSON.stringify(config));
            console.log('Configuration sauvegardée avec succès (single-site).');
        }
        catch (error) {
            console.error('Erreur lors de la sauvegarde de la configuration:', error);
            vscode.window.showErrorMessage('Erreur lors de la sauvegarde de la configuration.');
        }
    }
    async getConfig() {
        try {
            const configStr = await this.context.secrets.get(ConfigManager.CONFIG_KEY);
            if (!configStr) {
                console.log('Aucune configuration trouvée (single-site).');
                return null;
            }
            return JSON.parse(configStr);
        }
        catch (error) {
            console.error('Erreur lors de la lecture de la configuration:', error);
            return null;
        }
    }
    async clearConfig() {
        await this.context.secrets.delete(ConfigManager.CONFIG_KEY);
    }
    // Nouvelles méthodes pour la gestion multi-sites
    async getMultiSiteConfig() {
        try {
            const configStr = await this.context.secrets.get(ConfigManager.MULTI_SITE_CONFIG_KEY);
            if (!configStr) {
                return { connections: [] };
            }
            return JSON.parse(configStr);
        }
        catch (error) {
            console.error('Erreur lors de la lecture de la configuration multi-sites:', error);
            return { connections: [] };
        }
    }
    async saveMultiSiteConfig(config) {
        try {
            await this.context.secrets.store(ConfigManager.MULTI_SITE_CONFIG_KEY, JSON.stringify(config));
            console.log('Configuration multi-sites sauvegardée avec succès.');
        }
        catch (error) {
            console.error('Erreur lors de la sauvegarde de la configuration multi-sites:', error);
        }
    }
    async addConnection(connection) {
        const multiConfig = await this.getMultiSiteConfig();
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
        await this.saveMultiSiteConfig(multiConfig);
    }
    async removeConnection(connectionId) {
        const multiConfig = await this.getMultiSiteConfig();
        multiConfig.connections = multiConfig.connections.filter(c => c.id !== connectionId);
        // Si la connexion supprimée était active, choisir une nouvelle connexion active
        if (multiConfig.activeConnectionId === connectionId) {
            multiConfig.activeConnectionId = multiConfig.connections.length > 0 ? multiConfig.connections[0].id : undefined;
        }
        await this.saveMultiSiteConfig(multiConfig);
    }
    async setActiveConnection(connectionId) {
        const multiConfig = await this.getMultiSiteConfig();
        const connection = multiConfig.connections.find(c => c.id === connectionId);
        if (connection) {
            multiConfig.activeConnectionId = connectionId;
            await this.saveMultiSiteConfig(multiConfig);
            // Maintenir la compatibilité avec l'ancien système
            await this.saveConfig(connection);
            return connection;
        }
        return null;
    }
    async getActiveConnection() {
        const multiConfig = await this.getMultiSiteConfig();
        if (multiConfig.activeConnectionId) {
            const connection = multiConfig.connections.find(c => c.id === multiConfig.activeConnectionId);
            if (connection) {
                return connection;
            }
        }
        // Fallback vers l'ancien système
        return await this.getConfig();
    }
    async getAllConnections() {
        const multiConfig = await this.getMultiSiteConfig();
        return multiConfig.connections;
    }
    async switchPlugin() {
        const currentConfig = await this.getConfig();
        if (!currentConfig) {
            const choice = await vscode.window.showInformationMessage('Aucune connexion active. Voulez-vous en configurer une ?', 'Oui', 'Non');
            if (choice === 'Oui') {
                return this.promptForConfig();
            }
            return null;
        }
        const apiConnector = new ApiConnector_1.ApiConnector(currentConfig.siteUrl, currentConfig.username, currentConfig.applicationPassword);
        try {
            const status = await apiConnector.getStatus();
            if (!status.active_plugins || status.active_plugins.length === 0) {
                vscode.window.showErrorMessage('Aucun plugin de snippet compatible n\'est actif sur votre site.');
                return currentConfig;
            }
            const rawPlugins = Array.isArray(status.active_plugins) ? status.active_plugins : [];
            const normalizedPlugins = rawPlugins.map((plugin) => this.normalizePlugin(String(plugin)));
            const availablePlugins = normalizedPlugins.filter((plugin, index) => normalizedPlugins.indexOf(plugin) === index);
            const newPlugin = await vscode.window.showQuickPick(availablePlugins, {
                placeHolder: `Plugin actuel: ${this.normalizePlugin(currentConfig.plugin)}. Choisissez un nouveau plugin.`,
            });
            if (!newPlugin || newPlugin === currentConfig.plugin) {
                return currentConfig;
            }
            const newConfig = {
                ...currentConfig,
                plugin: this.normalizePlugin(newPlugin),
                fluentSnippetsPath: newPlugin === 'FluentSnippets' ? status.fluent_snippets_path : undefined
            };
            await this.saveConfig(newConfig);
            vscode.window.showInformationMessage(`Passage à ${newPlugin} réussi.`);
            return newConfig;
        }
        catch (error) {
            vscode.window.showErrorMessage(`Échec du changement de plugin: ${error.message}`);
            return currentConfig;
        }
    }
    async manageConnections() {
        const connections = await this.getAllConnections();
        const activeConnection = await this.getActiveConnection();
        const options = [
            '➕ Ajouter une nouvelle connexion',
            ...connections.map(conn => {
                const isActive = activeConnection?.id === conn.id;
                return `${isActive ? '🟢' : '⚪'} ${conn.name || conn.siteUrl} (${conn.plugin})`;
            }),
            ...(connections.length > 0 ? ['🗑️ Supprimer une connexion'] : [])
        ];
        const selected = await vscode.window.showQuickPick(options, {
            placeHolder: 'Gérer les connexions WordPress'
        });
        if (!selected)
            return null;
        if (selected.startsWith('➕')) {
            return await this.promptForNewConnection();
        }
        else if (selected.startsWith('🗑️')) {
            return await this.promptForConnectionDeletion();
        }
        else {
            // Sélection d'une connexion existante
            const connectionIndex = options.indexOf(selected) - 1;
            const selectedConnection = connections[connectionIndex];
            if (selectedConnection) {
                await this.setActiveConnection(selectedConnection.id);
                vscode.window.showInformationMessage(`Connexion active: ${selectedConnection.name || selectedConnection.siteUrl}`);
                return selectedConnection;
            }
        }
        return null;
    }
    async promptForConnectionDeletion() {
        const connections = await this.getAllConnections();
        if (connections.length === 0) {
            vscode.window.showInformationMessage('Aucune connexion à supprimer.');
            return null;
        }
        const connectionOptions = connections.map(conn => ({
            label: conn.name || conn.siteUrl,
            description: `${conn.siteUrl} (${conn.plugin})`,
            connection: conn
        }));
        const selected = await vscode.window.showQuickPick(connectionOptions, {
            placeHolder: 'Sélectionner la connexion à supprimer'
        });
        if (selected) {
            const confirm = await vscode.window.showWarningMessage(`Êtes-vous sûr de vouloir supprimer la connexion "${selected.label}" ?`, { modal: true }, 'Oui');
            if (confirm === 'Oui') {
                await this.removeConnection(selected.connection.id);
                vscode.window.showInformationMessage(`Connexion "${selected.label}" supprimée.`);
                // Retourner la nouvelle connexion active
                return await this.getActiveConnection();
            }
        }
        return null;
    }
    async promptForNewConnection() {
        const name = await vscode.window.showInputBox({
            prompt: 'Nom de la connexion (optionnel)',
            placeHolder: 'Mon site WordPress',
            ignoreFocusOut: true
        });
        const config = await this.promptForConfig();
        if (config) {
            config.name = name || config.siteUrl;
            config.id = `wp_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
            await this.addConnection(config);
            await this.setActiveConnection(config.id);
            return config;
        }
        return null;
    }
    async promptForConfig() {
        const siteUrl = await vscode.window.showInputBox({
            prompt: 'Entrez l\'URL de votre site WordPress',
            placeHolder: 'https://votresite.com',
            ignoreFocusOut: true,
            validateInput: (value) => {
                try {
                    new URL(value);
                    return null;
                }
                catch {
                    return 'Veuillez entrer une URL valide';
                }
            }
        });
        if (!siteUrl)
            return null;
        const username = await vscode.window.showInputBox({
            prompt: 'Entrez votre nom d\'utilisateur WordPress',
            placeHolder: 'admin',
            ignoreFocusOut: true
        });
        if (!username)
            return null;
        let applicationPassword = await vscode.window.showInputBox({
            prompt: 'Entrez votre mot de passe d\'application WordPress',
            password: true,
            ignoreFocusOut: true
        });
        if (!applicationPassword)
            return null;
        // Clean up password (remove spaces)
        applicationPassword = applicationPassword.replace(/\s+/g, '');
        const apiConnector = new ApiConnector_1.ApiConnector(siteUrl, username, applicationPassword);
        try {
            console.log(`Tentative de connexion à ${siteUrl} avec l'utilisateur ${username}...`);
            const status = await apiConnector.getStatus();
            console.log('Statut reçu:', status);
            if (!status.active_plugins || status.active_plugins.length === 0) {
                const msg = status.message || 'Aucun plugin de snippet compatible détecté.';
                vscode.window.showErrorMessage(msg);
                return null;
            }
            const rawPlugins = Array.isArray(status.active_plugins) ? status.active_plugins : [];
            const normalizedPlugins = rawPlugins.map((plugin) => this.normalizePlugin(String(plugin)));
            const availablePlugins = normalizedPlugins.filter((plugin, index) => normalizedPlugins.indexOf(plugin) === index);
            let selectedPlugin;
            if (availablePlugins.length > 1) {
                selectedPlugin = await vscode.window.showQuickPick(availablePlugins, {
                    placeHolder: 'Plusieurs plugins de snippets sont actifs. Veuillez en choisir un.',
                });
            }
            else {
                selectedPlugin = availablePlugins[0];
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
                plugin: this.normalizePlugin(selectedPlugin),
                fluentSnippetsPath: selectedPlugin === 'FluentSnippets' ? status.fluent_snippets_path : undefined
            };
            await this.saveConfig(config);
            vscode.window.showInformationMessage(`Connecté avec succès à ${siteUrl} en utilisant ${selectedPlugin}.`);
            return config;
        }
        catch (error) {
            vscode.window.showErrorMessage(`Échec de la connexion : ${error.message}`);
            return null;
        }
    }
}
exports.ConfigManager = ConfigManager;
ConfigManager.CONFIG_KEY = 'wordpressSnippets.connection';
ConfigManager.MULTI_SITE_CONFIG_KEY = 'wordpressSnippets.multiSiteConfig';
//# sourceMappingURL=ConfigManager.js.map