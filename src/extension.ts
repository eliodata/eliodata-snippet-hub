import * as path from 'path';
import * as fs from 'fs/promises';
import * as vscode from 'vscode';
import { SnippetController } from './controllers/SnippetController';
import { SnippetTreeDataProvider } from './providers/SnippetTreeDataProvider';
import { createSnippetProvider } from './providers/SnippetProviderFactory';
import { SnippetPluginProvider } from './providers/SnippetPluginProvider';
import { ConfigManager } from './core/ConfigManager';
import { ApiConnector } from './core/ApiConnector';
import { McpCustomToolDefinition, McpToolDefinition, WordPressConnectionConfig } from './types/Snippet';
import { VaultSiteSyncLockService } from './services/VaultSiteSyncLockService';

export async function activate(context: vscode.ExtensionContext) {
    const configManager = new ConfigManager(context);
    
    // State variables
    let provider: SnippetPluginProvider | undefined;
    let snippetTreeDataProvider: SnippetTreeDataProvider | undefined;
    let controller: SnippetController | undefined;
    let syncLockService: VaultSiteSyncLockService | undefined;
    let syncConfig: WordPressConnectionConfig | null = null;
    const syncStatusItem = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Left, 98);
    context.subscriptions.push(syncStatusItem);
    context.subscriptions.push({
        dispose: () => {
            provider?.dispose();
            syncLockService?.dispose();
        }
    });

    const updateSyncStatusItem = (config?: WordPressConnectionConfig | null) => {
        syncConfig = config ?? null;
        if (!config) {
            syncStatusItem.hide();
            return;
        }

        const roleText = syncLockService?.getSyncRole() || configManager.getWorkspaceSyncRole();
        const labelByRole = {
            owner: 'propriétaire',
            editor: 'lecture',
            off: 'arrêt'
        } as const;
        const siteFolder = config.vaultSiteFolder || 'site-wordpress';
        syncStatusItem.text = `$(sync) Sync WP · ${labelByRole[roleText]} · ${siteFolder}`;
        syncStatusItem.tooltip = syncLockService?.getStatusMessage()
            || provider?.getSyncStatusMessage?.()
            || `Mode ${labelByRole[roleText]} pour ${siteFolder}`;
        syncStatusItem.show();
    };

    // Function to initialize or update the provider
    const updateProvider = async (config: WordPressConnectionConfig) => {
        if (!config) {
            console.warn('updateProvider called with null config');
            return;
        }

        console.log('Updating provider with config:', config.siteUrl);

        try {
            if (provider && typeof provider.dispose === 'function') {
                provider.dispose();
            }
            if (syncLockService) {
                syncLockService.dispose();
                syncLockService = undefined;
            }

            syncLockService = new VaultSiteSyncLockService(context, config, () => updateSyncStatusItem(syncConfig));
            await syncLockService.initialize(configManager.getWorkspaceSyncRole());

            const newProvider = await createSnippetProvider(context, config);
            if (!newProvider) {
                console.error('Failed to create snippet provider');
                syncLockService.dispose();
                syncLockService = undefined;
                updateSyncStatusItem(null);
                return;
            }

            newProvider.configureSync?.({
                syncRole: syncLockService.getSyncRole(),
                canSyncToWordPress: () => syncLockService?.canSyncToWordPress() ?? true,
                canWriteVaultFromRemote: () => syncLockService?.canWriteVaultFromRemote() ?? true,
                getStatusMessage: () => syncLockService?.getStatusMessage() || ''
            });

            const initialized = await newProvider.initialize();
            if (!initialized) {
                syncLockService.dispose();
                syncLockService = undefined;
                updateSyncStatusItem(null);
                return;
            }
            provider = newProvider;
            updateSyncStatusItem(config);

            if (!snippetTreeDataProvider) {
                snippetTreeDataProvider = new SnippetTreeDataProvider(provider);
                vscode.window.registerTreeDataProvider('wordpress-snippets-view', snippetTreeDataProvider);
            } else {
                snippetTreeDataProvider.updateProvider(provider);
            }
            
            // Recreate controller with new provider
            controller = new SnippetController(provider, snippetTreeDataProvider!, context);
            snippetTreeDataProvider.refresh();
            if (syncLockService.getStatusMessage()) {
                vscode.window.setStatusBarMessage(syncLockService.getStatusMessage(), 5000);
            }
            console.log('Provider updated successfully');
        } catch (error) {
            console.error('Error updating provider:', error);
            vscode.window.showErrorMessage(`Impossible d'initialiser le site WordPress actif : ${error}`);
        }
    };

    // Initial setup attempt
    try {
        let config = await configManager.getActiveConnection();
        if (!config) {
            const connections = await configManager.getAllConnections();
            if (connections.length > 0) {
                config = await configManager.manageConnections();
            } else {
                // Don't prompt immediately on startup to avoid annoyance, 
                // unless it's the very first install?
                // For now, let's just leave config undefined if not found.
                // The user can use the command to connect.
                // But original logic prompted:
                const selection = await vscode.window.showInformationMessage(
                    'Aucun site WordPress n’est encore configuré.',
                    'Configurer maintenant'
                );
                if (selection === 'Configurer maintenant') {
                     config = await configManager.promptForConfig();
                     if (config) {
                        config.id = `wp_${Date.now()}_${Math.random().toString(36).slice(2, 11)}`;
                        config.name = config.name || config.siteUrl;
                        await configManager.addConnection(config);
                        await configManager.setActiveConnection(config.id);
                     }
                }
            }
        }
        
        if (config) {
            await updateProvider(config);
        }
    } catch (error) {
        console.error("Error during initial setup:", error);
    }

    // === Core commands - Registered UNCONDITIONALLY ===
    
    // Helper to check if ready
    const requireController = () => {
        if (!controller) {
            vscode.window.showErrorMessage('Aucun site WordPress actif. Configure une connexion avant de continuer.');
            return undefined;
        }
        return controller;
    };

    const canProviderSyncToWordPress = () => provider?.canSyncToWordPress?.() ?? true;

    const getActiveConnection = async () => {
        const connection = await configManager.getActiveConnection();
        if (!connection) {
            vscode.window.showErrorMessage('Aucun site WordPress actif. Configure un site avant d’utiliser MCP.');
            return null;
        }
        return connection;
    };

    const ensureMcpConnection = async (refreshIfNeeded = false) => {
        let connection = await getActiveConnection();
        if (!connection) {
            return null;
        }

        if (refreshIfNeeded || typeof connection.mcpEnabled === 'undefined') {
            const refreshedConnection = await configManager.refreshMcpTools(connection.id);
            if (refreshedConnection) {
                connection = refreshedConnection;
            }
        }

        if (!connection.mcpEnabled) {
            vscode.window.showInformationMessage(`MCP n’est pas encore disponible sur ${connection.name || connection.siteUrl}.`);
            return null;
        }

        await syncMcpToolCache(connection);

        return connection;
    };

    const openJsonDocument = async (title: string, payload: unknown) => {
        const document = await vscode.workspace.openTextDocument({
            language: 'json',
            content: JSON.stringify(
                {
                    title,
                    generatedAt: new Date().toISOString(),
                    payload
                },
                null,
                2
            )
        });
        await vscode.window.showTextDocument(document, { preview: false });
    };

    const getMcpCacheRoot = () => {
        const config = vscode.workspace.getConfiguration('wordpressSnippets');
        const configuredPath = config.get<string>('workspaceStoragePath') || config.get<string>('obsidianVaultPath');
        let storagePath: string;
        if (configuredPath && configuredPath.trim() !== '') {
            storagePath = configuredPath;
        } else {
            storagePath = path.join(context.globalStorageUri.fsPath, 'workspace_storage');
        }
        return path.join(storagePath, '.trae', 'mcp_cache');
    };

    const sanitizeFileSegment = (value: string) =>
        value.replace(/[^a-z0-9._-]+/gi, '-').replace(/^-+|-+$/g, '').toLowerCase() || 'default';

    const getConnectionCacheDir = (connection: WordPressConnectionConfig) =>
        path.join(getMcpCacheRoot(), `${sanitizeFileSegment(connection.name || connection.siteUrl)}-${sanitizeFileSegment(connection.id)}`);

    const getToolCachePath = (connection: WordPressConnectionConfig, tool: McpToolDefinition) =>
        path.join(getConnectionCacheDir(connection), `${sanitizeFileSegment(tool.name)}.json`);

    const buildExampleArguments = (tool: McpToolDefinition) => {
        const schema = tool.inputSchema as Record<string, any> | undefined;
        const properties = schema?.properties as Record<string, any> | undefined;
        const exampleArguments: Record<string, unknown> = {};

        if (!properties || typeof properties !== 'object') {
            return exampleArguments;
        }

        for (const [key, propertySchema] of Object.entries(properties)) {
            if (Array.isArray(propertySchema?.enum) && propertySchema.enum.length > 0) {
                exampleArguments[key] = propertySchema.enum[0];
                continue;
            }

            switch (propertySchema?.type) {
                case 'integer':
                case 'number':
                    exampleArguments[key] = 0;
                    break;
                case 'boolean':
                    exampleArguments[key] = false;
                    break;
                case 'array':
                    exampleArguments[key] = [];
                    break;
                case 'object':
                    exampleArguments[key] = {};
                    break;
                default:
                    exampleArguments[key] = '';
                    break;
            }
        }

        return exampleArguments;
    };

    const buildToolDocumentPayload = (
        connection: WordPressConnectionConfig,
        tool: McpToolDefinition,
        existingPayload?: Record<string, any>
    ) => ({
        connection: {
            id: connection.id,
            name: connection.name,
            siteUrl: connection.siteUrl,
            readonly: connection.mcpReadonly,
            syncedAt: connection.mcpLastSyncAt
        },
        tool,
        request: {
            arguments: existingPayload?.request?.arguments && typeof existingPayload.request.arguments === 'object'
                ? existingPayload.request.arguments
                : buildExampleArguments(tool),
            autoRunOnSave: Boolean(existingPayload?.request?.autoRunOnSave)
        },
        execution: {
            lastRunAt: existingPayload?.execution?.lastRunAt || null,
            lastError: existingPayload?.execution?.lastError || null
        },
        response: existingPayload?.response ?? null
    });

    const readMcpToolCachePayload = async (filePath: string) => {
        try {
            const content = await fs.readFile(filePath, 'utf8');
            const parsed = JSON.parse(content) as any;
            return parsed?.payload && typeof parsed.payload === 'object'
                ? parsed.payload as Record<string, any>
                : parsed && typeof parsed === 'object'
                    ? parsed as Record<string, any>
                    : undefined;
        } catch {
            return undefined;
        }
    };

    const writeMcpToolCacheFile = async (connection: WordPressConnectionConfig, tool: McpToolDefinition) => {
        const directory = getConnectionCacheDir(connection);
        await fs.mkdir(directory, { recursive: true });
        const filePath = getToolCachePath(connection, tool);
        const existingPayload = await readMcpToolCachePayload(filePath);
        const content = JSON.stringify(
            {
                title: `MCP Tool · ${tool.name}`,
                generatedAt: new Date().toISOString(),
                payload: buildToolDocumentPayload(connection, tool, existingPayload)
            },
            null,
            2
        );
        await fs.writeFile(filePath, content);
        return filePath;
    };

    const syncMcpToolCache = async (connection: WordPressConnectionConfig) => {
        const tools = Array.isArray(connection.mcpTools) ? connection.mcpTools : [];
        const directory = getConnectionCacheDir(connection);
        await fs.mkdir(directory, { recursive: true });

        const expectedFiles = new Set<string>();
        for (const tool of tools) {
            const filePath = await writeMcpToolCacheFile(connection, tool);
            expectedFiles.add(path.basename(filePath));
        }

        const indexPath = path.join(directory, 'index.json');
        expectedFiles.add('index.json');
        await fs.writeFile(
            indexPath,
            JSON.stringify(
                {
                    title: `MCP Tools · ${connection.name || connection.siteUrl}`,
                    generatedAt: new Date().toISOString(),
                    payload: {
                        connection: {
                            id: connection.id,
                            name: connection.name,
                            siteUrl: connection.siteUrl,
                            readonly: connection.mcpReadonly,
                            syncedAt: connection.mcpLastSyncAt
                        },
                        tools: tools.map(tool => ({
                            name: tool.name,
                            description: tool.description,
                            readOnlyHint: tool.readOnlyHint,
                            file: path.basename(getToolCachePath(connection, tool))
                        }))
                    }
                },
                null,
                2
            )
        );

        const existingEntries = await fs.readdir(directory, { withFileTypes: true });
        await Promise.all(existingEntries.map(async entry => {
            if (!entry.isFile() || expectedFiles.has(entry.name)) {
                return;
            }
            await fs.unlink(path.join(directory, entry.name));
        }));
    };

    const openMcpToolDocument = async (connection: WordPressConnectionConfig, tool: McpToolDefinition) => {
        const filePath = await writeMcpToolCacheFile(connection, tool);
        const document = await vscode.workspace.openTextDocument(filePath);
        await vscode.window.showTextDocument(document, { preview: false });
        return filePath;
    };

    const parseMcpToolDocument = (content: string) => {
        try {
            const parsed = JSON.parse(content) as any;
            const payload = parsed?.payload || parsed;
            const tool = payload?.tool;
            const request = payload?.request;
            const connection = payload?.connection;

            if (!tool?.name) {
                return null;
            }

            return {
                toolName: String(tool.name),
                requestArguments: request?.arguments && typeof request.arguments === 'object'
                    ? request.arguments as Record<string, unknown>
                    : {},
                requestAutoRunOnSave: Boolean(request?.autoRunOnSave),
                naturalLanguageRequest: typeof request?.naturalLanguage === 'string' ? request.naturalLanguage : undefined,
                connectionId: typeof connection?.id === 'string' ? connection.id : undefined,
                payload
            };
        } catch {
            return null;
        }
    };

    const mcpToolExecutionInProgress = new Set<string>();

    const isMcpCacheDocument = (document: vscode.TextDocument) => {
        const root = getMcpCacheRoot();
        return document.uri.scheme === 'file'
            && document.uri.fsPath.startsWith(root)
            && document.uri.fsPath.endsWith('.json')
            && path.basename(document.uri.fsPath) !== 'index.json';
    };

    const getConnectionForMcpDocument = async (document: vscode.TextDocument) => {
        const parsed = parseMcpToolDocument(document.getText());
        if (!parsed?.connectionId) {
            return null;
        }

        const allConnections = await configManager.getAllConnections();
        return allConnections.find(connection => connection.id === parsed.connectionId) || null;
    };

    const resolveToolFromName = (connection: WordPressConnectionConfig, toolName: string) => {
        const tools = Array.isArray(connection.mcpTools) ? connection.mcpTools : [];
        return tools.find(tool => tool.name === toolName) || null;
    };

    const pickMcpTool = async (connection: WordPressConnectionConfig) => {
        const tools = Array.isArray(connection.mcpTools) ? connection.mcpTools : [];
        if (tools.length === 0) {
            vscode.window.showInformationMessage(`Aucun outil MCP détecté pour ${connection.name || connection.siteUrl}.`);
            return null;
        }

        const toolItems = tools
            .slice()
            .sort((left, right) => left.name.localeCompare(right.name))
            .map(tool => ({
                label: tool.name,
                description: tool.description,
                detail: tool.readOnlyHint ? 'Lecture seule' : 'Lecture / écriture',
                tool
            }));

        const selected = await vscode.window.showQuickPick(toolItems, {
            placeHolder: `Choisir un outil MCP pour ${connection.name || connection.siteUrl}`
        });

        return selected?.tool || null;
    };

    const getApiConnector = (connection: WordPressConnectionConfig) =>
        new ApiConnector(connection.siteUrl, connection.username, connection.applicationPassword);

    const loadCustomTools = async (connection: WordPressConnectionConfig) => {
        const connector = getApiConnector(connection);
        const response = await connector.getCustomMcpTools();
        return Array.isArray(response.tools) ? response.tools : [];
    };

    const pickCustomTool = async (connection: WordPressConnectionConfig) => {
        const tools = await loadCustomTools(connection);
        if (tools.length === 0) {
            vscode.window.showInformationMessage(`Aucun outil MCP personnalisé sur ${connection.name || connection.siteUrl}.`);
            return null;
        }

        const selected = await vscode.window.showQuickPick(
            tools
                .slice()
                .sort((left, right) => left.name.localeCompare(right.name))
                .map(tool => ({
                    label: tool.name,
                    description: `${tool.method} ${tool.route}`,
                    detail: tool.readOnlyHint ? 'Lecture seule' : 'Lecture / écriture',
                    tool
                })),
            {
                placeHolder: `Choisir un outil MCP personnalisé pour ${connection.name || connection.siteUrl}`
            }
        );

        return selected?.tool || null;
    };

    const promptToolDefinition = async (existing?: McpCustomToolDefinition) => {
        const name = existing
            ? existing.name
            : await vscode.window.showInputBox({
                prompt: 'Nom technique de l’outil MCP personnalisé',
                placeHolder: 'site/posts/list',
                ignoreFocusOut: true,
                validateInput: value => /^[a-z0-9][a-z0-9._/-]{2,127}$/.test(value)
                    ? null
                    : 'Format attendu: a-z0-9._/- (min 3 caractères)'
            });

        if (!name) {
            return null;
        }

        const description = await vscode.window.showInputBox({
            prompt: 'Description de l’outil MCP personnalisé',
            value: existing?.description || '',
            placeHolder: 'Lister les contenus publiés via un endpoint personnalisé',
            ignoreFocusOut: true,
            validateInput: value => value.trim() ? null : 'Description obligatoire'
        });
        if (!description) {
            return null;
        }

        const method = await vscode.window.showQuickPick(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], {
            placeHolder: 'Méthode HTTP de l’outil',
            ignoreFocusOut: true
        });
        if (!method) {
            return null;
        }

        const route = await vscode.window.showInputBox({
            prompt: 'Route WordPress visée',
            value: existing?.route || '/wp-json/ide/v1/snippets',
            placeHolder: '/wp-json/namespace/v1/endpoint',
            ignoreFocusOut: true,
            validateInput: value => value.startsWith('/wp-json/') ? null : 'La route doit commencer par /wp-json/'
        });
        if (!route) {
            return null;
        }

        const passAs = await vscode.window.showQuickPick(['query', 'json'], {
            placeHolder: 'Mode de transmission des arguments',
            ignoreFocusOut: true
        });
        if (!passAs) {
            return null;
        }

        const readOnlySelection = await vscode.window.showQuickPick(['Oui', 'Non'], {
            placeHolder: 'Outil en lecture seule ?',
            ignoreFocusOut: true
        });
        if (!readOnlySelection) {
            return null;
        }

        const inputSchemaRaw = await vscode.window.showInputBox({
            prompt: 'Schéma JSON d’entrée (optionnel)',
            value: existing?.inputSchema ? JSON.stringify(existing.inputSchema) : '{}',
            placeHolder: '{"type":"object","properties":{"limit":{"type":"integer"}}}',
            ignoreFocusOut: true,
            validateInput: value => {
                if (!value || !value.trim()) {
                    return null;
                }
                try {
                    JSON.parse(value);
                    return null;
                } catch {
                    return 'JSON invalide';
                }
            }
        });

        if (inputSchemaRaw === undefined) {
            return null;
        }

        const inputSchema = inputSchemaRaw.trim() ? JSON.parse(inputSchemaRaw) : {};
        return {
            name,
            description,
            method: method as McpCustomToolDefinition['method'],
            route,
            passAs: passAs as McpCustomToolDefinition['passAs'],
            readOnlyHint: readOnlySelection === 'Oui',
            inputSchema
        } as McpCustomToolDefinition;
    };

    const executeMcpToolRequest = async (
        connection: WordPressConnectionConfig,
        selectedTool: McpToolDefinition,
        args: Record<string, unknown>
    ) => {
        const connector = getApiConnector(connection);
        return connector.callMcpTool(selectedTool.name, args);
    };

    const executeMcpTool = async (
        connection: WordPressConnectionConfig,
        selectedTool: McpToolDefinition,
        args: Record<string, unknown>,
        naturalLanguageRequest?: string
    ) => {
        const result = await executeMcpToolRequest(connection, selectedTool, args);
        await openJsonDocument(`MCP Call · ${selectedTool.name}`, {
            connection: {
                id: connection.id,
                name: connection.name,
                siteUrl: connection.siteUrl
            },
            request: {
                tool: selectedTool.name,
                arguments: args,
                naturalLanguage: naturalLanguageRequest || null
            },
            response: result
        });
    };

    const promptForJsonArguments = async (toolName: string, initialValue: Record<string, unknown> = {}) => {
        const argsInput = await vscode.window.showInputBox({
            prompt: `Arguments JSON pour ${toolName}`,
            placeHolder: '{"id":123}',
            value: JSON.stringify(initialValue, null, 2),
            ignoreFocusOut: true,
            validateInput: (value) => {
                try {
                    JSON.parse(value || '{}');
                    return null;
                } catch {
                    return 'Veuillez saisir un JSON valide';
                }
            }
        });

        if (argsInput === undefined) {
            return null;
        }

        return JSON.parse(argsInput || '{}') as Record<string, unknown>;
    };

    context.subscriptions.push(
        vscode.commands.registerCommand('wordpressSnippets.list', () => snippetTreeDataProvider?.refresh()),
        
        vscode.commands.registerCommand('wordpressSnippets.create', async () => {
            const ctrl = requireController();
            if (ctrl) {
                await ctrl.createSnippet();
                snippetTreeDataProvider?.refresh();
            }
        }),
        
        vscode.commands.registerCommand('wordpressSnippets.refresh', () => snippetTreeDataProvider?.refresh()),
        
        vscode.commands.registerCommand('wordpressSnippets.createSnippet', async () => {
            const ctrl = requireController();
            if (ctrl) {
                await ctrl.createSnippet();
                snippetTreeDataProvider?.refresh();
            }
        }),
        
        vscode.commands.registerCommand('wordpressSnippets.delete', async (item) => {
            const ctrl = requireController();
            if (ctrl) {
                await ctrl.deleteSnippet(item);
                snippetTreeDataProvider?.refresh();
            }
        }),
        
        vscode.commands.registerCommand('wordpressSnippets.configure', async () => {
            const ctrl = requireController();
            if (ctrl) {
                await ctrl.reconfigure();
                snippetTreeDataProvider?.refresh();
            } else {
                // If no controller, just run manage connections
                vscode.commands.executeCommand('wordpressSnippets.manageConnections');
            }
        }),
        
        vscode.commands.registerCommand('wordpressSnippets.openSnippet', (item) => controller?.openSnippet(item)),
        
        vscode.commands.registerCommand('wordpressSnippets.toggleSnippet', async (item) => {
            const ctrl = requireController();
            if (ctrl) {
                await ctrl.toggleSnippet(item);
                snippetTreeDataProvider?.refresh();
            }
        }),

        // === Sort & Filter ===
        vscode.commands.registerCommand('wordpressSnippets.sortAsc', () => snippetTreeDataProvider?.setSortOrder('asc')),
        vscode.commands.registerCommand('wordpressSnippets.sortDesc', () => snippetTreeDataProvider?.setSortOrder('desc')),
        vscode.commands.registerCommand('wordpressSnippets.filterActive', () => snippetTreeDataProvider?.setFilter('active')),
        vscode.commands.registerCommand('wordpressSnippets.filterInactive', () => snippetTreeDataProvider?.setFilter('inactive')),
        vscode.commands.registerCommand('wordpressSnippets.filterAll', () => snippetTreeDataProvider?.setFilter('all')),

        // === Search ===
        vscode.commands.registerCommand('wordpressSnippets.searchSnippets', async () => {
            if (!snippetTreeDataProvider) return;
            const currentTerm = snippetTreeDataProvider.getSearchTerm();
            const searchTerm = await vscode.window.showInputBox({
                prompt: 'Rechercher des snippets (par nom, description, code ou ID)',
                value: currentTerm,
                placeHolder: 'Tapez votre recherche ou un ID de snippet...'
            });
            if (searchTerm !== undefined) {
                snippetTreeDataProvider.setSearchTerm(searchTerm);
                setTimeout(() => {
                    const statusMessage = snippetTreeDataProvider?.getStatusMessage();
                    if (statusMessage) {
                        vscode.window.setStatusBarMessage(`🔍 ${statusMessage}`, 5000);
                    } else if (searchTerm.trim() === '') {
                        vscode.window.setStatusBarMessage('🔍 Recherche effacée', 2000);
                    }
                }, 500);
            }
        }),
        
        vscode.commands.registerCommand('wordpressSnippets.clearSearch', () => {
            snippetTreeDataProvider?.clearSearch();
            vscode.window.setStatusBarMessage('🔍 Recherche effacée', 2000);
        }),

        // === Snippet metadata management ===
        vscode.commands.registerCommand('wordpressSnippets.renameSnippet', async (item) => {
            const ctrl = requireController();
            if (ctrl) {
                await ctrl.renameSnippet(item);
                snippetTreeDataProvider?.refresh();
            }
        }),
        vscode.commands.registerCommand('wordpressSnippets.editDescription', async (item) => {
            const ctrl = requireController();
            if (ctrl) {
                await ctrl.editDescription(item);
                snippetTreeDataProvider?.refresh();
            }
        }),
        vscode.commands.registerCommand('wordpressSnippets.editTags', async (item) => {
            const ctrl = requireController();
            if (ctrl) {
                await ctrl.editTags(item);
                snippetTreeDataProvider?.refresh();
            }
        }),
        vscode.commands.registerCommand('wordpressSnippets.editAttribution', async (item) => {
            const ctrl = requireController();
            if (ctrl) {
                await ctrl.editAttribution(item);
                snippetTreeDataProvider?.refresh();
            }
        }),

        // === Other commands ===
        vscode.commands.registerCommand('wordpressSnippets.analyzeSnippet', () => controller?.analyzeSnippet()),
        vscode.commands.registerCommand('wordpressSnippets.restoreBackup', (item) => controller?.restoreBackup(item)),

        // === Plugin & connection management ===
        vscode.commands.registerCommand('wordpress-snippets.switchPlugin', async () => {
            const newConfig = await configManager.switchPlugin();
            if (newConfig) {
                await updateProvider(newConfig);
            }
        }),
        
        vscode.commands.registerCommand('wordpressSnippets.manageConnections', async () => {
            const newConfig = await configManager.manageConnections();
            if (newConfig) {
                await updateProvider(newConfig);
            }
        }),
        
        vscode.commands.registerCommand('wordpressSnippets.switchConnection', async () => {
            const connections = await configManager.getAllConnections();
            if (connections.length === 0) {
                vscode.window.showInformationMessage('Aucun site WordPress n’est encore configuré.');
                return;
            }

            const activeConnection = await configManager.getActiveConnection();
            const connectionOptions = connections.map(conn => ({
                label: conn.name || conn.siteUrl,
                description: `${conn.siteUrl} (${conn.plugin})`,
                detail: activeConnection?.id === conn.id ? '🟢 Site actif' : '',
                connection: conn
            }));

            const selected = await vscode.window.showQuickPick(connectionOptions, {
                placeHolder: 'Choisir le site WordPress actif'
            });

            if (selected && selected.connection.id !== activeConnection?.id) {
                const newConfig = await configManager.setActiveConnection(selected.connection.id);
                if (newConfig) {
                    await updateProvider(newConfig);
                    vscode.window.showInformationMessage(`Site actif : ${selected.label}`);
                }
            }
        }),

        vscode.commands.registerCommand('wordpressSnippets.refreshMcpTools', async () => {
            const activeConnection = await getActiveConnection();
            if (!activeConnection) {
                return;
            }

            const refreshedConnection = await configManager.refreshMcpTools(activeConnection.id);
            if (!refreshedConnection) {
                return;
            }

            if (refreshedConnection.mcpEnabled) {
                await syncMcpToolCache(refreshedConnection);
            }

            if (refreshedConnection.mcpEnabled) {
                vscode.window.showInformationMessage(
                    `Catalogue MCP synchronisé pour ${refreshedConnection.name || refreshedConnection.siteUrl} : ${refreshedConnection.mcpTools?.length || 0} outil(s).`
                );
            } else {
                vscode.window.showInformationMessage(`Aucun endpoint MCP détecté sur ${refreshedConnection.name || refreshedConnection.siteUrl}.`);
            }
        }),

        vscode.commands.registerCommand('wordpressSnippets.listMcpTools', async () => {
            const connection = await ensureMcpConnection(true);
            if (!connection) {
                return;
            }

            const selectedTool = await pickMcpTool(connection);
            if (!selectedTool) {
                return;
            }

            await openMcpToolDocument(connection, selectedTool);
        }),

        vscode.commands.registerCommand('wordpressSnippets.openMcpTool', async () => {
            const connection = await ensureMcpConnection(true);
            if (!connection) {
                return;
            }

            const selectedTool = await pickMcpTool(connection);
            if (!selectedTool) {
                return;
            }

            await openMcpToolDocument(connection, selectedTool);
        }),

        vscode.commands.registerCommand('wordpressSnippets.callMcpTool', async () => {
            const connection = await ensureMcpConnection(true);
            if (!connection) {
                return;
            }

            const activeEditor = vscode.window.activeTextEditor;
            const parsedCurrentDocument = activeEditor ? parseMcpToolDocument(activeEditor.document.getText()) : null;
            const selectedTool: McpToolDefinition | null = parsedCurrentDocument?.toolName
                ? resolveToolFromName(connection, parsedCurrentDocument.toolName)
                : await pickMcpTool(connection);
            if (!selectedTool) {
                return;
            }

            const args = await promptForJsonArguments(selectedTool.name, parsedCurrentDocument?.requestArguments || {});
            if (!args) {
                return;
            }

            try {
                await executeMcpTool(connection, selectedTool, args);
            } catch (error: any) {
                vscode.window.showErrorMessage(`Échec de l’appel MCP ${selectedTool.name} : ${error.message}`);
            }
        }),

        vscode.commands.registerCommand('wordpressSnippets.callCurrentMcpTool', async () => {
            const activeEditor = vscode.window.activeTextEditor;
            if (!activeEditor) {
                vscode.window.showInformationMessage('Ouvrez un document d’outil MCP avant de lancer cette commande.');
                return;
            }

            const parsedCurrentDocument = parseMcpToolDocument(activeEditor.document.getText());
            if (!parsedCurrentDocument?.toolName) {
                vscode.window.showInformationMessage('Le document actif ne contient pas de définition MCP exploitable.');
                return;
            }

            const connection = await ensureMcpConnection(true);
            if (!connection) {
                return;
            }

            const selectedTool = resolveToolFromName(connection, parsedCurrentDocument.toolName);
            if (!selectedTool) {
                vscode.window.showErrorMessage(`Outil MCP introuvable dans le catalogue actif : ${parsedCurrentDocument.toolName}`);
                return;
            }

            const args = parsedCurrentDocument.requestArguments || {};
            try {
                await executeMcpTool(connection, selectedTool, args);
            } catch (error: any) {
                vscode.window.showErrorMessage(`Échec de l’appel MCP ${selectedTool.name} : ${error.message}`);
            }
        }),

        vscode.commands.registerCommand('wordpressSnippets.createCustomMcpTool', async () => {
            const connection = await ensureMcpConnection(false);
            if (!connection) {
                return;
            }

            const toolDefinition = await promptToolDefinition();
            if (!toolDefinition) {
                return;
            }

            try {
                const connector = getApiConnector(connection);
                await connector.createCustomMcpTool(toolDefinition);
                const refreshedConnection = await configManager.refreshMcpTools(connection.id);
                if (refreshedConnection?.mcpEnabled) {
                    await syncMcpToolCache(refreshedConnection);
                }
                vscode.window.showInformationMessage(`Outil MCP personnalisé créé : ${toolDefinition.name}`);
            } catch (error: any) {
                vscode.window.showErrorMessage(`La création de l’outil MCP personnalisé a échoué : ${error.message}`);
            }
        }),

        vscode.commands.registerCommand('wordpressSnippets.updateCustomMcpTool', async () => {
            const connection = await ensureMcpConnection(false);
            if (!connection) {
                return;
            }

            try {
                const selectedTool = await pickCustomTool(connection);
                if (!selectedTool) {
                    return;
                }

                const updatedDefinition = await promptToolDefinition(selectedTool);
                if (!updatedDefinition) {
                    return;
                }

                const connector = getApiConnector(connection);
                const { name: _ignored, ...payload } = updatedDefinition;
                await connector.updateCustomMcpTool(selectedTool.name, payload);
                const refreshedConnection = await configManager.refreshMcpTools(connection.id);
                if (refreshedConnection?.mcpEnabled) {
                    await syncMcpToolCache(refreshedConnection);
                }
                vscode.window.showInformationMessage(`Outil MCP personnalisé mis à jour : ${selectedTool.name}`);
            } catch (error: any) {
                vscode.window.showErrorMessage(`La mise à jour de l’outil MCP personnalisé a échoué : ${error.message}`);
            }
        }),

        vscode.commands.registerCommand('wordpressSnippets.deleteCustomMcpTool', async () => {
            const connection = await ensureMcpConnection(false);
            if (!connection) {
                return;
            }

            try {
                const selectedTool = await pickCustomTool(connection);
                if (!selectedTool) {
                    return;
                }

                const confirm = await vscode.window.showWarningMessage(
                    `Supprimer l’outil MCP personnalisé "${selectedTool.name}" ?`,
                    { modal: true },
                    'Supprimer'
                );

                if (confirm !== 'Supprimer') {
                    return;
                }

                const connector = getApiConnector(connection);
                await connector.deleteCustomMcpTool(selectedTool.name);
                const refreshedConnection = await configManager.refreshMcpTools(connection.id);
                if (refreshedConnection?.mcpEnabled) {
                    await syncMcpToolCache(refreshedConnection);
                }
                vscode.window.showInformationMessage(`Outil MCP personnalisé supprimé : ${selectedTool.name}`);
            } catch (error: any) {
                vscode.window.showErrorMessage(`La suppression de l’outil MCP personnalisé a échoué : ${error.message}`);
            }
        })
    );

    // === Sync on explicit save only (never on external file changes such as git pull) ===
    context.subscriptions.push(
        vscode.workspace.onDidSaveTextDocument(async (document: vscode.TextDocument) => {
            if (provider && provider.isSnippetFile(document.uri.fsPath)) {
                if (!canProviderSyncToWordPress()) {
                    // An explicit save means this is the window the user works in: the lock follows it
                    const claimed = vscode.workspace.isTrusted && (await syncLockService?.claimForSave()) === true;
                    if (!claimed || !canProviderSyncToWordPress()) {
                        const reason = provider.getSyncStatusMessage?.() || syncLockService?.getStatusMessage() || 'synchronisation indisponible';
                        vscode.window.showWarningMessage(`Snippet enregistré sur le disque mais PAS envoyé à WordPress : ${reason}`);
                        return;
                    }
                    vscode.window.setStatusBarMessage(syncLockService?.getStatusMessage() || '', 5000);
                }
                await provider.updateSnippetFromFile(document.uri.fsPath);
                return;
            }

            if (!isMcpCacheDocument(document)) {
                return;
            }

            if (mcpToolExecutionInProgress.has(document.uri.fsPath)) {
                return;
            }

            // Automatic MCP runs are never triggered from an untrusted workspace
            if (!vscode.workspace.isTrusted) {
                return;
            }

            const parsedDocument = parseMcpToolDocument(document.getText());
            if (!parsedDocument?.toolName || !parsedDocument.requestAutoRunOnSave) {
                return;
            }

            const connection = await getConnectionForMcpDocument(document);
            if (!connection?.mcpEnabled) {
                vscode.window.showErrorMessage('Connexion MCP introuvable pour ce document.');
                return;
            }

            const selectedTool = resolveToolFromName(connection, parsedDocument.toolName);
            if (!selectedTool) {
                vscode.window.showErrorMessage(`Outil MCP introuvable : ${parsedDocument.toolName}`);
                return;
            }

            mcpToolExecutionInProgress.add(document.uri.fsPath);
            try {
                const result = await executeMcpToolRequest(connection, selectedTool, parsedDocument.requestArguments || {});
                const currentContent = await fs.readFile(document.uri.fsPath, 'utf8');
                const currentParsed = JSON.parse(currentContent) as any;
                const currentPayload = currentParsed?.payload && typeof currentParsed.payload === 'object'
                    ? currentParsed.payload
                    : currentParsed;
                const nextContent = JSON.stringify(
                    {
                        title: currentParsed?.title || `MCP Tool · ${selectedTool.name}`,
                        generatedAt: new Date().toISOString(),
                        payload: {
                            ...currentPayload,
                            execution: {
                                ...(currentPayload?.execution || {}),
                                lastRunAt: new Date().toISOString(),
                                lastError: null
                            },
                            response: result
                        }
                    },
                    null,
                    2
                );
                await fs.writeFile(document.uri.fsPath, nextContent);
                vscode.window.showInformationMessage(`Outil MCP exécuté après sauvegarde : ${selectedTool.name}`);
            } catch (error: any) {
                try {
                    const currentContent = await fs.readFile(document.uri.fsPath, 'utf8');
                    const currentParsed = JSON.parse(currentContent) as any;
                    const currentPayload = currentParsed?.payload && typeof currentParsed.payload === 'object'
                        ? currentParsed.payload
                        : currentParsed;
                    const nextContent = JSON.stringify(
                        {
                            title: currentParsed?.title || `MCP Tool · ${selectedTool.name}`,
                            generatedAt: new Date().toISOString(),
                            payload: {
                                ...currentPayload,
                                execution: {
                                    ...(currentPayload?.execution || {}),
                                    lastRunAt: new Date().toISOString(),
                                    lastError: error.message
                                }
                            }
                        },
                        null,
                        2
                    );
                    await fs.writeFile(document.uri.fsPath, nextContent);
                } catch {
                }
                vscode.window.showErrorMessage(`Exécution MCP sur sauvegarde échouée : ${error.message}`);
            } finally {
                mcpToolExecutionInProgress.delete(document.uri.fsPath);
            }
        })
    );
}

export function deactivate() {}
