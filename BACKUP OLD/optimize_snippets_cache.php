<?php
/**
 * Script d'optimisation du cache et des vecteurs de recherche pour les snippets FSBDD
 * Génère des métadonnées enrichies pour améliorer les performances de recherche
 */

class SnippetCacheOptimizer {
    private $cache_dir;
    private $metadata_file;
    private $vector_cache_file;
    private $snippets_metadata = [];
    
    public function __construct($cache_dir = '.snippet_cache') {
        $this->cache_dir = rtrim($cache_dir, '/');
        $this->metadata_file = $this->cache_dir . '/snippets_metadata.json';
        $this->vector_cache_file = $this->cache_dir . '/vector_cache.json';
    }
    
    /**
     * Optimise le cache complet des snippets
     */
    public function optimizeCache() {
        echo "Démarrage de l'optimisation du cache des snippets...\n";
        
        // 1. Analyser tous les snippets actifs
        $this->analyzeActiveSnippets();
        
        // 2. Générer les métadonnées enrichies
        $this->generateEnrichedMetadata();
        
        // 3. Créer l'index de recherche vectorielle
        $this->buildVectorIndex();
        
        // 4. Optimiser les relations entre snippets
        $this->buildRelationshipIndex();
        
        // 5. Sauvegarder les caches
        $this->saveCaches();
        
        echo "Optimisation terminée avec succès!\n";
    }
    
    /**
     * Analyse tous les snippets actifs
     */
    private function analyzeActiveSnippets() {
        echo "Analyse des snippets actifs...\n";
        
        $files = glob($this->cache_dir . '/snippet-*.php');
        $active_count = 0;
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            
            if (preg_match('/@active\s+true/', $content)) {
                $metadata = $this->extractFullMetadata($file, $content);
                $this->snippets_metadata[$metadata['id']] = $metadata;
                $active_count++;
            }
        }
        
        echo "Analysé {$active_count} snippets actifs\n";
    }
    
    /**
     * Extrait les métadonnées complètes d'un snippet
     */
    private function extractFullMetadata($file_path, $content) {
        $metadata = [
            'id' => '',
            'name' => '',
            'description' => '',
            'file_path' => $file_path,
            'file_name' => basename($file_path),
            'size' => strlen($content),
            'last_modified' => filemtime($file_path),
            'functions' => [],
            'hooks' => [],
            'ajax_actions' => [],
            'custom_fields' => [],
            'cpt_relations' => [],
            'woocommerce_features' => [],
            'ui_elements' => [],
            'keywords' => [],
            'complexity_score' => 0,
            'performance_impact' => 'low'
        ];
        
        // Métadonnées de base
        if (preg_match('/\* Snippet ID: (\d+)/', $content, $matches)) {
            $metadata['id'] = $matches[1];
        }
        
        if (preg_match('/\* Name: (.+)/', $content, $matches)) {
            $metadata['name'] = trim($matches[1]);
        }
        
        if (preg_match('/\* Description: (.*)/', $content, $matches)) {
            $metadata['description'] = trim($matches[1]);
        }
        
        // Analyse des fonctions
        preg_match_all('/function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $content, $matches);
        $metadata['functions'] = array_unique($matches[1]);
        
        // Analyse des hooks WordPress
        preg_match_all('/add_action\s*\(\s*[\'"]([^\'"]*)[\'"]/i', $content, $matches);
        $actions = $matches[1];
        preg_match_all('/add_filter\s*\(\s*[\'"]([^\'"]*)[\'"]/i', $content, $matches);
        $filters = $matches[1];
        $metadata['hooks'] = array_unique(array_merge($actions, $filters));
        
        // Analyse des actions AJAX
        preg_match_all('/wp_ajax_([a-zA-Z_]+)/', $content, $matches);
        $metadata['ajax_actions'] = array_unique($matches[1]);
        
        // Analyse des custom fields FSBDD
        preg_match_all('/fsbdd_([a-zA-Z_]+)/', $content, $matches);
        $metadata['custom_fields'] = array_unique($matches[1]);
        
        // Analyse des relations CPT
        $cpt_patterns = ['action-de-formation', 'formateur', 'client', 'salle-de-formation'];
        foreach ($cpt_patterns as $cpt) {
            if (strpos($content, $cpt) !== false) {
                $metadata['cpt_relations'][] = $cpt;
            }
        }
        
        // Analyse des fonctionnalités WooCommerce
        $wc_patterns = ['wc_', 'WC_', 'woocommerce', 'get_woocommerce'];
        foreach ($wc_patterns as $pattern) {
            if (strpos($content, $pattern) !== false) {
                $metadata['woocommerce_features'][] = $pattern;
            }
        }
        
        // Analyse des éléments UI
        $ui_patterns = [
            'sticky' => 'position sticky',
            'fixed' => 'position fixed',
            'modal' => 'modal dialog',
            'tooltip' => 'tooltip',
            'dropdown' => 'dropdown menu',
            'metabox' => 'admin metabox'
        ];
        
        foreach ($ui_patterns as $key => $description) {
            if (preg_match('/' . preg_quote($key, '/') . '/i', $content)) {
                $metadata['ui_elements'][] = $description;
            }
        }
        
        // Génération des mots-clés pour la recherche
        $metadata['keywords'] = $this->generateSearchKeywords($metadata, $content);
        
        // Calcul du score de complexité
        $metadata['complexity_score'] = $this->calculateComplexityScore($metadata, $content);
        
        // Évaluation de l'impact performance
        $metadata['performance_impact'] = $this->evaluatePerformanceImpact($metadata, $content);
        
        return $metadata;
    }
    
    /**
     * Génère des mots-clés optimisés pour la recherche
     */
    private function generateSearchKeywords($metadata, $content) {
        $keywords = [];
        
        // Mots-clés du nom
        $name_words = preg_split('/[\s_-]+/', strtolower($metadata['name']));
        $keywords = array_merge($keywords, $name_words);
        
        // Mots-clés de la description
        if (!empty($metadata['description'])) {
            $desc_words = preg_split('/[\s_-]+/', strtolower($metadata['description']));
            $keywords = array_merge($keywords, $desc_words);
        }
        
        // Mots-clés techniques
        $keywords = array_merge($keywords, $metadata['functions']);
        $keywords = array_merge($keywords, $metadata['custom_fields']);
        $keywords = array_merge($keywords, $metadata['cpt_relations']);
        $keywords = array_merge($keywords, $metadata['ajax_actions']);
        
        // Mots-clés contextuels FSBDD
        $fsbdd_contexts = [
            'formation', 'formateur', 'stagiaire', 'planning', 'cout', 'charge',
            'document', 'emargement', 'evaluation', 'session', 'effectif'
        ];
        
        foreach ($fsbdd_contexts as $context) {
            if (preg_match('/' . preg_quote($context, '/') . '/i', $content)) {
                $keywords[] = $context;
            }
        }
        
        // Nettoyer et dédupliquer
        $keywords = array_filter($keywords, function($word) {
            return strlen($word) > 2 && !in_array($word, ['the', 'and', 'for', 'with']);
        });
        
        return array_values(array_unique($keywords));
    }
    
    /**
     * Calcule un score de complexité
     */
    private function calculateComplexityScore($metadata, $content) {
        $score = 0;
        
        // Complexité basée sur la taille
        $score += min(floor($metadata['size'] / 1000), 10);
        
        // Complexité basée sur le nombre de fonctions
        $score += count($metadata['functions']) * 2;
        
        // Complexité basée sur les hooks
        $score += count($metadata['hooks']);
        
        // Complexité basée sur AJAX
        $score += count($metadata['ajax_actions']) * 3;
        
        // Complexité basée sur les requêtes DB
        $db_patterns = ['get_posts', 'WP_Query', 'wpdb', 'get_post_meta'];
        foreach ($db_patterns as $pattern) {
            $score += substr_count($content, $pattern);
        }
        
        return min($score, 100); // Cap à 100
    }
    
    /**
     * Évalue l'impact sur les performances
     */
    private function evaluatePerformanceImpact($metadata, $content) {
        $impact_score = 0;
        
        // Impact des hooks critiques
        $critical_hooks = ['init', 'wp_loaded', 'admin_init', 'wp_head'];
        foreach ($critical_hooks as $hook) {
            if (in_array($hook, $metadata['hooks'])) {
                $impact_score += 3;
            }
        }
        
        // Impact des requêtes DB
        if (preg_match_all('/get_posts|WP_Query|wpdb/', $content) > 5) {
            $impact_score += 5;
        }
        
        // Impact des actions AJAX
        $impact_score += count($metadata['ajax_actions']);
        
        // Impact de la taille du code
        if ($metadata['size'] > 10000) {
            $impact_score += 3;
        }
        
        if ($impact_score >= 10) return 'high';
        if ($impact_score >= 5) return 'medium';
        return 'low';
    }
    
    /**
     * Génère des métadonnées enrichies
     */
    private function generateEnrichedMetadata() {
        echo "Génération des métadonnées enrichies...\n";
        
        foreach ($this->snippets_metadata as $id => &$metadata) {
            // Ajouter des tags automatiques
            $metadata['auto_tags'] = $this->generateAutoTags($metadata);
            
            // Ajouter des suggestions de recherche
            $metadata['search_suggestions'] = $this->generateSearchSuggestions($metadata);
            
            // Ajouter un score de pertinence
            $metadata['relevance_score'] = $this->calculateRelevanceScore($metadata);
        }
    }
    
    /**
     * Génère des tags automatiques
     */
    private function generateAutoTags($metadata) {
        $tags = [];
        
        // Tags basés sur la complexité
        if ($metadata['complexity_score'] > 50) {
            $tags[] = 'complex';
        } elseif ($metadata['complexity_score'] < 20) {
            $tags[] = 'simple';
        }
        
        // Tags basés sur les fonctionnalités
        if (!empty($metadata['ajax_actions'])) $tags[] = 'ajax';
        if (!empty($metadata['woocommerce_features'])) $tags[] = 'woocommerce';
        if (!empty($metadata['ui_elements'])) $tags[] = 'ui';
        if (count($metadata['custom_fields']) > 5) $tags[] = 'metafields';
        
        // Tags basés sur l'impact performance
        $tags[] = 'performance-' . $metadata['performance_impact'];
        
        return $tags;
    }
    
    /**
     * Génère des suggestions de recherche
     */
    private function generateSearchSuggestions($metadata) {
        $suggestions = [];
        
        // Suggestions basées sur le nom
        $name_parts = explode(' ', $metadata['name']);
        foreach ($name_parts as $part) {
            if (strlen($part) > 3) {
                $suggestions[] = strtolower($part);
            }
        }
        
        // Suggestions basées sur les fonctionnalités
        if (!empty($metadata['custom_fields'])) {
            $suggestions[] = 'custom fields';
            $suggestions[] = 'fsbdd fields';
        }
        
        if (!empty($metadata['ajax_actions'])) {
            $suggestions[] = 'ajax functionality';
        }
        
        return array_unique($suggestions);
    }
    
    /**
     * Calcule un score de pertinence
     */
    private function calculateRelevanceScore($metadata) {
        $score = 0;
        
        // Score basé sur l'activité récente
        $days_since_modified = (time() - $metadata['last_modified']) / (24 * 3600);
        if ($days_since_modified < 30) $score += 10;
        elseif ($days_since_modified < 90) $score += 5;
        
        // Score basé sur la richesse des métadonnées
        $score += count($metadata['functions']) * 2;
        $score += count($metadata['hooks']);
        $score += count($metadata['custom_fields']) * 3;
        
        // Score basé sur l'utilité FSBDD
        if (!empty($metadata['cpt_relations'])) $score += 15;
        if (!empty($metadata['woocommerce_features'])) $score += 10;
        
        return min($score, 100);
    }
    
    /**
     * Construit l'index de recherche vectorielle
     */
    private function buildVectorIndex() {
        echo "Construction de l'index vectoriel...\n";
        
        $vector_index = [];
        
        foreach ($this->snippets_metadata as $id => $metadata) {
            $vector_index[$id] = [
                'keywords' => $metadata['keywords'],
                'tags' => $metadata['auto_tags'],
                'suggestions' => $metadata['search_suggestions'],
                'weight' => $metadata['relevance_score']
            ];
        }
        
        file_put_contents($this->vector_cache_file, json_encode($vector_index, JSON_PRETTY_PRINT));
    }
    
    /**
     * Construit l'index des relations entre snippets
     */
    private function buildRelationshipIndex() {
        echo "Construction de l'index des relations...\n";
        
        $relationships = [];
        
        foreach ($this->snippets_metadata as $id => $metadata) {
            $relationships[$id] = [
                'related_by_cpt' => [],
                'related_by_function' => [],
                'related_by_field' => []
            ];
            
            // Relations par CPT
            foreach ($this->snippets_metadata as $other_id => $other_metadata) {
                if ($id === $other_id) continue;
                
                $common_cpts = array_intersect($metadata['cpt_relations'], $other_metadata['cpt_relations']);
                if (!empty($common_cpts)) {
                    $relationships[$id]['related_by_cpt'][] = $other_id;
                }
                
                $common_fields = array_intersect($metadata['custom_fields'], $other_metadata['custom_fields']);
                if (count($common_fields) >= 2) {
                    $relationships[$id]['related_by_field'][] = $other_id;
                }
            }
        }
        
        // Ajouter les relations aux métadonnées
        foreach ($relationships as $id => $relations) {
            $this->snippets_metadata[$id]['relationships'] = $relations;
        }
    }
    
    /**
     * Sauvegarde tous les caches
     */
    private function saveCaches() {
        echo "Sauvegarde des caches...\n";
        
        // Sauvegarder les métadonnées complètes
        file_put_contents($this->metadata_file, json_encode($this->snippets_metadata, JSON_PRETTY_PRINT));
        
        // Créer un index rapide pour les recherches
        $quick_index = [];
        foreach ($this->snippets_metadata as $id => $metadata) {
            $quick_index[$id] = [
                'name' => $metadata['name'],
                'description' => $metadata['description'],
                'keywords' => array_slice($metadata['keywords'], 0, 10),
                'tags' => $metadata['auto_tags'],
                'relevance' => $metadata['relevance_score']
            ];
        }
        
        file_put_contents($this->cache_dir . '/quick_index.json', json_encode($quick_index, JSON_PRETTY_PRINT));
        
        echo "Caches sauvegardés avec succès!\n";
        echo "- Métadonnées complètes: {$this->metadata_file}\n";
        echo "- Index vectoriel: {$this->vector_cache_file}\n";
        echo "- Index rapide: {$this->cache_dir}/quick_index.json\n";
    }
}

// Exécution du script
if (php_sapi_name() === 'cli') {
    $optimizer = new SnippetCacheOptimizer();
    $optimizer->optimizeCache();
} else {
    echo "Ce script doit être exécuté en ligne de commande.\n";
}