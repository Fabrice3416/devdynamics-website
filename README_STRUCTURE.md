# DevDynamics - Structure du Projet

## 📁 Structure du Projet

```
DevDynamics-Project/
├── api/                        # Backend PHP (ancien backend/)
│   ├── config/                 # Configuration base de données
│   ├── middleware/             # Authentification, etc.
│   ├── routes/                 # Routes API
│   ├── utils/                  # Utilitaires (JWT, Response, Router)
│   ├── index.php              # Point d'entrée API
│   ├── .htaccess              # Configuration Apache
│   └── .env                   # Configuration environnement (NON COMMITÉ)
│
├── assets/                     # Images et ressources
│   └── images/
│
├── css/                        # Feuilles de style
│   ├── global.css
│   ├── components.css
│   └── pages/                  # Styles par page
│
├── js/                         # JavaScript
│   ├── config.js              # Configuration API
│   ├── api.js                 # Client API
│   ├── utils.js               # Utilitaires
│   └── pages/                  # Scripts par page
│
├── pages/                      # Pages HTML
│   ├── admin-login.html
│   ├── student-login.html
│   └── ...
│
├── index.html                  # Page d'accueil
│
├── .dev/                       # Fichiers de développement (NON DÉPLOYÉS)
│   ├── reorganize-hostinger.php
│   └── scripts/
│
├── .gitignore                  # Fichiers à ignorer
└── README.md                   # Ce fichier
```

## 🎯 Avantages de Cette Structure

### ✅ Compatible Hostinger
Cette structure correspond **exactement** à celle attendue sur Hostinger:
- Pas besoin de réorganiser les fichiers après `git pull`
- Déploiement direct et simple

### ✅ Séparation Claire
- **`api/`** : Tout le code backend
- **Racine** : Tout le code frontend
- **`.dev/`** : Fichiers de développement uniquement

### ✅ Workflow Simplifié

**Développement local:**
```bash
# Développez normalement dans VS Code
# Testez avec WAMP: http://localhost
```

**Commit et push:**
```bash
git add .
git commit -m "Votre message"
git push origin main
```

**Déploiement sur Hostinger:**
```bash
cd ~/public_html
git pull origin main
# C'est tout!
```

## 🔧 Configuration WAMP (Local)

### Lien Symbolique
Pour que WAMP serve le projet, créez un lien symbolique:

```powershell
# PowerShell en Administrateur
New-Item -ItemType SymbolicLink -Path "C:\wamp64\www\api" -Target "C:\Users\brucy\Desktop\DevDynamics-Project\api"
```

### URLs Locales
- Frontend: `http://localhost/`
- API: `http://localhost/api/`
- Admin: `http://localhost/pages/admin-login.html`

## 🌐 Configuration Hostinger (Production)

### Structure sur Hostinger
Après `git pull`, Hostinger aura automatiquement:
```
public_html/
├── api/          ✓ Backend
├── assets/       ✓ Images
├── css/          ✓ Styles
├── js/           ✓ Scripts
├── pages/        ✓ Pages
└── index.html    ✓ Accueil
```

### Fichier .env sur Hostinger
Créez manuellement `api/.env`:
```env
DB_HOST=localhost
DB_USER=u123456789_user
DB_PASSWORD=votre_mot_de_passe
DB_NAME=u123456789_devdynamics
DB_PORT=3306

APP_ENV=production
APP_DEBUG=false
APP_URL=https://votredomaine.com
```

## 📝 Fichiers Importants

### `.gitignore`
Configure les fichiers à ne pas commiter:
- `.env` (informations sensibles)
- `.dev/*` (fichiers de développement)
- `*.sql` (exports de base de données)
- `backup-*` (sauvegardes)

### `api/index.php`
Point d'entrée de l'API:
- Gère le routing
- Configure CORS
- Charge les routes

### `js/config.js`
Détection automatique de l'environnement:
- Local: `http://localhost/api`
- Production: `https://votredomaine.com/api`

## 🚀 Migration depuis l'Ancienne Structure

Si vous venez de l'ancienne structure (avec `frontend/` et `backend/`), consultez [INSTRUCTIONS_MIGRATION.md](INSTRUCTIONS_MIGRATION.md).

## ✅ Vérification

Pour vérifier que la structure est correcte:
```powershell
.\verify-structure.ps1
```

## 📚 Documentation Supplémentaire

- [INSTALLATION_HOSTINGER.md](INSTALLATION_HOSTINGER.md) - Guide de déploiement
- [INSTRUCTIONS_MIGRATION.md](INSTRUCTIONS_MIGRATION.md) - Migration depuis l'ancienne structure

## 🤝 Contribution

Lors de l'ajout de nouvelles fonctionnalités:

1. **Backend (API)**: Ajoutez dans `api/routes/`
2. **Frontend**: Ajoutez dans `pages/` et `js/pages/`
3. **Styles**: Ajoutez dans `css/pages/`
4. **Testez localement** avant de pousser
5. **Commitez avec un message clair**

## 🔒 Sécurité

⚠️ **Ne commitez JAMAIS:**
- Fichiers `.env` avec de vraies informations
- Exports de base de données avec données réelles
- Mots de passe ou clés API

✅ **Utilisez plutôt:**
- `.env.example` avec des valeurs de template
- Scripts d'initialisation sans données sensibles
