# Déploiement Bousòl (Hostinger)

## 1. Pré-requis

| Étape | Action |
|---|---|
| PHP 8.1+ | hPanel → Avancé → Configuration PHP |
| Extensions requises | `pdo_mysql`, `mbstring`, `fileinfo`, `openssl` |
| Extensions souhaitables | `gd` (traitement des spécimens PNG), `zip` (sinon l'export bascule sur `phar`) |
| Sauvegarde | hPanel → Fichiers → Backups → activer pour la base Bousòl |

## 2. Base de données

hPanel → Bases de données MySQL. Le préfixe du compte (`u218662965_`) est imposé et compté
dans la longueur ; ne saisir que le suffixe.

| Élément | Valeur à saisir | Nom complet obtenu |
|---|---|---|
| Base de données | `bousol` | `u218662965_bousol` |
| Utilisateur | `bousol_app` | `u218662965_bousol_app` |
| Mot de passe | généré par hPanel, 24+ caractères | — |

Le mot de passe se colle directement dans `config.php` (jamais dans le dépôt, jamais dans un
fichier `.md`) : c'est exactement l'erreur commise sur l'ancien portail.

Droits à accorder à l'utilisateur : `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `DROP`,
`INDEX`, `ALTER`, `CREATE ROUTINE`, `TRIGGER`. Les deux derniers sont indispensables :
l'import échoue sans eux, le schéma installe 8 triggers.

Importer via phpMyAdmin, dans cet ordre :

1. `database/schema.sql` — 49 tables
2. `database/schema_triggers.sql` — 8 garde-fous d'immuabilité
3. `database/seed.sql` — nomenclature, paramètres, plan de comptes, accès initial

Vérifier, en une seule requête à coller dans l'onglet SQL de phpMyAdmin :

```sql
SELECT
  (SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = 'u218662965_bousol')                      AS tables_attendu_49,
  (SELECT COUNT(*) FROM information_schema.triggers
     WHERE trigger_schema = 'u218662965_bousol')                    AS triggers_attendu_8,
  (SELECT montant FROM u218662965_bousol.lignes_budgetaires
     WHERE code = '11')                                             AS total_attendu_5599889_14,
  VERSION()                                                         AS version_serveur;
```

Le nom de la base est écrit en toutes lettres, sans `DATABASE()` : dans phpMyAdmin la base
« courante » est celle sélectionnée à gauche, et il est facile de lancer la requête depuis
`information_schema` sans s'en apercevoir. `DATABASE()` compterait alors les tables
d'`information_schema` (79 sur MySQL, jusqu'à 98 sur MariaDB) et 0 trigger, ce qui ressemble
à une installation ratée alors que tout est en place.

### Si l'étape 2 échoue avec l'erreur 1419

> `You do not have the SUPER privilege and binary logging is enabled`

C'est le cas le plus probable sur un hébergement mutualisé : MySQL journalise les écritures et
refuse la création de triggers à un utilisateur ordinaire. Les tables et les données s'importent
malgré tout, **et rien ne signale l'absence des triggers côté base** : ne pas passer outre sans
lire ce qui suit.

Ce que ces 8 triggers garantissent, et qui disparaît sans eux : journal d'audit et paramètres
non modifiables et non supprimables, appositions de signature immuables, fichiers remplacés mais
jamais supprimés, imputation refusée sur une ligne non imputable (dont la provision). Ce sont des
exigences explicites du cahier des charges (§ 7.5) et des cas de recette de l'annexe G.

Marche à suivre : ouvrir un ticket au support Hostinger et demander l'activation de
`log_bin_trust_function_creators` sur la base (ou l'octroi du privilège `SET_USER_ID`), puis
réimporter `schema_triggers.sql`. C'est une option de configuration MySQL courante et sans risque
pour l'hébergeur : elle n'accorde aucun droit d'administration.

En attendant, l'application reste utilisable : elle n'écrit jamais dans ces tables autrement qu'en
ajout. Mais la protection ne vaut plus que ce que vaut le code, et disparaît devant un accès direct
par phpMyAdmin. Tant que les 8 triggers ne sont pas installés, le tableau de bord affiche une
alerte permanente et la page **Paramétrage → Modules** liste précisément les règles non appliquées.

## 3. Code

```bash
cd ~/public_html && git pull origin main
# bousol/ apparaît à côté de index.html, pages/, api/
```

## 4. Configuration (jamais committée)

```bash
cp bousol/includes/config.example.php bousol/includes/config.php
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'   # valeur de coffre_key_hex
nano bousol/includes/config.php                       # db.*, coffre_key_hex
chmod 600 bousol/includes/config.php
chmod -R 750 bousol/storage
```

La clé du coffre doit être conservée hors du serveur (gestionnaire de mots de passe de la direction) :
sans elle, spécimens, pièces d'identité et exports chiffrés sont illisibles.

## 5. mPDF

Copier `vendor/mpdf` (composer require mpdf/mpdf en local) vers `bousol/lib/mpdf/`.
Vérifier `bousol/lib/mpdf/autoload.php`.

## 6. Diagnostic automatique

Une fois le code et `config.php` en place, ouvrir **`https://dev-dynamics.org/bousol/diagnostic.php`**.
La page contrôle en une passe : version de PHP et extensions, permissions et clé du coffre
(chiffrement testé pour de vrai), connexion à la base, 49 tables, 8 garde-fous et leur efficacité
réelle, support JSON, nomenclature budgétaire, dossiers de stockage inscriptibles, et génération
d'un PDF avec l'en-tête. Aucun mot de passe ni clé n'y apparaît.

Elle est librement accessible tant qu'aucun utilisateur ne s'est jamais connecté — c'est le moment
où l'on en a besoin sans pouvoir se connecter — puis réservée au Coordinateur.

En ligne de commande, si l'hébergement le permet : `php bousol/diagnostic.php`
(code de sortie 1 en cas d'erreur, exploitable dans un script).

## 7. Tests de fumée

| # | Test | Attendu |
|---|---|---|
| 1 | `curl -I https://dev-dynamics.org/bousol/` | 302 → login.php |
| 2 | `curl -I https://dev-dynamics.org/bousol/storage/.htaccess` | 403 |
| 3 | `curl -I https://dev-dynamics.org/bousol/includes/db.php` | 403 |
| 4 | Connexion `admin@dev-dynamics.org` / `Bousol!2026` | Redirection vers profil, changement de mot de passe imposé |
| 5 | Après changement | Tableau de bord, bandeau « Initialisation incomplète » |
| 5b | Tableau de bord | **Aucune** alerte « Protections d'immuabilité incomplètes » (sinon : voir § 2) |
| 6 | Site public https://dev-dynamics.org/ | Inchangé |

## 8. Après la première connexion

1. Changer le mot de passe et l'email de l'administrateur initial.
2. Créer les comptes du RAF et des mandataires (Paramétrage → Utilisateurs). Trois mandataires
   au minimum : tout règlement exige deux signatures de mandataires non bénéficiaires.
3. Saisir le numéro de contrat et la date de début d'exécution (Paramétrage → Paramètres).
   Les 8 périodes mensuelles et les échéances de rapport en découlent automatiquement.
4. Renseigner les paramètres encore « à définir » de l'annexe F au fur et à mesure des décisions.
5. Chaque titulaire imprime son acte de dépôt, le signe à la main, le fait viser, le scanne,
   puis dépose son spécimen (Profil → Mon spécimen). Sans cet acte, aucune signature n'est possible.
6. Activer les sauvegardes automatiques dans hPanel, et télécharger un premier export hors site
   (Paramétrage → Sauvegarde) pour armer l'alerte de retard.
