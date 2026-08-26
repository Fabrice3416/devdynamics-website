# Bousòl

Outil de pilotage administratif et comptable du projet **KèsKlè**, mis en œuvre par
DÉVELOPPEMENT ET DYNAMISME (Cap-Haïtien) dans le cadre d'une subvention du PAIESC financée
par l'Union européenne. Référence : `../Bousol_Cahier_des_charges.md` (v1.0).

Bousòl remplace l'ancien portail ACP (archivé sous le tag git `portail-acp-final`).

**URL de production :** https://dev-dynamics.org/bousol/

## Stack

- PHP 8.1+ (PDO MySQL), MySQL 8 — hébergement mutualisé Hostinger
- mPDF 8 dans `lib/mpdf/` (rendu documentaire)
- Bootstrap 5.3 + Bootstrap Icons (CDN)

## Installation locale

```bash
mysql -u root -p -e "CREATE DATABASE bousol CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p bousol < database/schema.sql
mysql -u root -p bousol < database/seed.sql
cp includes/config.example.php includes/config.php   # renseigner db + coffre_key_hex
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'      # -> coffre_key_hex
php -S localhost:8080 -t ..                            # http://localhost:8080/bousol/
```

Identifiant initial : `admin@dev-dynamics.org` / `Bousol!2026` (changement forcé à la première connexion).

## Architecture

```
bousol/
  index.php login.php logout.php dashboard.php profil.php
  diagnostic.php        contrôle d'installation (navigateur ou CLI)
  includes/
    config.example.php  db.php  functions.php
    auth.php            sessions, CSRF, rôles, réauthentification (signature)
    signature.php       spécimens, file de signature, appositions, estampille PDF
    audit.php           journal d'audit en ajout seul
    calendrier.php      paramètres historisés (annexe F) + calendrier relatif
    referentiels.php    modules, types de dossiers et pièces (annexe D), documents (annexe E)
    uploads.php         fichiers, empreintes SHA-256, coffre chiffré AES-256-GCM
    layout.php          gabarit des pages
  modules/              un dossier par module (CDC 7.2), chacun possède ses tables
    noyau/ signature/ tiers/ budget/ comptes/ activites/ depenses/ remuneration/ restitution/ financement/
  gabarits/             gabarits Excel des formulaires de gestion (petite_caisse.xlsx)
  tests/                recette_phase1.php (cas de l'annexe G, à lancer en CLI sur une base de test)
  pdf/
    generate.php        service de rendu (mPDF) -> fichiers
    serve.php           seul point d'accès aux fichiers (droits, coffre, audit)
    templates/          gabarits PHP/HTML (_entete.php commun)
  database/             schema.sql (49 tables), schema_triggers.sql (8 garde-fous), seed.sql
  storage/              hors accès web (Deny) : scans/ documents/ coffre/ liasses/ exports/ tmp/
  lib/mpdf/             non versionné
```

## Plan de livraison

| Phase | Contenu | Statut |
|---|---|---|
| 0 | Retrait du portail, squelette, socle, schéma complet, seed | **Livrée** |
| 1 | Noyau (paramétrage annexe F, calendrier relatif, périodes, utilisateurs, interrupteurs de modules, audit, sauvegardes) + Signature (spécimens, actes de dépôt, file de signature, appositions, codes de vérification) | **Livrée** |
| 2 | Tiers + Budget (double budget, six contrôles) | À faire |
| 3 | Comptes (partie double, règlements à double signature, rapprochement, caisse) | À faire |
| 4 | Dépenses (checklists annexe D) + Rémunération (prestations, DGI) | À faire |
| 5 | Activités (cadre logique, sessions, versions/anomalies) | À faire |
| 6 | Financement + Restitution (clôture, Annexe 4, Annexe G, liasses) | À faire |
| 7 | Hors ligne, bascule phase 2, registres post-clôture, archive | À faire |

Chaque phase est recettée sur ses cas d'échec de l'annexe G avant mise en service :
`php bousol/tests/recette_phase1.php` sur une base de test (20 cas, Noyau + Signature).

## Direction visuelle

Sobre et organique, très peu de couleur : encre `#2A2A28`, gris `#6B6A66`, un seul accent olive `#4C5A47`,
remplissages pierre `#EFEDE8` / `#FAF8F3`, filets `#C9C4BA`. Les formulaires de gestion sont livrés en Excel
(`gabarits/`), sur le modèle des feuilles PAIESC.

## Sécurité (socle)

HTTPS forcé, session dédiée `BOUSOL_SID` (60 min, `regenerate_id`), CSRF sur tout POST,
rate limiting login (5 / 5 min / IP), bcrypt coût 12, PDO sans émulation, uploads vérifiés
(extension + MIME réel + renommage aléatoire), `storage/` interdit (accès via `pdf/serve.php`),
coffre chiffré au repos (clé dans `config.php`, hors dépôt), journal d'audit non modifiable (triggers).
