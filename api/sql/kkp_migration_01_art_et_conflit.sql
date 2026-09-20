-- ============================================================
-- Koule Ki Pale — migration 01 : partie « L'art et le conflit »
--
-- Ajoute les colonnes necessaires a kkp_responses.
--
-- Pas de lecture de information_schema : sur un hebergement mutualise
-- Hostinger, l'utilisateur de la base n'y a pas acces (#1044). La migration
-- est donc decoupee en deux blocs a lancer l'un apres l'autre.
--
-- A executer dans phpMyAdmin, onglet SQL, sur la base du site.
-- ============================================================


-- ---------- BLOC 1 ----------
-- A ne lancer QUE si la colonne n'existe pas deja. Pour le savoir :
--     SHOW COLUMNS FROM kkp_responses LIKE 'art_conflict_meaning';
-- Une ligne renvoyee = la colonne est la, passe directement au bloc 2.
-- Si tu le lances quand meme, MySQL repond « Duplicate column name » :
-- c'est sans gravite, passe au bloc 2.

ALTER TABLE kkp_responses
  ADD COLUMN art_conflict_meaning TEXT NULL;


-- ---------- BLOC 2 ----------
-- Les quinze colonnes de la partie « L'art et le conflit ».
-- Avant : echelle oui/non et usages de l'art.
-- Apres : echelle a quatre niveaux et strategies envisagees.
-- Les deux echelles restent separees a dessein : les reunir laisserait
-- croire a une progression qui n'a pas ete mesuree.

ALTER TABLE kkp_responses
  ADD COLUMN art_can_help        ENUM('oui','non') NULL,
  ADD COLUMN art_helped          ENUM('beaucoup','un_peu','pas_vraiment','pas_du_tout') NULL,
  ADD COLUMN use_draw            TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN use_write           TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN use_music           TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN use_dance           TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN use_photo           TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN use_none            TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN use_other           TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN use_other_text      VARCHAR(255) NULL,
  ADD COLUMN strategy_listen     TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN strategy_art        TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN strategy_dialogue   TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN strategy_other      TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN strategy_other_text VARCHAR(255) NULL;


-- ---------- VERIFICATION ----------
-- Doit renvoyer 16 lignes : les 15 du bloc 2 plus art_conflict_meaning.

SHOW COLUMNS FROM kkp_responses
WHERE Field IN (
  'art_conflict_meaning', 'art_can_help', 'art_helped',
  'use_draw', 'use_write', 'use_music', 'use_dance', 'use_photo',
  'use_none', 'use_other', 'use_other_text',
  'strategy_listen', 'strategy_art', 'strategy_dialogue',
  'strategy_other', 'strategy_other_text'
);
