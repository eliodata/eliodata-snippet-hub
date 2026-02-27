# Installation du Plugin WordPress

## 🚀 Installation Rapide

### Étape 1: Prérequis
Avant d'installer ce plugin, assurez-vous d'avoir :
- WordPress 5.0 ou plus récent
- PHP 7.4 ou plus récent
- Le plugin **Code Snippets** installé et activé

### Étape 2: Installation du Plugin Code Snippets
1. Allez dans **Extensions > Ajouter**
2. Recherchez "Code Snippets"
3. Installez et activez le plugin **Code Snippets** par Code Snippets Pro

### Étape 3: Installation du Plugin Bridge
1. Téléchargez le dossier `wordpress-plugin` de ce projet
2. Compressez le dossier en fichier ZIP
3. Allez dans **Extensions > Ajouter > Téléverser une extension**
4. Sélectionnez le fichier ZIP et installez
5. Activez le plugin "IDE Code Snippets Bridge"

### Étape 4: Configuration de l'Authentification
1. Allez dans **Utilisateurs > Profil**
2. Faites défiler jusqu'à "Mots de passe d'application"
3. Créez un nouveau mot de passe d'application avec le nom "Trae Extension"
4. Copiez le mot de passe généré (vous en aurez besoin dans VS Code)

## 🔧 Configuration dans VS Code

1. Installez l'extension "Trae AI - WordPress Snippet Manager"
2. Ouvrez la palette de commandes (Cmd/Ctrl + Shift + P)
3. Tapez "WordPress Snippets: Configure Connection"
4. Entrez :
   - **URL du site** : https://votre-site.com (sans slash final)
   - **Nom d'utilisateur** : votre nom d'utilisateur WordPress
   - **Mot de passe d'application** : le mot de passe généré à l'étape 4

## 🔍 Vérification

### Test de l'API
Pour vérifier que l'API fonctionne, vous pouvez tester l'endpoint :
```
GET https://votre-site.com/wp-json/ide/v1/snippets
```

Avec l'authentification Basic (nom d'utilisateur + mot de passe d'application).

### Endpoints Disponibles
- `GET /wp-json/ide/v1/snippets` - Liste tous les snippets
- `POST /wp-json/ide/v1/snippets` - Créer un nouveau snippet
- `GET /wp-json/ide/v1/snippets/{id}` - Récupérer un snippet spécifique
- `PUT /wp-json/ide/v1/snippets/{id}` - Mettre à jour un snippet
- `DELETE /wp-json/ide/v1/snippets/{id}` - Supprimer un snippet

## ❗ Résolution des Problèmes

### Erreur 404
- Vérifiez que le plugin est bien activé
- Vérifiez que les permaliens sont configurés (Réglages > Permaliens > Enregistrer)
- Testez l'URL : `https://votre-site.com/wp-json/`

### Erreur 401 (Non autorisé)
- Vérifiez le nom d'utilisateur et le mot de passe d'application
- Assurez-vous que l'utilisateur a les droits d'administrateur

### Erreur 500
- Vérifiez que le plugin Code Snippets est installé et activé
- Consultez les logs d'erreur de WordPress

## 🔒 Sécurité

- Seuls les utilisateurs avec la capacité `manage_options` peuvent accéder à l'API
- Utilisez toujours HTTPS en production
- Les mots de passe d'application peuvent être révoqués à tout moment
- Toutes les données sont validées et nettoyées avant insertion en base

## 📞 Support

Si vous rencontrez des problèmes :
1. Vérifiez cette documentation
2. Consultez les logs d'erreur WordPress
3. Ouvrez une issue sur le repository GitHub