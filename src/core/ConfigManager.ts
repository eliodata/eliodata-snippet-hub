import * as vscode from 'vscode';
import { WordPressConnectionConfig, MultiSiteConfig, McpToolDefinition, McpToolsResponse, WorkspaceSyncRole } from '../types/Snippet';
import { ApiConnector } from './ApiConnector';

export class ConfigManager {
    private static readonly CONFIG_KEY = 'wordpressSnippets.connection';
    private static readonly MULTI_SITE_CONFIG_KEY = 'wordpressSnippets.multiSiteConfig';
    private static readonly WORKSPACE_CONNECTION_ID_SETTING = 'workspaceConnectionId';
    private static readonly WORKSPACE_SITE_FOLDER_SETTING = 'workspaceSiteFolder';
    private static readonly WORKSPACE_SYNC_ROLE_SETTING = 'syncRole';
    private context: vscode.ExtensionContext;

    constructor(context: vscode.ExtensionContext) {
        this.context = context;
    }

    private isSupportedPlugin(plugin: string): plugin is WordPressConnectionConfig['plugin'] {
        return plugin === 'IDE Native' || plugin === 'IDE Snippets' || plugin === 'Code Snippets';
    }

    private normalizePlugin(plugin: string): WordPressConnectionConfig['plugin'] {
        if (plugin === 'IDE Native') {
            return 'IDE Snippets';
        }
        if (plugin === 'IDE Snippets' || plugin === 'Code Snippets') {
            return plugin;
        }
        return 'IDE Snippets';
    }

    private buildDefaultVaultSiteFolder(siteUrl: string): string {
        try {
            const hostname = new URL(siteUrl).hostname.replace(/^www\./i, '');
            const slug = hostname
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');

            return `site-${slug || 'wordpress'}`;
        } catch {
            const fallback = siteUrl
                .toLowerCase()
                .replace(/^https?:\/\//, '')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');

            return `site-${fallback || 'wordpress'}`;
        }
    }

    private normalizeVaultSiteFolder(folder: string | undefined, siteUrl: string): string {
        const baseValue = (folder || '').trim() || this.buildDefaultVaultSiteFolder(siteUrl);
        const sanitized = baseValue
            .toLowerCase()
            .replace(/[^a-z0-9-]+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-+|-+$/g, '');

        if (sanitized === '') {
            return this.buildDefaultVaultSiteFolder(siteUrl);
        }

        return sanitized.startsWith('site-') ? sanitized : `site-${sanitized}`;
    }

    private normalizeConnection(config: WordPressConnectionConfig): WordPressConnectionConfig {
        return {
            ...config,
            vaultSiteFolder: this.normalizeVaultSiteFolder(config.vaultSiteFolder, config.siteUrl),
            plugin: this.normalizePlugin(config.plugin),
            mcpEnabled: Boolean(config.mcpEnabled),
            mcpReadonly: Boolean(config.mcpReadonly),
            mcpTools: Array.isArray(config.mcpTools) ? config.mcpTools : [],
            mcpLastSyncAt: config.mcpLastSyncAt
        };
    }

    private getSupportedPluginsFromStatus(status: any): WordPressConnectionConfig['plugin'][] {
        const rawPlugins = Array.isArray(status?.active_plugins) ? status.active_plugins : [];
        const supportedPlugins = rawPlugins
            .map((plugin: unknown) => String(plugin))
            .filter((plugin: string) => this.isSupportedPlugin(plugin))
            .map((plugin: string) => this.normalizePlugin(plugin));

        return supportedPlugins.filter((plugin: WordPressConnectionConfig['plugin'], index: number) => supportedPlugins.indexOf(plugin) === index);
    }

    private async discoverMcpTools(apiConnector: ApiConnector): Promise<{
        mcpEnabled: boolean;
        mcpReadonly: boolean;
        mcpTools: McpToolDefinition[];
        mcpLastSyncAt?: string;
    }> {
        try {
            const response: McpToolsResponse = await apiConnector.getMcpTools();
            return {
                mcpEnabled: true,
                mcpReadonly: Boolean(response.readonly),
                mcpTools: Array.isArray(response.tools) ? response.tools : [],
                mcpLastSyncAt: new Date().toISOString()
            };
        } catch (error: any) {
            const status = error?.response?.status;
            if (status === 404) {
                return {
                    mcpEnabled: false,
                    mcpReadonly: false,
                    mcpTools: [],
                    mcpLastSyncAt: undefined
                };
            }
            throw error;
        }
    }

    private async hydrateConnectionWithCapabilities(
        baseConfig: WordPressConnectionConfig,
        apiConnector: ApiConnector
    ): Promise<WordPressConnectionConfig> {
        const mcpConfig = await this.discoverMcpTools(apiConnector);
        return this.normalizeConnection({
            ...baseConfig,
            ...mcpConfig
        });
    }

    private async updateStoredConnection(updatedConnection: WordPressConnectionConfig): Promise<void> {
        const normalizedConnection = this.normalizeConnection(updatedConnection);
        const multiConfig = await this.getMultiSiteConfig();
        const existingIndex = multiConfig.connections.findIndex(connection => connection.id === normalizedConnection.id);

        if (existingIndex >= 0) {
            multiConfig.connections[existingIndex] = normalizedConnection;
        }

        if (multiConfig.activeConnectionId === normalizedConnection.id) {
            await this.saveConfig(normalizedConnection);
        }

        await this.saveMultiSiteConfig(multiConfig);
    }

    private getWorkspaceConfiguration(): vscode.WorkspaceConfiguration {
        return vscode.workspace.getConfiguration('wordpressSnippets');
    }

    private getWorkspaceConfigurationTarget(): vscode.ConfigurationTarget {
        return vscode.workspace.workspaceFile || (vscode.workspace.workspaceFolders?.length ?? 0) > 0
            ? vscode.ConfigurationTarget.Workspace
            : vscode.ConfigurationTarget.Global;
    }

    public async setWorkspaceConnectionPreference(connection: WordPressConnectionConfig): Promise<void> {
        const config = this.getWorkspaceConfiguration();
        const target = this.getWorkspaceConfigurationTarget();
        await config.update(ConfigManager.WORKSPACE_CONNECTION_ID_SETTING, connection.id, target);
        await config.update(
            ConfigManager.WORKSPACE_SITE_FOLDER_SETTING,
            this.normalizeVaultSiteFolder(connection.vaultSiteFolder, connection.siteUrl),
            target
        );
    }

    public getWorkspaceConnectionId(): string | undefined {
        return this.getWorkspaceConfiguration().get<string>(ConfigManager.WORKSPACE_CONNECTION_ID_SETTING) || undefined;
    }

    public getWorkspaceSiteFolder(): string | undefined {
        const value = this.getWorkspaceConfiguration().get<string>(ConfigManager.WORKSPACE_SITE_FOLDER_SETTING) || undefined;
        return value ? this.normalizeVaultSiteFolder(value, 'https://workspace.local') : undefined;
    }

    public getWorkspaceSyncRole(): WorkspaceSyncRole {
        const value = this.getWorkspaceConfiguration().get<string>(ConfigManager.WORKSPACE_SYNC_ROLE_SETTING) || 'owner';
        return value === 'editor' || value === 'off' ? value : 'owner';
    }

    public async saveConfig(config: WordPressConnectionConfig): Promise<void> {
        try {
            await this.context.secrets.store(ConfigManager.CONFIG_KEY, JSON.stringify(this.normalizeConnection(config)));
            console.log('Configuration sauvegardée avec succès (single-site).');
        } catch (error) {
            console.error('Erreur lors de la sauvegarde de la configuration:', error);
            vscode.window.showErrorMessage('Erreur lors de la sauvegarde de la configuration.');
        }
    }

    public async getConfig(): Promise<WordPressConnectionConfig | null> {
        try {
            const configStr = await this.context.secrets.get(ConfigManager.CONFIG_KEY);
            if (!configStr) {
                console.log('Aucune configuration trouvée (single-site).');
                return null;
            }
            return this.normalizeConnection(JSON.parse(configStr));
        } catch (error) {
            console.error('Erreur lors de la lecture de la configuration:', error);
            return null;
        }
    }

    public async clearConfig(): Promise<void> {
        await this.context.secrets.delete(ConfigManager.CONFIG_KEY);
    }

    // Nouvelles méthodes pour la gestion multi-sites
    public async getMultiSiteConfig(): Promise<MultiSiteConfig> {
        try {
            const configStr = await this.context.secrets.get(ConfigManager.MULTI_SITE_CONFIG_KEY);
            if (!configStr) {
                return { connections: [] };
            }
            const parsedConfig = JSON.parse(configStr) as MultiSiteConfig;
            return {
                connections: Array.isArray(parsedConfig.connections)
                    ? parsedConfig.connections.map(connection => this.normalizeConnection(connection))
                    : [],
                activeConnectionId: parsedConfig.activeConnectionId
            };
        } catch (error) {
            console.error('Erreur lors de la lecture de la configuration multi-sites:', error);
            return { connections: [] };
        }
    }

    public async saveMultiSiteConfig(config: MultiSiteConfig): Promise<void> {
        try {
            const normalizedConfig: MultiSiteConfig = {
                connections: Array.isArray(config.connections)
                    ? config.connections.map(connection => this.normalizeConnection(connection))
                    : [],
                activeConnectionId: config.activeConnectionId
            };
            await this.context.secrets.store(ConfigManager.MULTI_SITE_CONFIG_KEY, JSON.stringify(normalizedConfig));
            console.log('Configuration multi-sites sauvegardée avec succès.');
        } catch (error) {
            console.error('Erreur lors de la sauvegarde de la configuration multi-sites:', error);
        }
    }

    public async addConnection(connection: WordPressConnectionConfig): Promise<void> {
        const multiConfig = await this.getMultiSiteConfig();
        const normalizedConnection = this.normalizeConnection(connection);
        
        if (!normalizedConnection.id) {
            normalizedConnection.id = `wp_${Date.now()}_${Math.random().toString(36).slice(2, 11)}`;
        }
        
        const existingIndex = multiConfig.connections.findIndex(c => c.siteUrl === normalizedConnection.siteUrl);
        if (existingIndex >= 0) {
            multiConfig.connections[existingIndex] = normalizedConnection;
        } else {
            multiConfig.connections.push(normalizedConnection);
        }
        
        if (multiConfig.connections.length === 1) {
            multiConfig.activeConnectionId = normalizedConnection.id;
        }
        
        await this.saveMultiSiteConfig(multiConfig);
    }

    public async removeConnection(connectionId: string): Promise<void> {
        const multiConfig = await this.getMultiSiteConfig();
        multiConfig.connections = multiConfig.connections.filter(c => c.id !== connectionId);
        
        // Si la connexion supprimée était active, choisir une nouvelle connexion active
        if (multiConfig.activeConnectionId === connectionId) {
            multiConfig.activeConnectionId = multiConfig.connections.length > 0 ? multiConfig.connections[0].id : undefined;
        }
        
        await this.saveMultiSiteConfig(multiConfig);

        if (this.getWorkspaceConnectionId() === connectionId) {
            const config = this.getWorkspaceConfiguration();
            const target = this.getWorkspaceConfigurationTarget();
            await config.update(ConfigManager.WORKSPACE_CONNECTION_ID_SETTING, undefined, target);
            await config.update(ConfigManager.WORKSPACE_SITE_FOLDER_SETTING, undefined, target);
        }
    }

    public async setActiveConnection(connectionId: string): Promise<WordPressConnectionConfig | null> {
        const multiConfig = await this.getMultiSiteConfig();
        const connection = multiConfig.connections.find(c => c.id === connectionId);
        
        if (connection) {
            multiConfig.activeConnectionId = connectionId;
            await this.saveMultiSiteConfig(multiConfig);
            await this.saveConfig(connection);
            await this.setWorkspaceConnectionPreference(connection);
            return this.normalizeConnection(connection);
        }
        
        return null;
    }

    public async getActiveConnection(): Promise<WordPressConnectionConfig | null> {
        const multiConfig = await this.getMultiSiteConfig();
        const workspaceConnectionId = this.getWorkspaceConnectionId();
        if (workspaceConnectionId) {
            const workspaceConnection = multiConfig.connections.find(c => c.id === workspaceConnectionId);
            if (workspaceConnection) {
                return workspaceConnection;
            }
        }

        const workspaceSiteFolder = this.getWorkspaceSiteFolder();
        if (workspaceSiteFolder) {
            const workspaceConnection = multiConfig.connections.find(c =>
                this.normalizeVaultSiteFolder(c.vaultSiteFolder, c.siteUrl) === workspaceSiteFolder
            );
            if (workspaceConnection) {
                return workspaceConnection;
            }
        }
        
        if (multiConfig.activeConnectionId) {
            const connection = multiConfig.connections.find(c => c.id === multiConfig.activeConnectionId);
            if (connection) {
                return connection;
            }
        }
        
        // Fallback vers l'ancien système
        return await this.getConfig();
    }

    public async getAllConnections(): Promise<WordPressConnectionConfig[]> {
        const multiConfig = await this.getMultiSiteConfig();
        return multiConfig.connections;
    }

    public async switchPlugin(): Promise<WordPressConnectionConfig | null> {
        const currentConfig = await this.getActiveConnection();
        if (!currentConfig) {
            const choice = await vscode.window.showInformationMessage(
                'Aucun site actif. Voulez-vous configurer une connexion ?',
                'Configurer', 'Annuler'
            );
            if (choice === 'Configurer') {
                return this.promptForConfig();
            }
            return null;
        }

        const apiConnector = new ApiConnector(currentConfig.siteUrl, currentConfig.username, currentConfig.applicationPassword);
        try {
            const status = await apiConnector.getStatus();
            if (!status.active_plugins || status.active_plugins.length === 0) {
                vscode.window.showErrorMessage('Aucun moteur de snippets compatible n’est actif sur ce site.');
                return currentConfig;
            }

            const availablePlugins = this.getSupportedPluginsFromStatus(status);
            if (availablePlugins.length === 0) {
                vscode.window.showErrorMessage('Le companion public supporte uniquement IDE Snippets et Code Snippets.');
                return currentConfig;
            }

            const newPlugin = await vscode.window.showQuickPick(availablePlugins, {
                placeHolder: `Moteur actuel : ${this.normalizePlugin(currentConfig.plugin)}. Choisissez le moteur à utiliser.`,
            });

            if (!newPlugin || newPlugin === currentConfig.plugin) {
                return currentConfig;
            }

            const newConfig: WordPressConnectionConfig = {
                ...currentConfig,
                plugin: this.normalizePlugin(newPlugin),
            };

            await this.saveConfig(newConfig);
            await this.updateStoredConnection(newConfig);
            vscode.window.showInformationMessage(`Moteur actif mis à jour : ${newPlugin}.`);
            return newConfig;
        } catch (error: any) {
            vscode.window.showErrorMessage(`Impossible de changer de moteur de snippets : ${error.message}`);
            return currentConfig;
        }
    }



    public async manageConnections(): Promise<WordPressConnectionConfig | null> {
        const connections = await this.getAllConnections();
        const activeConnection = await this.getActiveConnection();
        
        const options = [
            '➕ Ajouter un site WordPress',
            ...connections.map(conn => {
                const isActive = activeConnection?.id === conn.id;
                const mcpLabel = conn.mcpEnabled ? ` · MCP ${conn.mcpTools?.length || 0}` : '';
                return `${isActive ? '🟢' : '⚪'} ${conn.name || conn.siteUrl} (${conn.plugin}${mcpLabel})`;
            }),
            ...(connections.length > 0 ? ['🗑️ Supprimer un site'] : [])
        ];
        
        const selected = await vscode.window.showQuickPick(options, {
            placeHolder: 'Gérer les sites WordPress configurés'
        });
        
        if (!selected) return null;
        
        if (selected.startsWith('➕')) {
            return await this.promptForNewConnection();
        } else if (selected.startsWith('🗑️')) {
            return await this.promptForConnectionDeletion();
        } else {
            // Sélection d'une connexion existante
            const connectionIndex = options.indexOf(selected) - 1;
            const selectedConnection = connections[connectionIndex];
            if (selectedConnection) {
                await this.setActiveConnection(selectedConnection.id);
                vscode.window.showInformationMessage(`Site actif : ${selectedConnection.name || selectedConnection.siteUrl}`);
                return selectedConnection;
            }
        }
        
        return null;
    }
    
    private async promptForConnectionDeletion(): Promise<WordPressConnectionConfig | null> {
        const connections = await this.getAllConnections();
        
        if (connections.length === 0) {
            vscode.window.showInformationMessage('Aucun site configuré à supprimer.');
            return null;
        }
        
        const connectionOptions = connections.map(conn => ({
            label: conn.name || conn.siteUrl,
            description: `${conn.siteUrl} (${conn.plugin})`,
            detail: conn.mcpEnabled ? `MCP actif · ${conn.mcpTools?.length || 0} outil(s)` : 'MCP non détecté',
            connection: conn
        }));
        
        const selected = await vscode.window.showQuickPick(connectionOptions, {
            placeHolder: 'Sélectionner le site à supprimer'
        });
        
        if (selected) {
            const confirm = await vscode.window.showWarningMessage(
                `Supprimer le site "${selected.label}" de la liste des connexions ?`,
                { modal: true },
                'Supprimer'
            );
            
            if (confirm === 'Supprimer') {
                await this.removeConnection(selected.connection.id);
                vscode.window.showInformationMessage(`Site supprimé : ${selected.label}.`);
                
                // Retourner la nouvelle connexion active
                return await this.getActiveConnection();
            }
        }
        
        return null;
    }
    
    private async promptForNewConnection(): Promise<WordPressConnectionConfig | null> {
        const name = await vscode.window.showInputBox({
            prompt: 'Nom d’affichage du site (optionnel)',
            placeHolder: 'Mon site vitrine',
            ignoreFocusOut: true
        });
        
        const config = await this.promptForConfig();
        if (config) {
            config.name = name || config.siteUrl;
            config.id = `wp_${Date.now()}_${Math.random().toString(36).slice(2, 11)}`;
            await this.addConnection(config);
            await this.setActiveConnection(config.id);
            return config;
        }
        
        return null;
    }

    public async refreshMcpTools(connectionId?: string): Promise<WordPressConnectionConfig | null> {
        const connection = connectionId
            ? (await this.getAllConnections()).find(item => item.id === connectionId) || null
            : await this.getActiveConnection();

        if (!connection) {
            vscode.window.showWarningMessage('Aucun site actif à synchroniser pour MCP.');
            return null;
        }

        const apiConnector = new ApiConnector(connection.siteUrl, connection.username, connection.applicationPassword);

        try {
            const updatedConnection = await this.hydrateConnectionWithCapabilities(connection, apiConnector);
            await this.updateStoredConnection(updatedConnection);
            return updatedConnection;
        } catch (error: any) {
            vscode.window.showErrorMessage(`Échec de la synchronisation MCP : ${error.message}`);
            return null;
        }
    }

    public async promptForConfig(): Promise<WordPressConnectionConfig | null> {
        const siteUrl = await vscode.window.showInputBox({
            prompt: 'URL du site WordPress',
            placeHolder: 'https://votresite.fr',
            ignoreFocusOut: true,
            validateInput: (value) => {
                try {
                    new URL(value);
                    return null;
                } catch {
                    return 'Veuillez entrer une URL valide';
                }
            }
        });

        if (!siteUrl) return null;

        const vaultSiteFolder = await vscode.window.showInputBox({
            prompt: 'Nom du dossier local associé à ce site',
            placeHolder: 'site-example',
            value: this.buildDefaultVaultSiteFolder(siteUrl),
            ignoreFocusOut: true,
            validateInput: (value) => {
                const normalized = value
                    .toLowerCase()
                    .replace(/[^a-z0-9-]+/g, '-')
                    .replace(/-+/g, '-')
                    .replace(/^-+|-+$/g, '');

                if (!normalized) {
                    return 'Veuillez entrer un nom de dossier valide';
                }

                return null;
            }
        });

        if (!vaultSiteFolder) return null;

        const username = await vscode.window.showInputBox({
            prompt: 'Identifiant WordPress',
            placeHolder: 'admin',
            ignoreFocusOut: true
        });

        if (!username) return null;

        let applicationPassword = await vscode.window.showInputBox({
            prompt: 'Mot de passe d’application WordPress',
            password: true,
            ignoreFocusOut: true
        });

        if (!applicationPassword) return null;

        // Clean up password (remove spaces)
        applicationPassword = applicationPassword.replace(/\s+/g, '');

        const apiConnector = new ApiConnector(siteUrl, username, applicationPassword);
        try {
            console.log(`Tentative de connexion à ${siteUrl} avec l'utilisateur ${username}...`);
            const status = await apiConnector.getStatus();
            console.log('Statut reçu:', status);

            if (!status.active_plugins || status.active_plugins.length === 0) {
                const msg = status.message || 'Aucun moteur de snippets compatible détecté.';
                vscode.window.showErrorMessage(msg);
                return null;
            }

            const availablePlugins = this.getSupportedPluginsFromStatus(status);
            if (availablePlugins.length === 0) {
                vscode.window.showErrorMessage('Aucun moteur de snippets compatible avec le companion public n’a été détecté.');
                return null;
            }

            let selectedPlugin: WordPressConnectionConfig['plugin'] | undefined;
            if (availablePlugins.length > 1) {
                selectedPlugin = await vscode.window.showQuickPick(availablePlugins, {
                    placeHolder: 'Plusieurs moteurs de snippets sont détectés. Choisissez celui à utiliser.',
                }) as WordPressConnectionConfig['plugin'] | undefined;
            } else {
                selectedPlugin = availablePlugins[0];
            }

            if (!selectedPlugin) {
                return null;
            }

            const config: WordPressConnectionConfig = {
                id: `wp_${Date.now()}_${Math.random().toString(36).slice(2, 11)}`,
                name: siteUrl,
                siteUrl,
                vaultSiteFolder: this.normalizeVaultSiteFolder(vaultSiteFolder, siteUrl),
                username,
                applicationPassword,
                plugin: this.normalizePlugin(selectedPlugin),
            };

            const hydratedConfig = await this.hydrateConnectionWithCapabilities(config, apiConnector);
            await this.saveConfig(hydratedConfig);
            vscode.window.showInformationMessage(`Connexion prête pour ${siteUrl} avec le moteur ${selectedPlugin}.`);
            return hydratedConfig;
        } catch (error: any) {
            vscode.window.showErrorMessage(`Échec de la connexion : ${error.message}`);
            return null;
        }
    }
}
