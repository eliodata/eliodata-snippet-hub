"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.createSnippetProvider = void 0;
const SnippetProvider_1 = require("./SnippetProvider");
async function createSnippetProvider(context, config) {
    if (!config) {
        return null;
    }
    return new SnippetProvider_1.SnippetProvider(context);
}
exports.createSnippetProvider = createSnippetProvider;
//# sourceMappingURL=SnippetProviderFactory.js.map