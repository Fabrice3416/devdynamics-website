# Portail DEVDYNAMICS / ACP

Application web isolee de gestion administrative, comptable et reporting pour le projet
Academie des Cartographes Populaires (PAIESC/CS/04-2026/021).

**URL de production :** https://dev-dynamics.org/portail/
**Plan de developpement complet :** voir `../PLAN_PORTAIL.md`

## Stack technique

- PHP 8.1 + PDO MySQL
- MySQL 8.x (24 tables + 1 vue + 5 triggers)
- mPDF 8.x (genere les PDF - a deposer dans `lib/mpdf/`)
- PHPMailer 6.x (envoi SMTP - a deposer dans `lib/phpmailer/`)
- Bootstrap 5.3 + Bootstrap Icons (via CDN)
- Chart.js 4.x (via CDN, Phase 7+)
- signature_pad.js 4.x (via CDN)

## Installation locale

```bash
# 1) Creer la base
mysql -u root -p -e "CREATE DATABASE portail_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p portail_dev < database/schema.sql
mysql -u root -p portail_dev < database/seed.sql

# 2) Config locale
cp includes/config.example.php includes/config.php
# Editer includes/config.php avec credentials BDD locaux

# 3) Telecharger les libs PHP (composer requis localement)
composer require mpdf/mpdf phpmailer/phpmailer
# Copier vendor/mpdf/mpdf/ vers lib/mpdf/
# Copier vendor/phpmailer/phpmailer/ vers lib/phpmailer/

# 4) Permissions
chmod -R 755 storage/
chmod 600 includes/config.php

# 5) Serveur de dev
php -S localhost:8080
# Ouvrir http://localhost:8080/portail/
# Login : admin@dev-dynamics.org / ChangeMoiVite!2026
```

## Etat d'avancement

| Phase | Statut | Contenu |
|---|---|---|
| **Phase 1 - Fondations** | LIVREE | BDD complete (24 tables + vue + 5 triggers), auth (login, logout, reset 60min, CSRF, rate limiting), dashboard, profil avec upload signature (pad + image), assets CSS/JS, structure MVC pretes |
| Phase 2 - F01 -> F02 | A FAIRE | Workflow imputations + decaissements + DGI auto + PDF |
| Phase 3 - ASF -> NH -> FRP | A FAIRE | Triple signature + tokens 72h + dossier_complet.pdf |
| Phase 4 - Compta complete | A FAIRE | Journal, budget, PDP, rapprochement, Petite Caisse, 18 alertes |
| Phase 5 - Administration | A FAIRE | Contrats, TCD, BC, BR, candidatures, livrables, partenaires/FECP |
| Phase 6 - Retroactif | A FAIRE | Saisie + import CSV |
| Phase 7 - Reporting | A FAIRE | RFM, DJ (ZIP), Rapport cumule |
| Phase 8 - Dashboard avance | EBAUCHE | Indicateurs basiques OK; Chart.js + alertes a ajouter |
| Phase 9 - UAT + Production | A FAIRE | Tests, formation, deploiement |

Les **stubs** des pages non encore developpees affichent un ecran d'attente
avec leur reference roadmap. Aucune page de la nav n'est cassee.

## Arborescence

```
portail/
  index.php login.php logout.php reset.php dashboard.php profil.php
  admin/         Stubs (Phase 5)
  compta/        Stubs (Phases 2-4, 6)
  reporting/     Stubs (Phase 7)
  api/           Endpoints tokenises (stubs Phase 3)
  pdf/
    generate.php   Service mPDF (Phase 2+)
    serve.php      Streaming securise
    templates/     Templates HTML mPDF (a creer Phase 2+)
  includes/      Auth, DB, helpers, alerts, uploads, header/footer/nav, stub
  controllers/   models/ services/  (MVC - a remplir Phase 2+)
  assets/        CSS, JS, images
  database/      schema.sql, seed.sql, README
  storage/       Stockage fichiers (protege .htaccess Deny from all)
  lib/           mPDF, PHPMailer (a deposer)
```

## Securite implementee Phase 1

- HTTPS force (.htaccess)
- Session `PORTAIL_SID` separee, timeout 60 min, regenerate_id apres login
- CSRF token verifie sur chaque POST
- Rate limiting login (5 tentatives / 5 min / IP)
- Bcrypt cost 10 pour mots de passe
- PDO prepared statements (EMULATE_PREPARES = false)
- Upload securise : whitelist extension + finfo MIME + renommage hash + compression GD
- storage/ totalement bloque (Deny from all) - acces via serve.php uniquement
- robots.txt : Disallow / (pas d'indexation)
- audit_log INSERT-only sur toutes les actions critiques
- X-Frame-Options, X-Content-Type-Options, Referrer-Policy

## Tests rapides

Apres install :
1. `http://localhost:8080/portail/` -> redirige vers login
2. Login admin -> dashboard avec budget 0 / 5 600 000 HTG
3. /portail/profil.php -> upload signature OK
4. /portail/admin/contrats.php -> ecran stub Phase 5
5. /portail/compta/f01.php (compte comptable) -> stub Phase 2
6. Logout -> session detruite, retour login
