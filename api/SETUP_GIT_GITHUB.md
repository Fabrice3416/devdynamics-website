# Configuration Git + GitHub pour DevDynamics

## 🎯 Structure du Projet

Nous allons créer **2 dépôts Git séparés** pour correspondre à la structure Hostinger:

```
1. devdynamics-backend  → public_html/api/
2. devdynamics-frontend → public_html/
```

Ou **1 seul dépôt monorepo** avec cette structure:
```
devdynamics-website/
├── backend/     → uploade vers public_html/api/
├── frontend/    → uploade vers public_html/
└── docs/
```

**Je recommande: 1 seul dépôt (plus simple pour débuter)**

---

## 📋 ÉTAPE 1: Créer la Bonne Structure Locale

### Option A: Réorganiser vos fichiers existants

```batch
# Créer la structure
mkdir c:\DevDynamics-Project
cd c:\DevDynamics-Project

# Créer les sous-dossiers
mkdir backend
mkdir frontend
mkdir docs

# Copier les fichiers backend
xcopy /E /I "c:\wamp64\www\api" "backend"

# Copier les fichiers frontend
xcopy /E /I "c:\Users\brucy\OneDrive\Bureau\devdynamics-website\frontend" "frontend"
```

### Structure finale attendue:
```
c:\DevDynamics-Project\
├── backend/
│   ├── config/
│   │   └── database.php
│   ├── utils/
│   │   ├── JWT.php
│   │   ├── Router.php
│   │   └── Response.php
│   ├── routes/
│   │   ├── auth.php
│   │   ├── courses.php
│   │   ├── students.php
│   │   └── ... (tous les autres)
│   ├── middleware/
│   │   └── auth.php
│   ├── index.php
│   ├── .htaccess
│   ├── .env.example
│   └── README.md
├── frontend/
│   ├── index.html
│   ├── js/
│   │   ├── api.js
│   │   ├── config.js
│   │   └── pages/
│   ├── css/
│   ├── assets/
│   └── pages/
├── docs/
│   ├── DEPLOIEMENT_HOSTINGER.md
│   ├── GESTION_MODIFICATIONS.md
│   └── API_DOCUMENTATION.md
├── .gitignore
└── README.md
```

---

## 📋 ÉTAPE 2: Initialiser Git

### 1. Créer le fichier .gitignore

Créez `c:\DevDynamics-Project\.gitignore`:

```gitignore
# Environment files
.env
.env.local
.env.production
*.env

# Logs
*.log
logs/
backend/logs/

# Uploads
backend/uploads/*
!backend/uploads/.gitkeep

# OS files
.DS_Store
Thumbs.db
desktop.ini

# IDE
.vscode/
.idea/
*.swp
*.swo
*~

# Dependencies (si vous utilisez Composer plus tard)
backend/vendor/
node_modules/

# Backups
*.backup
*.bak
*.old
*.sql

# Temporary files
*.tmp
*.temp

# WAMP specific
phpmyadmin/
wamp/

# Build files
*.zip
*.tar.gz
```

### 2. Créer .env.example pour le backend

Créez `backend/.env.example`:

```env
# Database Configuration
DB_HOST=localhost
DB_USER=root
DB_PASSWORD=
DB_NAME=devdynamics_db
DB_PORT=3306

# JWT Configuration
JWT_SECRET=your-secret-key-here
JWT_EXPIRY=604800

# Application Settings
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost

# Email Configuration (Optional)
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USER=
SMTP_PASSWORD=
SMTP_FROM=noreply@example.com
SMTP_FROM_NAME=DevDynamics
```

### 3. Initialiser Git

```batch
cd c:\DevDynamics-Project
git init
git add .
git commit -m "Initial commit - DevDynamics Full Stack"
```

---

## 📋 ÉTAPE 3: Créer le Dépôt GitHub

### 1. Créer un compte GitHub (si pas déjà fait)
- Allez sur https://github.com
- Créez un compte gratuit

### 2. Créer un nouveau dépôt
1. Cliquez sur le **+** en haut à droite → **New repository**
2. Remplissez:
   - **Repository name:** `devdynamics-website`
   - **Description:** "Site web DevDynamics - Formation et éducation"
   - **Visibility:** Private (ou Public selon votre choix)
   - **NE COCHEZ PAS** "Initialize with README" (vous en avez déjà un)
3. Cliquez **Create repository**

### 3. Connecter votre dépôt local à GitHub

GitHub vous donnera ces commandes:

```batch
git remote add origin https://github.com/votreusername/devdynamics-website.git
git branch -M main
git push -u origin main
```

**Note:** Remplacez `votreusername` par votre nom d'utilisateur GitHub

### 4. Authentification (la première fois)

GitHub peut vous demander de vous authentifier:
- **Option 1:** Personal Access Token (recommandé)
- **Option 2:** GitHub CLI

**Créer un Personal Access Token:**
1. GitHub → Settings → Developer settings → Personal access tokens → Tokens (classic)
2. Generate new token (classic)
3. Donnez-lui un nom: "DevDynamics Local"
4. Cochez: `repo` (Full control of private repositories)
5. Générez et **copiez le token** (vous ne pourrez plus le voir!)
6. Utilisez ce token comme mot de passe quand Git vous le demande

---

## 📋 ÉTAPE 4: Créer un README.md Principal

Créez `c:\DevDynamics-Project\README.md`:

```markdown
# DevDynamics Website

Site web de formation et d'éducation avec backend PHP et frontend moderne.

## 🏗️ Structure du Projet

- `backend/` - API REST en PHP (déployé vers `public_html/api/`)
- `frontend/` - Interface utilisateur (déployé vers `public_html/`)
- `docs/` - Documentation du projet

## 🚀 Déploiement

### Prérequis
- PHP 7.4+
- MySQL 5.7+
- Apache avec mod_rewrite

### Installation Locale (WAMP)

1. Clonez le dépôt:
   ```bash
   git clone https://github.com/votreusername/devdynamics-website.git
   ```

2. Copiez le backend vers WAMP:
   ```bash
   xcopy /E /I backend c:\wamp64\www\api
   ```

3. Configurez la base de données:
   - Copiez `backend/.env.example` vers `c:\wamp64\www\api\.env`
   - Remplissez avec vos identifiants MySQL
   - Importez la base de données

4. Testez:
   - Backend: http://localhost/api/courses
   - Frontend: Ouvrez `frontend/index.html`

### Déploiement Hostinger

Voir la documentation complète dans `docs/DEPLOIEMENT_HOSTINGER.md`

## 📚 Documentation

- [Déploiement Hostinger](docs/DEPLOIEMENT_HOSTINGER.md)
- [Gestion des Modifications](docs/GESTION_MODIFICATIONS.md)
- [Configuration Git](docs/SETUP_GIT_GITHUB.md)

## 🔒 Sécurité

- Ne committez JAMAIS le fichier `.env`
- Utilisez `.env.example` comme template
- Changez le `JWT_SECRET` en production

## 👥 Contribution

1. Créez une branche: `git checkout -b feature/ma-fonctionnalite`
2. Committez: `git commit -m "Ajout de ma fonctionnalité"`
3. Pushez: `git push origin feature/ma-fonctionnalite`
4. Créez une Pull Request

## 📝 License

Propriétaire - DevDynamics © 2024

## 📞 Contact

Pour toute question, contactez: contact@devdynamics.com
```

---

## 📋 ÉTAPE 5: Workflow Git Quotidien

### Faire des modifications

```batch
# 1. Vérifier l'état
git status

# 2. Voir les changements
git diff

# 3. Ajouter les fichiers modifiés
git add backend/routes/courses.php
# OU ajouter tout:
git add .

# 4. Commiter avec un message clair
git commit -m "Ajout de la pagination pour les cours"

# 5. Pousser vers GitHub
git push origin main
```

### Récupérer les modifications (si vous travaillez en équipe)

```batch
git pull origin main
```

### Créer une branche pour une nouvelle fonctionnalité

```batch
# Créer et switcher vers la branche
git checkout -b feature/notifications

# Faire vos modifications...
# ...

# Commiter
git add .
git commit -m "Ajout du système de notifications"

# Pousser la branche
git push origin feature/notifications

# Retourner sur main et merger
git checkout main
git merge feature/notifications
git push origin main
```

---

## 📋 ÉTAPE 6: Déployer sur Hostinger depuis Git

### Méthode 1: Git Clone sur Hostinger (si SSH disponible)

```bash
# Se connecter en SSH à Hostinger
ssh u123456789@votredomaine.com

# Aller dans public_html
cd public_html

# Cloner le backend
git clone https://github.com/votreusername/devdynamics-website.git temp
mv temp/backend api
mv temp/frontend/* .
rm -rf temp

# Configurer .env
cp api/.env.example api/.env
nano api/.env  # Éditer avec vos identifiants Hostinger

# Pull pour les mises à jour futures
cd api
git pull origin main
```

### Méthode 2: GitHub + FTP (Plus simple, recommandé)

```batch
# 1. Développer localement
# Modifier vos fichiers...

# 2. Commiter et pousser
git add .
git commit -m "Nouvelles fonctionnalités"
git push origin main

# 3. Télécharger sur Hostinger via FTP
# Ouvrir FileZilla
# Uploader uniquement les fichiers modifiés
```

### Méthode 3: GitHub Actions (Automatique - Avancé)

Créez `.github/workflows/deploy.yml`:

```yaml
name: Deploy to Hostinger

on:
  push:
    branches: [ main ]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
    - uses: actions/checkout@v2

    - name: Deploy via FTP
      uses: SamKirkland/FTP-Deploy-Action@4.3.0
      with:
        server: ftp.votredomaine.com
        username: ${{ secrets.FTP_USERNAME }}
        password: ${{ secrets.FTP_PASSWORD }}
        local-dir: ./backend/
        server-dir: /public_html/api/
```

**Note:** Nécessite de configurer les secrets dans GitHub Settings

---

## 📋 ÉTAPE 7: Bonnes Pratiques Git

### Messages de Commit Clairs

```bash
# ✅ BON
git commit -m "Ajout de la validation des emails dans le formulaire d'inscription"
git commit -m "Fix: Correction de l'erreur 500 sur /api/courses"
git commit -m "Refactor: Simplification de la classe Database"

# ❌ MAUVAIS
git commit -m "update"
git commit -m "fix bug"
git commit -m "changes"
```

### Commits Atomiques

Faites des commits petits et logiques:

```bash
# Plutôt que:
git add .
git commit -m "Plein de changements"

# Préférez:
git add backend/routes/auth.php
git commit -m "Amélioration de la sécurité du login"

git add backend/routes/courses.php
git commit -m "Ajout du filtre par catégorie"

git add frontend/js/api.js
git commit -m "Mise à jour de l'URL API en production"
```

### Branches pour Fonctionnalités

```bash
# Nouvelle fonctionnalité
git checkout -b feature/payment-integration

# Bug urgent
git checkout -b hotfix/login-error

# Amélioration
git checkout -b enhancement/performance
```

---

## 🔄 Script d'Automatisation

Créez `deploy.bat` à la racine:

```batch
@echo off
echo ========================================
echo  Deploiement DevDynamics
echo ========================================
echo.

echo [1/4] Verification des changements...
git status

echo.
echo [2/4] Ajout des fichiers...
git add .

echo.
set /p message="Message de commit: "
git commit -m "%message%"

echo.
echo [3/4] Push vers GitHub...
git push origin main

echo.
echo [4/4] Termine!
echo.
echo N'oubliez pas d'uploader sur Hostinger via FTP!
pause
```

Utilisation: Double-cliquez sur `deploy.bat`

---

## 📊 Commandes Git Essentielles

| Commande | Description |
|----------|-------------|
| `git status` | Voir l'état des fichiers |
| `git log` | Historique des commits |
| `git diff` | Voir les modifications |
| `git add <file>` | Ajouter un fichier |
| `git commit -m "msg"` | Créer un commit |
| `git push` | Pousser vers GitHub |
| `git pull` | Récupérer depuis GitHub |
| `git checkout -b <branch>` | Créer une branche |
| `git merge <branch>` | Fusionner une branche |
| `git reset --hard HEAD` | Annuler tous les changements |

---

## 🆘 Problèmes Courants

### "Git n'est pas reconnu"
Installez Git: https://git-scm.com/download/win

### "Permission denied"
Utilisez un Personal Access Token au lieu du mot de passe

### "Rejected - non-fast-forward"
```bash
git pull origin main --rebase
git push origin main
```

### Annuler le dernier commit (pas encore pushé)
```bash
git reset --soft HEAD~1
```

### Voir l'historique graphique
```bash
git log --oneline --graph --all
```

---

## 📚 Ressources

- Git Docs: https://git-scm.com/doc
- GitHub Guides: https://guides.github.com/
- Git Cheat Sheet: https://education.github.com/git-cheat-sheet-education.pdf

---

**Prochaine étape:** Exécutez les commandes de l'ÉTAPE 1 pour réorganiser vos fichiers!
