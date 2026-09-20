-- ============================================================
-- Koule Ki Pale — migration 01
--
-- Ajoute la question ouverte « que veut dire gerer un conflit a travers
-- l'art ? », posee dans les DEUX questionnaires. Une seule colonne suffit :
-- la phase distingue les deux passations, comme pour les affirmations q1..q7.
--
-- A executer sur une base ou kkp_responses existe deja.
-- Sans effet si la colonne est deja presente.
-- ============================================================

SET @existe := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'kkp_responses'
      AND COLUMN_NAME = 'art_conflict_meaning'
);

SET @sql := IF(@existe = 0,
    'ALTER TABLE kkp_responses
       ADD COLUMN art_conflict_meaning TEXT NULL AFTER reaction',
    'SELECT "Colonne art_conflict_meaning deja presente, rien a faire" AS resultat'
);

PREPARE migration FROM @sql;
EXECUTE migration;
DEALLOCATE PREPARE migration;
