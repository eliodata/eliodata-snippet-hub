import * as vscode from 'vscode';
import * as fs from 'fs/promises';
import * as path from 'path';
import * as crypto from 'crypto';
import * as yaml from 'yaml';
import { Snippet } from '../types/Snippet';
import { SnippetStorage } from './SnippetStorage';

export class ObsidianVaultStorage implements SnippetStorage {
    private vaultPath: string;
    private snippetsDir: string;
    private backupDir: string;
    private siteSlug: string;
    private legacySiteSlug: string;

    constructor(context: vscode.ExtensionContext, siteUrl: string, siteFolderName?: string) {
        const config = vscode.workspace.getConfiguration('wordpressSnippets');
        const configuredPath = config.get<string>('workspaceStoragePath') || config.get<string>('obsidianVaultPath');
        if (configuredPath && configuredPath.trim() !== '') {
            this.vaultPath = configuredPath;
        } else {
            this.vaultPath = path.join(context.globalStorageUri.fsPath, 'workspace_storage');
        }

        this.legacySiteSlug = this.buildLegacySiteSlug(siteUrl);
        this.siteSlug = this.normalizeSiteFolder(siteFolderName || this.buildDefaultSiteFolder(siteUrl));
        this.snippetsDir = path.join(this.vaultPath, 'snippets', this.siteSlug);
        this.backupDir = path.join(this.vaultPath, '.snippet_backups', this.siteSlug);
    }

    private buildDefaultSiteFolder(siteUrl: string): string {
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

    private buildLegacySiteSlug(siteUrl: string): string {
        return siteUrl.replace(/^https?:\/\//, '').replace(/[^a-z0-9]/gi, '-').toLowerCase();
    }

    private normalizeSiteFolder(value: string): string {
        const normalized = value
            .toLowerCase()
            .replace(/[^a-z0-9-]+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-+|-+$/g, '');

        if (normalized === '') {
            return 'site-wordpress';
        }

        return normalized.startsWith('site-') ? normalized : `site-${normalized}`;
    }

    public async initialize(): Promise<void> {
        try {
            const legacyDir = path.join(this.vaultPath, 'snippets', this.legacySiteSlug);
            const legacyBackupDir = path.join(this.vaultPath, '.snippet_backups', this.legacySiteSlug);

            if (legacyDir !== this.snippetsDir) {
                try {
                    await fs.access(legacyDir);
                    try {
                        await fs.access(this.snippetsDir);
                    } catch {
                        await fs.rename(legacyDir, this.snippetsDir);
                    }
                } catch {
                    // Legacy directory does not exist
                }
            }

            if (legacyBackupDir !== this.backupDir) {
                try {
                    await fs.access(legacyBackupDir);
                    try {
                        await fs.access(this.backupDir);
                    } catch {
                        await fs.rename(legacyBackupDir, this.backupDir);
                    }
                } catch {
                    // Legacy backup directory does not exist
                }
            }

            await fs.mkdir(this.snippetsDir, { recursive: true });
            await fs.mkdir(this.backupDir, { recursive: true });
        } catch (error) {
            console.error('Failed to create local snippet storage directories', error);
        }
    }

    public getSnippetFilePath(id: string | number): string {
        return path.join(this.snippetsDir, `snippet-${id}.md`);
    }

    public getSnippetsDir(): string {
        return this.snippetsDir;
    }

    public isSnippetFile(filePath: string): boolean {
        return filePath.startsWith(this.snippetsDir) && filePath.endsWith('.md');
    }

    private generateHash(content: string): string {
        return crypto.createHash('sha256').update(content).digest('hex');
    }

    public async write(snippet: Snippet): Promise<string> {
        const filePath = this.getSnippetFilePath(snippet.id);
        
        let existingFrontmatter: any = {};
        try {
            const existingContent = await fs.readFile(filePath, 'utf8');
            const match = existingContent.match(/^---\n([\s\S]*?)\n---/);
            if (match) {
                existingFrontmatter = yaml.parse(match[1]) || {};
            }
        } catch (e) {
            // File doesn't exist yet, ignore
        }

        const frontmatter = {
            ...existingFrontmatter, // Preserve existing custom fields like depends_on
            id: snippet.id,
            site: this.siteSlug,
            title: snippet.name,
            slug: `snippet-${snippet.id}`,
            aliases: [`snippet ${snippet.id}`],
            active: snippet.active,
            scope: snippet.scope || 'global',
            tags: snippet.tags ? snippet.tags.split(',').map(t => t.trim()) : (existingFrontmatter.tags || []),
            wp_modified: snippet.modified,
            local_hash: this.generateHash(snippet.code),
            last_sync: new Date().toISOString()
        };

        const content = `---
${yaml.stringify(frontmatter)}---

# ${snippet.name}

${snippet.description || ''}

## Code

\`\`\`php
<?php
${snippet.code}
\`\`\`

## Notes
`;

        await fs.writeFile(filePath, content, 'utf8');
        return filePath;
    }

    public async read(id: string | number): Promise<Snippet | null> {
        const filePath = this.getSnippetFilePath(id);
        try {
            const content = await fs.readFile(filePath, 'utf8');
            return this.parseMarkdownToSnippet(content, id);
        } catch {
            return null;
        }
    }

    public async list(): Promise<Snippet[]> {
        try {
            const files = await fs.readdir(this.snippetsDir);
            const snippets: Snippet[] = [];
            for (const file of files) {
                if (file.endsWith('.md')) {
                    const id = file.replace('snippet-', '').replace('.md', '');
                    const snippet = await this.read(id);
                    if (snippet) snippets.push(snippet);
                }
            }
            return snippets;
        } catch {
            return [];
        }
    }

    public async delete(id: string | number): Promise<void> {
        const filePath = this.getSnippetFilePath(id);
        try {
            await fs.unlink(filePath);
        } catch (e) {
            console.error(`Failed to delete snippet file ${filePath}`, e);
        }
    }

    public async getBackups(id: string | number): Promise<string[]> {
        const dir = path.join(this.backupDir, `snippet-${id}`);
        try {
            const files = await fs.readdir(dir);
            return files.filter(f => f.endsWith('.md')).sort().reverse();
        } catch {
            return [];
        }
    }

    public async restoreBackup(id: string | number, backupFile: string): Promise<Snippet | null> {
        const backupPath = path.join(this.backupDir, `snippet-${id}`, backupFile);
        try {
            const content = await fs.readFile(backupPath, 'utf8');
            return this.parseMarkdownToSnippet(content, id);
        } catch {
            return null;
        }
    }

    public async createBackup(snippet: Snippet): Promise<void> {
        const dir = path.join(this.backupDir, `snippet-${snippet.id}`);
        await fs.mkdir(dir, { recursive: true });
        const ts = new Date().toISOString().replace(/[:.]/g, '-');
        const backupPath = path.join(dir, `backup-${ts}.md`);
        
        // Write the current state to a backup
        const currentFile = this.getSnippetFilePath(snippet.id);
        try {
            const currentContent = await fs.readFile(currentFile, 'utf8');
            await fs.writeFile(backupPath, currentContent, 'utf8');
            
            // Limit backups
            const backups = await this.getBackups(snippet.id);
            if (backups.length > 10) {
                for (const old of backups.slice(10)) {
                    await fs.unlink(path.join(dir, old));
                }
            }
        } catch (e) {
            console.error(`Failed to create backup for ${snippet.id}`, e);
        }
    }

    private parseMarkdownToSnippet(content: string, fallbackId: string | number): Snippet | null {
        const match = content.match(/^---\n([\s\S]*?)\n---\n([\s\S]*)$/);
        if (!match) return null;

        try {
            const frontmatter = yaml.parse(match[1]);
            const body = match[2];
            
            // Extract code
            const codeMatch = body.match(/\`\`\`php\n([\s\S]*?)\`\`\`/);
            const code = codeMatch ? codeMatch[1].trim() : '';

            // Extract description (everything before ## Code)
            const descMatch = body.match(/# (.*?)\n\n([\s\S]*?)## Code/);
            const name = descMatch ? descMatch[1].trim() : frontmatter.title;
            const description = descMatch ? descMatch[2].trim() : '';

            return {
                id: frontmatter.id || fallbackId,
                name: name || `Snippet ${fallbackId}`,
                description: description,
                code: code,
                scope: frontmatter.scope || 'global',
                active: frontmatter.active ?? true,
                created: frontmatter.created || '',
                modified: frontmatter.wp_modified || '',
                tags: Array.isArray(frontmatter.tags) ? frontmatter.tags.join(', ') : ''
            };
        } catch (e) {
            console.error('Failed to parse markdown snippet', e);
            return null;
        }
    }
}
