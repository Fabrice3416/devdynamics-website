#!/bin/bash
# Script de déploiement pour Hostinger
# À exécuter après le git pull sur Hostinger

echo "Déploiement DevDynamics sur Hostinger..."

# Copier les fichiers frontend à la racine
cp -r frontend/* public_html/

# Copier les fichiers backend dans api/
mkdir -p public_html/api
cp -r backend/* public_html/api/

# Créer le fichier .htaccess à la racine pour forcer HTTPS
cat > public_html/.htaccess << 'EOF'
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
EOF

echo "Configuration de l'environnement de production..."
# Modifier le .env pour la production
sed -i 's/APP_ENV=development/APP_ENV=production/g' public_html/api/.env
sed -i 's/APP_DEBUG=true/APP_DEBUG=false/g' public_html/api/.env

echo "Déploiement terminé!"
echo ""
echo "N'oubliez pas de:"
echo "1. Configurer les identifiants de base de données dans public_html/api/.env"
echo "2. Importer la base de données via phpMyAdmin"
echo "3. Créer un utilisateur admin"
