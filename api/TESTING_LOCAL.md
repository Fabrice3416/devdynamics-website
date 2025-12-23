# Guide de Test Local - Backend PHP

Ce guide explique comment tester le backend PHP localement sur Windows avant le déploiement sur Hostinger.

## Méthode 1: Serveur PHP Intégré (Recommandé)

### Prérequis
- PHP 7.4+ installé sur votre machine
- MySQL ou XAMPP installé

### Vérifier si PHP est installé

Ouvrez PowerShell ou CMD et tapez:

```bash
php -v
```

Si PHP n'est pas installé, installez XAMPP: https://www.apachefriends.org/

### Étapes

#### 1. Configurer la Base de Données

**Option A: Avec XAMPP**
1. Lancez XAMPP Control Panel
2. Démarrez Apache et MySQL
3. Ouvrez phpMyAdmin: http://localhost/phpmyadmin
4. Créez une base de données `devdynamics_db`
5. Importez le schéma depuis `backend/database/schema.sql`

**Option B: MySQL direct**
```bash
# Connectez-vous à MySQL
mysql -u root -p

# Créez la base de données
CREATE DATABASE devdynamics_db;

# Importez le schéma
mysql -u root -p devdynamics_db < backend/database/schema.sql
```

#### 2. Créer le fichier .env

Créez le fichier `.env` dans `backend-php/`:

```env
# Database Local
DB_HOST=localhost
DB_USER=root
DB_PASSWORD=
DB_NAME=devdynamics_db
DB_PORT=3306

# JWT Configuration
JWT_SECRET=test_local_secret_key_12345
JWT_EXPIRY=604800

# Application Settings
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8000
```

#### 3. Créer un utilisateur admin de test

Créez un fichier `backend-php/create-admin.php`:

```php
<?php
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance();

$email = 'admin@test.com';
$password = 'admin123';
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

try {
    $db->query(
        "INSERT INTO users (name, email, password, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
        ['Admin Test', $email, $hashedPassword]
    );

    echo "Admin créé avec succès!\n";
    echo "Email: $email\n";
    echo "Password: $password\n";
} catch (Exception $e) {
    echo "Erreur: " . $e->getMessage() . "\n";
}
```

Exécutez:
```bash
php create-admin.php
```

#### 4. Démarrer le Serveur PHP

Ouvrez PowerShell ou CMD dans le dossier `backend-php`:

```bash
cd c:\Users\brucy\OneDrive\Bureau\devdynamics-website\backend-php
php -S localhost:8000
```

Vous devriez voir:
```
[Wed Dec 16 10:00:00 2025] PHP 8.x.x Development Server (http://localhost:8000) started
```

**Le serveur est maintenant actif sur http://localhost:8000**

## Méthode 2: Avec XAMPP

### Étapes

1. **Installer XAMPP** (si pas déjà fait)

2. **Copier les fichiers**
   - Copiez le dossier `backend-php` dans `C:\xampp\htdocs\`
   - Renommez en `api` (optionnel)

3. **Structure:**
   ```
   C:\xampp\htdocs\api\
   ├── index.php
   ├── .htaccess
   ├── .env
   └── ...
   ```

4. **Modifier .htaccess**

   Dans `backend-php/.htaccess`, changez:
   ```apache
   RewriteBase /api/
   ```

5. **Démarrer XAMPP**
   - Lancez Apache
   - Lancez MySQL

6. **Accéder à l'API**
   ```
   http://localhost/api/courses
   ```

## Tests de l'API

### Test 1: Route de Base

Ouvrez votre navigateur:
```
http://localhost:8000/api/courses
```

Vous devriez voir un JSON (vide si pas de cours):
```json
{
  "success": true,
  "message": "Success",
  "data": []
}
```

### Test 2: Organization Info

```
http://localhost:8000/api/organization
```

### Test 3: Login Admin (avec outil HTTP)

#### Avec PowerShell (Windows 10+):

```powershell
$body = @{
    email = "admin@test.com"
    password = "admin123"
} | ConvertTo-Json

$response = Invoke-WebRequest -Uri "http://localhost:8000/api/auth/login" `
    -Method POST `
    -ContentType "application/json" `
    -Body $body

$response.Content
```

#### Avec curl (si installé):

```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"admin@test.com\",\"password\":\"admin123\"}"
```

Réponse attendue:
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "user": {
      "id": 1,
      "email": "admin@test.com",
      "name": "Admin Test",
      "role": "admin"
    }
  }
}
```

### Test 4: Route Protégée (avec token)

Copiez le token obtenu et testez:

```powershell
$token = "VOTRE_TOKEN_ICI"

$response = Invoke-WebRequest -Uri "http://localhost:8000/api/students/profile" `
    -Method GET `
    -Headers @{
        "Authorization" = "Bearer $token"
        "Content-Type" = "application/json"
    }

$response.Content
```

## Outils de Test Recommandés

### 1. Postman (Le plus simple)

1. Téléchargez Postman: https://www.postman.com/downloads/
2. Importez cette collection:

**Collection JSON à sauvegarder** (`DevDynamics-Local.postman_collection.json`):

```json
{
  "info": {
    "name": "DevDynamics Local API",
    "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
  },
  "item": [
    {
      "name": "Auth",
      "item": [
        {
          "name": "Login",
          "request": {
            "method": "POST",
            "header": [],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"email\": \"admin@test.com\",\n  \"password\": \"admin123\"\n}",
              "options": {
                "raw": {
                  "language": "json"
                }
              }
            },
            "url": {
              "raw": "http://localhost:8000/api/auth/login",
              "protocol": "http",
              "host": ["localhost"],
              "port": "8000",
              "path": ["api", "auth", "login"]
            }
          }
        }
      ]
    },
    {
      "name": "Courses",
      "item": [
        {
          "name": "Get All Courses",
          "request": {
            "method": "GET",
            "header": [],
            "url": {
              "raw": "http://localhost:8000/api/courses",
              "protocol": "http",
              "host": ["localhost"],
              "port": "8000",
              "path": ["api", "courses"]
            }
          }
        }
      ]
    }
  ]
}
```

### 2. Thunder Client (Extension VS Code)

1. Installez l'extension "Thunder Client" dans VS Code
2. Créez des requêtes directement dans l'éditeur

### 3. Navigateur + DevTools

Pour les routes GET simples:
1. Ouvrez Chrome/Edge
2. F12 pour ouvrir DevTools
3. Allez dans l'onglet Network
4. Testez les URLs

## Tests Complets - Checklist

### Routes Publiques ✓
- [ ] `GET /api/courses` - Liste des cours
- [ ] `GET /api/programs` - Liste des programmes
- [ ] `GET /api/blog` - Articles de blog
- [ ] `GET /api/organization` - Info organisation
- [ ] `GET /api/testimonials` - Témoignages
- [ ] `GET /api/sponsors` - Sponsors
- [ ] `POST /api/contact` - Formulaire de contact
- [ ] `POST /api/donations` - Faire un don

### Routes Auth ✓
- [ ] `POST /api/auth/login` - Login admin
- [ ] `POST /api/students/register` - Inscription étudiant
- [ ] `POST /api/students/login` - Login étudiant

### Routes Protégées (nécessite token) ✓
- [ ] `GET /api/students/profile` - Profil étudiant
- [ ] `PUT /api/students/profile` - Modifier profil
- [ ] `GET /api/students/enrollments` - Inscriptions

### Routes Admin (nécessite role admin) ✓
- [ ] `GET /api/admin/dashboard/stats` - Statistiques
- [ ] `POST /api/courses` - Créer un cours
- [ ] `POST /api/blog` - Créer un article

## Scripts de Test Automatique

Créez `test-api.php` dans `backend-php/`:

```php
<?php
echo "=== Test Backend PHP ===\n\n";

// Test 1: Database Connection
echo "Test 1: Connexion Base de Données\n";
try {
    require_once __DIR__ . '/config/database.php';
    $db = Database::getInstance();
    echo "✓ Connexion réussie\n\n";
} catch (Exception $e) {
    echo "✗ Erreur: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 2: JWT
echo "Test 2: JWT Token\n";
try {
    require_once __DIR__ . '/utils/JWT.php';
    $token = JWT::encode(['user_id' => 1, 'test' => true]);
    $decoded = JWT::decode($token);
    echo "✓ JWT fonctionne\n";
    echo "Token: " . substr($token, 0, 50) . "...\n\n";
} catch (Exception $e) {
    echo "✗ Erreur JWT: " . $e->getMessage() . "\n\n";
}

// Test 3: Routes
echo "Test 3: Vérification des fichiers de routes\n";
$routes = ['auth', 'students', 'courses', 'blog', 'admin'];
foreach ($routes as $route) {
    $file = __DIR__ . "/routes/$route.php";
    if (file_exists($file)) {
        echo "✓ routes/$route.php\n";
    } else {
        echo "✗ routes/$route.php manquant\n";
    }
}

echo "\n=== Tests terminés ===\n";
```

Exécutez:
```bash
php test-api.php
```

## Dépannage

### Erreur: "Call to undefined function password_hash()"
- Votre version de PHP est trop ancienne
- Installez PHP 7.4+

### Erreur: "Connection refused"
- Vérifiez que MySQL est démarré
- Vérifiez les credentials dans `.env`

### Erreur 404 sur toutes les routes
- Le serveur PHP intégré gère mal le routing
- Utilisez directement: `http://localhost:8000/index.php/api/courses`
- Ou utilisez XAMPP avec .htaccess

### "No such file .env"
- Créez le fichier `.env` depuis `.env.example`
- Assurez-vous qu'il est dans le dossier `backend-php/`

### JSON mal formaté
- Vérifiez qu'il n'y a pas d'espace ou de BOM avant `<?php`
- Vérifiez les erreurs PHP dans la console

## Passer en Production

Une fois les tests locaux réussis:

1. Exportez votre base de données:
   ```bash
   mysqldump -u root -p devdynamics_db > backup.sql
   ```

2. Suivez le guide de déploiement Hostinger dans `README.md`

3. Importez les données sur Hostinger

4. Testez les mêmes endpoints sur votre domaine

## Support

Si vous rencontrez des problèmes:
1. Vérifiez les erreurs PHP dans la console
2. Activez le debug dans `.env`: `APP_DEBUG=true`
3. Vérifiez les logs dans `php -S` (affichés dans la console)
