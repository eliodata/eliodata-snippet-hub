# Contexte Intelligent - Snippets WordPress FSBDD

## Architecture CPT (Custom Post Types)

### CPT Principaux
- **action-de-formation** : Hub central (80% des snippets)
- **formateur** : Ressources humaines
- **client/prospect** : Gestion commerciale
- **salle-de-formation** : Ressources logistiques

### Relations Clés
```php
// Liaison formateur -> action
'fsbdd_user_formateurrel' => $formateur_id

// Coûts formateurs
'fsbdd_selectcoutform' => $formateur_id
'fsbdd_selectctfourn' => $fournisseur_id

// Produits WooCommerce
'fsbdd_relsessproduit' => $product_id
'fsbdd_relsessaction_cpt_produit' => $session_id
```

## Custom Fields Critiques (préfixe fsbdd_)

### Planning & Coûts
```php
// Structure planning complexe
'fsbdd_planning' => [
    'fsbdd_planjour' => 'date',
    'fsbdd_gpformatr' => [
        'fsbdd_user_formateurrel' => 'id',
        'fsbdd_dispjourform' => 'disponibilité',
        'fsbdd_heuresjoursform' => 'heures'
    ]
]

// Groupe coûts formation
'fsbdd_grpctsformation' => [
    'fsbdd_typechargedue' => '1|2', // 1=Formateur, 2=Fournisseur
    'fsbdd_montrechrge' => 'montant_total',
    'fsbdd_totalheures' => 'heures_calculées'
]
```

### Documents & États
```php
// États documents
'fsbdd_etatemargm' => 'état_émargements'
'fsbdd_etatcpterenduf' => 'état_compte_rendu'
'fsbdd_etateval' => 'état_évaluations'

// Réglements
'fsbdd_reglements' => 'array_paiements'
```

### Stagiaires
```php
'fsbdd_gpeffectif' => [
    'fsbdd_prenomstagiaire' => 'prénom',
    'fsbdd_nomstagiaire' => 'nom',
    'fsbdd_nirstagiaire' => 'identifiant_unique',
    'fsbdd_emailstagi' => 'email'
]
```

## Patterns Récurrents

### 1. Calculs Automatisés (40% des snippets)
- Calcul heures formateurs
- Calcul coûts/marges
- Mise à jour automatique totaux

### 2. Gestion Documentaire
- Upload/download fichiers
- États documents (émargements, évaluations)
- Arborescence : `/documents-internes/{formateur_id}/{action_slug}/`

### 3. Planning Complexe
- Détection conflits
- Gestion disponibilités
- Calcul heures par jour/demi-jour

### 4. Intégration WooCommerce
- Liaison commandes/sessions
- Gestion stagiaires
- Calcul CA/marges

### 5. AJAX Intensif
```php
// Hooks AJAX fréquents
wp_ajax_fs_get_filtered_data
wp_ajax_update_sessions_alerts
wp_ajax_fsbdd_download_lettre_mission
```

## Shortcodes Métier
```php
[fsbdd_stagiaire_count] // Comptage stagiaires
[fsbdd_lettre_mission] // Génération lettres
[fsbdd_lettres_mission_formateur] // Lettres formateurs
```

## Fonctions Utilitaires Clés
```php
// MetaBox
rwmb_meta($key, $args, $post_id)
rwmb_set_meta($post_id, $key, $value)

// WordPress natif
get_post_meta($post_id, $key, true)
update_post_meta($post_id, $key, $value)

// Spécifiques FSBDD
fsbdd_get_action_formateur_ids($action_id)
update_fsbdd_etat_documents($post_id, $type)
```

## Stratégie de Recherche Optimisée

### Recherche Vectorielle TF-IDF pour :
- Concepts métier : "calcul coût formateur", "planning conflit"
- Fonctionnalités complexes : "upload document", "marge session"
- Relations CPT : "formateur action", "stagiaire commande"

### Cache Direct pour :
- Noms champs exacts : `fsbdd_planning`
- Fonctions spécifiques : `rwmb_meta`, `update_post_meta`
- Hooks précis : `wp_ajax_*`

## Workflows Typiques

1. **Création Action Formation**
   - Génération planning
   - Attribution formateurs
   - Calcul coûts automatique

2. **Gestion Documents**
   - Upload par formateur
   - Mise à jour états
   - Notifications

3. **Suivi Commercial**
   - Liaison commandes WooCommerce
   - Calcul CA/marges
   - Gestion stagiaires

Ce contexte permet une exploitation optimale de la base vectorielle selon la complexité et le type de requête.