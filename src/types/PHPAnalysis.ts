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
