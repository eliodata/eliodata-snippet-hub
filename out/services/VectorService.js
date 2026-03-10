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
exports.VectorService = void 0;
const fs = __importStar(require("fs"));
const path = __importStar(require("path"));
const cosineSimilarity = require('compute-cosine-similarity');
const PHPAnalyzer_1 = require("../utils/PHPAnalyzer");
/**
 * Service de vectorisation simplifié utilisant TF-IDF
 */
class VectorService {
    constructor(context) {
        this.vocabulary = new Map();
        this.idfScores = new Map();
        this.context = context;
        this.phpAnalyzer = new PHPAnalyzer_1.PHPAnalyzer();
        this.config = {
            maxCacheSize: 100 * 1024 * 1024,
            chunkSize: 500,
            modelPath: 'tfidf-simple',
            embeddingDimension: 300,
            overlapSize: 50
        };
        this.vectorIndex = {
            version: '1.0',
            model: this.config.modelPath,
            lastUpdate: new Date().toISOString(),
            snippets: {}
        };
    }
    /**
     * Initialiser le service
     */
    async initialize() {
        try {
            await this.loadVectorIndex();
            console.log('VectorService initialisé avec succès');
        }
        catch (error) {
            console.error('Erreur lors de l\'initialisation du VectorService:', error);
            throw error;
        }
    }
    /**
     * Vectoriser un snippet
     */
    async vectorizeSnippet(snippet, forceInactive = false) {
        try {
            // Ignorer les snippets désactivés sauf si forcé
            if (!snippet.active && !forceInactive) {
                console.log(`Snippet ${snippet.id} ignoré (désactivé)`);
                // Supprimer du cache s'il existe
                if (this.vectorIndex.snippets[snippet.id.toString()]) {
                    delete this.vectorIndex.snippets[snippet.id.toString()];
                    await this.saveVectorIndex();
                    console.log(`Vecteur supprimé pour snippet désactivé ${snippet.id}`);
                }
                return;
            }
            const snippetHash = this.calculateHash(snippet.code + snippet.name + snippet.description + snippet.active.toString());
            // Vérifier si le snippet a déjà été vectorisé avec le même hash
            if (this.vectorIndex.snippets[snippet.id] &&
                this.vectorIndex.snippets[snippet.id].hash === snippetHash) {
                return;
            }
            // Analyser le code PHP
            const analysisResult = await this.phpAnalyzer.analyze(snippet.code);
            // Extraire les métadonnées
            const metadata = {
                functions: analysisResult.functions.map((f) => f.name),
                hooks: analysisResult.hooks.map((h) => h.name),
                cptRelations: analysisResult.cptReferences,
                wooFunctions: analysisResult.wooCommerceReferences,
                globalVariables: analysisResult.globalVariables,
                classes: analysisResult.classes.map((c) => c.name),
                constants: analysisResult.constants.map((c) => c.name),
                complexityScore: this.calculateComplexityScore(analysisResult),
                dependencies: analysisResult.dependencies,
                isActive: snippet.active,
                lastStatusUpdate: new Date().toISOString()
            };
            // Créer le texte combiné pour la vectorisation
            const combinedText = this.createCombinedText(snippet, metadata);
            // Diviser en chunks
            const chunks = this.chunkText(combinedText, this.config.chunkSize);
            // Vectoriser chaque chunk avec TF-IDF
            const embeddings = [];
            const chunkData = [];
            for (let i = 0; i < chunks.length; i++) {
                const vector = this.createTFIDFVector(chunks[i]);
                embeddings.push(vector);
                chunkData.push(chunks[i]);
            }
            // Stocker dans l'index
            this.vectorIndex.snippets[snippet.id.toString()] = {
                snippetId: snippet.id.toString(),
                hash: snippetHash,
                embeddings,
                metadata,
                chunks: chunkData,
                lastUpdated: new Date().toISOString()
            };
            await this.saveVectorIndex();
            console.log(`Snippet ${snippet.id} vectorisé avec succès`);
        }
        catch (error) {
            console.error(`Erreur lors de la vectorisation du snippet ${snippet.id}:`, error);
            throw error;
        }
    }
    /**
     * Rechercher des snippets similaires
     */
    async searchSimilar(query, limit = 10, includeInactive = false) {
        try {
            const queryVector = this.createTFIDFVector(query);
            const results = [];
            for (const [snippetId, embedding] of Object.entries(this.vectorIndex.snippets)) {
                // Filtrer par statut si nécessaire
                if (!includeInactive && !embedding.metadata.isActive) {
                    continue;
                }
                let maxSimilarity = 0;
                let bestChunkIndex = 0;
                // Comparer avec chaque chunk du snippet
                for (let i = 0; i < embedding.embeddings.length; i++) {
                    const similarity = cosineSimilarity(queryVector, embedding.embeddings[i]);
                    if (similarity !== null && similarity > maxSimilarity) {
                        maxSimilarity = similarity;
                        bestChunkIndex = i;
                    }
                }
                if (maxSimilarity > 0.1) { // Seuil de similarité
                    results.push({
                        snippet: {
                            id: snippetId,
                            name: embedding.metadata.functions.join(', ') || 'Snippet',
                            description: `Fonctions: ${embedding.metadata.functions.slice(0, 3).join(', ')} ${embedding.metadata.isActive ? '✅' : '❌'}`,
                            code: embedding.chunks[bestChunkIndex]
                        },
                        similarity: maxSimilarity,
                        matchedChunks: [embedding.chunks[bestChunkIndex]]
                    });
                }
            }
            // Trier par similarité décroissante
            results.sort((a, b) => b.similarity - a.similarity);
            return results.slice(0, limit);
        }
        catch (error) {
            console.error('Erreur lors de la recherche:', error);
            throw error;
        }
    }
    /**
     * Créer un vecteur TF-IDF simple
     */
    createTFIDFVector(text) {
        const words = this.tokenize(text);
        const termFreq = new Map();
        // Calculer TF
        words.forEach(word => {
            termFreq.set(word, (termFreq.get(word) || 0) + 1);
        });
        // Normaliser TF
        const maxFreq = Math.max(...Array.from(termFreq.values()));
        termFreq.forEach((freq, term) => {
            termFreq.set(term, freq / maxFreq);
        });
        // Créer le vecteur
        const vector = [];
        const vocabArray = Array.from(this.vocabulary.keys());
        for (const term of vocabArray) {
            const tf = termFreq.get(term) || 0;
            const idf = this.idfScores.get(term) || 0;
            vector.push(tf * idf);
        }
        return vector;
    }
    /**
     * Tokeniser le texte
     */
    tokenize(text) {
        return text.toLowerCase()
            .replace(/[^a-zA-Z0-9\s_]/g, ' ')
            .split(/\s+/)
            .filter(word => word.length > 2);
    }
    /**
     * Construire le vocabulaire et calculer IDF
     */
    buildVocabulary() {
        const documentFreq = new Map();
        const totalDocs = Object.keys(this.vectorIndex.snippets).length;
        // Compter la fréquence des documents
        for (const embedding of Object.values(this.vectorIndex.snippets)) {
            const docWords = new Set();
            for (const chunk of embedding.chunks) {
                const words = this.tokenize(chunk);
                words.forEach(word => docWords.add(word));
            }
            docWords.forEach(word => {
                documentFreq.set(word, (documentFreq.get(word) || 0) + 1);
            });
        }
        // Calculer IDF et construire le vocabulaire
        let vocabIndex = 0;
        documentFreq.forEach((docFreq, term) => {
            if (docFreq > 1) { // Ignorer les termes très rares
                this.vocabulary.set(term, vocabIndex++);
                this.idfScores.set(term, Math.log(totalDocs / docFreq));
            }
        });
    }
    /**
     * Créer le texte combiné pour la vectorisation
     */
    createCombinedText(snippet, metadata) {
        const parts = [
            snippet.name,
            snippet.description,
            snippet.code,
            metadata.functions.join(' '),
            metadata.hooks.join(' '),
            metadata.classes.join(' '),
            metadata.constants.join(' ')
        ];
        return parts.filter(part => part && part.trim()).join(' ');
    }
    /**
     * Diviser le texte en chunks
     */
    chunkText(text, chunkSize) {
        const chunks = [];
        for (let i = 0; i < text.length; i += chunkSize) {
            chunks.push(text.slice(i, i + chunkSize));
        }
        return chunks;
    }
    /**
     * Charger l'index vectoriel
     */
    async loadVectorIndex() {
        try {
            const indexPath = this.getIndexPath();
            if (fs.existsSync(indexPath)) {
                const data = fs.readFileSync(indexPath, 'utf8');
                this.vectorIndex = JSON.parse(data);
                this.buildVocabulary();
            }
        }
        catch (error) {
            console.error('Erreur lors du chargement de l\'index:', error);
        }
    }
    /**
     * Sauvegarder l'index vectoriel
     */
    async saveVectorIndex() {
        try {
            const indexPath = this.getIndexPath();
            const indexDir = path.dirname(indexPath);
            if (!fs.existsSync(indexDir)) {
                fs.mkdirSync(indexDir, { recursive: true });
            }
            this.vectorIndex.lastUpdate = new Date().toISOString();
            fs.writeFileSync(indexPath, JSON.stringify(this.vectorIndex, null, 2));
            // Reconstruire le vocabulaire après la sauvegarde
            this.buildVocabulary();
        }
        catch (error) {
            console.error('Erreur lors de la sauvegarde de l\'index:', error);
            throw error;
        }
    }
    /**
     * Obtenir le chemin de l'index
     */
    getIndexPath() {
        return path.join(this.context.globalStorageUri.fsPath, 'vector_cache', 'index.json');
    }
    /**
     * Calculer un score de complexité basique
     */
    calculateComplexityScore(analysis) {
        let score = 0;
        score += analysis.functions.length * 2;
        score += analysis.classes.length * 3;
        score += analysis.hooks.length;
        score += analysis.constants.length;
        score += analysis.globalVariables.length;
        return score;
    }
    /**
     * Vider le cache vectoriel
     */
    async clearCache() {
        try {
            this.vectorIndex = {
                version: '1.0',
                model: this.config.modelPath,
                lastUpdate: new Date().toISOString(),
                snippets: {}
            };
            this.vocabulary.clear();
            this.idfScores.clear();
            await this.saveVectorIndex();
            console.log('Cache vectoriel vidé avec succès');
        }
        catch (error) {
            console.error('Erreur lors du vidage du cache:', error);
            throw error;
        }
    }
    /**
     * Nettoyer automatiquement les vecteurs des snippets désactivés
     */
    async cleanupInactiveVectors(activeSnippetIds) {
        let cleanedCount = 0;
        const activeIds = new Set(activeSnippetIds);
        for (const snippetId of Object.keys(this.vectorIndex.snippets)) {
            if (!activeIds.has(snippetId)) {
                delete this.vectorIndex.snippets[snippetId];
                cleanedCount++;
                console.log(`Vecteur supprimé pour snippet inactif/supprimé: ${snippetId}`);
            }
        }
        if (cleanedCount > 0) {
            await this.saveVectorIndex();
            console.log(`Nettoyage terminé: ${cleanedCount} vecteurs supprimés`);
        }
        return cleanedCount;
    }
    /**
     * Diagnostiquer l'état du cache vectoriel
     */
    diagnoseVectorCache() {
        const totalSnippets = Object.keys(this.vectorIndex.snippets).length;
        const totalVectors = Object.values(this.vectorIndex.snippets)
            .reduce((sum, embedding) => sum + embedding.embeddings.length, 0);
        const vocabularySize = Object.keys(this.vocabulary).length;
        const activeSnippets = Object.values(this.vectorIndex.snippets)
            .filter(embedding => embedding.metadata.isActive).length;
        const inactiveSnippets = totalSnippets - activeSnippets;
        return {
            totalSnippets,
            totalVectors,
            vocabularySize,
            cacheSize: JSON.stringify(this.vectorIndex).length,
            activeSnippets,
            inactiveSnippets
        };
    }
    /**
     * Tester la recherche vectorielle avec debug
     */
    async testVectorSearch(query) {
        const startTime = Date.now();
        const queryVector = this.createTFIDFVector(query);
        const queryWords = this.tokenize(query);
        const vocabularyUsed = queryWords.filter(word => this.vocabulary.has(word));
        const results = await this.searchSimilar(query, 5);
        const searchTime = Date.now() - startTime;
        return {
            queryVector: queryVector.slice(0, 10),
            vocabularyUsed,
            results,
            searchTime
        };
    }
    calculateHash(content) {
        // Simple hash pour détecter les changements
        let hash = 0;
        for (let i = 0; i < content.length; i++) {
            const char = content.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash; // Convert to 32bit integer
        }
        return hash.toString();
    }
}
exports.VectorService = VectorService;
//# sourceMappingURL=VectorService.js.map