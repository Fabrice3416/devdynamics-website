# Installation sur Hostinger - Guide Complet

## Problème: Structure Git vs Structure Hostinger

Après avoir cloné le dépôt Git sur Hostinger, vous avez cette structure:

```
public_html/
├── frontend/
│   ├── assets/
│   ├── css/
│   ├── js/
│   ├── pages/
│   └── index.html
├── backend/
│   ├── config/
│   ├── routes/
│   ├── middleware/
│   ├── utils/
│   ├── index.php
│   └── .env
├── docs/
├── README.md
└── ... autres fichiers du repo
```

Mais Hostinger a besoin de cette structure:

```
public_html/
├── api/                    ← backend renommé
│   ├── config/
│   ├── routes/
│   ├── middleware/
│   ├── utils/
│   ├── index.php
│   └── .env
├── assets/                 ← de frontend/
├── css/                    ← de frontend/
├── js/                     ← de frontend/
├── pages/                  ← de frontend/
└── index.html              ← de frontend/
```

## Solution 1: Script Automatique (Recommandé)

### Étape 1: Upload du script

1. Uploadez le fichier `reorganize-hostinger.php` à la racine de `public_html/`
2. Accédez à `https://votredomaine.com/reorganize-hostinger.php` dans votre navigateur
3. Le script va automatiquement:
   - Déplacer le contenu de `frontend/` à la racine
   - Renommer `backend/` en `api/`
   - Supprimer les dossiers inutiles (`docs/`, `.git/`, etc.)
   - Afficher la structure finale

4. **Supprimez** le fichier `reorganize-hostinger.php` après utilisation

### Étape 2: Configuration de la base de données

1. Dans le panneau Hostinger, créez une base de données MySQL
2. Notez les informations:
   ```
   Nom de la base: u123456789_devdynamics
   Utilisateur: u123456789_user
   Mot de passe: [votre mot de passe]
   Hôte: localhost
   ```

3. Modifiez le fichier `api/.env` via le Gestionnaire de fichiers:
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

### Étape 3: Importer la base de données

1. Allez dans **phpMyAdmin** dans le panneau Hostinger
2. Sélectionnez votre base de données
3. Cliquez sur **Importer**
4. Uploadez le fichier `devdynamics_export.sql`
5. Cliquez sur **Exécuter**

### Étape 4: Créer un utilisateur admin

1. Créez un fichier `api/setup-admin.php`:

```php
<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/utils/Response.php';

$email = 'admin@votredomaine.com';
$password = 'MotDePasseSecurise123!';
$fullName = 'Admin DevDynamics';

try {
    $db = Database::getInstance();

    $existing = $db->fetchOne("SELECT id FROM users WHERE email = ?", [$email]);
    if ($existing) {
        die("Admin existe déjà!");
    }

    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    $db->query(
        "INSERT INTO users (full_name, email, password_hash, role, is_verified, created_at, updated_at)
         VALUES (?, ?, ?, 'admin', 1, NOW(), NOW())",
        [$fullName, $email, $hashedPassword]
    );

    echo "✓ Admin créé!\n";
    echo "Email: $email\n";
    echo "Mot de passe: $password\n\n";
    echo "SUPPRIMEZ ce fichier maintenant!";

} catch (Exception $e) {
    echo "Erreur: " . $e->getMessage();
}
?>
```

2. Accédez à `https://votredomaine.com/api/setup-admin.php`
3. **SUPPRIMEZ** immédiatement `api/setup-admin.php`

### Étape 5: Test

Testez ces URLs:
- `https://votredomaine.com` → Page d'accueil
- `https://votredomaine.com/api/organization/info` → Info organisation
- `https://votredomaine.com/pages/admin-login.html` → Login admin

## Solution 2: Réorganisation Manuelle

Si vous préférez faire manuellement via le Gestionnaire de fichiers:

### Étapes:

1. **Créez le dossier `api/`** à la racine de `public_html/`

2. **Déplacez** tout le contenu de `backend/` dans `api/`:
   - Sélectionnez tous les fichiers dans `backend/`
   - Coupez (Ctrl+X)
   - Ouvrez `api/`
   - Collez (Ctrl+V)

3. **Déplacez** le contenu de `frontend/` à la racine:
   - Ouvrez `frontend/`
   - Sélectionnez: `assets/`, `css/`, `js/`, `pages/`, `index.html`
   - Coupez (Ctrl+X)
   - Retournez à `public_html/`
   - Collez (Ctrl+V)

4. **Supprimez** les dossiers vides:
   - `backend/` (maintenant vide)
   - `frontend/` (maintenant vide)
   - `docs/`
   - `.git/` (optionnel, pour économiser de l'espace)

5. **Supprimez** les fichiers de développement:
   - `README.md`
   - `DEPLOIEMENT_RAPIDE.md`
   - `deploy-hostinger.sh`
   - `.gitignore`
   - Tous les `.md` sauf si vous voulez les garder

## Solution 3: Utiliser Git Deploy Correctement

Pour éviter ce problème à l'avenir, vous pouvez utiliser un hook Git qui réorganise automatiquement:

### Créer un hook post-receive

1. Dans votre projet local, créez `.git-hooks/post-receive`:

```bash
#!/bin/bash
# Hook exécuté après git pull sur Hostinger

cd $HOME/public_html

# Réorganiser automatiquement
php reorganize-hostinger.php

echo "✓ Déploiement terminé!"
```

2. Dans le panneau Hostinger Git, configurez ce hook

## Vérification Finale

Structure correcte dans `public_html/`:

```
✓ api/
  ✓ config/
  ✓ routes/
  ✓ middleware/
  ✓ utils/
  ✓ index.php
  ✓ .htaccess
  ✓ .env (configuré avec vos identifiants)

✓ assets/
✓ css/
✓ js/
✓ pages/
✓ index.html
```

Fichiers à supprimer après installation:
```
✗ backend/
✗ frontend/
✗ docs/
✗ reorganize-hostinger.php
✗ setup-admin.php
✗ devdynamics_export.sql
```

## Dépannage

### Le script ne fonctionne pas
→ Vérifiez les permissions (755 pour les dossiers, 644 pour les fichiers)
→ Exécutez via SSH si vous y avez accès:
```bash
cd ~/public_html
php reorganize-hostinger.php
```

### Erreur de permissions
→ Dans le Gestionnaire de fichiers, faites clic droit → Permissions → 755

### Page blanche après réorganisation
→ Vérifiez que `index.html` est bien à la racine de `public_html/`
→ Vérifiez les logs d'erreur dans le panneau Hostinger

## Méthode Alternative: FTP Direct

Si Git pose trop de problèmes, vous pouvez aussi:

1. **Téléchargez** FileZilla ou un autre client FTP
2. **Connectez-vous** avec vos identifiants FTP Hostinger
3. **Uploadez** directement:
   - Contenu de `frontend/` → `public_html/`
   - Contenu de `backend/` → `public_html/api/`
4. Pas besoin de réorganisation!

## Support

Pour plus d'aide, consultez:
- [DEPLOIEMENT_RAPIDE.md](DEPLOIEMENT_RAPIDE.md)
- Documentation Hostinger: https://support.hostinger.com
- Support Hostinger via le panneau de contrôle
