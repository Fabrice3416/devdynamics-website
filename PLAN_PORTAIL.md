# Plan d'Implémentation — Portail DEVDYNAMICS / ACP

> **Référence contrat :** PAIESC/CS/04-2026/021
> **Cible :** `https://dev-dynamics.org/portail/`
> **Stack :** PHP 8.1 + MySQL 8.x + mPDF 8.x + PHPMailer 6.x + Chart.js 4.x + Bootstrap 5.3 + signature_pad.js
> **Hébergeur :** Hostinger Web Premium (PHP 8.x + MySQL 8.x + Apache + SSH)
> **Durée :** 9 semaines de développement (cahier des charges V4.0 Finale, Mai 2026)

---

## Table des matières

0. [Intégration avec le site existant (isolation totale)](#0-intégration-avec-le-site-existant-isolation-totale)
1. [Vue d'ensemble et principes directeurs](#1-vue-densemble-et-principes-directeurs)
2. [Pré-requis et environnement](#2-pré-requis-et-environnement)
3. [Architecture cible et structure du code](#3-architecture-cible-et-structure-du-code)
4. [Phase 1 — Fondations (Semaine 1)](#4-phase-1--fondations-semaine-1)
5. [Phase 2 — Workflow F01 → F02 + Signatures (Semaines 1-2)](#5-phase-2--workflow-f01--f02--signatures-semaines-1-2)
6. [Phase 3 — ASF → NH → FRP + Tokens (Semaines 2-3)](#6-phase-3--asf--nh--frp--tokens-semaines-2-3)
7. [Phase 4 — Comptabilité complète (Semaines 3-4)](#7-phase-4--comptabilité-complète-semaines-3-4)
8. [Phase 5 — Module Administration (Semaines 4-5)](#8-phase-5--module-administration-semaines-4-5)
9. [Phase 6 — Saisie rétroactive (Semaine 6)](#9-phase-6--saisie-rétroactive-semaine-6)
10. [Phase 7 — Module Reporting (Semaines 6-7)](#10-phase-7--module-reporting-semaines-6-7)
11. [Phase 8 — Dashboard + Responsive (Semaines 7-8)](#11-phase-8--dashboard--responsive-semaines-7-8)
12. [Phase 9 — Tests UAT + Production (Semaines 8-9)](#12-phase-9--tests-uat--production-semaines-8-9)
13. [Sécurité — Checklist transverse](#13-sécurité--checklist-transverse)
14. [Conventions de code](#14-conventions-de-code)
15. [Risques identifiés et mitigations](#15-risques-identifiés-et-mitigations)
16. [Livrables par phase](#16-livrables-par-phase)

---

## 0. Intégration avec le site existant (isolation totale)

Le repo actuel héberge le site public **dev-dynamics.org** (frontend statique + API REST PHP sous `api/`). Le portail interne est ajouté **comme une application isolée** dans `/portail/`. Aucun lien n'est ajouté sur les pages publiques.

### 0.1 Ce qui existe déjà et que l'on NE TOUCHE PAS

| Zone existante | Contenu | Statut |
|---|---|---|
| `index.html` | Page d'accueil publique | **Intact** |
| `pages/*.html` (22 pages) | admin-login, student-login, blog, cours-list, candidature, sponsors, programmes, etc. | **Intact** |
| `css/global.css`, `css/components.css`, `css/pages/*.css` | Styles du site public | **Intact** — le portail aura ses propres CSS sous `portail/assets/css/` |
| `js/config.js`, `js/api.js`, `js/utils.js`, `js/pages/*.js` | JS du site public | **Intact** |
| `api/` (index.php, routes, utils, middleware, config) | API REST du site public | **Intact** — le portail n'utilise pas ce router |
| `assets/images/` | Images publiques | **Lecture seule** — logo DEVDYNAMICS pourra être référencé dans les PDF |
| `.env` (à la racine `api/`) | Credentials BDD du site public | **Non partagé** — le portail aura son propre `.env` |

### 0.2 Mode d'accès au portail

- **URL unique** : `https://dev-dynamics.org/portail/`
- **Aucun bouton, aucun lien** sur `index.html`, sur les pages dans `pages/`, ni dans la nav du site public
- **Aucune mention** du portail visible pour les visiteurs anonymes
- Les utilisateurs internes (Coordinateur, Administrateur, Comptable, Prestataires via tokens) reçoivent l'URL par canal privé
- Pas de référencement (robots.txt à ajouter dans `portail/` : `User-agent: * \n Disallow: /`)

### 0.3 Choix d'isolation

| Aspect | Décision | Justification |
|---|---|---|
| Sous-dossier | `/portail/` à la racine du projet | Exigence cahier des charges |
| Base de données | **BDD séparée** (`u<id>_portail` distincte de `u<id>_devdynamics`) | Évite collisions de noms de tables ; permet backups/permissions indépendants ; respecte le cahier (24 tables propres) |
| `.env` | `portail/includes/.env` séparé du `api/.env` existant | Credentials BDD différents, SMTP peut être identique ou non |
| Stack PHP | PHP natif + PDO (cahier impose) | Pas d'utilisation du `Router`, `JWT`, `Mailer` du site public — réimplémenté proprement dans `portail/` |
| Auth | Système session natif PHP indépendant | Le site public utilise JWT (étudiants/admin) ; le portail utilise sessions PHP (cahier l'impose). Pas de SSO inter-systèmes |
| SMTP | Compte `noreply@dev-dynamics.org` (peut être partagé) | À configurer dans le `.env` du portail |
| CSS/JS | `portail/assets/css/portail.css` + Bootstrap 5.3 CDN | Aucune dépendance au CSS existant — le site public utilise un design system propre, le portail utilise Bootstrap (imposé) |

### 0.4 Cohabitation Apache (.htaccess)

- Le `.htaccess` racine du site public **n'est pas modifié**
- Le portail aura son propre `portail/.htaccess` (HTTPS forcé, dénis sur `storage/`, redirections internes)
- Aucun reroutage vers l'API existante — le portail est 100 % autonome

### 0.5 Réutilisation contrôlée (optionnelle)

| Élément du site public | Réutilisation côté portail ? |
|---|---|
| Logo DEVDYNAMICS (`assets/images/`) | **Oui**, référencé en lecture dans les templates PDF (chemin absolu) |
| `api/utils/Mailer.php` (PHPMailer wrapper) | **Non** — réimplémenté dans `portail/includes/alerts.php` (cahier impose PHPMailer + table `alertes` avec journalisation) |
| `api/utils/SimplePDF.php` | **Non** — cahier impose mPDF 8.x |
| `.env` | **Non** — fichier dédié au portail |

### 0.6 Risques liés à la cohabitation et mitigations

| Risque | Mitigation |
|---|---|
| Confusion entre les 2 BDD lors d'opérations Hostinger | Nommer explicitement : `u<id>_devdyn_public` et `u<id>_devdyn_portail` |
| Backups oubliés sur la BDD portail | Configurer le backup automatique Hostinger **par BDD**, vérifier au déploiement |
| Conflit de session PHP entre site public et portail | Le site public utilise JWT (pas de session PHP côté serveur) ; aucun conflit. Vérifier néanmoins `session_name('PORTAIL_SID')` dans `portail/includes/auth.php` |
| Indexation accidentelle par moteurs de recherche | `portail/robots.txt` + meta `noindex` sur toutes les pages portail |
| Modification accidentelle du site public lors du dev portail | Toute PR touchant à un fichier hors `/portail/` est rejetée en code review |

---

## 1. Vue d'ensemble et principes directeurs

### 1.1 Trois modules, une seule application

| Module | Pages clés | Rôles principaux |
|---|---|---|
| **Administration** | Contrats, TCD, BC, BR, Candidatures, Livrables, Partenaires (FECP) | Administrateur (R/W), Coordinateur (R) |
| **Comptabilité** | F01, F02, ASF, NH, FRP, Journal, Budget, PDP, Rapprochement, Petite Caisse, Rétroactif | Administrateur, Comptable, Coordinateur (R) |
| **Reporting** | RFM, DJ (ZIP), Rapport Cumulé | Coordinateur, Administrateur |

### 1.2 Principes non négociables

- **Chèque SOGEBANK par défaut** — virement = exception avec justification écrite obligatoire.
- **Chaîne séquentielle verrouillée** F01 → F02 → ASF → NH → FRP : aucune étape ne peut être sautée.
- **Trigger MySQL** pour DGI 2 % (intégrité même en cas d'écriture directe en base).
- **Toutes les opérations multi-tables encapsulées dans une transaction PDO** (`beginTransaction` / `commit` / `rollBack`).
- **`storage/` 100 % protégé** par `.htaccess Deny from all` — accès uniquement via `serve.php` après contrôle de session/token.
- **Audit log immuable** : INSERT-only par le code PHP, jamais d'UPDATE/DELETE par les utilisateurs.
- **Validation MIME réelle** via `finfo_file()` — jamais se fier à `$_FILES['type']`.
- **Renommage sécurisé des uploads** : `hash(uniqid + user_id + filename)` — jamais conserver le nom original.

### 1.3 Objectifs mesurables

- 24 tables MySQL + 1 vue + 5 triggers, toutes FK déclarées explicitement.
- Toutes les pages PHP appellent `check_role(['admin', 'coordinateur', ...])` en tête.
- 100 % des PDF générés via mPDF depuis `pdf/templates/`.
- 100 % des emails passent par PHPMailer + SMTP (jamais `mail()`).
- Zéro requête SQL non préparée (PDO + paramètres nommés).

---

## 2. Pré-requis et environnement

### 2.1 Côté Hostinger (hPanel)

| Élément | Action | Cohabitation site existant |
|---|---|---|
| Sous-dossier `/portail/` sous `public_html/` | Créer manuellement | Sera créé à côté de `index.html`, `pages/`, `api/` existants |
| **Nouvelle** base MySQL dédiée portail (`u<id>_portail`) | Créer via hPanel (nom, user, password) | **Distincte** de la BDD du site public actuel |
| Compte email `noreply@dev-dynamics.org` | Récupérer les paramètres SMTP | Peut être le même compte que celui utilisé par `api/utils/Mailer.php` |
| Certificat SSL | Vérifier qu'il couvre `dev-dynamics.org/portail/` | Wildcard ou même certificat racine — généralement déjà OK |
| Accès SSH | Activer + récupérer la clé pour déploiement | Identique à l'existant (un seul accès SSH par hébergement) |
| PHP 8.1+ | Vérifier dans hPanel → PHP Configuration | Une seule version PHP par hébergement — vérifier compatibilité avec l'API existante (qui utilise PHP 8.x) |
| Extensions PHP requises | `pdo_mysql`, `gd`, `mbstring`, `fileinfo`, `zip`, `openssl` | Vérifier activation (probablement déjà OK pour l'existant) |

### 2.2 Côté local (développement)

- PHP 8.1+ + MySQL 8.x locaux (Docker ou XAMPP)
- Composer (uniquement pour récupérer mPDF + PHPMailer en local — sur Hostinger on dépose les libs en FTP)
- Git + branche `develop` séparée de `main`
- Outil de diff SQL pour synchroniser les migrations

### 2.3 Configuration sensible

Créer `portail/includes/config.php` (jamais committé — ajouté au `.gitignore` racine) contenant :

```php
return [
    'db' => ['host' => 'localhost', 'name' => 'u<id>_portail', 'user' => '...', 'pass' => '...'],
    'smtp' => ['host' => 'smtp.hostinger.com', 'port' => 465, 'user' => 'noreply@dev-dynamics.org', 'pass' => '...'],
    'app' => ['url' => 'https://dev-dynamics.org/portail/', 'env' => 'production'],
];
```

Fournir un `portail/includes/config.example.php` versionné comme template.

**Ajouter au `.gitignore`** (à la racine du projet) :
```
/portail/includes/config.php
/portail/storage/
/portail/lib/mpdf/tmp/
```

---

## 3. Architecture cible et structure du code

### 3.1 Arborescence sur Hostinger (avec cohabitation site existant)

```
public_html/
├── index.html                      ← Site public — INTACT
├── pages/                          ← Site public — INTACT
├── css/  js/  assets/              ← Site public — INTACT
├── api/                            ← API du site public — INTACTE
│
└── portail/                        ← NOUVELLE APPLICATION ISOLÉE
    ├── .htaccess                   ← HTTPS + sécurité propres au portail
    ├── robots.txt                  ← Disallow: / (noindex)
    ├── index.php                   ← Redirection vers login
    ├── login.php | logout.php | dashboard.php | reset.php | profil.php
    ├── admin/
    │   ├── contrats.php  tcd.php  bon_commande.php  bon_reception.php
    │   ├── candidatures.php  livrables.php  partenaires.php
    ├── compta/
    │   ├── f01.php  f02.php  asf.php  nh.php  frp.php
    │   ├── plan_decaissement.php  retroactif.php  retroactif_import.php
    │   ├── journal.php  budget.php  rapprochement.php
    │   ├── petite_caisse.php  petite_caisse_journal.php
    │   ├── petite_caisse_renflouement.php  petite_caisse_rapprochement.php
    ├── reporting/
    │   ├── rapports.php  export.php  serve_rapport.php
    ├── api/                        ← Endpoints tokenisés du portail (à ne pas confondre avec /api/ du site public)
    │   ├── nh_token.php  frp_token.php  candidature.php
    ├── pdf/
    │   ├── generate.php  serve.php
    │   └── templates/  (f01.html, f02.html, asf.html, nh.html, dossier_complet.html, etc.)
    ├── lib/
    │   ├── mpdf/  phpmailer/
    ├── includes/
    │   ├── db.php  auth.php  functions.php  alerts.php  uploads.php
    │   └── config.php              ← NON committé (gitignore)
    ├── controllers/  models/  services/      ← MVC léger
    ├── assets/                     ← CSS/JS/images propres au portail
    │   ├── css/portail.css
    │   ├── js/
    │   └── images/  (peut faire un lien symbolique vers ../assets/images/ pour le logo)
    └── storage/                    ← Protégé par .htaccess Deny from all
        ├── dossiers/YYYY/M0X/DEP-XXX/
        ├── contrats/  bons_commande/  bons_reception/
        ├── preuves_paiement/  factures/  devis/
        ├── rapprochements/  asf/  fecp/
        ├── caisse/recus/  caisse/renflouements/
        ├── retroactif/  signatures/  rapports/
        └── .htaccess  (Deny from all)
```

⚠ **Important** : Le dossier `portail/api/` ne contient que les **endpoints tokenisés** (NH, FRP) — c'est une coïncidence de nommage avec `/api/` du site public, mais les deux sont totalement indépendants. Aucun include croisé.

### 3.2 MVC léger imposé

- **`models/`** : classes d'accès aux tables (PDO préparé). Une classe par table majeure : `ImputationModel`, `DecaissementModel`, `AsfModel`, etc.
- **`controllers/`** : chaque fichier dans `admin/`, `compta/`, `reporting/` est un point d'entrée mince qui délègue aux services.
- **`services/`** : logique métier réutilisable — `PdfService`, `EmailService`, `TokenService`, `AuditService`, `PetiteCaisseService`, `ReportingService`.
- **`includes/`** : helpers transverses (auth, db, uploads, alerts).

Cette séparation est **imposée dès la S1** — refactoriser plus tard est coûteux (note explicite du cahier).

### 3.3 Conventions de nommage

| Type | Pattern | Exemple |
|---|---|---|
| Numéros métier | `XXX-ACP-NNN-AAAA` | `F01-ACP-001-2026`, `BC-ACP-042-2026` |
| FRP / FECP | `XXX-ACP-NNN-M0X` | `FRP-ACP-001-M01`, `FECP-ACP-01-M03` |
| Rapports | `RFM-ACP-AAAA-M0X` / `DJ-ACP-AAAA-M0X` | `RFM-ACP-2026-M01` |
| Tables MySQL | `snake_case` pluriel | `imputations`, `bons_commande` |
| Classes PHP | `PascalCase` | `ImputationModel`, `PdfService` |
| Fichiers stockage | `hash(uniqid+user_id+filename).ext` | `a3f2b8c1d9e4f7.pdf` |

---

## 4. Phase 1 — Fondations (Semaine 1)

### 4.1 Objectifs

- BDD complète et fonctionnelle (24 tables + 1 vue + 5 triggers + données d'initialisation).
- Login + reset mot de passe + session 60 min + CSRF.
- Squelette MVC + `includes/` opérationnel.

### 4.2 Étape 1.1 — Base MySQL

**Créer une BDD MySQL dédiée** via hPanel Hostinger (ex : `u<id>_portail`), distincte de la BDD du site public. Credentials stockés dans `portail/includes/config.php`.

Créer un fichier `portail/database/schema.sql` versionné contenant :

1. **24 tables** dans l'ordre des FK (parents avant enfants) :
   - `users`, `prestataires`, `contrats`, `lignes_budgetaires`
   - `tcd_devis`, `candidatures`, `livrables`, `partenaires`
   - `fiches_execution_cpsi`, `imputations`, `decaissements`
   - `attestations_service_fait`, `tokens`, `notes_honoraires`, `fiches_reglement`
   - `alertes`, `rapprochements_bancaires`
   - `caisse_transactions`, `caisse_renflouements`
   - `rapports_generes`, `audit_log`
   - `bons_commande`, `bons_reception`, `plan_decaissement`

2. **Toutes les FK explicites** avec `ON DELETE RESTRICT` par défaut (jamais CASCADE sauf si justifié).

3. **Vue `journal_depenses`** (section 2.25 du cahier) — joins dans le bon ordre corrigé.

4. **5 triggers** :
   - `trg_dgi_insert` / `trg_dgi_update` (BEFORE) sur `decaissements`
   - `trg_frp_cloture_before` (BEFORE UPDATE) + `trg_frp_cloture_after` (AFTER UPDATE) sur `fiches_reglement`
   - `trg_note_preselection` (BEFORE INS/UPD) sur `candidatures`
   - `trg_peut_rappeler` (AFTER INSERT) sur `decaissements`

5. **Toutes les valeurs ENUM listées explicitement** (statuts, rôles, rubriques, natures).

### 4.3 Étape 1.2 — Données d'initialisation

`database/seed.sql` :

- 1 user admin par défaut (mot de passe à changer au premier login)
- 1 prestataire `DEVDYNAMICS — Interne` (id=1, type=institution) → AJUST-01
- 1 contrat `CONTRAT-INTERNE-DEVDYN` (lié au prestataire interne, type CASI fictif) → utilisé pour les F01 de renflouement Petite Caisse
- Lignes budgétaires depuis l'Annexe B du contrat PAIESC (total 5 600 000 HTG)
- 47 livrables prédéfinis (codes A-01 à F-XX selon catégories)

### 4.4 Étape 1.3 — Couche d'accès & sécurité

| Fichier | Responsabilité |
|---|---|
| `includes/config.php` | Lecture credentials (jamais committé) |
| `includes/db.php` | Singleton PDO avec `ERRMODE_EXCEPTION` + `EMULATE_PREPARES = false` |
| `includes/auth.php` | `start_secure_session()`, `check_role([])`, `csrf_token()`, `verify_csrf()`, `logout()` |
| `includes/functions.php` | Helpers : `e()` (htmlspecialchars), `format_htg()`, `generate_numero()` |
| `includes/uploads.php` | Validation 5 étapes (extension whitelist, MIME `finfo`, taille, renommage hash, compression GD si > 2 Mo) |
| `includes/alerts.php` | Wrapper INSERT dans `alertes` + envoi PHPMailer |

### 4.5 Étape 1.4 — Authentification

- `login.php` : email + password → `password_verify` → `session_regenerate_id(true)` → stocker `user_id`, `role`, `email`, `last_activity` en session
- Middleware (en tête de toute page protégée) : si `time() - last_activity > 3600` → destruction session + redirect login
- `reset.php` : génération `bin2hex(random_bytes(32))` valable 1h, email PHPMailer avec lien, formulaire nouveau mot de passe, `UPDATE users SET mot_de_passe = password_hash(...)`
- CSRF token généré au démarrage de session, vérifié dans **chaque** POST (rejet 403 sinon)

### 4.6 Critères de validation Phase 1

- [ ] `mysql -u <user> -p <db> < schema.sql` s'exécute sans erreur
- [ ] `SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = <db>` = 24
- [ ] `SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = <db>` = 5
- [ ] Login admin par défaut fonctionne, redirige vers `dashboard.php`
- [ ] Tentative d'accès direct à `/portail/admin/contrats.php` sans session → redirect login
- [ ] Reset mot de passe par email fonctionne bout en bout
- [ ] Un POST sans CSRF token renvoie 403

---

## 5. Phase 2 — Workflow F01 → F02 + Signatures (Semaines 1-2)

### 5.1 Étape 2.1 — Page profil + upload signature

`profil.php` :

- Section "Ma signature" : choix upload PNG OU dessin sur `signature_pad.js`
- Stockage : `storage/signatures/users/user_{id}.png` (PNG fond transparent recommandé, 400×150 px min)
- Archivage de l'ancienne signature : `user_{id}_archive_{timestamp}.png` (jamais supprimée — audit)
- `UPDATE users SET signature_image = <chemin>`
- `INSERT audit_log` (`action='upload_fichier'`)

### 5.2 Étape 2.2 — F01 (Fiche d'Imputation)

`compta/f01.php` (rôle : Comptable, lecture pour les autres) :

- Liste paginée + filtres (statut, mois, contrat, ligne budgétaire)
- Formulaire de saisie :
  - Sélection contrat → si `is_cps01 = 1` → afficher deux blocs (Honoraires 130k + Allocation 15k)
  - Sélection ligne budgétaire (FK vers `lignes_budgetaires`) — corrigé V4 : pas du texte libre
  - Rubrique (ENUM), nature_paiement (ENUM), description, montant
- **Blocage gel Petite Caisse** : si un `caisse_renflouements` est en cours (statut ≠ `verse`), désactiver le bouton "Nouvelle dépense F01 (PC)"
- À la soumission : `INSERT imputations` + `peut_rappeler = 1` + alerte email Administrateur (via `alerts.php`)
- **Bouton Rappeler** visible uniquement si `peut_rappeler = 1` (aucun F02 lié) → repasse statut à `brouillon` + INSERT `audit_log` (`f01_rappele`)

### 5.3 Étape 2.3 — F02 (Bon de Décaissement)

`compta/f02.php` (rôle : Administrateur) :

- Liste des F01 au statut `soumis`
- Formulaire de validation :
  - `montant_brut` copié depuis `imputations.montant` (lecture seule)
  - **Politique chèque** : radio "Chèque SOGEBANK" coché par défaut. Si virement choisi → `justification_virement` (TEXT non vide) obligatoire
  - Si chèque : `numero_cheque` obligatoire. Si `is_cps01 = 1` : aussi `numero_cheque_allocation`
  - **Upload obligatoire** : `preuve_paiement_scan` (scan talonnaire chèque ou confirmation virement)
  - **Upload conditionnel** : `facture_scan` (obligatoire pour CASI et CPSP, non applicable CPS)
- Le **trigger MySQL `trg_dgi_insert`** calcule `dgi_2pct` + `net_honoraires` automatiquement
- Calcul PHP en miroir pour affichage : `total_net_a_verser = net_honoraires + COALESCE(montant_allocation, 0)`
- À la validation :
  - Transaction PDO : `INSERT decaissements` + `INSERT audit_log` (`f02_valide`)
  - Copie automatique `users.signature_image` → `decaissements.sig_admin_scan`
  - Alerte email Coordinateur + Prestataire (`f02_valide`)
  - Si `mode = virement ET montant > 30 000` → alerte additionnelle `virement_30k` au Coordinateur

### 5.4 Étape 2.4 — Génération PDF F01 + F02

`pdf/templates/f01.html` et `pdf/templates/f02.html` :

- En-tête DEVDYNAMICS + logo + couleurs `#1F4E79` (bleu marine) et `#1A7A5E` (teal)
- Police Arial, format A4
- Blocs signature : `<div class='signature-block'><img src='{chemin}' style='height:50px;'><p>{Nom} — {Titre} — {date}</p></div>`
- Fallback texte si signature absente : `[Signé électroniquement par X — date]`
- Annexes scans intégrés en fin de Pièce 2 (preuve paiement + facture CASI/CPSP)

`pdf/generate.php` : service unique appelé par tous les contrôleurs avec un identifiant de template et un payload de données.

### 5.5 Critères de validation Phase 2

- [ ] F01 simple (CPS standard) : saisie → soumission → F02 → DGI calculé correctement par le trigger
- [ ] F01 CPS-ACP-01 (`is_cps01=1`) : deux blocs visibles, deux chèques requis au F02
- [ ] Bouton Rappeler fonctionne tant qu'aucun F02 n'existe, disparaît dès création F02
- [ ] Virement > 30 000 HTG déclenche email alerte au Coordinateur
- [ ] PDF F02 inclut signature Admin + scan chèque en annexe
- [ ] Sans CSRF token → 403

---

## 6. Phase 3 — ASF → NH → FRP + Tokens (Semaines 2-3)

### 6.1 Étape 3.1 — ASF (Attestation Service Fait)

`compta/asf.php` (rôle : Coordinateur) :

- Formulaire de certification : livrables réalisés, statut (`conformes`/`partiels`/`non_conformes`), taux_presence (NULL si CPSP)
- **Upload optionnel mais recommandé** : pièces jointes terrain en JSON (`pieces_jointes_json`) — listes de présence, photos
- À la certification :
  - Transaction PDO :
    - `INSERT attestations_service_fait`
    - Copie `users.signature_image` → `asf.sig_coord_scan`
    - **Génération token NH** (`type=note_honoraires`, expire = NOW() + 72h) via `TokenService`
    - `INSERT alertes` (`asf_certifiee`) + email PHPMailer au prestataire avec lien `api/nh_token.php?t=TOKEN`
    - `INSERT audit_log`

### 6.2 Étape 3.2 — Endpoint tokenisé NH

`api/nh_token.php` (aucune session — accès via token uniquement) :

- Validation : `tokens.expire_at > NOW() AND tokens.utilise = 0`
- Si invalide : page d'erreur + instruction contacter Administrateur
- Rate limiting : max 10 tentatives/IP/heure (via `COUNT(*) FROM tokens` + IP loggée dans `audit_log`)
- Formulaire NH :
  - Description prestation, montant brut (doit correspondre à `imputations.montant`), mode paiement
  - **Pad signature_pad.js** dans un `<canvas>` masqué jusqu'à validation des champs
  - À la soumission : `canvas.toDataURL('image/png')` → champ caché POST → PHP `base64_decode` + validation `finfo` (rejet si non PNG) + `file_put_contents('storage/signatures/presta/nh_{token_id}.png')`
  - Transaction : `INSERT notes_honoraires` (avec `sig_presta_scan`) + `UPDATE tokens SET utilise = 1` + génération token FRP + email Admin + `INSERT audit_log`

### 6.3 Étape 3.3 — Régénération token

Bouton "Renvoyer le lien" dans la fiche dépense (Admin uniquement) :
- INSERT nouveau token (ne pas modifier l'ancien — conservé pour audit)
- Email avec nouveau lien
- `INSERT audit_log` (`token_regenere`)

### 6.4 Étape 3.4 — FRP (Fiche de Règlement)

`compta/frp.php` :

- Affiche les NH soumises en attente de FRP
- **Triple signature** (3 utilisateurs distincts pour les dépenses normales) :
  - Prestataire : via `api/frp_token.php?t=TOKEN` (signature_pad.js)
  - Administrateur : copie automatique `users.signature_image`
  - Coordinateur : copie automatique `users.signature_image`
- **Cas renflouement Petite Caisse** : deux signatures uniquement (Admin + Coord) — pas de prestataire → AJUST-03
- À la 3e signature (ou 2e pour renflouement) :
  - Trigger `trg_frp_cloture_before` (BEFORE UPDATE) → `SET NEW.date_cloture = NOW()`
  - Trigger `trg_frp_cloture_after` (AFTER UPDATE) → `UPDATE imputations SET statut='valide', peut_rappeler=0`
  - PHP génère le PDF `dossier_complet.pdf` (5 pièces paginées, scans en annexe par section)
  - Email global aux 3 parties + Coordinateur (`frp_cloture`)

### 6.5 Étape 3.5 — Template `dossier_complet.html`

Structure paginée (template unique mPDF) :
1. **Pièce 1** : Fiche d'Imputation (F01)
2. **Pièce 2** : Bon de Décaissement (F02) + annexes scans (preuve paiement + facture)
3. **Pièce 3** : ASF + annexes terrain
4. **Pièce 4** : NH — ou mention "Non applicable — opération interne" si renflouement PC (AJUST-03)
5. **Pièce 5** : FRP + annexes devis TCD si applicable

Stockage : `storage/dossiers/YYYY/M0X/DEP-XXX/dossier_complet.pdf`

### 6.6 Critères de validation Phase 3

- [ ] Email NH envoyé au prestataire avec lien fonctionnel
- [ ] Pad signature fonctionne sur desktop (souris) et mobile (doigt)
- [ ] Token expiré → message d'erreur + bouton "Renvoyer le lien" disponible pour Admin
- [ ] Triple signature FRP → trigger met `date_cloture` + `imputations.statut = 'valide'` automatiquement
- [ ] PDF `dossier_complet.pdf` généré avec 5 sections et signatures intégrées
- [ ] Renflouement PC : Pièce 4 = section de substitution, FRP avec 2 signatures

---

## 7. Phase 4 — Comptabilité complète (Semaines 3-4)

### 7.1 Étape 4.1 — Journal des dépenses

`compta/journal.php` :
- SELECT sur la vue `journal_depenses` avec filtres (mois, rubrique, ligne, prestataire, mode paiement, statut)
- Totaux en pied de page : total décaissé, total DGI, total net, nb dossiers clôturés/en cours
- Export CSV (encodage UTF-8 BOM)

### 7.2 Étape 4.2 — Suivi budgétaire

`compta/budget.php` :
- Requête principale :
  ```sql
  SELECT lb.code, lb.libelle, lb.budget_initial_htg,
         COALESCE(SUM(d.montant_brut), 0) AS consomme
    FROM lignes_budgetaires lb
    LEFT JOIN imputations i ON i.ligne_budgetaire_id = lb.id AND i.statut = 'valide'
    LEFT JOIN decaissements d ON d.imputation_id = i.id
   GROUP BY lb.id
  ```
- Barres de progression : verte <70%, orange ≥70%, rouge ≥90%
- Graphique Chart.js (barres horizontales)
- Déclenchement alertes : `budget_70pct`, `budget_90pct` (par ligne + global)

### 7.3 Étape 4.3 — Plan de Décaissement Prévisionnel

`compta/plan_decaissement.php` (rôle : Coordinateur) :
- Grille tableau croisé : lignes budgétaires × mois M01–M06
- Saisie par cellule → `INSERT/UPDATE plan_decaissement`
- Toute modification horodatée (`updated_at`) + `INSERT audit_log` (`pdp_modifie`)
- Comparaison prévu vs réalisé temps réel (requête section 2.24 du cahier)
- Alerte `pdp_ecart` si écart > 20 % sur une ligne
- Export CSV pour le bailleur PAIESC

### 7.4 Étape 4.4 — Rapprochement bancaire

`compta/rapprochement.php` :
- Sélecteur mois/année
- **Upload obligatoire** du scan relevé SOGEBANK avant toute saisie (bloquant)
- Double saisie du solde (deux champs comparés)
- Calcul écart automatique → `observations` obligatoire si écart ≠ 0
- Génération PDF `rapprochement.html`

### 7.5 Étape 4.5 — Petite Caisse (Fonds Imprest 30 000 HTG)

4 pages :

| Page | Rôle | Contenu |
|---|---|---|
| `petite_caisse.php` | Comptable | Formulaire F-PC + solde temps réel + liste dépenses non renflouées |
| `petite_caisse_journal.php` | Tous (lecture) | Journal filtrable par mois et rubrique |
| `petite_caisse_renflouement.php` | Admin | Historique + formulaire demande de renflouement |
| `petite_caisse_rapprochement.php` | Admin | Vue espèces déclarées vs solde app |

**Règles côté PHP** :
- Plafond 10 000 HTG par transaction (au-delà : message "Utiliser circuit F01/F02")
- `recu_scan` obligatoire (refus si NULL)
- Si solde < 9 000 HTG → alerte `caisse_seuil` + email Comptable + Admin
- **Gel pendant renflouement** (AJUST-02) : bouton "Nouvelle dépense" grisé tant que `caisse_renflouements.statut ≠ 'verse'`
- Renflouement : F01 auto-générée référence `CONTRAT-INTERNE-DEVDYN` (AJUST-01)
- Transaction PDO : `INSERT caisse_renflouements` + `INSERT imputations` + `UPDATE caisse_transactions SET renflouement_id`

### 7.6 Étape 4.6 — Système d'alertes complet

Implémenter les **18 types** dans `includes/alerts.php` :

| Type | Déclencheur | Destinataires |
|---|---|---|
| `f01_soumis` | F01 soumis | Admin |
| `f02_valide` | F02 validé | Coord + Prestataire |
| `asf_certifiee` | ASF certifiée | Prestataire (lien NH) |
| `nh_soumise` | NH soumise | Admin + lien FRP au Prestataire |
| `frp_cloture` | FRP clôturé | 3 parties + Coord |
| `budget_70pct` / `budget_90pct` | Seuils budgétaires | Coord + Admin |
| `virement_30k` | Virement > 30 000 | Coord |
| `livrable_retard` | Retard > 7j | Admin |
| `contrat_expiration` | Expiration < 30j | Admin |
| `caisse_seuil` | Solde PC < 9 000 | Comptable + Admin |
| `caisse_mensuel` | Fin mois sans renflouement | Admin |
| `token_expire` | Token NH/FRP expiré | Prestataire + Admin |
| `renflouement_requis` | Demande renflouement | Admin |
| `bc_sans_reception` | BC sans BR > 7j | Admin |
| `br_non_conforme` | BR non conforme | Admin + Coord |
| `pdp_ecart` | Écart PDP > 20% | Coord |
| `saisie_retroactive` | Saisie rétroactive | Coord |

Toutes les alertes : `INSERT alertes` + envoi PHPMailer + gestion réessai si `statut_envoi = 'echec'`.

### 7.7 Critères de validation Phase 4

- [ ] Journal export CSV ouvre correctement dans Excel (UTF-8 BOM)
- [ ] Budget global affiche 5 600 000 HTG + alertes 70/90% correctes
- [ ] PDP : saisir M01, faire une dépense réelle → écart visible et conforme
- [ ] Rapprochement : sans scan relevé → soumission bloquée
- [ ] Petite Caisse : transaction de 12 000 HTG → refusée avec message clair
- [ ] Solde PC à 8 500 HTG → email alerte envoyé
- [ ] Renflouement déclenché → F01 auto-créée avec `CONTRAT-INTERNE-DEVDYN`
- [ ] Pendant renflouement : nouvelle dépense F-PC bloquée

---

## 8. Phase 5 — Module Administration (Semaines 4-5)

### 8.1 Étape 5.1 — Contrats

`admin/contrats.php` :
- Liste paginée, filtres type (CPS/CPSP/CASI/CPSI) + statut
- Création : sélection prestataire, type, dates, montant — case `is_cps01` visible uniquement pour CPS
- Upload PDF signé → `storage/contrats/`
- Alerte orange si `date_fin < CURDATE() + 30 jours` (`contrat_expiration`)
- Archivage : statut → `cloture`

### 8.2 Étape 5.2 — Bon de Commande

`admin/bon_commande.php` (rôle : Admin, contrats CASI uniquement) :
- Formulaire : sélection contrat CASI, objet, type (`biens_materiels`/`services`)
- Lignes en JSON : `[{description, quantite, unite, prix_unitaire, montant_ligne}]`
- Calcul auto montant total = somme des lignes (doit correspondre au montant du contrat)
- Génération PDF `bc.html` + signature Admin → `storage/bons_commande/BC-ACP-XXX/`
- Statut initial : `emis`
- **Blocage métier** : F01 d'une CASI biens matériels ne peut être soumis que si BC associé est `recu`
- Pour CASI services : F01 peut être soumis dès `emis`

### 8.3 Étape 5.3 — Bon de Réception

`admin/bon_reception.php` (rôle : Admin) :
- Formulaire : sélection BC, date réception, lignes reçues (quantité reçue vs commandée, conformité)
- Statut livraison : `conforme` / `partielle` / `non_conforme`
- Upload pièces (photos, bon livraison fournisseur)
- À la validation : `UPDATE bons_commande SET statut = 'recu' | 'partiellement_recu'`
- **Blocage** : F02 bloqué si BR `non_conforme` (alerte `br_non_conforme`)
- Alerte `bc_sans_reception` si BC reste sans BR > 7 jours

### 8.4 Étape 5.4 — TCD (Tableau Comparatif Devis)

`admin/tcd.php` :
- Obligatoire pour achat ≥ 300 000 HTG (CASI ou CPSP)
- Formulaire : contrat concerné, prestataire retenu, motif choix
- 3 devis : nom, montant, **scan obligatoire chacun** (`devis_1/2/3_scan`)
- Validation Coordinateur (case à cocher)
- **Blocage** : sans TCD validé → impossible de créer F01 lié à ce contrat

### 8.5 Étape 5.5 — Candidatures

`admin/candidatures.php` :
- Import CSV depuis `Registre_Candidatures_ACP.xlsx`
- **Déduplication** sur combinaison (`telephone + date_naissance`) → option écraser/ignorer
- Notation manuelle, calculs auto par trigger `trg_note_preselection`
- Filtres + export CSV
- **Quota** : alerte si femmes retenues < 15 (sur 30)

### 8.6 Étape 5.6 — Livrables (47 livrables)

`admin/livrables.php` :
- Vue tableau : code, catégorie, description, responsable, date cible, statut
- Mise à jour statut : `non_demarre` → `en_cours` → `livre`
- Alerte rouge + email Admin si `date_cible < CURDATE() AND statut ≠ 'livre'` après > 7 jours
- Barres de progression globale + par catégorie

### 8.7 Étape 5.7 — Partenaires + FECP

`admin/partenaires.php` :
- Fiche partenaire : coordonnées, statut CPSI, upload convention
- **FECP mensuelle** : formulaire avec engagements DEVDYN + Partenaire en JSON
- **Double signature** : Coord DEVDYN (upload) + Partenaire (signature_pad.js direct sur écran partagé)
- Génération PDF `fecp.html`

### 8.8 Critères de validation Phase 5

- [ ] Contrat CASI ≥ 300 000 sans TCD validé → F01 refusé avec message clair
- [ ] BC émis → BR validé conforme → F01 puis F02 acceptés
- [ ] BR non conforme → F02 bloqué + alerte
- [ ] Import CSV 100 candidatures avec 5 doublons → 95 importées, 5 signalées
- [ ] FECP : double signature → PDF généré + email partenaire

---

## 9. Phase 6 — Saisie rétroactive (Semaine 6)

### 9.1 Étape 6.1 — Formulaire individuel

`compta/retroactif.php` (rôle : Administrateur uniquement) :
- Formulaire en 6 sections sur une seule page : Identification + 5 pièces
- Champs : date dépense (≥ 24 avril 2026, vérification PHP), contrat, ligne, rubrique, nature, montant
- Upload simultané de toutes les pièces déjà disponibles :
  - F01 : pré-rempli depuis identification
  - F02 : N° chèque, scan chèque émis, scan facture si CASI/CPSP
  - ASF : livrables, statut, pièces jointes terrain
  - NH : description, montant — pad signature OU upload NH papier scannée
  - FRP : date paiement, 3 signatures (pads OU upload FRP papier signée)
- À la soumission :
  - Transaction PDO : INSERT dans toutes les tables avec `is_retroactif = 1` et `date_saisie_reelle = NOW()`
  - PDF généré avec **tampon visible** "SAISIE RÉTROACTIVE — [date réelle]" (template `dossier_retroactif.html`)
  - `INSERT audit_log` (`saisie_retroactive`)
  - Alerte email Coordinateur pour validation cohérence

### 9.2 Étape 6.2 — Import CSV en masse

`compta/retroactif_import.php` :
- 10 colonnes obligatoires + 1 optionnelle (cf. section 4.7 du cahier)
- Limite : **50 lignes max par import** (au-delà : découper)
- Création automatique de tous les enregistrements (imputations, decaissements, asf, notes_honoraires, fiches_reglement) avec `is_retroactif = 1`
- Page de complétion : upload des scans dans un second temps
- Dossier non clôturé tant que scans obligatoires absents
- Délai 7 jours pour compléter les scans

### 9.3 Étape 6.3 — Règles de validation

- Date dépense ≥ 24 avril 2026 (rejet sinon)
- Accès Admin uniquement (Comptable refusé)
- Non modifiable après validation Coordinateur — uniquement annulation avec justification dans `audit_log`
- Tous les scans obligatoires identiques aux opérations courantes

### 9.4 Critères de validation Phase 6

- [ ] Saisie rétroactive avec date 1er mars 2026 → rejetée
- [ ] Saisie rétroactive complète d'une dépense d'avril 2026 → dossier généré avec tampon
- [ ] Import CSV 30 lignes → 30 dossiers en attente de scans
- [ ] Comptable tente d'accéder à `retroactif.php` → 403

---

## 10. Phase 7 — Module Reporting (Semaines 6-7)

### 10.1 Étape 7.1 — RFM (Rapport Financier Mensuel)

`reporting/rapports.php` + template `pdf/templates/rfm.html` :

Structure du PDF (5-8 pages) :
1. Page de garde (logo, titre, mois, date génération, signataire)
2. Résumé exécutif (budget consommé total, mois, solde)
3. **Tableau prévu vs réalisé** par ligne budgétaire (intégration PDP)
4. Journal des dépenses du mois
5. Récapitulatif par prestataire
6. Solde Petite Caisse
7. Rapprochement bancaire
8. Signatures Coord + Admin

### 10.2 Étape 7.2 — DJ (Dossier Justificatifs)

`reporting/export.php` :

**Performance Hostinger mutualisé** (audit V4) — en tête du script :
```php
set_time_limit(300);
ini_set('memory_limit', '512M');
```
Limite à **30 dossiers max par export ZIP** — au-delà, proposer sous-périodes.

Structure ZIP :
```
DJ-ACP-2026-M01.zip
├── 00_INDEX.pdf                              ← Table des matières
├── 01_Synthese/
│   ├── 01_RFM-ACP-2026-M01.pdf
│   ├── 02_Rapprochement_M01.pdf
│   └── 03_Releve_SOGEBANK_M01.pdf
├── 02_Dossiers_Depenses/                     ← Triés selon option choisie
│   ├── DEP-001_NOM_Prenom/Dossier_Complet_DEP-001.pdf
│   └── DEP-002.../...
└── 03_Petite_Caisse/
    ├── Journal_PC_M01.pdf
    ├── Renflouement_RENF-ACP-001-2026.pdf
    └── Recus/F-PC-ACP-001.jpg ...
```

Options de tri : `chronologique`, `ligne_budgetaire`, `type_contrat`.

Pipeline :
1. Vérification préalable : dossiers de la période au statut `valide` (FRP clôturé). Si incomplets → liste + option "forcer avec avertissement"
2. Transaction PDO : `INSERT rapports_generes` (statut `en_cours`)
3. Compilation PHP : assemblage PDF + scans depuis `storage/`
4. Génération index PDF (`index_rapport.html`)
5. Création ZIP via `ZipArchive` (extension native PHP 8.x)
6. `UPDATE rapports_generes SET statut = 'genere'` + commit
7. Si erreur : `UPDATE statut = 'erreur'` + rollback

### 10.3 Étape 7.3 — Rapport Cumulé Projet

Génération à la demande sur plage de mois (ex : M01–M03).

**Chart.js → mPDF** (technique imposée par Hostinger mutualisé) :
1. Écran de préparation : Chart.js rend le graphique dans `<canvas>` masqué
2. `canvas.toDataURL('image/png')` → Base64
3. POST silencieux au script mPDF
4. mPDF insère l'image Base64 dans le PDF

### 10.4 Étape 7.4 — Téléchargement sécurisé

`reporting/serve_rapport.php?id=X&token=Y` :
- Vérification rôle ou token temporaire
- Streaming avec en-têtes appropriés (`Content-Type: application/zip` + `Content-Disposition: attachment`)
- Aucune URL directe vers `storage/`

### 10.5 Critères de validation Phase 7

- [ ] RFM M01 généré en < 30 secondes
- [ ] DJ avec 25 dossiers généré en ZIP fonctionnel, ouverture sans erreur dans 7-Zip et Explorer Windows
- [ ] DJ avec 35 dossiers → message "limiter à 30" + suggestion sous-périodes
- [ ] Rapport Cumulé M01-M03 inclut un graphique Chart.js converti
- [ ] Téléchargement sans token → 403

---

## 11. Phase 8 — Dashboard + Responsive (Semaines 7-8)

### 11.1 Étape 8.1 — Dashboard

`dashboard.php` (Coord + Admin, lecture seule) — blocs :

| Bloc | Source de données | Alerte |
|---|---|---|
| Budget global (consommé / 5 600 000) | Vue agrégée | Orange ≥70%, rouge ≥90% |
| Budget par rubrique | GROUP BY rubrique | Idem |
| Prévu vs Réalisé (mois en cours) | Requête PDP (section 2.24) | Écart négatif rouge |
| Livrables X/47 | COUNT GROUP BY statut | Retards rouge |
| Candidatures X retenus/30, X femmes/15 | COUNT decision_finale | Alerte si femmes < 15 |
| Dossiers en cours par étape | COUNT par table | Bloqués > 48h |
| Petite Caisse (solde / 30 000) | Calcul temps réel | Orange < 9 000 |
| Partenaires (5 CPSI) | statut | Partiel/inactif orange |
| Prochaines échéances | contrats < 30j, livrables < 14j | Liste chrono |
| Rapports récents (5 derniers) | `rapports_generes` ORDER BY created_at DESC LIMIT 5 | — |

### 11.2 Étape 8.2 — Responsive Bootstrap 5

- Comptable utilise smartphone sur le terrain → testé prioritairement
- Formulaires F01 et F-PC : optimisés mobile (gros boutons, champs full-width)
- Tableaux : passage en cartes empilées < 768 px

### 11.3 Critères de validation Phase 8

- [ ] Dashboard charge en < 2 secondes
- [ ] Tous les indicateurs s'actualisent après une nouvelle dépense
- [ ] Test smartphone (Chrome mobile + Safari iOS) : F01 saisissable, dashboard lisible

---

## 12. Phase 9 — Tests UAT + Production (Semaines 8-9)

### 12.1 Grille UAT V2

| Code | Cas de test | Critère |
|---|---|---|
| WFL-01 | F01 standard → FRP clôturé | Statut final `valide`, PDF 5 pièces |
| WFL-02 | F01 CPS-ACP-01 (double bloc) | 2 chèques distincts au F02 |
| WFL-03 | Rappel F01 avant F02 | Bouton visible, statut repasse à `brouillon` |
| WFL-04 | Virement > 30 000 | Alerte email Coord reçue |
| WFL-05 | Renflouement PC complet | F01 auto, FRP 2 signatures, dossier sans NH |
| SEC-01 | Tentative accès direct `/storage/` | 403 Forbidden |
| SEC-02 | POST sans CSRF | 403 |
| SEC-03 | Upload `.php` déguisé en `.pdf` | Rejeté par `finfo` |
| PC-01 à PC-06 | Tous scénarios Petite Caisse | Voir cahier |
| RPT-01 à RPT-04 | Génération RFM + DJ + Cumulé + index | Tous PDF/ZIP valides |

### 12.2 Tests bout en bout supplémentaires

- Import CSV candidatures 100 lignes avec doublons
- Workflow rétroactif (saisie individuelle + import CSV)
- BC → BR conforme → F02 acceptée
- BC → BR non conforme → F02 bloquée
- TCD manquant pour CASI 350 000 HTG → F01 bloqué
- Session expirée après 60 min d'inactivité

### 12.3 Formation équipe

- Manuel utilisateur PDF (1 par rôle : Coord, Admin, Comptable)
- 2 sessions live : workflow comptable + module reporting
- Procédure d'urgence (reset mot de passe, contact technique)

### 12.4 Mise en production

1. **Vérifier que le site public continue de fonctionner** avant tout déploiement portail (smoke test : page d'accueil, login étudiant, page candidature)
2. Créer la BDD `u<id>_portail` sur Hostinger (distincte de l'existante)
3. Snapshot BDD locale portail → export SQL → import sur Hostinger dans la nouvelle BDD
4. Upload code : `git pull` (le dossier `portail/` arrive à côté de `index.html`, `pages/`, `api/` sans les écraser)
5. Créer `portail/includes/config.php` directement sur le serveur (jamais via Git)
6. Création des dossiers `portail/storage/` avec bonnes permissions (755 dossiers, 644 fichiers, 600 `config.php`)
7. Vérifier que `portail/.htaccess` est en place (HTTPS + sécurité) et que `portail/storage/.htaccess` bloque l'accès direct
8. Test login admin par défaut → changement mot de passe immédiat
9. Insertion données seed (script SQL séparé)
10. Smoke test des 9 critères UAT critiques
11. **Re-vérifier le site public** : aucune régression sur `index.html`, `pages/`, `api/` — toutes les URL publiques répondent normalement
12. Communiquer l'URL `https://dev-dynamics.org/portail/` aux utilisateurs internes par canal privé (jamais publiquement)

---

## 13. Sécurité — Checklist transverse

| Domaine | Mesure | Vérification |
|---|---|---|
| Auth | bcrypt + `password_verify` | Aucun mot de passe en clair |
| Session | `session_regenerate_id(true)` après login | `last_activity` mis à jour à chaque requête |
| Timeout | 60 min stricts | Test : attendre 61 min → redirect login |
| CSRF | Token dans chaque POST | Test : POST sans token → 403 |
| SQL | PDO préparé + `EMULATE_PREPARES = false` | Aucun `mysqli_query` ni concat string |
| XSS | `htmlspecialchars($x, ENT_QUOTES, 'UTF-8')` (helper `e()`) | Audit grep manuel |
| Uploads | Whitelist extension + MIME `finfo` + renommage hash + GD compression + `.htaccess` | Test upload `evil.php.pdf` → rejeté |
| Tokens 72h | `bin2hex(random_bytes(32))` + expire DATETIME + `utilise` flag | Test token expiré → 403 |
| Rate limiting | Max 10 tentatives/IP/h via `audit_log` | Test 11e tentative → blocage |
| Audit | INSERT-only, jamais d'UPDATE/DELETE par utilisateur | Privilèges MySQL : `audit_log` REVOKE UPDATE/DELETE |
| Permissions fichiers | 755 dossiers / 644 fichiers / 600 `config.php` | `ls -la` après déploiement |
| HTTPS | Toutes les pages | `$_SERVER['HTTPS']` check + redirect |
| Email | PHPMailer + SMTP, jamais `mail()` | `grep -r "mail(" includes/` = 0 résultats |

---

## 14. Conventions de code

### 14.1 PHP

- Strict types : `declare(strict_types=1);` en tête de chaque fichier
- PSR-12 pour le style
- Tous les noms de fonction en `snake_case`, classes en `PascalCase`
- Pas de `echo` direct dans les controllers — utiliser des vues séparées
- Toujours `try/catch` autour des opérations PDO multi-écritures

### 14.2 SQL

- Noms de tables : pluriel, `snake_case`
- Index explicites sur toutes les FK + colonnes filtrées fréquemment (`date_depense`, `statut`)
- ENUM : valeurs en minuscules `snake_case`
- Triggers nommés `trg_<table>_<event>` (ex : `trg_dgi_insert`)

### 14.3 Frontend

- Bootstrap 5.3 classes utilitaires d'abord
- CSS custom dans `assets/css/portail.css` uniquement pour overrides
- JS modulaire dans `assets/js/` — un fichier par feature
- Pas de jQuery (Bootstrap 5 ne le requiert plus)

### 14.4 Git

- Branches : `feature/<phase>-<feature>`, `fix/<ticket>`
- Commits en français, format : `<phase>: <action>` (ex : `Phase 2: ajout calcul DGI dans trg_dgi_insert`)
- PR sur `develop`, merge sur `main` uniquement à la fin de chaque phase
- Tag `vX.Y` à chaque fin de phase

---

## 15. Risques identifiés et mitigations

| Risque | Probabilité | Impact | Mitigation |
|---|---|---|---|
| Timeout PHP sur génération DJ > 30 dossiers | Élevée | Bloque le reporting | `set_time_limit(300)` + limite UI + génération par sous-période |
| Stockage Hostinger saturé par scans | Moyenne | Service interrompu | Compression GD auto > 2 Mo + archivage trimestriel |
| Email SMTP Hostinger bloqué temporairement | Moyenne | Workflow ralenti | Table `alertes` avec retry + alerte UI dans dashboard |
| Token NH/FRP perdu par prestataire | Élevée | Étape bloquée | Bouton "Renvoyer le lien" Admin + audit log |
| Saisie rétroactive massive en S8 | Élevée | Audit complexe | Limite 50 lignes/import CSV + tampon visible + validation Coord obligatoire |
| Trigger MySQL échoue silencieusement | Faible | Calculs DGI faux | Calcul PHP en miroir + comparaison à l'affichage + alerte si divergence |
| Concurrence sur Petite Caisse | Faible | Solde incohérent | `SELECT ... FOR UPDATE` dans la transaction + verrou app pendant renflouement |
| Signature_pad.js non supporté sur vieux smartphones | Faible | Prestataire ne peut signer | Fallback upload NH papier scannée + audit |

---

## 16. Livrables par phase

| Phase | Semaines | Livrable principal | Critère "Done" |
|---|---|---|---|
| 1 | S1 | BDD + Auth + Reset | Login fonctionnel, 24 tables + 5 triggers en place |
| 2 | S1-S2 | F01 + F02 + signatures upload | Workflow F01 → F02 avec DGI auto + PDF + uploads |
| 3 | S2-S3 | ASF + NH + FRP + tokens | Chaîne complète F01 → FRP avec triple signature |
| 4 | S3-S4 | Journal + Budget + PDP + Rapprochement + Petite Caisse + 18 alertes | Module Comptabilité 100% |
| 5 | S4-S5 | Contrats + BC + BR + TCD + Candidatures + Livrables + FECP | Module Administration 100% |
| 6 | S6 | Saisie rétroactive (individuelle + CSV) | 50 lignes importables, dossier avec tampon |
| 7 | S6-S7 | RFM + DJ (ZIP) + Rapport Cumulé | 3 types de rapports générés et téléchargeables |
| 8 | S7-S8 | Dashboard + Responsive | Tous les indicateurs + smartphone OK |
| 9 | S8-S9 | UAT + Formation + Production | App en production sur dev-dynamics.org/portail/ |

---

## Annexe A — Données d'initialisation obligatoires

À insérer **avant** la mise en service :

1. **users** : 1 administrateur par défaut (à mot de passe temporaire)
2. **prestataires** : id=1 = `DEVDYNAMICS — Interne`, type=`institution`
3. **contrats** : id=1 = `CONTRAT-INTERNE-DEVDYN` lié au prestataire interne (utilisé pour les F01 de renflouement PC — AJUST-01)
4. **lignes_budgetaires** : toutes les lignes de l'Annexe B du contrat PAIESC (total = 5 600 000 HTG)
5. **livrables** : 47 entrées prédéfinies selon les catégories (`partenariats`, `formation`, `terrain`, `communication`, `rapports`, `scolaire`)

## Annexe B — Endpoints sensibles

| URL | Méthode | Rôle requis | Notes |
|---|---|---|---|
| `/portail/login.php` | POST | Public | Rate limit 5 tentatives/IP/5min |
| `/portail/reset.php?token=X` | GET/POST | Public | Token 1h |
| `/portail/api/nh_token.php?t=X` | GET/POST | Token | Rate limit 10/IP/h |
| `/portail/api/frp_token.php?t=X` | GET/POST | Token | Idem |
| `/portail/pdf/serve.php?id=X` | GET | Session ou token | Vérif rôle ou token avant streaming |
| `/portail/reporting/serve_rapport.php?id=X` | GET | Coord/Admin | Idem |

## Annexe C — Checklist pré-déploiement

### Côté portail
- [ ] `portail/includes/config.php` créé sur serveur avec credentials prod (jamais committé)
- [ ] `portail/storage/.htaccess` (`Deny from all`) en place et testé via URL directe
- [ ] HTTPS forcé via `portail/.htaccess`
- [ ] mPDF + PHPMailer déposés dans `portail/lib/` via FTP
- [ ] Permissions : 755 dossiers / 644 fichiers / 600 `config.php`
- [ ] Tous les dossiers `portail/storage/...` créés avec bonnes perms (écriture pour PHP)
- [ ] `portail/robots.txt` avec `Disallow: /` créé
- [ ] BDD `u<id>_portail` créée, distincte de la BDD du site public
- [ ] Backup automatique configuré sur **les deux** BDD Hostinger
- [ ] Smoke test des 9 critères UAT critiques
- [ ] Mot de passe admin par défaut **changé**
- [ ] Email `noreply@dev-dynamics.org` testé (login SMTP + envoi réel)
- [ ] `session_name('PORTAIL_SID')` défini dans `portail/includes/auth.php` (évite collision théorique avec sessions tierces)
- [ ] Documentation utilisateur livrée aux 3 rôles (Coord, Admin, Comptable)

### Côté site public — non-régression
- [ ] `index.html` charge normalement (page d'accueil)
- [ ] Login étudiant fonctionne (`pages/student-login.html`)
- [ ] Login admin fonctionne (`pages/admin-login.html`)
- [ ] API publique répond (`/api/...` endpoints critiques)
- [ ] Aucun lien vers `/portail/` visible sur les pages publiques
- [ ] `robots.txt` racine (s'il existe) inchangé
