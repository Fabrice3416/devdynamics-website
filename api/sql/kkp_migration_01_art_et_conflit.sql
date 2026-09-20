-- ============================================================
-- Koule Ki Pale — migration 01 : partie « L'art et le conflit »
--
-- Ajoute toutes les colonnes manquantes a kkp_responses, et uniquement
-- celles-la. Le script est idempotent : le relancer ne fait rien de plus.
-- Il remplace la migration qui n'ajoutait que art_conflict_meaning ; si
-- celle-ci a deja ete passee, cette colonne est simplement ignoree ici.
--
-- A executer dans phpMyAdmin, onglet SQL, sur la base du site.
-- ============================================================

SET @manquantes := (
    SELECT GROUP_CONCAT(voulue.ajout SEPARATOR ', ')
    FROM (
        SELECT 'art_conflict_meaning' AS nom,
               'ADD COLUMN art_conflict_meaning TEXT NULL' AS ajout
        UNION ALL SELECT 'art_can_help',
               'ADD COLUMN art_can_help ENUM(''oui'',''non'') NULL'
        UNION ALL SELECT 'art_helped',
               'ADD COLUMN art_helped ENUM(''beaucoup'',''un_peu'',''pas_vraiment'',''pas_du_tout'') NULL'
        UNION ALL SELECT 'use_draw',  'ADD COLUMN use_draw TINYINT(1) NOT NULL DEFAULT 0'
        UNION ALL SELECT 'use_write', 'ADD COLUMN use_write TINYINT(1) NOT NULL DEFAULT 0'
        UNION ALL SELECT 'use_music', 'ADD COLUMN use_music TINYINT(1) NOT NULL DEFAULT 0'
        UNION ALL SELECT 'use_dance', 'ADD COLUMN use_dance TINYINT(1) NOT NULL DEFAULT 0'
        UNION ALL SELECT 'use_photo', 'ADD COLUMN use_photo TINYINT(1) NOT NULL DEFAULT 0'
        UNION ALL SELECT 'use_none',  'ADD COLUMN use_none TINYINT(1) NOT NULL DEFAULT 0'
        UNION ALL SELECT 'use_other', 'ADD COLUMN use_other TINYINT(1) NOT NULL DEFAULT 0'
        UNION ALL SELECT 'use_other_text', 'ADD COLUMN use_other_text VARCHAR(255) NULL'
        UNION ALL SELECT 'strategy_listen',   'ADD COLUMN strategy_listen TINYINT(1) NOT NULL DEFAULT 0'
        UNION ALL SELECT 'strategy_art',      'ADD COLUMN strategy_art TINYINT(1) NOT NULL DEFAULT 0'
        UNION ALL SELECT 'strategy_dialogue', 'ADD COLUMN strategy_dialogue TINYINT(1) NOT NULL DEFAULT 0'
        UNION ALL SELECT 'strategy_other',    'ADD COLUMN strategy_other TINYINT(1) NOT NULL DEFAULT 0'
        UNION ALL SELECT 'strategy_other_text', 'ADD COLUMN strategy_other_text VARCHAR(255) NULL'
    ) AS voulue
    WHERE voulue.nom NOT IN (
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kkp_responses'
    )
);

SET @sql := IF(@manquantes IS NULL,
    'SELECT ''Rien a faire : toutes les colonnes sont deja presentes.'' AS resultat',
    CONCAT('ALTER TABLE kkp_responses ', @manquantes)
);

PREPARE migration FROM @sql;
EXECUTE migration;
DEALLOCATE PREPARE migration;

-- Verification
SELECT COUNT(*) AS colonnes_presentes
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kkp_responses';
