# Deploiement Portail DEVDYNAMICS / ACP

## 1. Pre-requis Hostinger

| Etape | Action |
|---|---|
| Acceder hPanel | https://hpanel.hostinger.com |
| Verifier PHP 8.1+ | hPanel -> Avance -> Configuration PHP |
| Extensions actives | `pdo_mysql`, `gd`, `mbstring`, `fileinfo`, `zip`, `openssl` (verifier) |
| Email | Aucun setup specifique - le portail utilise `mail()` natif PHP (meme relais que le site public). SPF deja configure pour `dev-dynamics.org`. |
| Backup auto | hPanel -> Files -> Backups -> Activer pour la BDD `u<id>_portail` |

## 2. Creation BDD

```sql
-- hPanel -> Bases de donnees MySQL -> Nouvelle base
-- Nom : u<id>_portail
-- Utilisateur : u<id>_portail_user
-- Mot de passe : <fort>
```

Importer schema puis seed via phpMyAdmin :

```bash
# Ordre obligatoire
1. portail/database/schema.sql   (24 tables + vue + 7 triggers)
2. portail/database/seed.sql      (admin + prestataire interne + 47 livrables)
```

Verifier :

```sql
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u<id>_portail';
-- Attendu : 24
SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='u<id>_portail';
-- Attendu : 7
SELECT COUNT(*) FROM livrables;  -- 47
SELECT SUM(budget_initial_htg) FROM lignes_budgetaires;  -- 5600000.00
```

## 3. Deploiement code

```bash
# Sur Hostinger (SSH ou Git Bash via WebShell)
cd ~/public_html
git pull origin main
# Le dossier portail/ apparait a cote de index.html, pages/, api/ existants
```

## 4. Configuration sensible

```bash
# Creer le fichier de config (NE JAMAIS le committer)
cp portail/includes/config.example.php portail/includes/config.php
nano portail/includes/config.php
# Renseigner uniquement : db.name, db.user, db.pass
# (Email : aucune config sensible, mail() natif PHP via Hostinger)
chmod 600 portail/includes/config.php
```

**Credentials BDD de production** (deja configures dans `config.php` local) :
```
Nom BDD : u218662965_portail
User    : u218662965_portail_user
Pass    : r&bnqU2V
```

## 5. Librairies

Seul **mPDF** est requis (pour les PDF). L'envoi d'email utilise `mail()` natif PHP, pas besoin de PHPMailer.

```bash
# En local (avec composer)
mkdir -p /tmp/portail_libs && cd /tmp/portail_libs
composer require mpdf/mpdf

# Copier vers le serveur
scp -r vendor/mpdf user@hostinger:public_html/portail/lib/

# Verifier
ls public_html/portail/lib/mpdf/src/Mpdf.php
```

> Si mPDF n'est pas installe, le portail fonctionne quand meme : les pages s'affichent normalement, seuls les boutons "Telecharger PDF" retournent une erreur. Les PDF des dossiers sont generes a la demande.

## 6. Permissions

```bash
cd public_html/portail
chmod -R 755 storage/
chmod 644 .htaccess robots.txt
chmod 600 includes/config.php

# Verifier que le serveur web peut ecrire dans storage/
touch storage/test.txt && rm storage/test.txt
```

## 7. Tests smoke

| # | Test | Resultat attendu |
|---|---|---|
| 1 | `curl -I https://dev-dynamics.org/portail/` | 302 -> login.php |
| 2 | `curl https://dev-dynamics.org/portail/storage/test.pdf` | 403 Forbidden |
| 3 | Acceder `/portail/login.php` | Page login affichee |
| 4 | Se connecter `admin@dev-dynamics.org` / `ChangeMoiVite!2026` | Redirige dashboard |
| 5 | Changer le mot de passe via /portail/profil.php | OK |
| 6 | Uploader signature PNG via /portail/profil.php | Image visible |
| 7 | Site public `https://dev-dynamics.org/` | Inchange, charge normalement |

## 8. Grille UAT V2

| Code | Test | Critere |
|---|---|---|
| WFL-01 | F01 standard -> FRP cloture | Statut final `valide`, PDF 5 pieces |
| WFL-02 | F01 CPS-ACP-01 (double bloc) | 2 cheques distincts au F02 |
| WFL-03 | Rappel F01 avant F02 | Bouton visible, statut -> brouillon |
| WFL-04 | Virement > 30 000 HTG | Alerte email Coord recue |
| WFL-05 | Renflouement PC complet | F01 auto, FRP 2 sig, dossier sans NH |
| SEC-01 | Acces direct `/storage/` | 403 |
| SEC-02 | POST sans CSRF | 403 |
| SEC-03 | Upload `.php` deguise en `.pdf` | Rejete par finfo |
| PC-01 | Depense 5 000 HTG | OK, solde mis a jour |
| PC-02 | Depense 12 000 HTG | Refusee (plafond 10k) |
| PC-03 | Depense sans recu | Refusee |
| PC-04 | Solde < 9 000 HTG | Email alerte recu |
| PC-05 | Renflouement en cours | Nouvelles depenses bloquees |
| PC-06 | Versement renflouement | Solde -> 30 000, gel leve |
| RPT-01 | Generer RFM mensuel | PDF telecharge |
| RPT-02 | Generer DJ 25 dossiers | ZIP valide |
| RPT-03 | DJ 35 dossiers | Avertissement >30 |
| RPT-04 | Cumule M01-M03 | Entree creee |

## 9. Identifiants par defaut

| Role | Email | Mot de passe initial |
|---|---|---|
| Administrateur | admin@dev-dynamics.org | `ChangeMoiVite!2026` |

**Changer immediatement au premier login.**

Ajouter ensuite via SQL ou interface :
- 1 Coordinateur
- 1 Comptable
- Prestataires (autant que necessaire, lien email pour tokens NH/FRP)

```sql
INSERT INTO users (nom_complet, email, mot_de_passe, role, actif)
VALUES (
  'Coordinateur ACP',
  'coord@dev-dynamics.org',
  '$2y$10$<hash_genere_via_password_hash>',
  'coordinateur',
  1
);
```

Generer un hash avec :
```bash
php -r "echo password_hash('VotreMotDePasseFort', PASSWORD_BCRYPT, ['cost'=>10]);"
```

## 10. Verification post-deploiement (24h apres)

- [ ] Le site public est inchange et fonctionnel
- [ ] Login portail fonctionne
- [ ] Les 24 tables sont en place
- [ ] Au moins 1 email de test (login admin -> reset password) est arrive
- [ ] `audit_log` enregistre les actions
- [ ] `alertes` enregistre les notifications
- [ ] Aucune erreur PHP dans `~/.php-fpm-error.log` Hostinger
- [ ] HTTPS force fonctionne
- [ ] `storage/.htaccess` bloque bien les acces directs

## 11. Plan d'apprentissage utilisateurs

| Role | Pages prioritaires | Temps formation |
|---|---|---|
| Comptable | F01 (saisie), Petite Caisse, Profil | 1h |
| Administrateur | Tous + F02 + Contrats + Renflouement | 3h |
| Coordinateur | ASF + FRP + Rapports + PDP + Livrables | 2h |
| Prestataires | Lien email NH puis FRP (autoportant) | 0h |

## 12. Support

- Bug technique : creer une issue dans le depot GitHub
- Aide utilisateur : transmettre l'URL `dev-dynamics.org/portail/` aux roles autorises
- mPDF / PHPMailer non installes : la BDD continue de fonctionner, les PDF/emails sont marques `en_attente`. Le retry est automatique des qu'on depose les libs.
