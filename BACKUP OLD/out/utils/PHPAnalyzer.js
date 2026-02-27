"use strict";
var __awaiter = (this && this.__awaiter) || function (thisArg, _arguments, P, generator) {
    function adopt(value) { return value instanceof P ? value : new P(function (resolve) { resolve(value); }); }
    return new (P || (P = Promise))(function (resolve, reject) {
        function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
        function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
        function step(result) { result.done ? resolve(result.value) : adopt(result.value).then(fulfilled, rejected); }
        step((generator = generator.apply(thisArg, _arguments || [])).next());
    });
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.PHPAnalyzer = void 0;
class PHPAnalyzer {
    constructor() {
        this.wordpressFunctions = [
            'add_action', 'add_filter', 'remove_action', 'remove_filter',
            'wp_enqueue_script', 'wp_enqueue_style', 'wp_localize_script',
            'get_post_meta', 'update_post_meta', 'delete_post_meta',
            'wp_insert_post', 'wp_update_post', 'wp_delete_post',
            'get_posts', 'get_post', 'wp_query', 'get_user_meta',
            'current_user_can', 'is_admin', 'is_user_logged_in'
        ];
        this.wooCommerceFunctions = [
            'wc_get_order', 'wc_get_product', 'wc_get_orders',
            'wc_create_order', 'wc_get_cart', 'wc_add_to_cart',
            'wc_get_checkout', 'wc_get_customer', 'WC_Order',
            'WC_Product', 'WC_Cart', 'WC_Checkout'
        ];
        this.customPostTypes = [
            'client', 'formateur', 'action-de-formation', 'salle-de-formation',
            'shop_order', 'product', 'shop_coupon', 'shop_webhook'
        ];
    }
    analyze(code) {
        return __awaiter(this, void 0, void 0, function* () {
            const result = {
                functions: [],
                hooks: [],
                classes: [],
                constants: [],
                globalVariables: [],
                dependencies: [],
                cptReferences: [],
                wooCommerceReferences: []
            };
            try {
                // Analyse basique par regex (plus simple que php-parser pour l'instant)
                this.analyzeFunctions(code, result);
                this.analyzeHooks(code, result);
                this.analyzeClasses(code, result);
                this.analyzeConstants(code, result);
                this.analyzeGlobalVariables(code, result);
                this.analyzeDependencies(code, result);
                this.analyzeCPTReferences(code, result);
                this.analyzeWooCommerceReferences(code, result);
            }
            catch (error) {
                console.error('Erreur lors de l\'analyse PHP:', error);
            }
            return result;
        });
    }
    analyzeFunctions(code, result) {
        const functionRegex = /function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/g;
        const lines = code.split('\n');
        let match;
        while ((match = functionRegex.exec(code)) !== null) {
            const functionName = match[1];
            const lineNumber = this.getLineNumber(code, match.index);
            // Estimer la fin de la fonction (simpliste)
            let endLine = lineNumber;
            let braceCount = 0;
            let foundStart = false;
            for (let i = lineNumber - 1; i < lines.length; i++) {
                const line = lines[i];
                if (line.includes('{')) {
                    foundStart = true;
                    braceCount += (line.match(/{/g) || []).length;
                }
                if (foundStart) {
                    braceCount -= (line.match(/}/g) || []).length;
                    if (braceCount === 0) {
                        endLine = i + 1;
                        break;
                    }
                }
            }
            result.functions.push({
                name: functionName,
                startLine: lineNumber,
                endLine: endLine,
                parameters: this.extractParameters(match[0]),
                isWordPressFunction: this.wordpressFunctions.includes(functionName)
            });
        }
    }
    analyzeHooks(code, result) {
        // Actions
        const actionRegex = /add_action\s*\(\s*['"]([^'"]+)['"]\s*,\s*['"]?([^,'"\)]+)['"]?(?:\s*,\s*(\d+))?/g;
        let match;
        while ((match = actionRegex.exec(code)) !== null) {
            result.hooks.push({
                type: 'action',
                name: match[1],
                callback: match[2],
                priority: parseInt(match[3] || '10'),
                line: this.getLineNumber(code, match.index)
            });
        }
        // Filters
        const filterRegex = /add_filter\s*\(\s*['"]([^'"]+)['"]\s*,\s*['"]?([^,'"\)]+)['"]?(?:\s*,\s*(\d+))?/g;
        while ((match = filterRegex.exec(code)) !== null) {
            result.hooks.push({
                type: 'filter',
                name: match[1],
                callback: match[2],
                priority: parseInt(match[3] || '10'),
                line: this.getLineNumber(code, match.index)
            });
        }
    }
    analyzeClasses(code, result) {
        const classRegex = /class\s+([a-zA-Z_][a-zA-Z0-9_]*)/g;
        const lines = code.split('\n');
        let match;
        while ((match = classRegex.exec(code)) !== null) {
            const className = match[1];
            const startLine = this.getLineNumber(code, match.index);
            // Trouver les méthodes de la classe
            const methods = [];
            const methodRegex = new RegExp(`class\s+${className}[^{]*{[^}]*function\s+([a-zA-Z_][a-zA-Z0-9_]*)`, 'g');
            let methodMatch;
            while ((methodMatch = methodRegex.exec(code)) !== null) {
                methods.push(methodMatch[1]);
            }
            result.classes.push({
                name: className,
                startLine: startLine,
                endLine: startLine + 50,
                methods: methods
            });
        }
    }
    analyzeConstants(code, result) {
        const defineRegex = /define\s*\(\s*['"]([^'"]+)['"]\s*,\s*([^\)]+)\)/g;
        let match;
        while ((match = defineRegex.exec(code)) !== null) {
            result.constants.push({
                name: match[1],
                value: match[2].trim(),
                line: this.getLineNumber(code, match.index)
            });
        }
    }
    analyzeGlobalVariables(code, result) {
        const globalVars = ['$wpdb', '$post', '$wp_query', '$current_user', '$wp'];
        for (const globalVar of globalVars) {
            if (code.includes(globalVar)) {
                result.globalVariables.push(globalVar);
            }
        }
    }
    analyzeDependencies(code, result) {
        // Rechercher les includes/requires
        const includeRegex = /(?:include|require)(?:_once)?\s*\(?\s*['"]([^'"]+)['"]/g;
        let match;
        while ((match = includeRegex.exec(code)) !== null) {
            result.dependencies.push(match[1]);
        }
        // Rechercher les fonctions WordPress utilisées
        for (const wpFunction of this.wordpressFunctions) {
            if (code.includes(wpFunction)) {
                result.dependencies.push(wpFunction);
            }
        }
    }
    analyzeCPTReferences(code, result) {
        for (const cpt of this.customPostTypes) {
            if (code.includes(cpt) || code.includes(`'${cpt}'`) || code.includes(`"${cpt}"`)) {
                result.cptReferences.push(cpt);
            }
        }
    }
    analyzeWooCommerceReferences(code, result) {
        for (const wooFunction of this.wooCommerceFunctions) {
            if (code.includes(wooFunction)) {
                result.wooCommerceReferences.push(wooFunction);
            }
        }
    }
    getLineNumber(code, index) {
        return code.substring(0, index).split('\n').length;
    }
    extractParameters(functionDeclaration) {
        const paramMatch = functionDeclaration.match(/\(([^\)]*)\)/);
        if (!paramMatch || !paramMatch[1].trim()) {
            return [];
        }
        return paramMatch[1].split(',').map(param => param.trim().replace(/^\$/, ''));
    }
}
exports.PHPAnalyzer = PHPAnalyzer;
//# sourceMappingURL=PHPAnalyzer.js.map