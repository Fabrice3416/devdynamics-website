# Guide de Déploiement Rapide - Hostinger

## Étapes Simplifiées

### 1. Préparation de la Base de Données (5 min)

1. Connectez-vous au panneau Hostinger
2. **Bases de données MySQL** → **Créer une nouvelle base**
3. Notez les informations:
   - Nom de la base: `u123456789_devdynamics` (exemple)
   - Utilisateur: `u123456789_user` (exemple)
   - Mot de passe: (celui que vous avez créé)
   - Hôte: `localhost`

4. **phpMyAdmin** → Sélectionnez votre base → **Importer**
   - Exportez d'abord votre base locale depuis WAMP
   - Importez le fichier SQL

### 2. Upload des Fichiers (10 min)

#### Via Gestionnaire de fichiers Hostinger:

**Structure finale:**
```
public_html/
├── api/                    ← Tout le contenu de backend/
│   ├── config/
│   ├── middleware/
│   ├── routes/
│   ├── utils/
│   ├── .env               ← À MODIFIER
│   ├── .htaccess
│   └── index.php
├── assets/                 ← De frontend/assets/
├── css/                    ← De frontend/css/
├── js/                     ← De frontend/js/
├── pages/                  ← De frontend/pages/
└── index.html              ← De frontend/index.html
```

**Actions:**
1. Supprimez le contenu actuel de `public_html/` (sauf les fichiers système)
2. Uploadez tout le contenu de `frontend/` directement dans `public_html/`
3. Créez le dossier `public_html/api/`
4. Uploadez tout le contenu de `backend/` dans `public_html/api/`

### 3. Configuration du .env (2 min)

Dans `public_html/api/.env`, modifiez:

```env
# Database Configuration
DB_HOST=localhost
DB_USER=u123456789_user          ← Votre utilisateur MySQL Hostinger
DB_PASSWORD=VotreMotDePasse       ← Votre mot de passe MySQL
DB_NAME=u123456789_devdynamics   ← Votre nom de base de données
DB_PORT=3306

# JWT Configuration (garder tel quel)
JWT_SECRET=e49986cb134cae935c59ef3d6d5c204e6b8a152c032c5e74246c2b9a5f57d958
JWT_EXPIRY=604800

# Application Settings
APP_ENV=production                ← Changer de development à production
APP_DEBUG=false                   ← Changer de true à false
APP_URL=https://votredomaine.com  ← Votre domaine Hostinger
```

### 4. Vérification (2 min)

Testez ces URLs dans votre navigateur:

1. `https://votredomaine.com` → Page d'accueil
2. `https://votredomaine.com/api/organization/info` → Devrait retourner du JSON
3. `https://votredomaine.com/api/courses` → Liste des cours

### 5. Créer un Admin (3 min)

1. Créez un fichier temporaire `public_html/api/setup-admin.php`:

```php
<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/utils/Response.php';

$email = 'admin@votredomaine.com';
$password = 'VotreMotDePasseSecurise123!';
$fullName = 'Admin DevDynamics';

try {
    $db = Database::getInstance();

    // Vérifier si l'admin existe déjà
    $existing = $db->fetchOne("SELECT id FROM users WHERE email = ?", [$email]);

    if ($existing) {
        echo "Admin existe déjà!";
        exit;
    }

    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    $db->query(
        "INSERT INTO users (full_name, email, password_hash, role, is_verified, created_at, updated_at)
         VALUES (?, ?, ?, 'admin', 1, NOW(), NOW())",
        [$fullName, $email, $hashedPassword]
    );

    echo "✓ Admin créé avec succès!\n";
    echo "Email: $email\n";
    echo "Mot de passe: $password\n\n";
    echo "IMPORTANT: Supprimez ce fichier maintenant!";

} catch (Exception $e) {
    echo "Erreur: " . $e->getMessage();
}
?>
```

2. Accédez à `https://votredomaine.com/api/setup-admin.php`
3. **SUPPRIMEZ immédiatement** le fichier `setup-admin.php` après utilisation

### 6. Activer HTTPS (1 min)

Dans le panneau Hostinger:
1. **SSL** → Activez le certificat SSL gratuit (Let's Encrypt)
2. Attendez 5-10 minutes que le certificat soit activé

Créez `public_html/.htaccess` pour forcer HTTPS:

```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

## Checklist Finale

- [ ] Base de données créée et importée
- [ ] Fichiers uploadés (frontend à la racine, backend dans api/)
- [ ] `.env` configuré avec vos identifiants
- [ ] Tests des URLs effectués
- [ ] Admin créé et `setup-admin.php` supprimé
- [ ] SSL activé
- [ ] HTTPS forcé via `.htaccess`

## Dépannage Rapide

### Erreur 500
→ Vérifiez le fichier `.env` et les logs dans le panneau Hostinger

### Page blanche
→ Vérifiez que `index.html` est bien à la racine de `public_html/`

### API ne répond pas
→ Vérifiez que `.htaccess` existe dans `public_html/api/`

### Erreur de base de données
→ Vérifiez les identifiants dans `.env`

## Export de la Base de Données Locale

Pour exporter votre base depuis WAMP:

```bash
cd C:\wamp64\bin\mysql\mysql8.0.27\bin
mysql.exe -u root devdynamics_db --skip-column-names -e "SHOW TABLES" | findstr /v "Tables_in" > tables.txt
mysqldump.exe -u root devdynamics_db > devdynamics_export.sql
```

Le fichier `devdynamics_export.sql` sera créé, importez-le sur Hostinger.

## Support

Si vous rencontrez des problèmes, consultez le fichier [DEPLOIEMENT_HOSTINGER.md](docs/DEPLOIEMENT_HOSTINGER.md) pour plus de détails.
