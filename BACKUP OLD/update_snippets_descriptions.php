<?php
/**
 * Script de mise à jour automatique des descriptions des snippets FSBDD
 * Analyse le contenu des snippets actifs et génère des descriptions intelligentes
 * Optimisé pour le cache et les vecteurs de recherche
 */

class SnippetDescriptionUpdater {
    private $cache_dir;
    private $patterns;
    private $updated_count = 0;
    private $errors = [];
    
    public function __construct($cache_dir = '.snippet_cache') {
        $this->cache_dir = rtrim($cache_dir, '/');
        $this->initializePatterns();
    }
    
    /**
     * Initialise les patterns de détection des fonctionnalités FSBDD
     */
    private function initializePatterns() {
        $this->patterns = [
            // Calculs et coûts
            'calcul_couts' => [
                'keywords' => ['fsbdd_grpctsformation', 'fsbdd_selectcoutform', 'calculate', 'cout', 'montant'],
                'description' => 'Calcul automatique des coûts et charges pour les formations FSBDD'
            ],
            
            // Planning et horaires
            'planning' => [
                'keywords' => ['fsbdd_planning', 'horaire', 'planning', 'conflit', 'disponibilite'],
                'description' => 'Gestion du planning et des horaires des formateurs et formations'
            ],
            
            // Documents et états
            'documents' => [
                'keywords' => ['fsbdd_etat', 'upload', 'document', 'emargement', 'evaluation'],
                'description' => 'Gestion des documents de formation (émargements, évaluations, comptes-rendus)'
            ],
            
            // Stagiaires et effectifs
            'stagiaires' => [
                'keywords' => ['fsbdd_gpeffectif', 'fsbdd_nirstagiaire', 'stagiaire', 'effectif'],
                'description' => 'Gestion des stagiaires et effectifs des formations'
            ],
            
            // WooCommerce intégration
            'woocommerce' => [
                'keywords' => ['wc_', 'WC_', 'woocommerce', 'commande', 'produit', 'fsbdd_relsessaction'],
                'description' => 'Intégration WooCommerce pour la gestion commerciale des formations'
            ],
            
            // Interface utilisateur
            'ui_sticky' => [
                'keywords' => ['sticky', 'position.*fixed', 'toolbar', 'admin_footer'],
                'description' => 'Interface utilisateur avec éléments sticky et barres d\'outils'
            ],
            
            // AJAX et interactions
            'ajax' => [
                'keywords' => ['wp_ajax', 'ajax', 'wp_localize_script'],
                'description' => 'Fonctionnalités AJAX pour les interactions dynamiques'
            ],
            
            // Relations CPT
            'relations_cpt' => [
                'keywords' => ['action-de-formation', 'formateur', 'client', 'salle-de-formation'],
                'description' => 'Gestion des relations entre les Custom Post Types FSBDD'
            ],
            
            // Métaboxes et champs
            'metaboxes' => [
                'keywords' => ['add_meta_boxes', 'rwmb_meta', 'metabox', 'custom_field'],
                'description' => 'Métaboxes et champs personnalisés pour l\'administration'
            ],
            
            // Shortcodes
            'shortcodes' => [
                'keywords' => ['add_shortcode', '\\[fsbdd_', 'shortcode'],
                'description' => 'Shortcodes pour l\'affichage frontend des données FSBDD'
            ]
        ];
    }
    
    /**
     * Met à jour toutes les descriptions des snippets actifs
     */
    public function updateAllDescriptions() {
        $active_snippets = $this->getActiveSnippets();
        
        echo "Trouvé " . count($active_snippets) . " snippets actifs à analyser...\n";
        
        foreach ($active_snippets as $snippet_file) {
            try {
                $this->updateSnippetDescription($snippet_file);
            } catch (Exception $e) {
                $this->errors[] = "Erreur avec {$snippet_file}: " . $e->getMessage();
            }
        }
        
        $this->displayResults();
    }
    
    /**
     * Récupère la liste des snippets actifs
     */
    private function getActiveSnippets() {
        $snippets = [];
        $files = glob($this->cache_dir . '/snippet-*.php');
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (preg_match('/@active\s+true/', $content)) {
                $snippets[] = $file;
            }
        }
        
        return $snippets;
    }
    
    /**
     * Met à jour la description d'un snippet spécifique
     */
    private function updateSnippetDescription($file_path) {
        $content = file_get_contents($file_path);
        $original_content = $content;
        
        // Extraire les métadonnées actuelles
        $metadata = $this->extractMetadata($content);
        
        // Si description déjà présente et non vide, on passe
        if (!empty(trim($metadata['description']))) {
            echo "✓ " . basename($file_path) . " - Description déjà présente\n";
            return;
        }
        
        // Analyser le contenu pour générer une description
        $description = $this->generateDescription($content, $metadata);
        
        if (empty($description)) {
            echo "⚠ " . basename($file_path) . " - Impossible de générer une description\n";
            return;
        }
        
        // Mettre à jour la description
        $updated_content = $this->updateDescriptionInContent($content, $description);
        
        if ($updated_content !== $original_content) {
            file_put_contents($file_path, $updated_content);
            $this->updated_count++;
            echo "✅ " . basename($file_path) . " - Description mise à jour: " . substr($description, 0, 60) . "...\n";
        }
    }
    
    /**
     * Extrait les métadonnées du snippet
     */
    private function extractMetadata($content) {
        $metadata = [
            'id' => '',
            'name' => '',
            'description' => ''
        ];
        
        if (preg_match('/\* Snippet ID: (\d+)/', $content, $matches)) {
            $metadata['id'] = $matches[1];
        }
        
        if (preg_match('/\* Name: (.+)/', $content, $matches)) {
            $metadata['name'] = trim($matches[1]);
        }
        
        if (preg_match('/\* Description: (.*)/', $content, $matches)) {
            $metadata['description'] = trim($matches[1]);
        }
        
        return $metadata;
    }
    
    /**
     * Génère une description intelligente basée sur l'analyse du code
     */
    private function generateDescription($content, $metadata) {
        $detected_features = [];
        $primary_feature = null;
        $max_score = 0;
        
        // Analyser chaque pattern
        foreach ($this->patterns as $feature => $pattern) {
            $score = 0;
            
            foreach ($pattern['keywords'] as $keyword) {
                $matches = preg_match_all('/' . preg_quote($keyword, '/') . '/i', $content);
                $score += $matches;
            }
            
            if ($score > 0) {
                $detected_features[$feature] = $score;
                
                if ($score > $max_score) {
                    $max_score = $score;
                    $primary_feature = $feature;
                }
            }
        }
        
        if (!$primary_feature) {
            // Fallback: analyser le nom du snippet
            return $this->generateDescriptionFromName($metadata['name']);
        }
        
        // Construire la description
        $base_description = $this->patterns[$primary_feature]['description'];
        
        // Ajouter des détails spécifiques
        $details = $this->extractSpecificDetails($content, $primary_feature);
        if ($details) {
            $base_description .= '. ' . $details;
        }
        
        // Ajouter les fonctionnalités secondaires
        $secondary_features = array_filter($detected_features, function($score, $feature) use ($primary_feature) {
            return $feature !== $primary_feature && $score >= 2;
        }, ARRAY_FILTER_USE_BOTH);
        
        if (!empty($secondary_features)) {
            $secondary_list = [];
            foreach (array_keys($secondary_features) as $feature) {
                $secondary_list[] = strtolower(str_replace('_', ' ', $feature));
            }
            $base_description .= '. Inclut également: ' . implode(', ', $secondary_list);
        }
        
        return $base_description;
    }
    
    /**
     * Extrait des détails spécifiques selon le type de fonctionnalité
     */
    private function extractSpecificDetails($content, $feature) {
        switch ($feature) {
            case 'calcul_couts':
                if (preg_match('/fsbdd_grpctsformation/', $content)) {
                    return 'Mise à jour automatique des groupes de coûts';
                }
                break;
                
            case 'ui_sticky':
                if (preg_match('/position.*fixed.*bottom/', $content)) {
                    return 'Barre d\'outils fixe en bas d\'écran';
                } elseif (preg_match('/position.*sticky.*top/', $content)) {
                    return 'Conteneur sticky en haut de page';
                }
                break;
                
            case 'ajax':
                $ajax_actions = [];
                if (preg_match_all('/wp_ajax_([a-zA-Z_]+)/', $content, $matches)) {
                    $ajax_actions = array_unique($matches[1]);
                }
                if (!empty($ajax_actions)) {
                    return 'Actions AJAX: ' . implode(', ', array_slice($ajax_actions, 0, 3));
                }
                break;
        }
        
        return '';
    }
    
    /**
     * Génère une description basée sur le nom du snippet
     */
    private function generateDescriptionFromName($name) {
        $name_lower = strtolower($name);
        
        if (strpos($name_lower, 'metabox') !== false) {
            return 'Métabox personnalisée pour l\'administration WordPress';
        }
        
        if (strpos($name_lower, 'ajax') !== false) {
            return 'Fonctionnalité AJAX pour les interactions dynamiques';
        }
        
        if (strpos($name_lower, 'shortcode') !== false) {
            return 'Shortcode pour l\'affichage frontend';
        }
        
        if (strpos($name_lower, 'hook') !== false || strpos($name_lower, 'action') !== false) {
            return 'Hook WordPress pour l\'extension des fonctionnalités';
        }
        
        return 'Fonctionnalité personnalisée pour l\'écosystème FSBDD';
    }
    
    /**
     * Met à jour la description dans le contenu du fichier
     */
    private function updateDescriptionInContent($content, $description) {
        // Remplacer la ligne Description vide
        $pattern = '/(\* Description: )(.*)/';
        $replacement = '$1' . $description;
        
        return preg_replace($pattern, $replacement, $content);
    }
    
    /**
     * Affiche les résultats de la mise à jour
     */
    private function displayResults() {
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "RÉSULTATS DE LA MISE À JOUR\n";
        echo str_repeat('=', 50) . "\n";
        echo "Snippets mis à jour: {$this->updated_count}\n";
        
        if (!empty($this->errors)) {
            echo "Erreurs rencontrées: " . count($this->errors) . "\n";
            foreach ($this->errors as $error) {
                echo "  - {$error}\n";
            }
        }
        
        echo "\nMise à jour terminée avec succès!\n";
    }
}

// Exécution du script
if (php_sapi_name() === 'cli') {
    $updater = new SnippetDescriptionUpdater();
    $updater->updateAllDescriptions();
} else {
    echo "Ce script doit être exécuté en ligne de commande.\n";
}