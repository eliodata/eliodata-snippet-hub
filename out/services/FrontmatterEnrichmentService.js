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
exports.FrontmatterEnrichmentService = void 0;
const fs = __importStar(require("fs/promises"));
const path = __importStar(require("path"));
const yaml = __importStar(require("yaml"));
const vscode = __importStar(require("vscode"));
class FrontmatterEnrichmentService {
    constructor() {
        this.ignoredCalls = new Set([
            'if', 'for', 'foreach', 'while', 'switch', 'echo', 'print', 'isset', 'empty',
            'array', 'count', 'in_array', 'defined', 'function', 'include', 'require',
            'require_once', 'include_once', 'new', 'return', 'list'
        ]);
        // Generic WordPress domains. Override them with the wordpressSnippets.domainRules setting.
        this.defaultDomainRules = [
            { domain: 'woocommerce', keywords: ['woocommerce', 'wc_get_order', 'wc_get_product', 'wc_order', 'cart', 'checkout'] },
            { domain: 'rest-api', keywords: ['register_rest_route', 'wp_rest_request', 'rest_api_init'] },
            { domain: 'ajax', keywords: ['wp_ajax_', 'admin-ajax', 'check_ajax_referer', 'wp_send_json'] },
            { domain: 'frontend', keywords: ['add_shortcode', 'wp_enqueue_script', 'wp_enqueue_style', 'wp_head', 'wp_footer', 'the_content'] },
            { domain: 'admin', keywords: ['admin_menu', 'add_meta_box', 'admin_init', 'admin_notices', 'add_submenu_page', 'register_setting'] },
            { domain: 'content', keywords: ['register_post_type', 'register_taxonomy', 'wp_insert_post', 'update_post_meta', 'wp_query', 'get_posts'] },
            { domain: 'users', keywords: ['wp_get_current_user', 'get_user_meta', 'update_user_meta', 'user_register', 'wp_login', 'current_user_can'] },
            { domain: 'email', keywords: ['wp_mail', 'phpmailer', 'wp_mail_from'] },
            { domain: 'media', keywords: ['wp_upload_dir', 'media_handle_upload', 'wp_get_attachment', 'pdf', 'image'] },
            { domain: 'forms', keywords: ['gform_', 'wpcf7', 'form_submit', '$_post'] },
            { domain: 'cron', keywords: ['wp_schedule_event', 'wp_next_scheduled', 'cron'] },
            { domain: 'performance', keywords: ['set_transient', 'get_transient', 'wp_cache_', 'object cache'] },
            { domain: 'integrations', keywords: ['wp_remote_get', 'wp_remote_post', 'webhook', 'api_key'] },
            { domain: 'export', keywords: ['export', 'import', 'csv', 'xls'] },
            { domain: 'security', keywords: ['wp_verify_nonce', 'check_admin_referer', 'sanitize_', 'esc_html', 'capability'] }
        ];
    }
    getDomainRules() {
        const configured = vscode.workspace.getConfiguration('wordpressSnippets').get('domainRules');
        if (!Array.isArray(configured)) {
            return this.defaultDomainRules;
        }
        const rules = configured
            .filter((rule) => !!rule
            && typeof rule.domain === 'string'
            && rule.domain.trim() !== ''
            && Array.isArray(rule.keywords))
            .map(rule => ({
            domain: rule.domain.trim(),
            keywords: rule.keywords.filter((keyword) => typeof keyword === 'string' && keyword.trim() !== '')
        }));
        return rules.length > 0 ? rules : this.defaultDomainRules;
    }
    async enrichSnippetRelationsForFile(filePath) {
        const snippetsDir = path.dirname(filePath);
        const snippets = await this.readSnippets(snippetsDir);
        if (snippets.length === 0) {
            return 0;
        }
        const target = snippets.find(item => item.filePath === filePath);
        if (!target) {
            return 0;
        }
        const targetSlug = this.getSnippetSlug(target.fileName);
        const derived = this.buildDerivedMetadata(snippets);
        const targetDerived = derived.get(targetSlug);
        if (!targetDerived) {
            return 0;
        }
        const impacted = new Set([
            targetSlug,
            ...Array.from(targetDerived.dependsOn),
            ...Array.from(targetDerived.usedBy)
        ]);
        let updatedCount = 0;
        for (const snippet of snippets) {
            const slug = this.getSnippetSlug(snippet.fileName);
            if (!impacted.has(slug)) {
                continue;
            }
            const itemDerived = derived.get(slug);
            if (!itemDerived) {
                continue;
            }
            const nextFrontmatter = {
                ...snippet.frontmatter,
                depends_on: Array.from(itemDerived.dependsOn).map(value => `[[${value}]]`).sort(),
                used_by: Array.from(itemDerived.usedBy).map(value => `[[${value}]]`).sort(),
                calls: Array.from(itemDerived.calls).sort(),
                provides_hooks: Array.from(itemDerived.providesHooks).sort(),
                consumes_hooks: Array.from(itemDerived.consumesHooks).sort(),
                shortcodes_provided: Array.from(itemDerived.shortcodesProvided).sort(),
                shortcodes_used: Array.from(itemDerived.shortcodesUsed).sort(),
                domain: itemDerived.domain,
                domain_confidence: itemDerived.domainConfidence,
                enriched_at: new Date().toISOString()
            };
            const currentYaml = yaml.stringify(snippet.frontmatter);
            const nextYaml = yaml.stringify(nextFrontmatter);
            if (currentYaml === nextYaml) {
                continue;
            }
            const nextContent = `---\n${nextYaml}---\n${snippet.body}`;
            await fs.writeFile(snippet.filePath, nextContent, 'utf8');
            updatedCount++;
        }
        return updatedCount;
    }
    async readSnippets(snippetsDir) {
        let files = [];
        try {
            files = await fs.readdir(snippetsDir);
        }
        catch {
            return [];
        }
        const mdFiles = files.filter(file => file.endsWith('.md') && file.startsWith('snippet-'));
        const snippets = [];
        for (const fileName of mdFiles) {
            const filePath = path.join(snippetsDir, fileName);
            let content = '';
            try {
                content = await fs.readFile(filePath, 'utf8');
            }
            catch {
                continue;
            }
            const match = content.match(/^---\n([\s\S]*?)\n---\n([\s\S]*)$/);
            if (!match) {
                continue;
            }
            let frontmatter = {};
            try {
                frontmatter = (yaml.parse(match[1]) || {});
            }
            catch {
                continue;
            }
            const body = match[2];
            const codeMatch = body.match(/```php\n(?:<\?php\n)?([\s\S]*?)\n```/);
            const code = codeMatch ? codeMatch[1].trim() : '';
            snippets.push({ filePath, fileName, frontmatter, body, code });
        }
        return snippets;
    }
    buildFunctionProviders(snippets) {
        const providers = new Map();
        const pattern = /function\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*\(/g;
        for (const snippet of snippets) {
            const slug = this.getSnippetSlug(snippet.fileName);
            let match;
            while ((match = pattern.exec(snippet.code)) !== null) {
                if (!providers.has(match[1])) {
                    providers.set(match[1], slug);
                }
            }
        }
        return providers;
    }
    buildHookProviders(snippets) {
        const providers = new Map();
        const pattern = /(?:add_action|add_filter)\s*\(\s*['"]([^'"]+)['"]/g;
        for (const snippet of snippets) {
            const slug = this.getSnippetSlug(snippet.fileName);
            let match;
            while ((match = pattern.exec(snippet.code)) !== null) {
                const hook = match[1];
                if (!providers.has(hook)) {
                    providers.set(hook, new Set());
                }
                providers.get(hook)?.add(slug);
            }
        }
        return providers;
    }
    buildShortcodeProviders(snippets) {
        const providers = new Map();
        const pattern = /add_shortcode\s*\(\s*['"]([^'"]+)['"]/g;
        for (const snippet of snippets) {
            const slug = this.getSnippetSlug(snippet.fileName);
            let match;
            while ((match = pattern.exec(snippet.code)) !== null) {
                if (!providers.has(match[1])) {
                    providers.set(match[1], slug);
                }
            }
        }
        return providers;
    }
    buildDerivedMetadata(snippets) {
        const functionProviders = this.buildFunctionProviders(snippets);
        const hookProviders = this.buildHookProviders(snippets);
        const shortcodeProviders = this.buildShortcodeProviders(snippets);
        const derived = new Map();
        for (const snippet of snippets) {
            const slug = this.getSnippetSlug(snippet.fileName);
            const item = {
                slug,
                dependsOn: new Set(),
                usedBy: new Set(),
                calls: new Set(),
                providesHooks: new Set(),
                consumesHooks: new Set(),
                shortcodesProvided: new Set(),
                shortcodesUsed: new Set(),
                ...this.inferDomain(snippet)
            };
            const callPattern = /\b([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*\(/g;
            let callMatch;
            while ((callMatch = callPattern.exec(snippet.code)) !== null) {
                const callName = callMatch[1];
                if (this.ignoredCalls.has(callName.toLowerCase())) {
                    continue;
                }
                const provider = functionProviders.get(callName);
                if (!provider || provider === slug) {
                    continue;
                }
                item.dependsOn.add(provider);
                item.calls.add(callName);
            }
            const triggerPattern = /(?:do_action|apply_filters)\s*\(\s*['"]([^'"]+)['"]/g;
            let triggerMatch;
            while ((triggerMatch = triggerPattern.exec(snippet.code)) !== null) {
                const hook = triggerMatch[1];
                item.consumesHooks.add(hook);
                const providers = hookProviders.get(hook);
                if (!providers) {
                    continue;
                }
                for (const provider of providers) {
                    if (provider !== slug) {
                        item.dependsOn.add(provider);
                    }
                }
            }
            const provideHookPattern = /(?:add_action|add_filter)\s*\(\s*['"]([^'"]+)['"]/g;
            let provideHookMatch;
            while ((provideHookMatch = provideHookPattern.exec(snippet.code)) !== null) {
                item.providesHooks.add(provideHookMatch[1]);
            }
            const shortcodeProvidePattern = /add_shortcode\s*\(\s*['"]([^'"]+)['"]/g;
            let shortcodeProvideMatch;
            while ((shortcodeProvideMatch = shortcodeProvidePattern.exec(snippet.code)) !== null) {
                item.shortcodesProvided.add(shortcodeProvideMatch[1]);
            }
            const shortcodeUsePattern = /do_shortcode\s*\(\s*['"]\[([a-zA-Z0-9_-]+)/g;
            let shortcodeUseMatch;
            while ((shortcodeUseMatch = shortcodeUsePattern.exec(snippet.code)) !== null) {
                const shortcode = shortcodeUseMatch[1];
                item.shortcodesUsed.add(shortcode);
                const provider = shortcodeProviders.get(shortcode);
                if (provider && provider !== slug) {
                    item.dependsOn.add(provider);
                }
            }
            derived.set(slug, item);
        }
        for (const item of derived.values()) {
            for (const providerSlug of item.dependsOn) {
                const provider = derived.get(providerSlug);
                provider?.usedBy.add(item.slug);
            }
        }
        return derived;
    }
    inferDomain(snippet) {
        const text = `${snippet.fileName}\n${String(snippet.frontmatter.title || '')}\n${snippet.code}\n${snippet.body}`.toLowerCase();
        let bestDomain = 'general';
        let bestScore = 0;
        for (const rule of this.getDomainRules()) {
            let score = 0;
            for (const keyword of rule.keywords) {
                if (text.includes(keyword.toLowerCase())) {
                    score++;
                }
            }
            if (score > bestScore) {
                bestScore = score;
                bestDomain = rule.domain;
            }
        }
        const domainConfidence = bestScore >= 3
            ? 'high'
            : bestScore >= 2
                ? 'medium'
                : 'low';
        return {
            domain: bestDomain,
            domainConfidence
        };
    }
    getSnippetSlug(fileName) {
        return fileName.replace(/\.md$/i, '');
    }
}
exports.FrontmatterEnrichmentService = FrontmatterEnrichmentService;
//# sourceMappingURL=FrontmatterEnrichmentService.js.map