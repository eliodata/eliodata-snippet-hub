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
exports.SnippetTreeDataProvider = void 0;
const vscode = __importStar(require("vscode"));
class SnippetTreeDataProvider {
    constructor(snippetProvider) {
        this.snippetProvider = snippetProvider;
        this._onDidChangeTreeData = new vscode.EventEmitter();
        this.onDidChangeTreeData = this._onDidChangeTreeData.event;
        this.filter = 'all';
        this.searchTerm = '';
        this.sortOrder = 'asc';
        this.lastResultCount = 0;
        this.snippetProvider.onDidChangeSnippets(() => this.refresh());
    }
    updateProvider(provider) {
        this.snippetProvider = provider;
        this.snippetProvider.onDidChangeSnippets(() => this.refresh());
        this.refresh();
    }
    refresh() {
        this._onDidChangeTreeData.fire();
    }
    getTreeItem(element) {
        return new SnippetItem(element);
    }
    async getChildren(element) {
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
            }
            else {
                // Recherche textuelle avec normalisation des accents
                snippets = snippets.filter(s => this.normalizeString(s.name).includes(normalizedTerm) ||
                    this.normalizeString(s.description || '').includes(normalizedTerm) ||
                    this.normalizeString(s.code).includes(normalizedTerm));
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
    setFilter(filter) {
        this.filter = filter;
        this.refresh();
    }
    setSearchTerm(term) {
        this.searchTerm = term;
        this.refresh();
    }
    clearSearch() {
        this.searchTerm = '';
        this.lastResultCount = 0;
        this.refresh();
    }
    setSortOrder(order) {
        this.sortOrder = order;
        this.refresh();
    }
    normalizeString(str) {
        return str.toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '');
    }
    getSearchTerm() {
        return this.searchTerm;
    }
    getResultCount() {
        return this.lastResultCount;
    }
    async toggleSnippet(snippet) {
        if ('toggleSnippet' in this.snippetProvider) {
            const provider = this.snippetProvider;
            const newStatus = !snippet.active;
            const success = await provider.toggleSnippet(snippet.id, newStatus);
            if (success) {
                vscode.window.showInformationMessage(`Snippet "${snippet.name}" ${newStatus ? 'activated' : 'deactivated'}.`);
                this.refresh();
            }
            else {
                vscode.window.showErrorMessage(`Failed to toggle snippet "${snippet.name}".`);
            }
        }
    }
    getStatusMessage() {
        if (this.searchTerm) {
            const count = this.lastResultCount;
            const term = this.searchTerm;
            const trimmedTerm = term.trim();
            const isIdSearch = /^\d+$/.test(trimmedTerm) || /^FS\d+$/i.test(trimmedTerm);
            if (isIdSearch) {
                return count > 0 ? `Snippet ID ${term} trouvé` : `Aucun snippet avec l'ID ${term}`;
            }
            else {
                return `${count} snippet${count !== 1 ? 's' : ''} trouvé${count !== 1 ? 's' : ''} pour "${term}"`;
            }
        }
        return '';
    }
}
exports.SnippetTreeDataProvider = SnippetTreeDataProvider;
function formatRelativeTime(dateString) {
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
    constructor(snippet) {
        super(`[${snippet.id}] ${snippet.name}`, vscode.TreeItemCollapsibleState.None);
        this.snippet = snippet;
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
//# sourceMappingURL=SnippetTreeDataProvider.js.map