-- ============================================================
-- Refonte du site — témoignages, partenaires, limites d'envoi, organisation
-- À exécuter une fois dans phpMyAdmin, après refonte_02_formulaires.sql.
-- La liste des fondateurs et les coordonnées sont remplacées par celles du CDC.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE projects ADD COLUMN IF NOT EXISTS testimonials_open TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS project_testimonials (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    project_id  INT NOT NULL,
    name        VARCHAR(120) NOT NULL,
    role_title  VARCHAR(160) NULL,
    content     TEXT NOT NULL,
    video_id    VARCHAR(20) NULL COMMENT 'Identifiant YouTube',
    status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    featured    TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_testimonials_project (project_id, status),
    CONSTRAINT fk_testimonials_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partners (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(200) NOT NULL,
    logo_url    VARCHAR(500) NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    position    INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_partners_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO partners (name, position) VALUES ('Alliance Française du Cap-Haïtien', 0);

CREATE TABLE IF NOT EXISTS rate_limits (
    key_hash      CHAR(64) PRIMARY KEY COMMENT 'Empreinte de l adresse IP et de l usage',
    hits          INT NOT NULL DEFAULT 1,
    window_start  DATETIME NOT NULL,
    KEY idx_rate_limits_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Coordonnées : mise à jour de la ligne existante, création s'il n'y en a aucune
UPDATE organization_info SET
    name = 'DevDynamics',
    email = 'contact@dev-dynamics.org',
    phone = '+509 47 41 8737',
    address = '190, rue 15-16 B, Cap-Haïtien, Haïti',
    mission = 'Œuvrer dans la technologie et les solutions technologiques au service de la communauté capoise en particulier et haïtienne en général.',
    description = NULL;

INSERT INTO organization_info (name, email, phone, address, mission)
SELECT 'DevDynamics', 'contact@dev-dynamics.org', '+509 47 41 8737', '190, rue 15-16 B, Cap-Haïtien, Haïti',
       'Œuvrer dans la technologie et les solutions technologiques au service de la communauté capoise en particulier et haïtienne en général.'
WHERE NOT EXISTS (SELECT 1 FROM organization_info);

DELETE FROM founders;
INSERT INTO founders (name, role, order_position) VALUES
('Rodely JEAN', 'Responsable administratif', 0),
('Fabrice SAINTILMA', 'Responsable technique et développeur full stack', 1),
('Gueslander Lynx LAURENT', 'Responsable financier et data scientist', 2);
