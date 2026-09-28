import * as vscode from 'vscode';
import { SnippetProvider } from './SnippetProvider';
import { SnippetPluginProvider } from './SnippetPluginProvider';

import { WordPressConnectionConfig } from '../types/Snippet';

export async function createSnippetProvider(context: vscode.ExtensionContext, config: WordPressConnectionConfig | null): Promise<SnippetPluginProvider | null> {

    if (!config) {
        return null;
    }

    return new SnippetProvider(context);
}
