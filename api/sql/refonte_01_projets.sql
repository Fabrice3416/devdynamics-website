-- ============================================================
-- Refonte du site — projets et réalisations initiales
-- À exécuter une fois dans phpMyAdmin, avant la mise en ligne.
-- Sans effet si on la rejoue : une ligne dont l'adresse existe est ignorée.
-- Les nombres de participants sont provisoires et se corrigent dans l'admin.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS projects (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    slug          VARCHAR(120) NOT NULL,
    name          VARCHAR(200) NOT NULL,
    type          ENUM('formation','produit','mission','autre') NOT NULL DEFAULT 'autre',
    status        ENUM('draft','published') NOT NULL DEFAULT 'draft',
    start_date    DATE NULL,
    end_date      DATE NULL,
    period_label  VARCHAR(120) NULL COMMENT 'Remplace les dates a l affichage',
    summary       VARCHAR(400) NOT NULL,
    description   TEXT NULL,
    participants  INT UNSIGNED NULL,
    speakers      VARCHAR(255) NULL,
    external_url  VARCHAR(500) NULL,
    image_url     VARCHAR(500) NULL,
    created_at    TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_projects_slug (slug),
    KEY idx_projects_status (status, start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO projects
    (slug, name, type, status, start_date, end_date, period_label, summary, description, participants, speakers, external_url)
VALUES
('essor', 'Essor', 'produit', 'published', NULL, NULL, 'En ligne',
 'Application de gestion financière pour les petites et moyennes entreprises.',
 'Essor remplace le cahier papier : caisse, devis et factures, écritures comptables automatiques, suivi des soldes et rapports.',
 NULL, NULL, 'https://appessor.dev-dynamics.org'),
('sites-web-cohorte-3', 'Création de sites web, cohorte 3', 'formation', 'published', '2025-06-01', '2025-08-31', NULL,
 'Troisième cohorte de la formation à la création de sites web pour les débutants.',
 'Formation à la création de sites web avec HTML, CSS et JavaScript, destinée aux débutants.',
 9, NULL, NULL),
('reseaux-sociaux', 'Gestion des réseaux sociaux', 'formation', 'published', '2025-06-01', NULL, 'Été 2025',
 'Formation courte à la gestion des réseaux sociaux.',
 'Formation courte réalisée durant l''été 2025.',
 11, 'Darlendie Pierre, formatrice', NULL),
('depannage-cellulaire', 'Dépannage cellulaire', 'formation', 'published', '2025-06-01', NULL, 'Été 2025',
 'Formation courte au dépannage cellulaire.',
 'Formation courte réalisée durant l''été 2025.',
 13, 'Rodh Fabrice Phanet, formateur', NULL),
('sites-web-cohorte-2', 'Création de sites web, cohorte 2', 'formation', 'published', '2025-03-01', '2025-05-31', NULL,
 'Deuxième cohorte de la formation à la création de sites web pour les débutants.',
 'Formation à la création de sites web avec HTML, CSS et JavaScript, destinée aux débutants.',
 21, NULL, NULL),
('zonpam', 'Zonpam', 'mission', 'published', '2025-01-01', '2025-06-30', NULL,
 'Développement de l''application Flutter de Zonpam, produit de l''entreprise RI Numbers.',
 'DevDynamics a principalement travaillé sur l''application Flutter de Zonpam. L''entreprise RI Numbers est propriétaire du produit.',
 NULL, NULL, 'https://zonpam.com'),
('sites-web-cohorte-1', 'Création de sites web, cohorte 1', 'formation', 'published', '2024-11-01', '2025-02-28', NULL,
 'Première cohorte de la formation à la création de sites web pour les débutants.',
 'Formation à la création de sites web avec HTML, CSS et JavaScript, destinée aux débutants.',
 12, NULL, NULL);
