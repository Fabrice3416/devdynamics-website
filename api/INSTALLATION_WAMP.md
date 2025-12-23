# Installation avec WAMP

Tu as déjà WAMP installé, parfait ! Voici les étapes rapides.

## Étape 1: Démarrer WAMP

1. **Lance WAMP**
   - Cherche "WAMP" dans le menu Démarrer
   - Ou lance `C:\wamp64\wampmanager.exe` (ou `C:\wamp\wampmanager.exe`)

2. **Vérifie que WAMP est vert**
   - L'icône WAMP dans la barre de tâches doit être **verte**
   - Si orange ou rouge, clique dessus et "Start All Services"

## Étape 2: Copier les Fichiers Backend

1. **Ouvre l'Explorateur Windows**

2. **Va dans le dossier www de WAMP:**
   - Si WAMP64: `C:\wamp64\www\`
   - Si WAMP32: `C:\wamp\www\`

3. **Crée un dossier `api`**

4. **Copie tout le contenu de `backend-php` dans `www\api\`**

   Tu devrais avoir:
   ```
   C:\wamp64\www\api\
   ├── index.php
   ├── .htaccess
   ├── .env.example
   ├── config\
   ├── middleware\
   ├── utils\
   └── routes\
   ```

## Étape 3: Créer la Base de Données

1. **Clique sur l'icône WAMP** (barre de tâches)

2. **Sélectionne "phpMyAdmin"**
   - Ou va sur: http://localhost/phpmyadmin

3. **Crée la base de données:**
   - Clique sur "Nouvelle base de données"
   - Nom: `devdynamics_db`
   - Interclassement: `utf8mb4_general_ci`
   - Clique "Créer"

4. **Importe le schéma:**
   - Clique sur `devdynamics_db` (colonne gauche)
   - Onglet "Importer"
   - Parcourir → Sélectionne:
     ```
     C:\Users\brucy\OneDrive\Bureau\devdynamics-website\backend\database\schema.sql
     ```
   - Clique "Exécuter"

## Étape 4: Configurer .env

1. **Va dans `C:\wamp64\www\api\`**

2. **Copie `.env.example` → `.env`**

3. **Édite `.env` avec Notepad:**
   ```env
   # Database Configuration
   DB_HOST=localhost
   DB_USER=root
   DB_PASSWORD=
   DB_NAME=devdynamics_db
   DB_PORT=3306

   # JWT Configuration
   JWT_SECRET=votre_secret_local_test_12345
   JWT_EXPIRY=604800

   # Application Settings
   APP_ENV=development
   APP_DEBUG=true
   APP_URL=http://localhost
   ```

   **Note:** Le mot de passe par défaut de WAMP est vide (rien)

## Étape 5: Vérifier .htaccess

Dans `C:\wamp64\www\api\.htaccess`, vérifie:

```apache
RewriteBase /api/
```

## Étape 6: Créer un Admin

1. **Ouvre PowerShell ou CMD**

2. **Va dans le dossier api:**
   ```bash
   cd C:\wamp64\www\api
   ```

3. **Trouve où est PHP dans WAMP:**
   ```bash
   # WAMP64:
   C:\wamp64\bin\php\php8.2.0\php.exe create-admin.php

   # Ou WAMP32:
   C:\wamp\bin\php\php8.2.0\php.exe create-admin.php
   ```

   **Note:** Le numéro de version PHP peut varier (php8.1.0, php8.2.0, etc.)

4. **Pour trouver ta version PHP:**
   - Clique sur l'icône WAMP
   - PHP > Version
   - Note la version active

## Étape 7: Tester l'API

**Assure-toi que WAMP est vert (tous les services démarrés)**

**Test dans le navigateur:**

1. **Liste des cours:**
   ```
   http://localhost/api/courses
   ```

2. **Organization:**
   ```
   http://localhost/api/organization
   ```

3. **Programs:**
   ```
   http://localhost/api/programs
   ```

**Test Login avec PowerShell:**
```powershell
$body = @{
    email = "admin@test.com"
    password = "admin123"
} | ConvertTo-Json

$response = Invoke-WebRequest -Uri "http://localhost/api/auth/login" `
    -Method POST `
    -ContentType "application/json" `
    -Body $body

$response.Content
```

## Script PowerShell pour Créer l'Admin

Si tu ne trouves pas le chemin de PHP, utilise ce script:

**Crée un fichier `create-admin-wamp.ps1` dans `backend-php`:**

```powershell
# Trouver PHP dans WAMP
$phpPaths = @(
    "C:\wamp64\bin\php\php*\php.exe",
    "C:\wamp\bin\php\php*\php.exe"
)

$phpExe = $null
foreach ($pattern in $phpPaths) {
    $found = Get-ChildItem $pattern -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($found) {
        $phpExe = $found.FullName
        break
    }
}

if ($phpExe) {
    Write-Host "PHP trouvé: $phpExe"
    Write-Host "Création de l'admin..."
    & $phpExe create-admin.php
} else {
    Write-Host "PHP introuvable dans WAMP"
    Write-Host "Vérifiez que WAMP est installé"
}
```

**Exécute:**
```powershell
cd C:\Users\brucy\OneDrive\Bureau\devdynamics-website\backend-php
powershell -ExecutionPolicy Bypass -File create-admin-wamp.ps1
```

## Dépannage WAMP

### WAMP reste orange

**Cause:** Port 80 occupé (souvent par Skype, IIS, ou autre)

**Solution 1 - Via WAMP:**
1. Clique icône WAMP > Outils > Tester le port 80
2. Si occupé, change le port:
   - Clique WAMP > Apache > httpd.conf
   - Cherche `Listen 80` → Change en `Listen 8080`
   - Cherche `ServerName localhost:80` → Change en `localhost:8080`
   - Redémarre WAMP
   - Teste sur: http://localhost:8080/api/courses

**Solution 2 - Libérer le port 80:**
```powershell
# Voir qui utilise le port 80
netstat -ano | findstr :80

# Arrêter le processus (remplace PID par le numéro trouvé)
taskkill /PID <PID> /F
```

### MySQL ne démarre pas

1. Clique WAMP > MySQL > Service > Installer le service
2. Redémarre WAMP

### Erreur "Forbidden" ou "You don't have permission"

1. Clique WAMP > Apache > httpd-vhosts.conf
2. Ou édite: `C:\wamp64\bin\apache\apache2.x.x\conf\extra\httpd-vhosts.conf`
3. Ajoute:
   ```apache
   <VirtualHost *:80>
       ServerName localhost
       DocumentRoot "c:/wamp64/www"
       <Directory "c:/wamp64/www/">
           Options +Indexes +FollowSymLinks +MultiViews
           AllowOverride All
           Require local
       </Directory>
   </VirtualHost>
   ```

## URLs Importantes avec WAMP

- **API:** http://localhost/api/
- **phpMyAdmin:** http://localhost/phpmyadmin
- **WAMP Dashboard:** http://localhost

## Chemins WAMP

```
WAMP64:
├── C:\wamp64\www\              ← Tes fichiers web ici
├── C:\wamp64\bin\php\          ← PHP
├── C:\wamp64\bin\mysql\        ← MySQL
└── C:\wamp64\bin\apache\       ← Apache

WAMP32:
├── C:\wamp\www\                ← Tes fichiers web ici
├── C:\wamp\bin\php\            ← PHP
└── ...
```

## Commandes PHP avec WAMP

**Trouver la version PHP active:**
```bash
# WAMP64 exemple
C:\wamp64\bin\php\php8.2.0\php.exe -v
```

**Exécuter des scripts:**
```bash
cd C:\wamp64\www\api
C:\wamp64\bin\php\php8.2.0\php.exe test-api.php
C:\wamp64\bin\php\php8.2.0\php.exe create-admin.php
```

## Astuce: Ajouter PHP au PATH

Pour utiliser `php` directement sans le chemin complet:

1. Clique droit sur "Ce PC" > Propriétés
2. Paramètres système avancés
3. Variables d'environnement
4. Dans "Variables système", sélectionne "Path"
5. Clique "Modifier"
6. Ajoute: `C:\wamp64\bin\php\php8.2.0` (adapte la version)
7. Redémarre PowerShell

Ensuite tu pourras faire simplement:
```bash
php -v
php create-admin.php
```

## C'est tout !

Une fois que tout fonctionne:
1. Teste tous les endpoints (voir TESTING_LOCAL.md)
2. Exporte ta base de données
3. Déploie sur Hostinger (voir README.md)
