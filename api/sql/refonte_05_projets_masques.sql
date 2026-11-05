-- ============================================================
-- Un projet peut être masqué : prêt, mais volontairement absent du site.
-- Formulaires et témoignages restent utilisables par leur lien.
-- À exécuter une fois dans phpMyAdmin. Sans effet si on la rejoue.
-- ============================================================

ALTER TABLE projects
    MODIFY status ENUM('draft','published','hidden') NOT NULL DEFAULT 'draft';
