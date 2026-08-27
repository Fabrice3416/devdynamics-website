-- =====================================================================
-- Bousol - triggers d'immuabilite (CDC 7.5, annexe G)
-- A importer APRES schema.sql, avec le meme utilisateur.
--
-- Si l'import echoue avec :
--   ERROR 1419 : You do not have the SUPER privilege and binary logging is enabled
-- alors l'hebergeur journalise les ecritures et refuse la creation de triggers a un
-- utilisateur ordinaire. Demander au support d'activer log_bin_trust_function_creators
-- (ou d'accorder SET_USER_ID). Sans ces triggers, l'application fonctionne mais les
-- garanties d'immuabilite ne reposent plus que sur elle : le tableau de bord signale
-- alors l'anomalie en permanence.
-- =====================================================================
-- Reimportable : on retire d'abord les triggers deja en place.
DROP TRIGGER IF EXISTS trg_audit_no_update;
DROP TRIGGER IF EXISTS trg_audit_no_delete;
DROP TRIGGER IF EXISTS trg_parametres_no_update;
DROP TRIGGER IF EXISTS trg_parametres_no_delete;
DROP TRIGGER IF EXISTS trg_appositions_no_update;
DROP TRIGGER IF EXISTS trg_appositions_no_delete;
DROP TRIGGER IF EXISTS trg_fichiers_no_delete;
DROP TRIGGER IF EXISTS trg_imputations_ligne;

DELIMITER $$
CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON journal_audit
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_audit est en ajout seul : modification interdite';
END$$
CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON journal_audit
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_audit est en ajout seul : suppression interdite';
END$$
-- Les parametres sont historises : jamais modifies ni supprimes
CREATE TRIGGER trg_parametres_no_update BEFORE UPDATE ON parametres
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'parametres est historise : inserer une nouvelle version';
END$$
CREATE TRIGGER trg_parametres_no_delete BEFORE DELETE ON parametres
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'parametres est historise : suppression interdite';
END$$
-- Une apposition est un acte : immuable
CREATE TRIGGER trg_appositions_no_update BEFORE UPDATE ON appositions
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Une apposition ne se modifie pas';
END$$
CREATE TRIGGER trg_appositions_no_delete BEFORE DELETE ON appositions
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Une apposition ne se supprime pas';
END$$
-- Un fichier ne se supprime pas, il se remplace
CREATE TRIGGER trg_fichiers_no_delete BEFORE DELETE ON fichiers
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Un fichier ne se supprime pas : le remplacer par une nouvelle version';
END$$
-- Interdiction d'imputer directement sur la provision (ligne 10) ou sur une ligne non imputable
CREATE TRIGGER trg_imputations_ligne BEFORE INSERT ON imputations
FOR EACH ROW BEGIN
  DECLARE n VARCHAR(10);
  DECLARE p INT UNSIGNED;
  SELECT nature, projet_id INTO n, p FROM lignes_budgetaires WHERE id = NEW.ligne_id;
  IF n <> 'imputable' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ligne non imputable (rubrique, axe, calculee ou provision)';
  END IF;
  -- Cloisonnement : une depense ne s'impute jamais sur la ligne d'un autre projet.
  IF p <> NEW.projet_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ligne budgetaire appartenant a un autre projet';
  END IF;
END$$
DELIMITER ;
