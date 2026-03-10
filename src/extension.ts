import * as vscode from 'vscode';
import { SnippetController } from './controllers/SnippetController';
import { SnippetTreeDataProvider } from './providers/SnippetTreeDataProvider';
import { createSnippetProvider } from './providers/SnippetProviderFactory';
import { ConfigManager } from './core/ConfigManager';

export async function activate(context: vscode.ExtensionContext) {
    const configManager = new ConfigManager(context);
    
    // State variables
    let provider: any | undefined; // Use 'any' or specific type if imported
    let snippetTreeDataProvider: SnippetTreeDataProvider | undefined;
    let controller: SnippetController | undefined;

    // Function to initialize or update the provider
    const updateProvider = async (config: any) => {
        if (!config) {
            console.warn('updateProvider called with null config');
            return;
        }

        console.log('Updating provider with config:', config.siteUrl);

        try {
            const newProvider = await createSnippetProvider(context, config);
            if (!newProvider) {
                console.error('Failed to create snippet provider');
                return;
            }

            await newProvider.initialize();
            provider = newProvider;

            if (!snippetTreeDataProvider) {
                snippetTreeDataProvider = new SnippetTreeDataProvider(provider);
                vscode.window.registerTreeDataProvider('wordpress-snippets-view', snippetTreeDataProvider);
            } else {
                snippetTreeDataProvider.updateProvider(provider);
            }
            
            // Recreate controller with new provider
            controller = new SnippetController(provider, snippetTreeDataProvider!, context);
            snippetTreeDataProvider.refresh();
            console.log('Provider updated successfully');
        } catch (error) {
            console.error('Error updating provider:', error);
            vscode.window.showErrorMessage('Error initializing WordPress connection: ' + error);
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
                    "No WordPress connection configured.",
                    "Configure Now"
                );
                if (selection === "Configure Now") {
                     config = await configManager.promptForConfig();
                     if (config) {
                        config.id = `wp_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
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
            vscode.window.showErrorMessage("No active WordPress connection. Please configure a connection first.");
            return undefined;
        }
        return controller;
    };

    const requireProvider = () => {
        if (!snippetTreeDataProvider) {
             vscode.window.showErrorMessage("No active WordPress connection. Please configure a connection first.");
             return undefined;
        }
        return snippetTreeDataProvider;
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
                    await updateProvider(newConfig);
                    vscode.window.showInformationMessage(`Connexion active: ${selected.label}`);
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
