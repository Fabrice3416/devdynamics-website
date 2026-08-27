-- =====================================================================
-- Bousol - REINITIALISATION COMPLETE DE LA BASE
--
-- DESTRUCTIF : supprime toutes les tables et tout leur contenu.
-- A n'utiliser que sur une base qui ne porte aucune donnee reelle,
-- typiquement pour passer d'une version du schema a la suivante avant
-- la mise en service. Faire un export prealable en cas de doute.
--
-- Ordre d'emploi :
--   1. reinitialiser.sql   (ce fichier)
--   2. schema.sql
--   3. schema_triggers.sql
--   4. seed.sql
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

DROP TRIGGER IF EXISTS trg_audit_no_update;
DROP TRIGGER IF EXISTS trg_audit_no_delete;
DROP TRIGGER IF EXISTS trg_parametres_no_update;
DROP TRIGGER IF EXISTS trg_parametres_no_delete;
DROP TRIGGER IF EXISTS trg_appositions_no_update;
DROP TRIGGER IF EXISTS trg_appositions_no_delete;
DROP TRIGGER IF EXISTS trg_fichiers_no_delete;
DROP TRIGGER IF EXISTS trg_imputations_ligne;

DROP TABLE IF EXISTS activites;
DROP TABLE IF EXISTS affectations;
DROP TABLE IF EXISTS anomalies;
DROP TABLE IF EXISTS appositions;
DROP TABLE IF EXISTS arretes_caisse;
DROP TABLE IF EXISTS beneficiaires;
DROP TABLE IF EXISTS cadre_elements;
DROP TABLE IF EXISTS comptes;
DROP TABLE IF EXISTS comptes_bancaires;
DROP TABLE IF EXISTS contrats;
DROP TABLE IF EXISTS demandes_paiement;
DROP TABLE IF EXISTS difficultes;
DROP TABLE IF EXISTS documents;
DROP TABLE IF EXISTS dossiers;
DROP TABLE IF EXISTS ecritures;
DROP TABLE IF EXISTS enquetes_adoption;
DROP TABLE IF EXISTS fichiers;
DROP TABLE IF EXISTS formulaires;
DROP TABLE IF EXISTS imputations;
DROP TABLE IF EXISTS indicateurs;
DROP TABLE IF EXISTS journal_audit;
DROP TABLE IF EXISTS liasses;
DROP TABLE IF EXISTS lignes_budgetaires;
DROP TABLE IF EXISTS lignes_financieres;
DROP TABLE IF EXISTS lignes_rapprochement;
DROP TABLE IF EXISTS lignes_version;
DROP TABLE IF EXISTS module_etats;
DROP TABLE IF EXISTS mouvements;
DROP TABLE IF EXISTS parametres;
DROP TABLE IF EXISTS participations;
DROP TABLE IF EXISTS periodes;
DROP TABLE IF EXISTS phases;
DROP TABLE IF EXISTS pieces;
DROP TABLE IF EXISTS pieces_demande;
DROP TABLE IF EXISTS prestations;
DROP TABLE IF EXISTS proformas;
DROP TABLE IF EXISTS projets;
DROP TABLE IF EXISTS projets_comptes;
DROP TABLE IF EXISTS questions;
DROP TABLE IF EXISTS rapports;
DROP TABLE IF EXISTS rapports_execution;
DROP TABLE IF EXISTS rapprochements;
DROP TABLE IF EXISTS reglements;
DROP TABLE IF EXISTS releves;
DROP TABLE IF EXISTS reouvertures;
DROP TABLE IF EXISTS repartitions;
DROP TABLE IF EXISTS reponses;
DROP TABLE IF EXISTS representants;
DROP TABLE IF EXISTS sessions_formation;
DROP TABLE IF EXISTS sources_revenu;
DROP TABLE IF EXISTS specimens;
DROP TABLE IF EXISTS tiers;
DROP TABLE IF EXISTS tranches;
DROP TABLE IF EXISTS utilisateurs;
DROP TABLE IF EXISTS validations_reglement;
DROP TABLE IF EXISTS versements;
DROP TABLE IF EXISTS versements_dgi;
DROP TABLE IF EXISTS versions_application;
DROP TABLE IF EXISTS versions_budget;
DROP TABLE IF EXISTS versions_cadre;

SET FOREIGN_KEY_CHECKS = 1;

-- Verification : la requete suivante doit renvoyer 0.
-- SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();
