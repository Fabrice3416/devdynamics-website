# Base de donnees Bousol

58 tables sur 11 modules (CDC section 8 et annexe C, addendum 1 section 10), 8 triggers.

Le projet est une dimension de toute donnée d'exécution : 41 tables portent un `projet_id`
obligatoire. Restent partagés le socle (fichiers, documents, appositions, spécimens, journal
d'audit, qui portent le projet en valeur), le référentiel des tiers et les utilisateurs, dont
le rattachement passe par la table des affectations.

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
SELECT COUNT(*) FROM information_schema.tables   WHERE table_schema = DATABASE();  -- 58
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
| Cloisonnement par projet (addendum 2) | `projet_id` obligatoire sur 41 tables, unicites portees au couple projet et cle metier |
| Une depense, une ligne du meme projet | Trigger `trg_imputations_ligne` : refuse la ligne budgetaire d'un autre projet |
| Sans acte, pas d'affectation (addendum 4) | `affectations.acte_delegation_fichier_id` NOT NULL |
| Anonymat structurel des reponses | `reponses` ne porte ni adresse ni empreinte d'appareil : une colonne suffirait a le detruire |

## Identifiant initial

- Email : `admin@dev-dynamics.org`
- Mot de passe temporaire : `Bousol!2026` (changement force a la premiere connexion)

Ce compte est **administrateur de l'outil** : il cree les projets et n'y saisit rien. Il n'est
affecte a aucun projet, et le seed n'en cree aucune affectation, celle-ci exigeant le
televersement d'un acte de delegation d'autorite.

## Les deux projets charges

| Code | Intitule | Bailleur | Plafond | Lignes | Suivi post-cloture |
|---|---|---|---|---|---|
| `KESKLE` | KesKle | UGP DP-PAIESC | 5 600 000 HTG | 31 dont 18 imputables | oui |
| `KKP` | Koule Ki Pale | FOKAL, programme REVIV | 974 556 HTG | 17 dont 11 imputables | non |

Le detail par ligne du budget de Koule Ki Pale reste a saisir : la convention ne communique
que les sous-totaux de rubrique.
