# DevDynamics Backend PHP - Guide de Déploiement Hostinger

Backend PHP migré depuis Node.js, optimisé pour hébergement web Hostinger.

## Caractéristiques

- PHP 7.4+ compatible
- Architecture REST API complète
- Authentification JWT
- Base de données MySQL
- Sans dépendances externes (pas de Composer requis)
- Compatible hébergement partagé Hostinger

## Structure du Projet

```
backend-php/
├── index.php              # Point d'entrée principal
├── .htaccess             # Configuration Apache
├── .env                  # Configuration (à créer)
├── config/
│   └── database.php      # Connexion base de données
├── middleware/
│   └── auth.php          # Middleware JWT
├── utils/
│   ├── Response.php      # Gestion des réponses JSON
│   ├── Router.php        # Système de routing
│   └── JWT.php           # Gestion des tokens JWT
└── routes/               # Toutes les routes API
    ├── auth.php
    ├── students.php
    ├── courses.php
    ├── course-content.php
    ├── quiz-management.php
    ├── certificates.php
    ├── programs.php
    ├── blog.php
    ├── donations.php
    ├── contact.php
    ├── organization.php
    ├── testimonials.php
    ├── sponsors.php
    └── admin.php
```

## Installation sur Hostinger

### Étape 1: Préparer les Fichiers

1. Compressez le dossier `backend-php` en ZIP
2. Connectez-vous à votre panneau Hostinger
3. Ouvrez le gestionnaire de fichiers (File Manager)

### Étape 2: Télécharger les Fichiers

1. Naviguez vers `public_html` (ou votre dossier web)
2. Créez un sous-dossier `api` (optionnel, recommandé)
3. Téléchargez et extrayez le ZIP dans ce dossier

**Structure recommandée:**
```
public_html/
└── api/
    ├── index.php
    ├── .htaccess
    ├── config/
    ├── middleware/
    ├── utils/
    └── routes/
```

### Étape 3: Configuration de la Base de Données

1. Dans Hostinger, allez dans "Bases de données MySQL"
2. Créez une nouvelle base de données:
   - Nom: `devdynamics_db` (ou autre)
   - Notez le nom d'utilisateur et mot de passe

3. Importez le schéma depuis `backend/database/schema.sql` (Node.js)
   - Utilisez phpMyAdmin (disponible dans Hostinger)
   - Ou uploadez via l'interface de gestion MySQL

### Étape 4: Configuration du Fichier .env

1. Copiez `.env.example` en `.env`
2. Éditez `.env` avec vos informations:

```env
# Database Configuration (depuis Hostinger)
DB_HOST=localhost
DB_USER=u123456_dbuser
DB_PASSWORD=votre_mot_de_passe
DB_NAME=u123456_devdynamics
DB_PORT=3306

# JWT Configuration
JWT_SECRET=changez_cette_cle_secrete_en_production_xyz123
JWT_EXPIRY=604800

# Application Settings
APP_ENV=production
APP_DEBUG=false
APP_URL=https://votredomaine.com

# Email Configuration (SMTP Hostinger)
SMTP_HOST=smtp.hostinger.com
SMTP_PORT=587
SMTP_USER=noreply@votredomaine.com
SMTP_PASSWORD=votre_mot_de_passe_email
SMTP_FROM=noreply@votredomaine.com
SMTP_FROM_NAME=DevDynamics
```

### Étape 5: Vérifier .htaccess

Le fichier `.htaccess` doit être présent avec ce contenu:

```apache
# Enable URL rewriting
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /api/

    # Route all requests to index.php
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php [QSA,L]
</IfModule>
```

**Note:** Ajustez `RewriteBase` selon votre structure:
- Si dans `public_html/api/` → `RewriteBase /api/`
- Si dans `public_html/` → `RewriteBase /`

### Étape 6: Permissions des Fichiers

Vérifiez les permissions via File Manager:
- Dossiers: 755
- Fichiers PHP: 644
- `.htaccess`: 644
- `.env`: 600 (plus sécurisé)

### Étape 7: Tester l'API

Testez avec ces URLs (remplacez `votredomaine.com`):

```bash
# Test basique
https://votredomaine.com/api/

# Liste des cours (public)
https://votredomaine.com/api/courses

# Organisation info (public)
https://votredomaine.com/api/organization

# Login
POST https://votredomaine.com/api/auth/login
Content-Type: application/json

{
  "email": "admin@example.com",
  "password": "admin123"
}
```

## Configuration Avancée

### Sous-domaine API (Recommandé)

Pour une meilleure organisation, créez un sous-domaine `api.votredomaine.com`:

1. Dans Hostinger, allez dans "Sous-domaines"
2. Créez `api` pointant vers `/public_html/api`
3. Mettez à jour `.htaccess`:
   ```apache
   RewriteBase /
   ```

### SSL/HTTPS

Hostinger offre SSL gratuit:
1. Activez SSL dans le panneau
2. Forcez HTTPS en ajoutant dans `.htaccess`:

```apache
# Force HTTPS
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

### Sécurité Additionnelle

Ajoutez dans `.htaccess` pour protéger `.env`:

```apache
<FilesMatch "^\.env$">
    Order allow,deny
    Deny from all
</FilesMatch>
```

## Endpoints API Disponibles

### Authentification
- `POST /api/auth/login` - Connexion admin
- `POST /api/auth/register` - Inscription (admin only)

### Students
- `POST /api/students/register` - Inscription étudiant
- `POST /api/students/login` - Connexion étudiant
- `GET /api/students/profile` - Profil (auth)
- `PUT /api/students/profile` - Modifier profil (auth)
- `PUT /api/students/change-password` - Changer mot de passe (auth)
- `GET /api/students/enrollments` - Inscriptions (auth)
- `GET /api/students/courses/:courseId/progress` - Progression (auth)

### Courses
- `GET /api/courses` - Liste cours actifs (public)
- `GET /api/courses/:id` - Détails cours (public)
- `POST /api/courses` - Créer cours (admin)
- `PUT /api/courses/:id` - Modifier cours (admin)
- `DELETE /api/courses/:id` - Supprimer cours (admin)
- `POST /api/courses/:id/enroll` - S'inscrire (auth)

### Content
- `GET /api/content/courses/:courseId/modules` - Modules
- `GET /api/content/modules/:moduleId` - Module détails

### Quiz
- `POST /api/quiz-management/modules/:moduleId/quiz` - Créer quiz (admin)
- `GET /api/quiz-management/modules/:moduleId/quiz` - Obtenir quiz

### Certificates
- `GET /api/certificates/:id` - Certificat
- `GET /api/certificates/verify/:code` - Vérifier certificat

### Programs
- `GET /api/programs` - Liste programmes (public)
- `POST /api/programs` - Créer programme (admin)
- `PUT /api/programs/:id` - Modifier programme (admin)
- `DELETE /api/programs/:id` - Supprimer programme (admin)

### Blog
- `GET /api/blog` - Articles publiés (pagination)
- `GET /api/blog/:slug` - Article par slug
- `POST /api/blog` - Créer article (admin)
- `PUT /api/blog/:id` - Modifier article (admin)
- `DELETE /api/blog/:id` - Supprimer article (admin)

### Donations
- `GET /api/donations` - Liste donations (admin)
- `GET /api/donations/stats` - Statistiques (admin)
- `POST /api/donations` - Faire un don (public)
- `PUT /api/donations/:id/status` - Modifier statut (admin)

### Contact
- `GET /api/contact` - Messages (admin)
- `POST /api/contact` - Envoyer message (public)
- `PUT /api/contact/:id/status` - Modifier statut (admin)
- `DELETE /api/contact/:id` - Supprimer message (admin)

### Organization
- `GET /api/organization` - Info organisation (public)

### Testimonials
- `GET /api/testimonials` - Témoignages (public)

### Sponsors
- `GET /api/sponsors` - Sponsors (public)

### Admin
- `GET /api/admin/dashboard/stats` - Statistiques (admin)
- `GET /api/admin/users` - Liste utilisateurs (admin)
- `PUT /api/admin/users/:id/role` - Modifier rôle (admin)
- `DELETE /api/admin/users/:id` - Supprimer utilisateur (admin)

## Dépannage

### Erreur 500

1. Vérifiez les logs PHP dans Hostinger
2. Activez temporairement le debug dans `index.php`:
   ```php
   error_reporting(E_ALL);
   ini_set('display_errors', 1);
   ```

### Routes ne fonctionnent pas

1. Vérifiez que `mod_rewrite` est activé (normalement oui sur Hostinger)
2. Vérifiez le `RewriteBase` dans `.htaccess`
3. Testez avec `/index.php/api/courses` pour voir si le problème vient du rewrite

### Connexion base de données échoue

1. Vérifiez les credentials dans `.env`
2. Vérifiez que la base de données est créée
3. Vérifiez l'hôte (souvent `localhost` sur Hostinger)

### CORS errors

Si vous avez des erreurs CORS depuis le frontend:

1. Vérifiez les headers dans `index.php`
2. Ajustez `Access-Control-Allow-Origin` pour votre domaine frontend

## Migration depuis Node.js

Pour migrer vos données existantes:

1. Exportez la base de données MySQL du backend Node.js
2. Importez dans la nouvelle base de données Hostinger
3. Les schémas sont identiques, aucune modification requise

## Support

Pour questions ou problèmes:
- Vérifiez d'abord les logs d'erreur PHP
- Testez les endpoints avec Postman ou curl
- Vérifiez la configuration `.env`

## Notes de Production

1. Changez `JWT_SECRET` en production
2. Désactivez `display_errors` en production
3. Utilisez HTTPS obligatoirement
4. Configurez des sauvegardes automatiques de la base de données
5. Surveillez les logs régulièrement

## Licence

Même licence que le projet original DevDynamics.
