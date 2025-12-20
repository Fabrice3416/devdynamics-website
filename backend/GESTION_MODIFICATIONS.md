# Gestion des Modifications sur Hostinger

## 🎯 Méthodes Disponibles

### 1. Gestionnaire de Fichiers Hostinger (Le Plus Simple)

**Avantages:**
- Pas besoin de logiciel supplémentaire
- Modifications directes en ligne
- Éditeur de code intégré

**Comment faire:**
1. Connectez-vous à votre panneau Hostinger
2. Allez dans **Gestionnaire de fichiers**
3. Naviguez vers le fichier à modifier (ex: `public_html/api/routes/courses.php`)
4. Clic droit → **Éditer**
5. Faites vos modifications
6. **Enregistrez**

**Idéal pour:**
- Petites corrections
- Modifications urgentes
- Changements de configuration (.env)

---

### 2. FTP/SFTP (Recommandé pour les Développeurs)

**Avantages:**
- Modification locale avec votre éditeur préféré (VS Code, Sublime, etc.)
- Upload/download rapide de plusieurs fichiers
- Synchronisation automatique possible

**Configuration FTP:**
1. Dans Hostinger, allez dans **FTP Accounts**
2. Notez vos identifiants:
   ```
   Hôte: ftp.votredomaine.com
   Utilisateur: u123456789
   Mot de passe: [votre mot de passe]
   Port: 21 (FTP) ou 22 (SFTP)
   ```

**Clients FTP Recommandés:**
- **FileZilla** (gratuit, Windows/Mac/Linux)
- **WinSCP** (gratuit, Windows)
- **Cyberduck** (gratuit, Mac)
- **VS Code** avec l'extension "FTP-Simple"

**Workflow avec FTP:**
```
1. Modifiez vos fichiers localement (c:\wamp64\www\api\)
2. Testez localement avec WAMP
3. Connectez-vous via FTP à Hostinger
4. Uploadez uniquement les fichiers modifiés
5. Testez en production
```

---

### 3. Git + GitHub/GitLab (Méthode Professionnelle)

**Avantages:**
- Historique complet des modifications
- Retour en arrière facile
- Collaboration en équipe
- Déploiement automatisé possible

**Configuration Initiale:**

#### A. Créer un dépôt Git local
```bash
cd c:\wamp64\www\api
git init
git add .
git commit -m "Initial commit - Backend PHP"
```

#### B. Créer un dépôt sur GitHub
1. Allez sur https://github.com
2. Créez un nouveau dépôt "devdynamics-backend"
3. Suivez les instructions pour pousser votre code:
```bash
git remote add origin https://github.com/votreusername/devdynamics-backend.git
git branch -M main
git push -u origin main
```

#### C. Déployer sur Hostinger via Git

**Option 1: Manuel (Simple)**
```bash
# Sur votre PC local
git add .
git commit -m "Ajout de nouvelles fonctionnalités"
git push

# Ensuite, uploadez via FTP ou SSH
```

**Option 2: SSH + Git sur Hostinger (Avancé)**
```bash
# Connectez-vous en SSH à Hostinger
ssh u123456789@votredomaine.com

# Naviguez vers votre dossier
cd public_html/api

# Clonez ou pullez les modifications
git pull origin main
```

**Note:** Certains plans Hostinger ne permettent pas l'accès SSH. Vérifiez votre plan.

---

### 4. VS Code Remote SSH (Le Plus Confortable)

**Extension nécessaire:** Remote - SSH

**Configuration:**
1. Installez l'extension "Remote - SSH" dans VS Code
2. Connectez-vous via SSH à Hostinger
3. Éditez directement les fichiers sur le serveur
4. Les modifications sont instantanées

**Fichier de configuration SSH** (`~/.ssh/config`):
```
Host hostinger-devdynamics
    HostName votredomaine.com
    User u123456789
    Port 22
```

---

## 🔄 Workflow Recommandé

### Pour les Petites Modifications
```
1. Modifier localement avec WAMP
2. Tester localement
3. Uploader via Gestionnaire de fichiers Hostinger
   OU via FTP (FileZilla)
```

### Pour les Grosses Fonctionnalités
```
1. Créer une branche Git locale
2. Développer et tester localement
3. Commiter les modifications
4. Merger dans la branche main
5. Déployer sur Hostinger via FTP ou Git
6. Tester en production
```

---

## 📋 Fichiers à Ne JAMAIS Modifier Directement en Production

⚠️ **Toujours modifier ces fichiers localement d'abord:**
- `config/database.php` (risque de casser la connexion DB)
- `utils/JWT.php` (risque de casser l'authentification)
- `.htaccess` (risque de casser tout le site)
- `.env` (sauf pour changer les identifiants DB)

✅ **OK à modifier directement:**
- Contenu des routes (`routes/*.php`)
- Templates frontend (HTML/CSS/JS)
- Images et assets

---

## 🛠️ Outils et Extensions Utiles

### VS Code Extensions
```json
{
  "recommendations": [
    "ms-vscode-remote.remote-ssh",
    "formulahendry.auto-close-tag",
    "bmewburn.vscode-intelephense-client",
    "felixfbecker.php-debug",
    "qwtel.sqlite-viewer",
    "humao.rest-client"
  ]
}
```

### FileZilla Configuration Rapide
1. **Fichier** → **Gestionnaire de sites**
2. **Nouveau site** → "Hostinger DevDynamics"
3. Remplissez:
   - Hôte: `ftp.votredomaine.com`
   - Port: `21`
   - Protocole: `FTP` (ou `SFTP` si disponible)
   - Chiffrement: `Utiliser FTP explicite sur TLS si disponible`
   - Type d'authentification: `Normale`
   - Identifiant: `u123456789`
   - Mot de passe: `[votre mot de passe]`
4. **Connecter**

---

## 🔒 Sécurité

### Fichiers Sensibles à Protéger

Créez un fichier `.gitignore` pour ne pas versionner:
```
.env
.env.local
.env.production
*.log
uploads/*
!uploads/.gitkeep
node_modules/
vendor/
.vscode/
.idea/
*.zip
*.sql
```

### Sauvegarde Avant Modification
Avant toute modification importante:
```bash
# Sauvegarder la base de données
mysqldump -u u123456789 -p devdynamics_db > backup_$(date +%Y%m%d).sql

# Sauvegarder les fichiers
zip -r backup_files_$(date +%Y%m%d).zip public_html/api/
```

---

## 📊 Tableau Comparatif

| Méthode | Difficulté | Vitesse | Sécurité | Recommandé pour |
|---------|-----------|---------|----------|-----------------|
| Gestionnaire fichiers | ⭐ | ⚡⚡ | 🔒🔒 | Urgences, petits changements |
| FTP (FileZilla) | ⭐⭐ | ⚡⚡⚡ | 🔒🔒🔒 | Développement quotidien |
| Git + FTP | ⭐⭐⭐ | ⚡⚡ | 🔒🔒🔒🔒 | Projets pro, équipes |
| SSH + Git | ⭐⭐⭐⭐ | ⚡⚡⚡ | 🔒🔒🔒🔒🔒 | DevOps avancé |
| VS Code Remote | ⭐⭐⭐ | ⚡⚡⚡⚡ | 🔒🔒🔒🔒 | Confort maximal |

---

## 🎯 Ma Recommandation pour Vous

**Pour débuter:**
1. **Développement local:** WAMP + VS Code
2. **Transfert:** FileZilla (FTP)
3. **Modifications urgentes:** Gestionnaire de fichiers Hostinger

**À terme (quand vous êtes à l'aise):**
1. **Développement local:** WAMP + VS Code + Git
2. **Versioning:** GitHub
3. **Déploiement:** Git push + FTP upload
4. **Modifications rapides:** VS Code Remote SSH

---

## 📝 Exemple de Workflow Complet

### Scénario: Ajouter une nouvelle route API

#### 1. Développement Local
```bash
# Ouvrir VS Code
code c:\wamp64\www\api

# Créer/modifier le fichier
# routes/new-feature.php

# Tester localement
http://localhost/api/new-feature
```

#### 2. Versioning (optionnel mais recommandé)
```bash
git add routes/new-feature.php
git commit -m "Ajout de la route new-feature"
git push origin main
```

#### 3. Déploiement sur Hostinger
**Option A - FTP:**
- Ouvrir FileZilla
- Se connecter à Hostinger
- Naviguer vers `public_html/api/routes/`
- Glisser-déposer `new-feature.php`

**Option B - Gestionnaire de fichiers:**
- Panneau Hostinger → Gestionnaire de fichiers
- Naviguer vers `public_html/api/routes/`
- **Upload** → Sélectionner `new-feature.php`

#### 4. Test en Production
```
https://votredomaine.com/api/new-feature
```

---

## 🆘 Dépannage

### "Je ne peux pas me connecter via FTP"
- Vérifiez que vous utilisez les bons identifiants
- Essayez le port 21 (FTP) ou 22 (SFTP)
- Désactivez temporairement votre pare-feu
- Contactez le support Hostinger

### "Mes modifications ne s'affichent pas"
- Videz le cache du navigateur (Ctrl+F5)
- Vérifiez que le bon fichier a été uploadé
- Vérifiez les logs d'erreur dans Hostinger
- Assurez-vous que le fichier a les bonnes permissions (644)

### "Le site est cassé après mes modifications"
- Restaurez depuis une sauvegarde
- Ou reuploaded la version précédente du fichier
- Consultez les logs d'erreur PHP dans le panneau Hostinger

---

## 📞 Support

- **Documentation Hostinger:** https://support.hostinger.com
- **FileZilla:** https://filezilla-project.org/
- **Git:** https://git-scm.com/doc

---

**Dernière mise à jour:** 2025-12-18
