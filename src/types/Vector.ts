export interface SnippetEmbedding {
    snippetId: string;
    hash: string;
    embeddings: number[][];
    chunks: string[];
    metadata: SnippetMetadata;
    lastUpdated: string;
}

export interface SnippetMetadata {
    functions: string[];
    hooks: string[];
    cptRelations: string[];
    wooFunctions: string[];
    globalVariables: string[];
    classes: string[];
    constants: string[];
    complexityScore: number;
    dependencies: string[];
    wordpressVersion?: string;
    isActive: boolean;
    lastStatusUpdate: string;
}

export interface VectorSearchResult {
    snippet: any;
    similarity: number;
    matchedChunks: string[];
}

export interface VectorIndex {
    version: string;
    model: string;
    lastUpdate: string;
    snippets: Record<string, SnippetEmbedding>;
}

export interface ChunkData {
    content: string;
    type: 'function' | 'class' | 'hook' | 'comment' | 'general';
    startLine: number;
    endLine: number;
    metadata?: Partial<SnippetMetadata>;
}

export interface VectorCacheConfig {
    maxCacheSize: number;
    modelPath: string;
    embeddingDimension: number;
    chunkSize: number;
    overlapSize: number;
}

export interface VectorCacheDiagnostic {
    totalSnippets: number;
    totalVectors: number;
    vocabularySize: number;
    cacheSize: number;
    activeSnippets: number;
    inactiveSnippets: number;
}

export interface PHPAnalysisResult {
    functions: Array<{
        name: string;
        startLine: number;
        endLine: number;
        parameters: string[];
        isWordPressFunction: boolean;
    }>;
    hooks: Array<{
        type: 'action' | 'filter';
        name: string;
        callback: string;
        priority: number;
        line: number;
    }>;
    classes: Array<{
        name: string;
        startLine: number;
        endLine: number;
        methods: string[];
    }>;
    constants: Array<{
        name: string;
        value: string;
        line: number;
    }>;
    globalVariables: string[];
    dependencies: string[];
    cptReferences: string[];
    wooCommerceReferences: string[];
}