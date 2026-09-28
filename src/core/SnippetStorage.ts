import { Snippet } from '../types/Snippet';

export interface SnippetStorage {
    initialize(): Promise<void>;
    read(id: string | number): Promise<Snippet | null>;
    write(snippet: Snippet): Promise<string>;
    list(): Promise<Snippet[]>;
    delete(id: string | number): Promise<void>;
    getBackups(id: string | number): Promise<string[]>;
    restoreBackup(id: string | number, backupFile: string): Promise<Snippet | null>;
    getSnippetFilePath(id: string | number): string;
    getSnippetsDir(): string;
    isSnippetFile(filePath: string): boolean;
    createBackup(snippet: Snippet): Promise<void>;
}
