import * as fs from 'fs/promises';
import * as path from 'path';
import * as vscode from 'vscode';
import { WordPressConnectionConfig, WorkspaceSyncRole } from '../types/Snippet';

type LockPayload = {
    siteFolder: string;
    workspaceName: string;
    workspaceFile?: string;
    instanceId: string;
    acquiredAt: string;
    heartbeatAt: string;
};

export class VaultSiteSyncLockService implements vscode.Disposable {
    private static readonly HEARTBEAT_INTERVAL_MS = 10000;
    private static readonly STALE_AFTER_MS = 30000;

    private readonly instanceId = `${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
    private heartbeatTimer: ReturnType<typeof setInterval> | null = null;
    private lockFilePath: string | null = null;
    private syncRole: WorkspaceSyncRole = 'owner';
    // Role read from the settings, kept apart from syncRole which drops to 'editor' when another window holds the lock
    private configuredRole: WorkspaceSyncRole = 'owner';
    private ownsLock = false;
    private statusMessage = '';

    constructor(
        private readonly context: vscode.ExtensionContext,
        private readonly connection: WordPressConnectionConfig,
        private readonly onStatusChange?: () => void
    ) {}

    public async initialize(syncRole: WorkspaceSyncRole): Promise<void> {
        this.syncRole = syncRole;
        this.configuredRole = syncRole;
        this.lockFilePath = this.buildLockFilePath();

        if (syncRole === 'off') {
            this.statusMessage = 'Synchronisation désactivée pour ce workspace.';
            return;
        }

        if (syncRole === 'editor') {
            this.statusMessage = 'Workspace en lecture locale. Une autre fenêtre pilote la synchronisation.';
            return;
        }

        const acquired = await this.acquireLock();
        if (acquired) {
            this.ownsLock = true;
            this.statusMessage = 'Ce workspace pilote la synchronisation.';
            this.startHeartbeat();
            return;
        }

        this.syncRole = 'editor';
        this.statusMessage = 'Une autre fenêtre pilote déjà la synchronisation pour ce site. Passage en lecture locale.';
    }

    public canSyncToWordPress(): boolean {
        return this.syncRole === 'owner' && this.ownsLock;
    }

    /**
     * An explicit save in the editor is the user saying "this is the window I work in".
     * The lock follows that window: it is taken over from whichever window held it,
     * and the previous holder drops to read-only on its next heartbeat.
     * Windows configured as 'editor' or 'off' never take the lock this way.
     */
    public async claimForSave(): Promise<boolean> {
        if (this.configuredRole !== 'owner' || !this.lockFilePath) {
            return false;
        }
        if (this.ownsLock) {
            return true;
        }

        const previous = await this.readLockPayload();
        try {
            await fs.mkdir(path.dirname(this.lockFilePath), { recursive: true });
            await fs.writeFile(this.lockFilePath, JSON.stringify(this.buildPayload(), null, 2), 'utf8');
        } catch (error) {
            console.error('Impossible de reprendre le verrou de synchronisation local', error);
            return false;
        }

        this.ownsLock = true;
        this.syncRole = 'owner';
        this.statusMessage = previous && previous.instanceId !== this.instanceId && !this.isStale(previous)
            ? `Ce workspace a repris la synchronisation à la fenêtre « ${previous.workspaceName} ».`
            : 'Ce workspace pilote la synchronisation.';
        this.startHeartbeat();
        this.onStatusChange?.();
        return true;
    }

    public canWriteVaultFromRemote(): boolean {
        return this.canSyncToWordPress();
    }

    public getSyncRole(): WorkspaceSyncRole {
        return this.syncRole;
    }

    public getStatusMessage(): string {
        return this.statusMessage;
    }

    public dispose(): void {
        if (this.heartbeatTimer) {
            clearInterval(this.heartbeatTimer);
            this.heartbeatTimer = null;
        }

        if (this.ownsLock) {
            void this.releaseLock();
        }
    }

    private buildVaultPath(): string {
        const config = vscode.workspace.getConfiguration('wordpressSnippets');
        const configuredPath = config.get<string>('workspaceStoragePath') || config.get<string>('obsidianVaultPath');
        if (configuredPath && configuredPath.trim() !== '') {
            return configuredPath;
        }
        return path.join(this.context.globalStorageUri.fsPath, 'workspace_storage');
    }

    private buildLockFilePath(): string {
        const storagePath = this.buildVaultPath();
        const siteFolder = this.connection.vaultSiteFolder || 'site-wordpress';
        const lockPath = path.join(storagePath, '.trae', 'locks', `${siteFolder}.lock.json`);
        return lockPath;
    }

    private async acquireLock(): Promise<boolean> {
        if (!this.lockFilePath) {
            return false;
        }

        try {
            await fs.mkdir(path.dirname(this.lockFilePath), { recursive: true });
            const payload = this.buildPayload();

            try {
                const current = await this.readLockPayload();
                if (current && current.instanceId !== this.instanceId && !this.isStale(current)) {
                    return false;
                }
            } catch (err) {
                // Ignore invalid or missing lock content and overwrite it.
            }

            await fs.writeFile(this.lockFilePath, JSON.stringify(payload, null, 2), 'utf8');
            return true;
        } catch (err) {
            console.error('Impossible de créer le verrou de synchronisation local', err);
            return false;
        }
    }

    private startHeartbeat(): void {
        if (!this.lockFilePath || this.heartbeatTimer) {
            return;
        }

        this.heartbeatTimer = setInterval(() => {
            void this.refreshLock();
        }, VaultSiteSyncLockService.HEARTBEAT_INTERVAL_MS);
    }

    private async refreshLock(): Promise<void> {
        if (!this.lockFilePath || !this.ownsLock) {
            return;
        }

        try {
            const current = await this.readLockPayload();
            if (current && current.instanceId !== this.instanceId && !this.isStale(current)) {
                this.ownsLock = false;
                this.syncRole = 'editor';
                this.statusMessage = `Le verrou de synchronisation a été repris par la fenêtre « ${current.workspaceName} ». Passage en lecture locale.`;
                if (this.heartbeatTimer) {
                    clearInterval(this.heartbeatTimer);
                    this.heartbeatTimer = null;
                }
                this.onStatusChange?.();
                return;
            }

            await fs.writeFile(this.lockFilePath, JSON.stringify(this.buildPayload(), null, 2), 'utf8');
        } catch (error) {
            console.error('Impossible de rafraichir le verrou de sync du stockage local', error);
        }
    }

    private async releaseLock(): Promise<void> {
        if (!this.lockFilePath) {
            return;
        }

        try {
            const current = await this.readLockPayload();
            if (!current || current.instanceId !== this.instanceId) {
                return;
            }
            await fs.unlink(this.lockFilePath);
        } catch {
            // Ignore cleanup issues on shutdown.
        }
    }

    private async readLockPayload(): Promise<LockPayload | null> {
        if (!this.lockFilePath) {
            return null;
        }

        try {
            const content = await fs.readFile(this.lockFilePath, 'utf8');
            return JSON.parse(content) as LockPayload;
        } catch {
            return null;
        }
    }

    private isStale(payload: LockPayload): boolean {
        const heartbeatAt = Date.parse(payload.heartbeatAt);
        if (Number.isNaN(heartbeatAt)) {
            return true;
        }
        return Date.now() - heartbeatAt > VaultSiteSyncLockService.STALE_AFTER_MS;
    }

    private buildPayload(): LockPayload {
        const workspaceFile = vscode.workspace.workspaceFile?.fsPath;
        const workspaceName = vscode.workspace.name || path.basename(workspaceFile || process.cwd());
        const now = new Date().toISOString();

        return {
            siteFolder: this.connection.vaultSiteFolder || 'site-wordpress',
            workspaceName,
            workspaceFile,
            instanceId: this.instanceId,
            acquiredAt: now,
            heartbeatAt: now
        };
    }
}
