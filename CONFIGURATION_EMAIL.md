# Configuration des Emails - DevDynamics

## 📧 Fonctionnalité

Lorsqu'un visiteur envoie un message via le formulaire de contact:

1. **Email de notification** → Envoyé à l'administrateur (`contact@dev-dynamics.org`)
2. **Email de confirmation** → Envoyé au visiteur qui a rempli le formulaire

## ⚙️ Configuration SMTP (Hostinger)

### Étape 1: Créer un compte email sur Hostinger

1. Connectez-vous à votre panel Hostinger
2. Allez dans **Emails** → **Comptes Email**
3. Créez un compte: `contact@dev-dynamics.org`
4. Définissez un mot de passe fort

### Étape 2: Configurer le fichier .env

Ouvrez le fichier `api/.env` sur votre serveur Hostinger et ajoutez/modifiez:

```env
# SMTP Configuration - Hostinger Email
SMTP_HOST=smtp.hostinger.com
SMTP_PORT=587
SMTP_USERNAME=contact@dev-dynamics.org
SMTP_PASSWORD=votre_mot_de_passe_email
SMTP_FROM_EMAIL=contact@dev-dynamics.org
SMTP_FROM_NAME=DevDynamics
SMTP_TO_EMAIL=contact@dev-dynamics.org
```

**⚠️ IMPORTANT:**
- Remplacez `votre_mot_de_passe_email` par le vrai mot de passe de votre compte email
- Ne committez JAMAIS le fichier `.env` dans Git

### Étape 3: Vérifier la configuration

Les paramètres SMTP pour Hostinger sont:
- **Serveur SMTP:** smtp.hostinger.com
- **Port:** 587 (STARTTLS)
- **Authentification:** Oui
- **Username:** Votre adresse email complète
- **Password:** Votre mot de passe email

## 🧪 Tester l'envoi d'emails

### Méthode 1: Via le site web
1. Allez sur https://dev-dynamics.org
2. Remplissez le formulaire de contact
3. Envoyez le message
4. Vérifiez votre boîte email `contact@dev-dynamics.org`

### Méthode 2: Via l'API directement
```bash
curl -X POST https://dev-dynamics.org/api/contact \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test Utilisateur",
    "email": "test@example.com",
    "subject": "Test email",
    "message": "Ceci est un test"
  }'
```

## 📋 Contenu des emails

### Email de notification (Admin)
Contient:
- Nom du visiteur
- Email du visiteur
- Téléphone (si fourni)
- Sujet
- Message complet
- Date et heure

### Email de confirmation (Visiteur)
Contient:
- Message de remerciement personnalisé
- Rappel de leur message
- Informations de contact DevDynamics

## 🔧 Dépannage

### Les emails ne sont pas envoyés

1. **Vérifiez les logs du serveur:**
```bash
tail -f /var/log/apache2/error.log
# ou
tail -f /var/log/nginx/error.log
```

2. **Vérifiez la configuration SMTP:**
```php
// Testez dans un fichier PHP temporaire
<?php
require_once 'api/utils/Mailer.php';
$result = Mailer::send(
    'votre@email.com',
    'Test',
    'Message de test'
);
var_dump($result);
```

3. **Vérifications communes:**
   - ✅ Le compte email existe sur Hostinger
   - ✅ Le mot de passe est correct
   - ✅ Le fichier `.env` est bien configuré
   - ✅ Les permissions du fichier `.env` sont correctes (644)

### Erreur "SMTP authentication failed"
- Vérifiez que le mot de passe est correct
- Assurez-vous d'utiliser l'adresse email complète comme username

### Emails envoyés mais pas reçus
- Vérifiez le dossier spam/courrier indésirable
- Vérifiez que `SMTP_TO_EMAIL` est correct dans `.env`

## 📊 Monitoring

Les tentatives d'envoi sont enregistrées dans les logs PHP:
- Succès: `Email envoyé avec succès à: xxx@xxx.com`
- Échec: `Échec de l'envoi d'email à: xxx@xxx.com`

## 🔐 Sécurité

✅ **Bonnes pratiques implémentées:**
- Validation des emails (FILTER_VALIDATE_EMAIL)
- Échappement HTML (htmlspecialchars)
- Mot de passe stocké dans .env (non commité)
- Headers email sécurisés

## 📚 Fichiers concernés

- `api/utils/Mailer.php` - Classe d'envoi d'emails (utilise PHPMailer)
- `api/routes/contact.php` - Route qui utilise le Mailer
- `api/.env` - Configuration SMTP (NON commité)
- `api/.env.example` - Template de configuration
- `api/vendor/phpmailer/` - Bibliothèque PHPMailer (NON commité)

## 🔧 Installation sur le serveur Hostinger

Après avoir téléchargé votre site sur Hostinger, vous devez installer PHPMailer:

### Option 1: Upload manuel (Recommandé)
1. Téléchargez le dossier `api/vendor/phpmailer` depuis votre ordinateur
2. Uploadez-le vers `public_html/api/vendor/phpmailer` sur Hostinger via FTP/File Manager

### Option 2: Via SSH (si disponible)
```bash
cd public_html/api/vendor
wget https://github.com/PHPMailer/PHPMailer/archive/refs/tags/v6.9.1.zip
unzip v6.9.1.zip
mv PHPMailer-6.9.1 phpmailer
rm v6.9.1.zip
```

### Option 3: Test local
Pour tester l'envoi d'emails en local avant de déployer:
1. Ouvrez dans votre navigateur: `http://localhost/api/test-email.php`
2. Vérifiez les résultats affichés
3. Consultez votre boîte email `contact@dev-dynamics.org`

⚠️ **Important:** Supprimez `api/test-email.php` après les tests (il est déjà ignoré par Git)

---

Pour toute question, contactez l'équipe technique.
