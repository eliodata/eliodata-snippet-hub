#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

/**
 * Script de mise à jour des descriptions de snippets FSBDD
 * Traite tous les snippets actifs, même ceux qui ont déjà une description
 */
class SnippetDescriptionUpdater {
    constructor() {
        this.snippetCacheDir = '.snippet_cache';
        this.patterns = {
            // Patterns FSBDD spécifiques
            calculs: /fsbdd_(grpctsformation|totalheures|montrechrge|selectcoutform)/,
            planning: /fsbdd_planning/,
            stagiaires: /fsbdd_(gpeffectif|nirstagiaire)/,
            documents: /fsbdd_etat/,
            woocommerce: /woocommerce|WC_|wc_/,
            ajax: /wp_ajax_|ajax/,
            metabox: /add_meta_box|rwmb_meta/,
            sticky: /sticky|fixed|position.*absolute/,
            ui: /css|style|class.*=|getElementById/
        };
        
        this.forceUpdate = process.argv.includes('--force-update');
    }

    /**
     * Analyse le contenu d'un snippet pour détecter ses fonctionnalités
     */
    analyzeSnippetContent(content) {
        const features = [];
        const name = this.extractName(content);
        
        // Détection par patterns
        if (this.patterns.calculs.test(content)) {
            features.push('Calculs automatiques FSBDD');
        }
        if (this.patterns.planning.test(content)) {
            features.push('Gestion planning formateurs');
        }
        if (this.patterns.stagiaires.test(content)) {
            features.push('Gestion stagiaires');
        }
        if (this.patterns.documents.test(content)) {
            features.push('Documents de formation');
        }
        if (this.patterns.woocommerce.test(content)) {
            features.push('Intégration WooCommerce');
        }
        if (this.patterns.ajax.test(content)) {
            features.push('Fonctionnalités AJAX');
        }
        if (this.patterns.metabox.test(content)) {
            features.push('Métabox personnalisée');
        }
        if (this.patterns.sticky.test(content)) {
            features.push('Interface utilisateur sticky');
        }
        if (this.patterns.ui.test(content)) {
            features.push('Interface utilisateur');
        }

        return this.generateDescription(name, features, content);
    }

    /**
     * Extrait le nom du snippet
     */
    extractName(content) {
        const nameMatch = content.match(/@name\s+(.+)/i);
        return nameMatch ? nameMatch[1].trim() : 'Snippet sans nom';
    }

    /**
     * Génère une description basée sur le nom et les fonctionnalités détectées
     */
    generateDescription(name, features, content) {
        if (features.length === 0) {
            // Analyse basique du nom pour générer une description
            const nameLower = name.toLowerCase();
            if (nameLower.includes('calcul') || nameLower.includes('cout')) {
                return 'Système de calcul automatique pour la gestion des coûts et tarifications dans l\'écosystème FSBDD.';
            }
            if (nameLower.includes('planning')) {
                return 'Gestion intelligente du planning des formateurs avec détection de conflits et optimisation des créneaux.';
            }
            if (nameLower.includes('sticky') || nameLower.includes('bouton')) {
                return 'Interface utilisateur optimisée avec éléments sticky pour un accès rapide aux fonctionnalités critiques.';
            }
            if (nameLower.includes('metabox') || nameLower.includes('switch')) {
                return 'Métabox personnalisée pour l\'administration WordPress avec fonctionnalités avancées de gestion.';
            }
            if (nameLower.includes('ajax') || nameLower.includes('api')) {
                return 'Fonctionnalités AJAX pour une expérience utilisateur fluide et des mises à jour en temps réel.';
            }
            return 'Fonctionnalité WordPress personnalisée pour l\'écosystème FSBDD avec intégrations métier spécialisées.';
        }

        const mainFeature = features[0];
        const additionalFeatures = features.slice(1);
        
        let description = `${mainFeature} avec intégration complète dans l'écosystème FSBDD.`;
        
        if (additionalFeatures.length > 0) {
            description += ` Inclut également : ${additionalFeatures.join(', ')}.`;
        }
        
        // Ajout de contexte spécifique selon le type
        if (features.includes('Calculs automatiques FSBDD')) {
            description += ' Optimise la gestion financière avec calculs en temps réel des coûts formateurs et marges.';
        }
        if (features.includes('Gestion planning formateurs')) {
            description += ' Prévient les conflits de planning et optimise l\'allocation des ressources humaines.';
        }
        if (features.includes('Intégration WooCommerce')) {
            description += ' Synchronise les données commerciales avec le système de formation pour un suivi unifié.';
        }
        
        return description;
    }

    /**
     * Met à jour la description dans le contenu du snippet
     */
    updateSnippetDescription(content, newDescription) {
        const lines = content.split('\n');
        let updated = false;
        
        // Recherche de la ligne avec Description (vide ou non)
        for (let i = 0; i < lines.length; i++) {
            const line = lines[i].trim();
            if (line.startsWith('* Description:') || line.match(/^\*\s*Description\s*:?\s*$/)) {
                lines[i] = ` * Description: ${newDescription}`;
                updated = true;
                break;
            }
        }
        
        // Si pas de ligne Description trouvée, l'ajouter après Name:
        if (!updated) {
            for (let i = 0; i < lines.length; i++) {
                const line = lines[i].trim();
                if (line.startsWith('* Name:')) {
                    lines.splice(i + 1, 0, ` * Description: ${newDescription}`);
                    updated = true;
                    break;
                }
            }
        }
        
        return lines.join('\n');
    }

    /**
     * Vérifie si un snippet est actif
     */
    isActiveSnippet(content) {
        return content.includes('@active true');
    }

    /**
     * Vérifie si un snippet a déjà une description
     */
    hasDescription(content) {
        const lines = content.split('\n');
        for (const line of lines) {
            const trimmed = line.trim();
            if (trimmed.startsWith('* Description:')) {
                const desc = trimmed.replace('* Description:', '').trim();
                // Considère comme vide si la description est vide ou contient seulement "* Active: true"
                return desc !== '' && !desc.match(/^\*\s*Active:\s*true\s*$/);
            }
        }
        return false;
    }

    /**
     * Traite tous les snippets du cache
     */
    async processAllSnippets() {
        if (!fs.existsSync(this.snippetCacheDir)) {
            console.error(`❌ Répertoire ${this.snippetCacheDir} introuvable`);
            return;
        }

        const files = fs.readdirSync(this.snippetCacheDir)
            .filter(file => file.endsWith('.php'))
            .sort();

        let processed = 0;
        let updated = 0;
        let skipped = 0;

        console.log(`🚀 Début de la mise à jour des descriptions de snippets`);
        console.log(`📁 Répertoire: ${this.snippetCacheDir}`);
        console.log(`📊 Fichiers trouvés: ${files.length}`);
        console.log(`🔄 Mode: ${this.forceUpdate ? 'Mise à jour forcée' : 'Mise à jour sélective'}`);
        console.log('\n' + '='.repeat(60));

        for (const file of files) {
            const filePath = path.join(this.snippetCacheDir, file);
            const content = fs.readFileSync(filePath, 'utf8');
            
            if (!this.isActiveSnippet(content)) {
                continue;
            }

            processed++;
            const hasDesc = this.hasDescription(content);
            
            // Traiter TOUS les snippets actifs, même ceux avec descriptions existantes
            // si forceUpdate est activé OU si la description est vide/invalide
            if (hasDesc && !this.forceUpdate) {
                skipped++;
                console.log(`⏭️  ${file} - Description existante (ignoré)`);
                continue;
            }

            const newDescription = this.analyzeSnippetContent(content);
            const updatedContent = this.updateSnippetDescription(content, newDescription);
            
            fs.writeFileSync(filePath, updatedContent, 'utf8');
            updated++;
            
            const status = hasDesc ? '🔄 Mise à jour' : '✅ Nouvelle';
            console.log(`${status} ${file}`);
            console.log(`   📝 ${newDescription.substring(0, 80)}${newDescription.length > 80 ? '...' : ''}`);
        }

        console.log('\n' + '='.repeat(60));
        console.log(`📊 RÉSULTATS FINAUX`);
        console.log(`   Snippets actifs traités: ${processed}`);
        console.log(`   Descriptions mises à jour: ${updated}`);
        console.log(`   Snippets ignorés: ${skipped}`);
        console.log(`\n✨ Mise à jour terminée avec succès!`);
    }
}

// Exécution du script
if (require.main === module) {
    const updater = new SnippetDescriptionUpdater();
    updater.processAllSnippets().catch(console.error);
}

module.exports = SnippetDescriptionUpdater;