import * as vscode from 'vscode';
import { SnippetProvider } from './SnippetProvider';
import { FluentSnippetProvider } from './FluentSnippetProvider';
import { SnippetPluginProvider } from './SnippetPluginProvider';

import { WordPressConnectionConfig } from '../types/Snippet';

export async function createSnippetProvider(context: vscode.ExtensionContext, config: WordPressConnectionConfig | null): Promise<SnippetPluginProvider | null> {

    if (!config) {
        return null;
    }

    if (config.plugin === 'FluentSnippets') {
        return new FluentSnippetProvider(context);
    } else {
        return new SnippetProvider(context);
    }
}