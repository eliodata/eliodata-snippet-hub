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
exports.VaultSiteSyncLockService = void 0;
const fs = __importStar(require("fs/promises"));
const path = __importStar(require("path"));
const vscode = __importStar(require("vscode"));
class VaultSiteSyncLockService {
    constructor(context, connection, onStatusChange) {
        this.context = context;
        this.connection = connection;
        this.onStatusChange = onStatusChange;
        this.instanceId = `${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
        this.heartbeatTimer = null;
        this.lockFilePath = null;
        this.syncRole = 'owner';
        // Role read from the settings, kept apart from syncRole which drops to 'editor' when another window holds the lock
        this.configuredRole = 'owner';
        this.ownsLock = false;
        this.statusMessage = '';
    }
    async initialize(syncRole) {
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
    canSyncToWordPress() {
        return this.syncRole === 'owner' && this.ownsLock;
    }
    /**
     * An explicit save in the editor is the user saying "this is the window I work in".
     * The lock follows that window: it is taken over from whichever window held it,
     * and the previous holder drops to read-only on its next heartbeat.
     * Windows configured as 'editor' or 'off' never take the lock this way.
     */
    async claimForSave() {
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
        }
        catch (error) {
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
    canWriteVaultFromRemote() {
        return this.canSyncToWordPress();
    }
    getSyncRole() {
        return this.syncRole;
    }
    getStatusMessage() {
        return this.statusMessage;
    }
    dispose() {
        if (this.heartbeatTimer) {
            clearInterval(this.heartbeatTimer);
            this.heartbeatTimer = null;
        }
        if (this.ownsLock) {
            void this.releaseLock();
        }
    }
    buildVaultPath() {
        const config = vscode.workspace.getConfiguration('wordpressSnippets');
        const configuredPath = config.get('workspaceStoragePath') || config.get('obsidianVaultPath');
        if (configuredPath && configuredPath.trim() !== '') {
            return configuredPath;
        }
        return path.join(this.context.globalStorageUri.fsPath, 'workspace_storage');
    }
    buildLockFilePath() {
        const storagePath = this.buildVaultPath();
        const siteFolder = this.connection.vaultSiteFolder || 'site-wordpress';
        const lockPath = path.join(storagePath, '.trae', 'locks', `${siteFolder}.lock.json`);
        return lockPath;
    }
    async acquireLock() {
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
            }
            catch (err) {
                // Ignore invalid or missing lock content and overwrite it.
            }
            await fs.writeFile(this.lockFilePath, JSON.stringify(payload, null, 2), 'utf8');
            return true;
        }
        catch (err) {
            console.error('Impossible de créer le verrou de synchronisation local', err);
            return false;
        }
    }
    startHeartbeat() {
        if (!this.lockFilePath || this.heartbeatTimer) {
            return;
        }
        this.heartbeatTimer = setInterval(() => {
            void this.refreshLock();
        }, VaultSiteSyncLockService.HEARTBEAT_INTERVAL_MS);
    }
    async refreshLock() {
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
        }
        catch (error) {
            console.error('Impossible de rafraichir le verrou de sync du stockage local', error);
        }
    }
    async releaseLock() {
        if (!this.lockFilePath) {
            return;
        }
        try {
            const current = await this.readLockPayload();
            if (!current || current.instanceId !== this.instanceId) {
                return;
            }
            await fs.unlink(this.lockFilePath);
        }
        catch {
            // Ignore cleanup issues on shutdown.
        }
    }
    async readLockPayload() {
        if (!this.lockFilePath) {
            return null;
        }
        try {
            const content = await fs.readFile(this.lockFilePath, 'utf8');
            return JSON.parse(content);
        }
        catch {
            return null;
        }
    }
    isStale(payload) {
        const heartbeatAt = Date.parse(payload.heartbeatAt);
        if (Number.isNaN(heartbeatAt)) {
            return true;
        }
        return Date.now() - heartbeatAt > VaultSiteSyncLockService.STALE_AFTER_MS;
    }
    buildPayload() {
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
exports.VaultSiteSyncLockService = VaultSiteSyncLockService;
VaultSiteSyncLockService.HEARTBEAT_INTERVAL_MS = 10000;
VaultSiteSyncLockService.STALE_AFTER_MS = 30000;
//# sourceMappingURL=VaultSiteSyncLockService.js.map