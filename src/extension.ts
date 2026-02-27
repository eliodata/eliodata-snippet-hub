import * as vscode from 'vscode';
import { SnippetController } from './controllers/SnippetController';
import { SnippetTreeDataProvider } from './providers/SnippetTreeDataProvider';
import { createSnippetProvider } from './providers/SnippetProviderFactory';
import { ConfigManager } from './core/ConfigManager';

export async function activate(context: vscode.ExtensionContext) {
    const configManager = new ConfigManager(context);
    let config = await configManager.getActiveConnection();

    if (!config) {
        const connections = await configManager.getAllConnections();
        if (connections.length > 0) {
            config = await configManager.manageConnections();
        } else {
            config = await configManager.promptForConfig();
            if (config) {
                config.id = `wp_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
                config.name = config.name || config.siteUrl;
                await configManager.addConnection(config);
                await configManager.setActiveConnection(config.id);
            }
        }

        if (!config) {
            return;
        }
    }

    let provider = await createSnippetProvider(context, config);
    if (!provider) {
        return;
    }

    await provider.initialize();

    const snippetTreeDataProvider = new SnippetTreeDataProvider(provider);
    vscode.window.registerTreeDataProvider('wordpress-snippets-view', snippetTreeDataProvider);

    const controller = new SnippetController(provider, snippetTreeDataProvider, context);

    // === Core commands ===
    context.subscriptions.push(
        vscode.commands.registerCommand('wordpressSnippets.list', () => snippetTreeDataProvider.refresh()),
        vscode.commands.registerCommand('wordpressSnippets.create', async () => {
            await controller.createSnippet();
            snippetTreeDataProvider.refresh();
        }),
        vscode.commands.registerCommand('wordpressSnippets.refresh', () => snippetTreeDataProvider.refresh()),
        vscode.commands.registerCommand('wordpressSnippets.createSnippet', async () => {
            await controller.createSnippet();
            snippetTreeDataProvider.refresh();
        }),
        vscode.commands.registerCommand('wordpressSnippets.delete', async (item) => {
            await controller.deleteSnippet(item);
            snippetTreeDataProvider.refresh();
        }),
        vscode.commands.registerCommand('wordpressSnippets.configure', async () => {
            await controller.reconfigure();
            snippetTreeDataProvider.refresh();
        }),
        vscode.commands.registerCommand('wordpressSnippets.openSnippet', (item) => controller.openSnippet(item)),
        vscode.commands.registerCommand('wordpressSnippets.toggleSnippet', async (item) => {
            await controller.toggleSnippet(item);
            snippetTreeDataProvider.refresh();
        }),

        // === Sort & Filter ===
        vscode.commands.registerCommand('wordpressSnippets.sortAsc', () => snippetTreeDataProvider.setSortOrder('asc')),
        vscode.commands.registerCommand('wordpressSnippets.sortDesc', () => snippetTreeDataProvider.setSortOrder('desc')),
        vscode.commands.registerCommand('wordpressSnippets.filterActive', () => snippetTreeDataProvider.setFilter('active')),
        vscode.commands.registerCommand('wordpressSnippets.filterInactive', () => snippetTreeDataProvider.setFilter('inactive')),
        vscode.commands.registerCommand('wordpressSnippets.filterAll', () => snippetTreeDataProvider.setFilter('all')),

        // === Search ===
        vscode.commands.registerCommand('wordpressSnippets.searchSnippets', async () => {
            const currentTerm = snippetTreeDataProvider.getSearchTerm();
            const searchTerm = await vscode.window.showInputBox({
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
                    } else if (searchTerm.trim() === '') {
                        vscode.window.setStatusBarMessage('🔍 Recherche effacée', 2000);
                    }
                }, 500);
            }
        }),
        vscode.commands.registerCommand('wordpressSnippets.clearSearch', () => {
            snippetTreeDataProvider.clearSearch();
            vscode.window.setStatusBarMessage('🔍 Recherche effacée', 2000);
        }),

        // === Snippet metadata management ===
        vscode.commands.registerCommand('wordpressSnippets.renameSnippet', async (item) => {
            await controller.renameSnippet(item);
            snippetTreeDataProvider.refresh();
        }),
        vscode.commands.registerCommand('wordpressSnippets.editDescription', async (item) => {
            await controller.editDescription(item);
            snippetTreeDataProvider.refresh();
        }),
        vscode.commands.registerCommand('wordpressSnippets.editTags', async (item) => {
            await controller.editTags(item);
            snippetTreeDataProvider.refresh();
        }),

        // === Other commands ===
        vscode.commands.registerCommand('wordpressSnippets.analyzeSnippet', () => controller.analyzeSnippet()),
        vscode.commands.registerCommand('wordpressSnippets.restoreBackup', (item) => controller.restoreBackup(item)),

        // === Plugin & connection management ===
        vscode.commands.registerCommand('wordpress-snippets.switchPlugin', async () => {
            const newConfig = await configManager.switchPlugin();
            if (newConfig) {
                const newProvider = await createSnippetProvider(context, newConfig);
                if (newProvider) {
                    await newProvider.initialize();
                    provider = newProvider;
                    snippetTreeDataProvider.updateProvider(provider);
                    snippetTreeDataProvider.refresh();
                }
            }
        }),
        vscode.commands.registerCommand('wordpressSnippets.manageConnections', async () => {
            const newConfig = await configManager.manageConnections();
            if (newConfig) {
                const newProvider = await createSnippetProvider(context, newConfig);
                if (newProvider) {
                    await newProvider.initialize();
                    provider = newProvider;
                    snippetTreeDataProvider.updateProvider(provider);
                    snippetTreeDataProvider.refresh();
                }
            }
        }),
        vscode.commands.registerCommand('wordpressSnippets.switchConnection', async () => {
            const connections = await configManager.getAllConnections();
            if (connections.length === 0) {
                vscode.window.showInformationMessage('Aucune connexion configurée.');
                return;
            }

            const activeConnection = await configManager.getActiveConnection();
            const connectionOptions = connections.map(conn => ({
                label: conn.name || conn.siteUrl,
                description: `${conn.siteUrl} (${conn.plugin})`,
                detail: activeConnection?.id === conn.id ? '🟢 Connexion active' : '',
                connection: conn
            }));

            const selected = await vscode.window.showQuickPick(connectionOptions, {
                placeHolder: 'Choisir la connexion WordPress active'
            });

            if (selected && selected.connection.id !== activeConnection?.id) {
                const newConfig = await configManager.setActiveConnection(selected.connection.id);
                if (newConfig) {
                    const newProvider = await createSnippetProvider(context, newConfig);
                    if (newProvider) {
                        await newProvider.initialize();
                        provider = newProvider;
                        snippetTreeDataProvider.updateProvider(provider);
                        snippetTreeDataProvider.refresh();
                        vscode.window.showInformationMessage(`Connexion active: ${selected.label}`);
                    }
                }
            }
        })
    );

    // === Auto-save on file change ===
    context.subscriptions.push(
        vscode.workspace.onDidSaveTextDocument(async (document: vscode.TextDocument) => {
            if (provider && provider.isSnippetFile(document.uri.fsPath)) {
                await provider.updateSnippetFromFile(document.uri.fsPath);
            }
        })
    );
}

export function deactivate() {}
