import { Snippet, SnippetCreateData, SnippetUpdateData, WorkspaceSyncRole } from '../types/Snippet';
import * as vscode from 'vscode';

export interface SnippetSyncOptions {
    syncRole: WorkspaceSyncRole;
    canSyncToWordPress: () => boolean;
    canWriteVaultFromRemote: () => boolean;
    getStatusMessage: () => string;
}

export interface SnippetPluginProvider extends vscode.Disposable {
    onDidChangeSnippets: vscode.Event<void>;
    initialize(): Promise<boolean>;
    getSnippets(status?: 'all' | 'active' | 'inactive'): Promise<Snippet[]>;
    getSnippet(id: string | number): Promise<Snippet | null>;
    createSnippet(data: SnippetCreateData): Promise<Snippet | null>;
    updateSnippet(data: SnippetUpdateData): Promise<boolean>;
    deleteSnippet(id: string | number): Promise<boolean>;
    updateSnippetFromFile(filePath: string): Promise<void>;
    isSnippetFile(filePath: string): boolean;
    getSnippetCachePath(id: string | number): string;
    restoreBackup(snippetId: string | number, backupFile: string): Promise<boolean>;
    getBackups(snippetId: string | number): Promise<string[]>;
    configureSync?(options: SnippetSyncOptions): void;
    canSyncToWordPress?(): boolean;
    canWriteVaultFromRemote?(): boolean;
    getSyncStatusMessage?(): string;
    updateAttribution?(
        id: string | number,
        targetMode: 'all' | 'post_types' | 'specific_posts',
        targetPostTypes: string,
        targetPostIds: string
    ): Promise<boolean>;
}
