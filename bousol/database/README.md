# Base de donnees Bousol

49 tables sur 10 modules (CDC section 8, annexe C), 8 triggers.

Les triggers sont dans un fichier séparé : sur un hébergement mutualisé avec journalisation
binaire, MySQL peut refuser leur création à un utilisateur sans SUPER (erreur 1419). Isolés,
l'échec se voit ; noyés dans `schema.sql`, il passerait inaperçu. Voir `../DEPLOIEMENT.md` § 2.

```bash
mysql -u root -p -e "CREATE DATABASE bousol CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p bousol < schema.sql
mysql -u root -p bousol < schema_triggers.sql
mysql -u root -p bousol < seed.sql
```

Verifications :

```sql
SELECT COUNT(*) FROM information_schema.tables   WHERE table_schema = DATABASE();  -- 49
SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE(); -- 8
SELECT COUNT(*) FROM lignes_budgetaires;                                            -- 31
SELECT montant FROM lignes_budgetaires WHERE code = '11';                           -- 5599889.14
```

## Regles implementees en base

| Regle (CDC) | Mecanisme |
|---|---|
| Journal d'audit en ajout seul (7.5) | Triggers SIGNAL sur UPDATE/DELETE |
| Parametres historises (2.5) | Triggers SIGNAL sur UPDATE/DELETE, une ligne par version |
| Apposition immuable (1.8) | Triggers SIGNAL |
| Fichier jamais supprime (5.3) | Trigger SIGNAL sur DELETE, colonne `remplace_id` |
| Provision non imputable (2.3) | Trigger `trg_imputations_ligne` : seules les lignes `imputable` acceptent une imputation |
| Un dossier = une ligne (4.1) | `imputations.dossier_id` UNIQUE |
| Un reglement = une ecriture (8.3) | `ecritures.reglement_id` UNIQUE |
| Frontieres de modules (7.2) | Cles etrangeres uniquement vers Noyau, Tiers, Budget ; sinon colonnes `*_ref` en valeur |

## Identifiant initial

- Email : `admin@dev-dynamics.org`
- Mot de passe temporaire : `Bousol!2026` (changement force a la premiere connexion)
