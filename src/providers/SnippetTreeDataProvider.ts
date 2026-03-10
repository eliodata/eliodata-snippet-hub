import * as vscode from 'vscode';
import { SnippetPluginProvider } from './SnippetPluginProvider';
import { Snippet } from '../types/Snippet';

export class SnippetTreeDataProvider implements vscode.TreeDataProvider<Snippet> {

    private _onDidChangeTreeData: vscode.EventEmitter<Snippet | undefined | null | void> = new vscode.EventEmitter<Snippet | undefined | null | void>();
    readonly onDidChangeTreeData: vscode.Event<Snippet | undefined | null | void> = this._onDidChangeTreeData.event;
    private filter: 'all' | 'active' | 'inactive' = 'all';
    private searchTerm: string = '';
    private sortOrder: 'asc' | 'desc' = 'asc';
    private lastResultCount: number = 0;

    constructor(private snippetProvider: SnippetPluginProvider) {
        this.snippetProvider.onDidChangeSnippets(() => this.refresh());
    }

    updateProvider(provider: SnippetPluginProvider): void {
        this.snippetProvider = provider;
        this.snippetProvider.onDidChangeSnippets(() => this.refresh());
        this.refresh();
    }

    refresh(): void {
        this._onDidChangeTreeData.fire();
    }

    getTreeItem(element: Snippet): vscode.TreeItem {
        return new SnippetItem(element);
    }

    async getChildren(element?: Snippet): Promise<Snippet[]> {
        if (element) {
            return Promise.resolve([]);
        }
        let snippets = await this.snippetProvider.getSnippets(this.filter);

        if (this.searchTerm) {
            const normalizedTerm = this.normalizeString(this.searchTerm);
            
            // Recherche par ID - support des IDs numériques et des IDs avec préfixe FS
            const trimmedTerm = this.searchTerm.trim();
            if (/^\d+$/.test(trimmedTerm) || /^FS\d+$/i.test(trimmedTerm)) {
                snippets = snippets.filter(s => {
                    const snippetIdStr = s.id.toString();
                    
                    // Si l'utilisateur cherche un ID numérique pur
                    if (/^\d+$/.test(trimmedTerm)) {
                        const searchId = parseInt(trimmedTerm, 10);
                        // Comparer avec l'ID numérique ou avec l'ID FS correspondant
                        return s.id === searchId || 
                               snippetIdStr === trimmedTerm || 
                               snippetIdStr === `FS${trimmedTerm}`;
                    }
                    
                    // Si l'utilisateur cherche un ID avec préfixe FS
                    if (/^FS\d+$/i.test(trimmedTerm)) {
                        return snippetIdStr.toLowerCase() === trimmedTerm.toLowerCase();
                    }
                    
                    return false;
                });
            } else {
                // Recherche textuelle avec normalisation des accents
                snippets = snippets.filter(s => 
                    this.normalizeString(s.name).includes(normalizedTerm) || 
                    this.normalizeString(s.description || '').includes(normalizedTerm) ||
                    this.normalizeString(s.code).includes(normalizedTerm)
                );
            }
        }

        snippets.sort((a, b) => {
            const nameA = a.name.toLowerCase();
            const nameB = b.name.toLowerCase();
            if (nameA < nameB) {
                return this.sortOrder === 'asc' ? -1 : 1;
            }
            if (nameA > nameB) {
                return this.sortOrder === 'asc' ? 1 : -1;
            }
            return 0;
        });

        // Mettre à jour le compteur de résultats
        this.lastResultCount = snippets.length;
        return snippets;
    }

    setFilter(filter: 'all' | 'active' | 'inactive'): void {
        this.filter = filter;
        this.refresh();
    }

    setSearchTerm(term: string): void {
        this.searchTerm = term;
        this.refresh();
    }

    clearSearch(): void {
        this.searchTerm = '';
        this.lastResultCount = 0;
        this.refresh();
    }

    setSortOrder(order: 'asc' | 'desc'): void {
        this.sortOrder = order;
        this.refresh();
    }

    private normalizeString(str: string): string {
        return str.toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '');
    }

    getSearchTerm(): string {
        return this.searchTerm;
    }

    getResultCount(): number {
        return this.lastResultCount;
    }

    async toggleSnippet(snippet: Snippet): Promise<void> {
        if ('toggleSnippet' in this.snippetProvider) {
            const provider = this.snippetProvider as any;
            const newStatus = !snippet.active;
            const success = await provider.toggleSnippet(snippet.id, newStatus);
            if (success) {
                vscode.window.showInformationMessage(`Snippet "${snippet.name}" ${newStatus ? 'activated' : 'deactivated'}.`);
                this.refresh();
            } else {
                vscode.window.showErrorMessage(`Failed to toggle snippet "${snippet.name}".`);
            }
        }
    }

    getStatusMessage(): string {
        if (this.searchTerm) {
            const count = this.lastResultCount;
            const term = this.searchTerm;
            const trimmedTerm = term.trim();
            const isIdSearch = /^\d+$/.test(trimmedTerm) || /^FS\d+$/i.test(trimmedTerm);
            if (isIdSearch) {
                return count > 0 ? `Snippet ID ${term} trouvé` : `Aucun snippet avec l'ID ${term}`;
            } else {
                return `${count} snippet${count !== 1 ? 's' : ''} trouvé${count !== 1 ? 's' : ''} pour "${term}"`;
            }
        }
        return '';
    }
}

function formatRelativeTime(dateString: string): string {
    if (!dateString) {
        return '';
    }
    // Replace space with 'T' to make it ISO 8601 compatible for robust parsing
    const isoDateString = dateString.replace(' ', 'T');
    const date = new Date(isoDateString);

    if (isNaN(date.getTime())) {
        return dateString; // Return original string if parsing fails
    }

    const now = new Date();
    const seconds = Math.floor((now.getTime() - date.getTime()) / 1000);

    if (seconds < 2) {
        return 'just now';
    }
    if (seconds < 60) {
        return `${seconds} seconds ago`;
    }
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) {
        return `${minutes} minute${minutes > 1 ? 's' : ''} ago`;
    }
    const hours = Math.floor(minutes / 60);
    if (hours < 24) {
        return `${hours} hour${hours > 1 ? 's' : ''} ago`;
    }
    const days = Math.floor(hours / 24);
    if (days <= 7) {
        return `${days} day${days > 1 ? 's' : ''} ago`;
    }
    return date.toLocaleDateString();
}

class SnippetItem extends vscode.TreeItem {
    constructor(
        public readonly snippet: Snippet
    ) {
        super(`[${snippet.id}] ${snippet.name}`, vscode.TreeItemCollapsibleState.None);
        this.tooltip = `[${snippet.id}] ${this.snippet.name}\nModified: ${snippet.modified}\nDescription: ${snippet.description || ''}`;
        this.description = formatRelativeTime(snippet.modified);
        this.command = {
            command: 'wordpressSnippets.openSnippet',
            title: 'Open Snippet',
            arguments: [this.snippet]
        };
        this.contextValue = 'snippet';
        this.iconPath = new vscode.ThemeIcon(snippet.active ? 'check' : 'circle-slash');
    }
}