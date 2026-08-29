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
  projets.php           choix du projet courant, à la connexion et à tout moment
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
  tests/                recettes par phase et contrôle de conformité, en CLI sur une base de test
  pdf/
    generate.php        service de rendu (mPDF) -> fichiers
    serve.php           seul point d'accès aux fichiers (droits, coffre, audit)
    templates/          gabarits PHP/HTML (_entete.php commun)
  database/             schema.sql (52 tables), schema_triggers.sql (10 garde-fous), seed.sql
  storage/              hors accès web (Deny) : scans/ documents/ coffre/ liasses/ exports/ tmp/
  lib/mpdf/             non versionné
```

## Plan de livraison

| Phase | Contenu | Statut |
|---|---|---|
| 0 | Retrait du portail, squelette, socle, schéma complet, seed | **Livrée** |
| 1 | Noyau (paramétrage, calendrier relatif, périodes, comptes, interrupteurs de modules, audit, sauvegardes) + Signature (spécimens, actes de dépôt, file de signature, appositions, codes de vérification) | **Livrée** |
| 1 bis | Cloisonnement par projet : création de projet et affectations sur acte de délégation par l'administrateur de l'outil, sélecteur de projet permanent, droits par affectation, paramètres et calendrier lus par projet, préfixe de projet sur les fichiers | **Livrée** |
| 2 | Tiers + Budget (double budget, six contrôles) | À faire |
| 3 | Comptes (partie double, règlements à double signature, rapprochement, caisse) | À faire |
| 4 | Dépenses (checklists annexe D) + Rémunération (prestations, DGI) | À faire |
| 5 | Activités (cadre logique, sessions, versions/anomalies) | À faire |
| 6 | Financement + Restitution (clôture, Annexe 4, Annexe G, liasses) | À faire |
| 7 | Hors ligne, bascule phase 2, registres post-clôture, archive | À faire |

Chaque phase est recettée sur ses cas d'échec de l'annexe G avant mise en service, sur une
base de test et jamais sur la production — les recettes écrivent, et le journal d'audit ne se
nettoie pas. Elles exigent `BOUSOL_RECETTE=oui` et annoncent la base qu'elles visent.

| Recette | Couvre | Cas |
|---|---|---|
| `recette_phase1.php` | socle, Noyau, Signature, cloisonnement, habilitation | 23 |
| `recette_phase2.php` | Tiers, Budget, les sept contrôles du CDC 2.3, bénéficiaires | 67 |
| `recette_phase3.php` | Comptes, partie double, règlements, rapprochement, caisse | 68 |
| `recette_phase4.php` | Dépenses, checklist de pièces, cycle en neuf étapes | 54 |

Elles rendent aussi chaque écran et vérifient qu'il va jusqu'au bout de son document : une page
tronquée par une erreur de gabarit est invisible en production, où `display_errors` est à Off.

`php bousol/tests/conformite.php` complète les recettes par ce qu'elles ne peuvent pas voir. Une
recette vérifie ce que le code fait ; ce contrôle-ci traque ce qu'il aurait dû faire — une fonction
de bibliothèque que personne n'appelle, un état du modèle jamais posé, un paramètre de l'annexe F
que personne ne lit. Chaque exception légitime porte sa raison dans son allowlist, et c'est cette
justification qui est le contrôle. À lancer avant d'annoncer un module livré.

## Le projet, dimension de toute donnée

Un utilisateur travaille toujours à l'intérieur d'un projet, choisi à la connexion et affiché
en permanence. Les soldes, les listes, les files d'attente et les rapports ne montrent que le
projet courant : la contrainte n'est pas ergonomique mais comptable.

Le rôle n'est pas un attribut de l'utilisateur mais une **affectation** — un lien entre une
personne, un projet et un rôle — et aucune affectation n'existe sans acte de délégation
téléversé. L'**administrateur de l'outil** crée les projets, y désigne les coordinateurs et
n'y saisit rien ; à ne pas confondre avec l'Administrateur des budgets, qui est le RAF.

Un troisième projet se crée entièrement par l'interface : il naît avec ses trois phases, son
registre de paramètres aux valeurs initiales de l'annexe F et son plan de comptes, puis son
coordinateur charge la nomenclature et la date d'ancrage.

## Direction visuelle

Sobre et organique, très peu de couleur : encre `#2A2A28`, gris `#6B6A66`, un seul accent olive `#4C5A47`,
remplissages pierre `#EFEDE8` / `#FAF8F3`, filets `#C9C4BA`. Les formulaires de gestion sont livrés en Excel
(`gabarits/`), sur le modèle des feuilles PAIESC.

## Sécurité (socle)

Le fichier de configuration (mot de passe de la base, clé du coffre) vit hors de la racine web,
en `../bousol-config.php` au-dessus de `public_html` : aucune URL ne l'atteint, et une remise à
plat des permissions par l'hébergeur reste sans conséquence.

HTTPS forcé, session dédiée `BOUSOL_SID` (60 min, `regenerate_id`), CSRF sur tout POST,
rate limiting login (5 / 5 min / IP), bcrypt coût 12, PDO sans émulation, uploads vérifiés
(extension + MIME réel + renommage aléatoire), `storage/` interdit (accès via `pdf/serve.php`),
coffre chiffré au repos (clé dans `config.php`, hors dépôt), journal d'audit non modifiable (triggers).
