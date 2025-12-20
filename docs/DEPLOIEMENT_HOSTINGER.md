# Guide de Déploiement sur Hostinger

## Étape 1: Préparation de la Base de Données

1. Connectez-vous à votre compte Hostinger
2. Allez dans **Bases de données MySQL**
3. Créez une nouvelle base de données:
   - Nom de la base: `devdynamics_db` (ou autre nom de votre choix)
   - Notez: le nom d'utilisateur, le mot de passe, et l'hôte

4. Accédez à **phpMyAdmin**
5. Sélectionnez votre base de données
6. Importez votre base de données existante ou créez les tables

## Étape 2: Configuration du Backend PHP

1. Via le **Gestionnaire de fichiers** ou **FTP**:
   - Téléchargez tout le contenu du dossier `c:\wamp64\www\api\` dans le dossier `public_html/api/`

2. Structure finale sur Hostinger:
   ```
   public_html/
   ├── api/
   │   ├── config/
   │   │   └── database.php
   │   ├── utils/
   │   ├── routes/
   │   ├── middleware/
   │   ├── index.php
   │   ├── .htaccess
   │   └── .env
   ├── index.html
   ├── assets/
   ├── css/
   ├── js/
   └── pages/
   ```

3. **Modifiez le fichier `.env`** sur Hostinger avec vos informations:
   ```env
   # Database Configuration (Hostinger MySQL)
   DB_HOST=localhost
   DB_USER=u123456789_dbuser
   DB_PASSWORD=VotreMotDePasse
   DB_NAME=u123456789_devdynamics
   DB_PORT=3306

   # JWT Configuration
   JWT_SECRET=e49986cb134cae935c59ef3d6d5c204e6b8a152c032c5e74246c2b9a5f57d958
   JWT_EXPIRY=604800

   # Application Settings
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://votredomaine.com
   ```

## Étape 3: Configuration du Frontend

1. Téléchargez tous les fichiers du frontend dans `public_html/`:
   - index.html
   - assets/
   - css/
   - js/
   - pages/

2. **Modifiez `js/api.js`** pour pointer vers votre domaine:
   ```javascript
   // Production
   const API_BASE_URL = 'https://votredomaine.com/api';
   ```

   OU utilisez la détection automatique avec `js/config.js`:
   ```javascript
   function getAPIBaseURL() {
       const hostname = window.location.hostname;

       // Production
       if (hostname !== 'localhost' && hostname !== '127.0.0.1') {
           return 'https://votredomaine.com/api';
       }

       // Local
       return 'http://localhost/api';
   }
   ```

## Étape 4: Vérification du fichier .htaccess

Assurez-vous que le fichier `api/.htaccess` est présent:

```apache
RewriteEngine On
RewriteBase /api/

# Security headers
Header set X-Content-Type-Options "nosniff"
Header set X-Frame-Options "SAMEORIGIN"
Header set X-XSS-Protection "1; mode=block"

# PHP settings
php_flag display_errors Off
php_value upload_max_filesize 10M
php_value post_max_size 10M

# Redirect all requests to index.php
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]
```

## Étape 5: Créer un Utilisateur Admin

1. Créez un fichier `create-admin-hostinger.php` dans `public_html/api/`:

```php
<?php
require_once __DIR__ . '/config/database.php';

$email = 'admin@votredomaine.com';
$password = 'MotDePasseSecurise123!';
$fullName = 'Admin';

try {
    $db = Database::getInstance();
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    $db->query(
        "INSERT INTO users (full_name, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
        [$fullName, $email, $hashedPassword]
    );

    echo "Admin créé avec succès!\n";
    echo "Email: $email\n";

    // IMPORTANT: Supprimez ce fichier après utilisation
} catch (Exception $e) {
    echo "Erreur: " . $e->getMessage();
}
?>
```

2. Accédez à `https://votredomaine.com/api/create-admin-hostinger.php`
3. **IMPORTANT: Supprimez ce fichier immédiatement après**

## Étape 6: Test de l'API

Testez les endpoints suivants:

1. `https://votredomaine.com/api/courses` - Devrait retourner la liste des cours
2. `https://votredomaine.com/api/organization/info` - Infos de l'organisation
3. `https://votredomaine.com/api/auth/login` - Page de login admin

## Étape 7: Configuration SSL (HTTPS)

Hostinger active automatiquement SSL. Assurez-vous que:
1. Le certificat SSL est actif dans le panneau de contrôle
2. Forcez HTTPS en ajoutant dans `.htaccess` à la racine:

```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

## Dépannage Courant

### Erreur 500
- Vérifiez les logs d'erreur dans le panneau Hostinger
- Assurez-vous que le fichier `.env` existe avec les bonnes informations
- Vérifiez les permissions des fichiers (644 pour les fichiers, 755 pour les dossiers)

### Erreur de connexion à la base de données
- Vérifiez les identifiants dans `.env`
- Sur Hostinger, l'hôte est généralement `localhost`
- Le nom d'utilisateur et de base de données commencent souvent par `u123456789_`

### Routes ne fonctionnent pas (404)
- Vérifiez que `.htaccess` est présent dans `api/`
- Assurez-vous que `mod_rewrite` est activé (généralement activé par défaut sur Hostinger)

### CORS Errors
- Les headers CORS sont déjà configurés dans `index.php`
- Si problème persiste, ajoutez votre domaine dans les headers

## Checklist de Déploiement

- [ ] Base de données créée sur Hostinger
- [ ] Tables importées/créées
- [ ] Fichiers backend uploadés dans `public_html/api/`
- [ ] Fichiers frontend uploadés dans `public_html/`
- [ ] `.env` configuré avec les bonnes informations
- [ ] `.htaccess` présent et configuré
- [ ] URL API mise à jour dans `js/api.js`
- [ ] Utilisateur admin créé
- [ ] SSL activé et forcé
- [ ] Tests des endpoints effectués
- [ ] `create-admin-hostinger.php` supprimé

## Support

En cas de problème:
1. Consultez les logs d'erreur dans le panneau Hostinger
2. Vérifiez le fichier `php_error.log` dans votre dossier `api/`
3. Contactez le support Hostinger si nécessaire
