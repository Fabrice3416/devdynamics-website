-- ============================================================
-- Refonte du site — formulaires rattachés aux projets
-- À exécuter une fois dans phpMyAdmin, après refonte_01_projets.sql.
-- Un projet qui porte des formulaires ne peut pas être supprimé : sa
-- suppression emporterait des réponses sans que personne ne l'ait voulu.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS forms (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    project_id      INT NOT NULL,
    slug            VARCHAR(120) NOT NULL,
    title           VARCHAR(200) NOT NULL,
    intro           TEXT NULL,
    thanks_message  VARCHAR(500) NULL,
    status          ENUM('draft','open','suspended') NOT NULL DEFAULT 'draft',
    closes_at       DATETIME NULL COMMENT 'Heure d Haiti',
    one_per_device  TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_forms_slug (slug),
    KEY idx_forms_project (project_id),
    CONSTRAINT fk_forms_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_fields (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    form_id     INT NOT NULL,
    type        ENUM('text','textarea','number','date','single','multiple','select','scale','yesno') NOT NULL,
    label       VARCHAR(500) NOT NULL,
    help_text   VARCHAR(500) NULL,
    required    TINYINT(1) NOT NULL DEFAULT 0,
    options     TEXT NULL COMMENT 'Liste JSON pour single, multiple et select',
    position    INT NOT NULL DEFAULT 0,
    hidden      TINYINT(1) NOT NULL DEFAULT 0,
    chart       ENUM('none','bar','pie') NOT NULL DEFAULT 'none',
    KEY idx_form_fields_form (form_id, position),
    CONSTRAINT fk_form_fields_form FOREIGN KEY (form_id) REFERENCES forms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_responses (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    form_id      INT NOT NULL,
    device_hash  CHAR(64) NULL COMMENT 'Empreinte, jamais affichee ni exportee',
    created_at   TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_form_device (form_id, device_hash),
    CONSTRAINT fk_form_responses_form FOREIGN KEY (form_id) REFERENCES forms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_answers (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    response_id  INT NOT NULL,
    field_id     INT NOT NULL,
    value        TEXT NOT NULL COMMENT 'Liste JSON pour multiple',
    KEY idx_form_answers_response (response_id),
    KEY idx_form_answers_field (field_id),
    CONSTRAINT fk_form_answers_response FOREIGN KEY (response_id) REFERENCES form_responses (id) ON DELETE CASCADE,
    CONSTRAINT fk_form_answers_field FOREIGN KEY (field_id) REFERENCES form_fields (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
