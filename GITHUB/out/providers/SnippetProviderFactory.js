"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.createSnippetProvider = void 0;
const SnippetProvider_1 = require("./SnippetProvider");
const FluentSnippetProvider_1 = require("./FluentSnippetProvider");
async function createSnippetProvider(context, config) {
    if (!config) {
        return null;
    }
    if (config.plugin === 'FluentSnippets') {
        return new FluentSnippetProvider_1.FluentSnippetProvider(context);
    }
    else {
        return new SnippetProvider_1.SnippetProvider(context);
    }
}
exports.createSnippetProvider = createSnippetProvider;
//# sourceMappingURL=SnippetProviderFactory.js.map