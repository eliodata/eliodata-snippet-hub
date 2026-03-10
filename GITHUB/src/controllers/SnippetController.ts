import * as vscode from 'vscode';
import { ConfigManager } from '../core/ConfigManager';
import { SnippetPluginProvider } from '../providers/SnippetPluginProvider';
import { SnippetTreeDataProvider } from '../providers/SnippetTreeDataProvider';
import { Snippet } from '../types/Snippet';

export class SnippetController {
    private configManager: ConfigManager;

    constructor(
        private snippetProvider: SnippetPluginProvider,
        private snippetTreeDataProvider: SnippetTreeDataProvider,
        context: vscode.ExtensionContext
    ) {
        this.configManager = new ConfigManager(context);
    }

    public async createSnippet() {
        const name = await vscode.window.showInputBox({ prompt: 'Nom du snippet' });
        if (!name) { return; }

        const description = await vscode.window.showInputBox({ prompt: 'Description du snippet' });
        if (description === undefined) { return; }

        const content = await vscode.window.showInputBox({ prompt: 'Contenu du snippet (code PHP)' });
        if (!content) { return; }

        await this.snippetProvider.createSnippet({ name, code: content, description });
    }

    public async deleteSnippet(snippet: Snippet) {
        if (!snippet) {
            const snippets = await this.snippetProvider.getSnippets();
            const snippetItems = snippets.map(s => ({ label: s.name, snippet: s }));
            const selected = await vscode.window.showQuickPick(snippetItems);
            if (selected) {
                snippet = selected.snippet;
            } else {
                return;
            }
        }

        const confirm = await vscode.window.showWarningMessage(
            `Supprimer le snippet "${snippet.name}" ?`, { modal: true }, 'Oui'
        );
        if (confirm === 'Oui') {
            await this.snippetProvider.deleteSnippet(snippet.id);
        }
    }

    public async reconfigure() {
        const newConfig = await this.configManager.manageConnections();
        if (newConfig) {
            vscode.commands.executeCommand('wordpressSnippets.refresh');
        } else {
            vscode.window.showInformationMessage('Configuration annulée.');
        }
    }

    public async switchPlugin() {
        const newConfig = await this.configManager.switchPlugin();
        if (newConfig) {
            vscode.commands.executeCommand('wordpressSnippets.refresh');
        } else {
            vscode.window.showInformationMessage('Changement de plugin annulé.');
        }
    }

    public async openSnippet(snippet: Snippet) {
        if (!snippet) { return; }

        const filePath = this.snippetProvider.getSnippetCachePath(snippet.id);

        try {
            const fs = require('fs').promises;
            await fs.access(filePath);
            const doc = await vscode.workspace.openTextDocument(filePath);
            await vscode.window.showTextDocument(doc, { preview: false });
        } catch (error: any) {
            // Try to fetch the snippet and cache it
            try {
                const fetchedSnippet = await this.snippetProvider.getSnippet(snippet.id);
                if (fetchedSnippet) {
                    const doc = await vscode.workspace.openTextDocument(filePath);
                    await vscode.window.showTextDocument(doc, { preview: false });
                } else {
                    vscode.window.showErrorMessage(`Snippet ${snippet.id} introuvable sur le serveur.`);
                }
            } catch (fetchError: any) {
                vscode.window.showErrorMessage(`Impossible d'ouvrir le snippet ${snippet.id}: ${fetchError.message}`);
            }
        }
    }

    public async toggleSnippet(snippet: Snippet) {
        if (!snippet) { return; }
        await this.snippetTreeDataProvider.toggleSnippet(snippet);
    }

    // === Metadata management commands ===

    public async renameSnippet(item?: any) {
        const snippet = item?.snippet || item;
        if (!snippet || !snippet.id) {
            vscode.window.showWarningMessage('Aucun snippet sélectionné.');
            return;
        }

        const newName = await vscode.window.showInputBox({
            prompt: 'Nouveau nom du snippet',
            value: snippet.name,
            placeHolder: 'Entrez le nouveau nom...',
            validateInput: (v) => v.trim() ? null : 'Le nom ne peut pas être vide'
        });

        if (newName && newName !== snippet.name) {
            if ('renameSnippet' in this.snippetProvider) {
                const provider = this.snippetProvider as any;
                const success = await provider.renameSnippet(snippet.id, newName);
                if (success) {
                    vscode.window.showInformationMessage(`Snippet renommé: "${newName}"`);
                }
            } else {
                vscode.window.showWarningMessage('Le renommage n\'est pas supporté pour ce type de plugin.');
            }
        }
    }

    public async editDescription(item?: any) {
        const snippet = item?.snippet || item;
        if (!snippet || !snippet.id) {
            vscode.window.showWarningMessage('Aucun snippet sélectionné.');
            return;
        }

        // Fetch fresh data from server
        const freshSnippet = await this.snippetProvider.getSnippet(snippet.id);
        const currentDesc = freshSnippet?.description || snippet.description || '';

        const newDescription = await vscode.window.showInputBox({
            prompt: 'Description du snippet',
            value: currentDesc,
            placeHolder: 'Entrez la description...'
        });

        if (newDescription !== undefined && newDescription !== currentDesc) {
            if ('updateDescription' in this.snippetProvider) {
                const provider = this.snippetProvider as any;
                const success = await provider.updateDescription(snippet.id, newDescription);
                if (success) {
                    vscode.window.showInformationMessage('Description mise à jour.');
                }
            } else {
                vscode.window.showWarningMessage('La modification de description n\'est pas supportée pour ce type de plugin.');
            }
        }
    }

    public async editTags(item?: any) {
        const snippet = item?.snippet || item;
        if (!snippet || !snippet.id) {
            vscode.window.showWarningMessage('Aucun snippet sélectionné.');
            return;
        }

        // Fetch fresh data from server
        const freshSnippet = await this.snippetProvider.getSnippet(snippet.id);
        const currentTags = freshSnippet?.tags || snippet.tags || '';

        const newTags = await vscode.window.showInputBox({
            prompt: 'Tags/mots-clés du snippet (séparés par des virgules)',
            value: currentTags,
            placeHolder: 'woocommerce, checkout, panier...'
        });

        if (newTags !== undefined && newTags !== currentTags) {
            if ('updateTags' in this.snippetProvider) {
                const provider = this.snippetProvider as any;
                const success = await provider.updateTags(snippet.id, newTags);
                if (success) {
                    vscode.window.showInformationMessage('Tags mis à jour.');
                }
            } else {
                vscode.window.showWarningMessage('La modification des tags n\'est pas supportée pour ce type de plugin.');
            }
        }
    }

    public async editAttribution(item?: any) {
        const snippet = item?.snippet || item;
        if (!snippet || !snippet.id) {
            vscode.window.showWarningMessage('Aucun snippet sélectionné.');
            return;
        }

        if (!this.snippetProvider.updateAttribution) {
            vscode.window.showWarningMessage('La modification des attributions n\'est pas supportée pour ce type de plugin.');
            return;
        }

        const freshSnippet = await this.snippetProvider.getSnippet(snippet.id);
        const currentMode = (freshSnippet?.target_mode || snippet.target_mode || 'all') as 'all' | 'post_types' | 'specific_posts';
        const currentPostTypes = freshSnippet?.target_post_types || snippet.target_post_types || '';
        const currentPostIds = freshSnippet?.target_post_ids || snippet.target_post_ids || '';

        const selectedMode = await vscode.window.showQuickPick(
            [
                { label: 'Tous les contenus', value: 'all' as const },
                { label: 'Types de contenus', value: 'post_types' as const },
                { label: 'Contenus spécifiques', value: 'specific_posts' as const }
            ],
            {
                placeHolder: 'Sélectionner le mode d\'attribution',
            }
        );

        if (!selectedMode) {
            return;
        }

        let targetPostTypes = '';
        let targetPostIds = '';

        if (selectedMode.value === 'post_types') {
            const input = await vscode.window.showInputBox({
                prompt: 'Types de contenus (slug, séparés par des virgules)',
                value: currentPostTypes,
                placeHolder: 'post, page, product'
            });

            if (input === undefined) {
                return;
            }

            targetPostTypes = input
                .split(',')
                .map((value) => value.trim())
                .filter(Boolean)
                .join(',');
        }

        if (selectedMode.value === 'specific_posts') {
            const input = await vscode.window.showInputBox({
                prompt: 'IDs des contenus (séparés par des virgules)',
                value: currentPostIds,
                placeHolder: '12,45,90'
            });

            if (input === undefined) {
                return;
            }

            targetPostIds = input
                .split(',')
                .map((value) => value.trim())
                .filter((value) => /^\d+$/.test(value))
                .join(',');
        }

        const success = await this.snippetProvider.updateAttribution(
            snippet.id,
            selectedMode.value,
            targetPostTypes,
            targetPostIds
        );

        if (success) {
            vscode.window.showInformationMessage('Attributions mises à jour.');
        }
    }

    public async restoreBackup(item?: any) {
        let snippetId: string | number;
        if (item && item.snippet) {
            snippetId = item.snippet.id;
        } else {
            const idStr = await vscode.window.showInputBox({ prompt: 'Entrez l\'ID du snippet à restaurer' });
            if (!idStr) { return; }
            const numericId = parseInt(idStr, 10);
            snippetId = isNaN(numericId) ? idStr : numericId;
        }

        const backups = await this.snippetProvider.getBackups(snippetId);
        if (backups.length === 0) {
            vscode.window.showInformationMessage('Aucune sauvegarde trouvée pour ce snippet.');
            return;
        }

        const selectedBackup = await vscode.window.showQuickPick(backups, {
            placeHolder: 'Sélectionner une sauvegarde à restaurer',
        });

        if (selectedBackup) {
            const success = await this.snippetProvider.restoreBackup(snippetId, selectedBackup);
            if (success) {
                vscode.window.showInformationMessage(`Snippet ${snippetId} restauré depuis ${selectedBackup}.`);
                const snippet = await this.snippetProvider.getSnippet(snippetId);
                if (snippet) {
                    await this.openSnippet(snippet);
                }
            } else {
                vscode.window.showErrorMessage('Échec de la restauration.');
            }
        }
    }

    public async analyzeSnippet(id?: string | number) {
        let snippetId = id;

        if (!snippetId) {
            const editor = vscode.window.activeTextEditor;
            if (editor) {
                const text = editor.document.getText();
                const numericMatch = text.match(/\*\s*Snippet ID:\s*(\d+)/);
                const fsMatch = text.match(/\*\s*Snippet ID:\s*(FS\d+)/);
                if (fsMatch && fsMatch[1]) {
                    snippetId = fsMatch[1];
                } else if (numericMatch && numericMatch[1]) {
                    snippetId = parseInt(numericMatch[1], 10);
                }
            }
        }

        if (!snippetId) {
            const idStr = await vscode.window.showInputBox({ prompt: 'Entrez l\'ID du snippet à analyser' });
            if (idStr) {
                const numericId = parseInt(idStr, 10);
                snippetId = isNaN(numericId) ? idStr : numericId;
            } else {
                return;
            }
        }

        if (snippetId) {
            const snippet = await this.snippetProvider.getSnippet(snippetId);
            if (snippet) {
                const analysis = `ID: ${snippet.id}\nNom: ${snippet.name}\nDescription: ${snippet.description}\nActif: ${snippet.active}\nTags: ${snippet.tags || 'aucun'}\nCible: ${snippet.target_mode || 'all'}\nTypes de contenus: ${snippet.target_post_types || '-'}\nIDs contenus: ${snippet.target_post_ids || '-'}\n\nCode:\n---\n${snippet.code}`;
                vscode.window.showInformationMessage(`Analyse: ${snippet.name}`, { modal: true, detail: analysis });
            } else {
                vscode.window.showErrorMessage(`Snippet ID ${snippetId} introuvable.`);
            }
        }
    }
}
