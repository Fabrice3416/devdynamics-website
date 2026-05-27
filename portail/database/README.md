# Base de donnees - Portail DEVDYNAMICS / ACP

## Fichiers

| Fichier | Description |
|---|---|
| `schema.sql` | 24 tables + 1 vue + 5 triggers |
| `seed.sql` | Donnees d'initialisation (user admin, prestataire Interne, contrat interne, 10 lignes budgetaires, 47 livrables) |

## Installation locale

```bash
# 1. Creer la base
mysql -u root -p -e "CREATE DATABASE portail_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Charger le schema
mysql -u root -p portail_dev < schema.sql

# 3. Charger les donnees d'initialisation
mysql -u root -p portail_dev < seed.sql

# 4. Verifier
mysql -u root -p portail_dev -e "
  SELECT COUNT(*) AS tables FROM information_schema.tables WHERE table_schema='portail_dev';
  SELECT COUNT(*) AS triggers FROM information_schema.triggers WHERE trigger_schema='portail_dev';
  SELECT COUNT(*) AS livrables FROM livrables;
  SELECT SUM(budget_initial_htg) AS budget_total FROM lignes_budgetaires;
"
# Attendu : tables=24 (+ 1 vue), triggers=7, livrables=47, budget_total=5600000.00
```

## Installation sur Hostinger (production)

1. hPanel -> Bases de donnees MySQL -> **Nouvelle base** distincte du site public
   - Nom : `u<id>_portail`
   - Utilisateur dedie avec mot de passe fort
2. hPanel -> phpMyAdmin -> Importer `schema.sql` puis `seed.sql`
3. Renseigner les credentials dans `portail/includes/config.php`
4. **Changer immediatement** le mot de passe admin par defaut au premier login

## Identifiants par defaut (a CHANGER)

- Email : `admin@dev-dynamics.org`
- Mot de passe : `ChangeMoiVite!2026`

## Verification des triggers

Tester le calcul DGI automatique :

```sql
-- Inserer une imputation puis un decaissement test
INSERT INTO imputations (numero, date_depense, contrat_id, ligne_budgetaire_id,
  rubrique, nature_paiement, description, montant, statut, soumis_par)
VALUES ('TEST-F01-001', CURDATE(), 1, 1, 'personnel', 'honoraires', 'Test', 100000, 'soumis', 1);

SET @imp = LAST_INSERT_ID();
INSERT INTO decaissements (numero, imputation_id, montant_brut, mode_paiement)
VALUES ('TEST-F02-001', @imp, 100000, 'cheque');

SELECT numero, montant_brut, dgi_2pct, net_honoraires, total_net_a_verser
  FROM decaissements WHERE numero='TEST-F02-001';
-- Attendu : dgi_2pct=2000, net_honoraires=98000, total_net_a_verser=98000
```
