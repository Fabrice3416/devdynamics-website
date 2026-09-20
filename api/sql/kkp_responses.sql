-- ============================================================
-- Koulè Ki Pale — questionnaires avant / après formation
-- REVIV, Appel 5, « Koulè Ki Pale : Jèn yo pran pawòl »
--
-- Une seule table porte les deux passations. `phase` les distingue,
-- `personal_code` les rapproche sans jamais nommer le participant.
-- ============================================================

CREATE TABLE IF NOT EXISTS kkp_responses (
    id              INT AUTO_INCREMENT PRIMARY KEY,

    phase           ENUM('avant','apres') NOT NULL DEFAULT 'avant',
    personal_code   VARCHAR(12) NOT NULL COMMENT 'Deux lettres du prenom + jour de naissance, ex. MA14',

    -- 1. Pour mieux te connaitre
    age             TINYINT UNSIGNED NULL,
    gender          ENUM('feminin','masculin','autre') NULL,
    situation       ENUM('etudes','emploi','recherche','autre') NULL,
    prior_training  TINYINT(1) NULL COMMENT 'Deja suivi une formation sur la gestion des conflits',

    -- Pratiques artistiques (choix multiple)
    art_drawing     TINYINT(1) NOT NULL DEFAULT 0,
    art_theatre     TINYINT(1) NOT NULL DEFAULT 0,
    art_music       TINYINT(1) NOT NULL DEFAULT 0,
    art_writing     TINYINT(1) NOT NULL DEFAULT 0,
    art_other       TINYINT(1) NOT NULL DEFAULT 0,
    art_none        TINYINT(1) NOT NULL DEFAULT 0,

    -- 2. Rapport au conflit : 7 affirmations, echelle de Likert 1 a 5
    q1              TINYINT UNSIGNED NULL COMMENT 'Reconnaitre les signes d un conflit qui monte',
    q2              TINYINT UNSIGNED NULL COMMENT 'Nommer ce que je ressens',
    q3              TINYINT UNSIGNED NULL COMMENT 'Connaitre des moyens de regler sans violence',
    q4              TINYINT UNSIGNED NULL COMMENT 'Ecouter un point de vue oppose',
    q5              TINYINT UNSIGNED NULL COMMENT 'Exprimer un desaccord sans agresser',
    q6              TINYINT UNSIGNED NULL COMMENT 'Connaitre mes droits et les recours',
    q7              TINYINT UNSIGNED NULL COMMENT 'Prendre la parole en public sur ce que je vis',

    -- 3. Ta facon de reagir
    reaction        ENUM('evite','cede','impose','compromis','collabore') NULL,

    -- 4. Tes attentes (questionnaire d'avant uniquement)
    expectations    TEXT NULL,
    special_needs   TEXT NULL,

    -- ---- Questionnaire de fin de formation uniquement ----

    -- 3. Ton avis sur la formation : 7 affirmations, echelle de Likert 1 a 5
    s1              TINYINT UNSIGNED NULL COMMENT 'Satisfait de la formation dans l ensemble',
    s2              TINYINT UNSIGNED NULL COMMENT 'Activites interessantes',
    s3              TINYINT UNSIGNED NULL COMMENT 'Facilitatrice et intervenants clairs et disponibles',
    s4              TINYINT UNSIGNED NULL COMMENT 'Sentiment de securite dans le groupe',
    s5              TINYINT UNSIGNED NULL COMMENT 'Choses utiles apprises',
    s6              TINYINT UNSIGNED NULL COMMENT 'Interventions sur le droit (CALSDH, ASF Canada) utiles',
    s7              TINYINT UNSIGNED NULL COMMENT 'Organisation (salle, horaires, materiel) satisfaisante',

    -- 4. Questions ouvertes
    fav_activity        TEXT NULL COMMENT 'Activite preferee et pourquoi',
    will_do_differently TEXT NULL COMMENT 'Ce que le participant fera differemment',
    improvements        TEXT NULL COMMENT 'Ce qu il ameliorerait',

    -- 5. Apres la formation
    can_apply       ENUM('oui','non','pas_certain') NULL COMMENT 'Pourra utiliser dans la vie quotidienne',
    would_recommend ENUM('oui','non','pas_certain') NULL COMMENT 'Recommanderait la formation',

    -- 6. Temoignage et autorisation de diffusion
    testimonial         TEXT NULL,
    testimonial_consent ENUM('oui_nom','oui_anonyme','non') NULL,
    testimonial_name    VARCHAR(120) NULL COMMENT 'Renseigne uniquement si consentement = oui_nom',

    -- Traces techniques (aucune donnee nominative)
    ip_hash         CHAR(64) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_kkp_phase_code (phase, personal_code),
    KEY idx_kkp_phase (phase),
    KEY idx_kkp_code (personal_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
