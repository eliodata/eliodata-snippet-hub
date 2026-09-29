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
exports.ObsidianVaultStorage = void 0;
const vscode = __importStar(require("vscode"));
const fs = __importStar(require("fs/promises"));
const path = __importStar(require("path"));
const crypto = __importStar(require("crypto"));
const yaml = __importStar(require("yaml"));
class ObsidianVaultStorage {
    constructor(context, siteUrl, siteFolderName) {
        const config = vscode.workspace.getConfiguration('wordpressSnippets');
        const configuredPath = config.get('workspaceStoragePath') || config.get('obsidianVaultPath');
        if (configuredPath && configuredPath.trim() !== '') {
            this.vaultPath = configuredPath;
        }
        else {
            this.vaultPath = path.join(context.globalStorageUri.fsPath, 'workspace_storage');
        }
        this.legacySiteSlug = this.buildLegacySiteSlug(siteUrl);
        this.siteSlug = this.normalizeSiteFolder(siteFolderName || this.buildDefaultSiteFolder(siteUrl));
        this.snippetsDir = path.join(this.vaultPath, 'snippets', this.siteSlug);
        this.backupDir = path.join(this.vaultPath, '.snippet_backups', this.siteSlug);
    }
    buildDefaultSiteFolder(siteUrl) {
        try {
            const hostname = new URL(siteUrl).hostname.replace(/^www\./i, '');
            const slug = hostname
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
            return `site-${slug || 'wordpress'}`;
        }
        catch {
            const fallback = siteUrl
                .toLowerCase()
                .replace(/^https?:\/\//, '')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
            return `site-${fallback || 'wordpress'}`;
        }
    }
    buildLegacySiteSlug(siteUrl) {
        return siteUrl.replace(/^https?:\/\//, '').replace(/[^a-z0-9]/gi, '-').toLowerCase();
    }
    normalizeSiteFolder(value) {
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
    async initialize() {
        try {
            const legacyDir = path.join(this.vaultPath, 'snippets', this.legacySiteSlug);
            const legacyBackupDir = path.join(this.vaultPath, '.snippet_backups', this.legacySiteSlug);
            if (legacyDir !== this.snippetsDir) {
                try {
                    await fs.access(legacyDir);
                    try {
                        await fs.access(this.snippetsDir);
                    }
                    catch {
                        await fs.rename(legacyDir, this.snippetsDir);
                    }
                }
                catch {
                    // Legacy directory does not exist
                }
            }
            if (legacyBackupDir !== this.backupDir) {
                try {
                    await fs.access(legacyBackupDir);
                    try {
                        await fs.access(this.backupDir);
                    }
                    catch {
                        await fs.rename(legacyBackupDir, this.backupDir);
                    }
                }
                catch {
                    // Legacy backup directory does not exist
                }
            }
            await fs.mkdir(this.snippetsDir, { recursive: true });
            await fs.mkdir(this.backupDir, { recursive: true });
        }
        catch (error) {
            console.error('Failed to create local snippet storage directories', error);
        }
    }
    getSnippetFilePath(id) {
        return path.join(this.snippetsDir, `snippet-${id}.md`);
    }
    getSnippetsDir() {
        return this.snippetsDir;
    }
    isSnippetFile(filePath) {
        return filePath.startsWith(this.snippetsDir) && filePath.endsWith('.md');
    }
    generateHash(content) {
        return crypto.createHash('sha256').update(content).digest('hex');
    }
    /** Code as stored in the file, without the opening PHP tag, trimmed. */
    static normalizeCode(code) {
        return String(code || '').replace(/^\uFEFF?\s*<\?(?:php)?\s*/i, '').trim();
    }
    /**
     * Local changes that WordPress does not have yet.
     *
     * Since 4.2.0, a file changed outside the editor (script, Obsidian, AI agent, git) is no
     * longer sent to WordPress automatically. The refresh that follows any save rewrites every
     * snippet file from the server: without this check it silently replaced those files with
     * the older server version, and the next save in the editor published that older version.
     *
     * `local_hash` in the frontmatter is the hash of the code last written from WordPress.
     * The file has unsynced changes when its code no longer matches that hash AND differs
     * from the code the server returns now. A file already equal to the server is never held.
     */
    async hasUnsyncedLocalChanges(id, remoteCode) {
        let content;
        try {
            content = await fs.readFile(this.getSnippetFilePath(id), 'utf8');
        }
        catch {
            return false;
        }
        const match = content.match(/^---\n([\s\S]*?)\n---\n([\s\S]*)$/);
        if (!match) {
            return false;
        }
        let frontmatter = {};
        try {
            frontmatter = yaml.parse(match[1]) || {};
        }
        catch {
            return false;
        }
        const baseHash = typeof frontmatter.local_hash === 'string' ? frontmatter.local_hash : '';
        if (baseHash === '') {
            return false;
        }
        const codeMatch = match[2].match(/```php\n([\s\S]*?)```/);
        if (!codeMatch) {
            return false;
        }
        // write() stores the code exactly as `<?php\n` + code + `\n`: read back that way,
        // an untouched file gives `local_hash` even when the code starts or ends with blank
        // lines. The trimmed form covers files written by other tools.
        const raw = codeMatch[1];
        if (raw.startsWith('<?php\n') && raw.endsWith('\n')
            && this.generateHash(raw.slice('<?php\n'.length, -1)) === baseHash) {
            return false;
        }
        const localCode = ObsidianVaultStorage.normalizeCode(raw);
        const localHash = this.generateHash(localCode);
        if (localHash === baseHash) {
            return false;
        }
        return localHash !== this.generateHash(ObsidianVaultStorage.normalizeCode(remoteCode));
    }
    async write(snippet) {
        const filePath = this.getSnippetFilePath(snippet.id);
        let existingFrontmatter = {};
        try {
            const existingContent = await fs.readFile(filePath, 'utf8');
            const match = existingContent.match(/^---\n([\s\S]*?)\n---/);
            if (match) {
                existingFrontmatter = yaml.parse(match[1]) || {};
            }
        }
        catch (e) {
            // File doesn't exist yet, ignore
        }
        const frontmatter = {
            ...existingFrontmatter,
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
    async read(id) {
        const filePath = this.getSnippetFilePath(id);
        try {
            const content = await fs.readFile(filePath, 'utf8');
            return this.parseMarkdownToSnippet(content, id);
        }
        catch {
            return null;
        }
    }
    async list() {
        try {
            const files = await fs.readdir(this.snippetsDir);
            const snippets = [];
            for (const file of files) {
                if (file.endsWith('.md')) {
                    const id = file.replace('snippet-', '').replace('.md', '');
                    const snippet = await this.read(id);
                    if (snippet)
                        snippets.push(snippet);
                }
            }
            return snippets;
        }
        catch {
            return [];
        }
    }
    async delete(id) {
        const filePath = this.getSnippetFilePath(id);
        try {
            await fs.unlink(filePath);
        }
        catch (e) {
            console.error(`Failed to delete snippet file ${filePath}`, e);
        }
    }
    async getBackups(id) {
        const dir = path.join(this.backupDir, `snippet-${id}`);
        try {
            const files = await fs.readdir(dir);
            return files.filter(f => f.endsWith('.md')).sort().reverse();
        }
        catch {
            return [];
        }
    }
    async restoreBackup(id, backupFile) {
        const backupPath = path.join(this.backupDir, `snippet-${id}`, backupFile);
        try {
            const content = await fs.readFile(backupPath, 'utf8');
            return this.parseMarkdownToSnippet(content, id);
        }
        catch {
            return null;
        }
    }
    async createBackup(snippet) {
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
        }
        catch (e) {
            console.error(`Failed to create backup for ${snippet.id}`, e);
        }
    }
    parseMarkdownToSnippet(content, fallbackId) {
        const match = content.match(/^---\n([\s\S]*?)\n---\n([\s\S]*)$/);
        if (!match)
            return null;
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
        }
        catch (e) {
            console.error('Failed to parse markdown snippet', e);
            return null;
        }
    }
}
exports.ObsidianVaultStorage = ObsidianVaultStorage;
//# sourceMappingURL=ObsidianVaultStorage.js.map