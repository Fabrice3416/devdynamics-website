-- =====================================================================
-- Bousol - schema MySQL 8 (49 tables, 10 modules) - CDC section 8 / annexe C
-- Regles : chaque module possede ses tables ; aucune cle etrangere ne
-- traverse une frontiere de module sauf vers le Noyau et les referentiels
-- (Tiers, Budget). Les references inter-modules sont portees en valeurs
-- (colonnes *_ref) et resolues par les fonctions de service du module cible.
-- =====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- MODULE NOYAU (11 tables)
-- =====================================================================

CREATE TABLE projets (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code               VARCHAR(20) NOT NULL COMMENT 'Prefixe des fichiers et des sequences ; fige des la premiere piece',
  intitule           VARCHAR(150) NOT NULL,
  bailleur           VARCHAR(120) NOT NULL,
  referentiel        VARCHAR(60) NOT NULL COMMENT 'Guide applicable : PAIESC, FOKAL_REVIV...',
  -- Dates, plafond, duree et phase de suivi sont des parametres du projet (annexe F),
  -- pour qu'il n'existe qu'une seule source de verite et un seul mecanisme d'historisation.
  statut             ENUM('creation','actif','clos','archive') NOT NULL DEFAULT 'creation',
  cree_par           INT UNSIGNED NULL COMMENT 'Administrateur de l''outil',
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_projets_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE affectations (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  utilisateur_id          INT UNSIGNED NOT NULL,
  projet_id               INT UNSIGNED NOT NULL,
  role                    ENUM('coordinateur','raf','mandataire') NOT NULL,
  acte_delegation_fichier_id INT UNSIGNED NOT NULL COMMENT 'Delegation d''autorite signee : sans acte, pas d''affectation',
  duree_prestations_fin   DATE NULL COMMENT 'Peut exceder la duree d''execution (audits posterieurs)',
  remuneration_differee   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Versement unique en fin de projet',
  date_debut              DATE NOT NULL,
  date_fin                DATE NULL,
  affecte_par             INT UNSIGNED NOT NULL,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_affectation (utilisateur_id, projet_id, role),
  KEY idx_affectations_projet (projet_id),
  CONSTRAINT fk_affectations_user FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id),
  CONSTRAINT fk_affectations_projet FOREIGN KEY (projet_id) REFERENCES projets(id),
  CONSTRAINT fk_affectations_acte FOREIGN KEY (acte_delegation_fichier_id) REFERENCES fichiers(id),
  CONSTRAINT fk_affectations_auteur FOREIGN KEY (affecte_par) REFERENCES utilisateurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Le role est une affectation, jamais un attribut de l''utilisateur';

CREATE TABLE tiers (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type             ENUM('personne','fournisseur','organisation','administration') NOT NULL,
  nom              VARCHAR(150) NOT NULL,
  sigle            VARCHAR(30) NULL,
  fonction         VARCHAR(120) NULL COMMENT 'Fonction contractuelle (personne)',
  nif              VARCHAR(30) NULL,
  patente          VARCHAR(50) NULL,
  piece_identite_fichier_id INT UNSIGNED NULL COMMENT 'Fichier au coffre',
  commune          VARCHAR(80) NULL,
  domaine          VARCHAR(150) NULL COMMENT 'Domaine d''intervention (organisation)',
  beneficiaire_paiesc TINYINT(1) NOT NULL DEFAULT 0,
  date_confirmation DATE NULL,
  statut_avancement ENUM('identifiee','confirmee','formee','equipee','active','inactive') NULL,
  est_mandataire   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Qualite attachee a la personne, pas au role',
  telephone        VARCHAR(40) NULL,
  email            VARCHAR(120) NULL,
  adresse          VARCHAR(255) NULL,
  coordonnees_reglement VARCHAR(255) NULL,
  actif            TINYINT(1) NOT NULL DEFAULT 1,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tiers_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE utilisateurs (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tiers_id           INT UNSIGNED NOT NULL COMMENT 'Un utilisateur est un acces ; la personne est dans tiers',
  email              VARCHAR(120) NOT NULL,
  mot_de_passe       VARCHAR(255) NOT NULL,
  admin_outil        TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Cree les projets et n''y saisit rien ; unique et exterieur aux projets',
  actif              TINYINT(1) NOT NULL DEFAULT 1,
  doit_changer_mdp   TINYINT(1) NOT NULL DEFAULT 1,
  reset_token        VARCHAR(64) NULL,
  reset_token_expire DATETIME NULL,
  derniere_connexion DATETIME NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_utilisateurs_email (email),
  CONSTRAINT fk_utilisateurs_tiers FOREIGN KEY (tiers_id) REFERENCES tiers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE parametres (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  cle        VARCHAR(60) NOT NULL,
  valeur     VARCHAR(255) NULL,
  date_effet DATE NOT NULL,
  motif      VARCHAR(255) NULL,
  auteur_id  INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_parametres_cle (cle, date_effet),
  CONSTRAINT fk_parametres_auteur FOREIGN KEY (auteur_id) REFERENCES utilisateurs(id),
  KEY idx_parametres_projet (projet_id),
  CONSTRAINT fk_parametres_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Historise : jamais d''UPDATE, une ligne par version';

CREATE TABLE phases (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  code       ENUM('projet_actif','regularisation','post_cloture') NOT NULL,
  date_debut DATE NULL,
  date_fin   DATE NULL,
  statut     ENUM('a_venir','en_cours','close') NOT NULL DEFAULT 'a_venir',
  enveloppe_indirecte_figee DECIMAL(14,2) NULL COMMENT 'Figee a la bascule (7 % des couts directs constates)',
  declenchee_par INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_phases_code (projet_id, code),
  CONSTRAINT fk_phases_user FOREIGN KEY (declenchee_par) REFERENCES utilisateurs(id),
  KEY idx_phases_projet (projet_id),
  CONSTRAINT fk_phases_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE periodes (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  numero     TINYINT UNSIGNED NOT NULL COMMENT 'Mois de projet 1..N',
  date_debut DATE NOT NULL,
  date_fin   DATE NOT NULL,
  statut     ENUM('ouverte','en_cloture','figee') NOT NULL DEFAULT 'ouverte',
  figee_le   DATETIME NULL,
  figee_par  INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_periodes_numero (projet_id, numero),
  CONSTRAINT fk_periodes_user FOREIGN KEY (figee_par) REFERENCES utilisateurs(id),
  KEY idx_periodes_projet (projet_id),
  CONSTRAINT fk_periodes_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fichiers (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nom_genere  VARCHAR(255) NOT NULL COMMENT 'Nom lisible selon la convention de classement',
  chemin      VARCHAR(255) NOT NULL COMMENT 'Relatif a storage/',
  extension   VARCHAR(8) NOT NULL,
  mime        VARCHAR(80) NOT NULL,
  taille      INT UNSIGNED NOT NULL,
  empreinte   CHAR(64) NOT NULL COMMENT 'SHA-256 du contenu en clair',
  coffre      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Chiffre au repos',
  projet_code VARCHAR(20) NULL COMMENT 'Projet en valeur',
  remplace_id INT UNSIGNED NULL COMMENT 'Version precedente (jamais supprimee)',
  auteur_id   INT UNSIGNED NULL,
  capture_le  DATETIME NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fichiers_empreinte (empreinte),
  CONSTRAINT fk_fichiers_remplace FOREIGN KEY (remplace_id) REFERENCES fichiers(id),
  CONSTRAINT fk_fichiers_auteur FOREIGN KEY (auteur_id) REFERENCES utilisateurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE documents (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type        VARCHAR(40) NOT NULL COMMENT 'Code du catalogue annexe E',
  module      VARCHAR(20) NOT NULL,
  objet_type  VARCHAR(40) NOT NULL,
  objet_id    INT UNSIGNED NOT NULL,
  projet_code VARCHAR(20) NULL COMMENT 'Projet en valeur',
  version     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  statut      ENUM('brouillon','a_signer','signe','fige','remplace','annule') NOT NULL DEFAULT 'brouillon',
  regime      ENUM('papier','electronique') NOT NULL DEFAULT 'papier',
  fichier_id  INT UNSIGNED NULL,
  exemplaires_imprimes TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_documents_objet (module, objet_type, objet_id),
  CONSTRAINT fk_documents_fichier FOREIGN KEY (fichier_id) REFERENCES fichiers(id),
  CONSTRAINT fk_documents_user FOREIGN KEY (created_by) REFERENCES utilisateurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE journal_audit (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  module          VARCHAR(20) NOT NULL,
  action          VARCHAR(40) NOT NULL,
  objet_type      VARCHAR(40) NULL,
  objet_id        VARCHAR(40) NULL COMMENT 'En valeur, jamais en cle etrangere',
  projet_code VARCHAR(20) NULL COMMENT 'Projet en valeur',
  detail          TEXT NULL,
  utilisateur_id  INT UNSIGNED NULL,
  utilisateur_nom VARCHAR(150) NULL,
  ip              VARCHAR(45) NULL,
  agent           VARCHAR(255) NULL,
  empreinte_avant CHAR(64) NULL,
  empreinte_apres CHAR(64) NULL,
  horodatage      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_horodatage (horodatage),
  KEY idx_audit_ip_action (ip, action, horodatage),
  KEY idx_audit_objet (module, objet_type, objet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ajout seul';

CREATE TABLE module_etats (
  id           TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  module       VARCHAR(20) NOT NULL,
  libelle      VARCHAR(40) NOT NULL,
  version      VARCHAR(12) NOT NULL DEFAULT '0.1.0',
  interrupteur ENUM('actif','maintenance') NOT NULL DEFAULT 'actif',
  motif        VARCHAR(255) NULL,
  critique     TINYINT(1) NOT NULL DEFAULT 0,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reouvertures (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  periode_id  INT UNSIGNED NULL COMMENT 'NULL = reouverture apres bascule (etat regularisation)',
  motif       TEXT NOT NULL,
  date_debut  DATE NOT NULL,
  date_limite DATE NOT NULL COMMENT 'Bornee dans le temps',
  statut      ENUM('ouverte','close') NOT NULL DEFAULT 'ouverte',
  auteur_id   INT UNSIGNED NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_reouvertures_periode FOREIGN KEY (periode_id) REFERENCES periodes(id),
  CONSTRAINT fk_reouvertures_auteur FOREIGN KEY (auteur_id) REFERENCES utilisateurs(id),
  KEY idx_reouvertures_projet (projet_id),
  CONSTRAINT fk_reouvertures_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- MODULE SIGNATURE (2)
-- =====================================================================

CREATE TABLE specimens (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  titulaire_id          INT UNSIGNED NOT NULL COMMENT 'utilisateurs.id',
  image_fichier_id      INT UNSIGNED NOT NULL COMMENT 'Coffre',
  acte_depot_fichier_id INT UNSIGNED NOT NULL COMMENT 'Acte signe a la main, scanne - obligatoire',
  date_depot            DATE NOT NULL,
  date_revocation       DATE NULL,
  motif_revocation      VARCHAR(255) NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_specimens_titulaire (titulaire_id, date_revocation),
  CONSTRAINT fk_specimens_titulaire FOREIGN KEY (titulaire_id) REFERENCES utilisateurs(id),
  CONSTRAINT fk_specimens_image FOREIGN KEY (image_fichier_id) REFERENCES fichiers(id),
  CONSTRAINT fk_specimens_acte FOREIGN KEY (acte_depot_fichier_id) REFERENCES fichiers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appositions (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id       INT UNSIGNED NOT NULL,
  specimen_id       INT UNSIGNED NOT NULL,
  signataire_id     INT UNSIGNED NOT NULL COMMENT 'utilisateurs.id - doit etre le titulaire du specimen',
  qualite           ENUM('approbation','reglement') NOT NULL,
  session_empreinte CHAR(64) NOT NULL COMMENT 'Hash de l''id de session : deux appositions ne peuvent partager une session',
  empreinte_avant   CHAR(64) NOT NULL,
  empreinte_apres   CHAR(64) NOT NULL,
  code_verification CHAR(12) NOT NULL COMMENT 'Imprime sous le bloc de signature',
  ip                VARCHAR(45) NULL,
  appareil          VARCHAR(255) NULL,
  horodatage        DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_appositions_code (code_verification),
  KEY idx_appositions_document (document_id),
  CONSTRAINT fk_appositions_document FOREIGN KEY (document_id) REFERENCES documents(id),
  CONSTRAINT fk_appositions_specimen FOREIGN KEY (specimen_id) REFERENCES specimens(id),
  CONSTRAINT fk_appositions_signataire FOREIGN KEY (signataire_id) REFERENCES utilisateurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- MODULE TIERS (3) - tiers est cree plus haut (le Noyau y renvoie)
-- =====================================================================

CREATE TABLE comptes_bancaires (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  etablissement VARCHAR(120) NOT NULL,
  numero        VARCHAR(60) NOT NULL,
  titulaire     VARCHAR(150) NOT NULL,
  devise        CHAR(3) NOT NULL DEFAULT 'HTG',
  type          ENUM('banque','caisse') NOT NULL DEFAULT 'banque',
  actif         TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_comptes_bancaires (etablissement, numero)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Referentiel partage : un compte peut servir plusieurs projets';

CREATE TABLE projets_comptes (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id           INT UNSIGNED NOT NULL,
  compte_bancaire_id  SMALLINT UNSIGNED NOT NULL,
  role                ENUM('principal','caisse','secondaire') NOT NULL DEFAULT 'principal',
  dedie               TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Compte dedie au projet : rapprochement direct',
  date_rattachement   DATE NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_projet_compte (projet_id, compte_bancaire_id),
  CONSTRAINT fk_pc_projet FOREIGN KEY (projet_id) REFERENCES projets(id),
  CONSTRAINT fk_pc_compte FOREIGN KEY (compte_bancaire_id) REFERENCES comptes_bancaires(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE beneficiaires (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  organisation_id INT UNSIGNED NULL COMMENT 'Facultatif : KesKle forme des representants, Koule Ki Pale des personnes',
  nom             VARCHAR(150) NOT NULL,
  fonction        VARCHAR(120) NULL,
  sexe            ENUM('F','M','autre') NOT NULL,
  tranche_age     ENUM('moins_18','18_24','25_35','36_50','plus_50') NOT NULL,
  autorisation_parentale_fichier_id INT UNSIGNED NULL COMMENT 'Exigee si tranche_age = moins_18 (CDC 3.2)',
  telephone       VARCHAR(40) NULL,
  actif           TINYINT(1) NOT NULL DEFAULT 1,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_beneficiaires_org FOREIGN KEY (organisation_id) REFERENCES tiers(id),
  CONSTRAINT fk_beneficiaires_autorisation FOREIGN KEY (autorisation_parentale_fichier_id) REFERENCES fichiers(id),
  KEY idx_beneficiaires_projet (projet_id),
  CONSTRAINT fk_beneficiaires_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lignes_budgetaires (
  id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  code            VARCHAR(10) NOT NULL,
  parent_code     VARCHAR(10) NULL,
  rubrique        TINYINT UNSIGNED NULL COMMENT '1..6 : numero de rubrique pour la numerotation des pieces',
  niveau          TINYINT UNSIGNED NOT NULL,
  nature          ENUM('rubrique','axe','imputable','calculee') NOT NULL,
  libelle         VARCHAR(150) NOT NULL,
  unite           ENUM('mois','jour','unite','personne','forfait') NULL,
  quantite        DECIMAL(10,2) NULL,
  valeur_unitaire DECIMAL(14,2) NULL,
  montant         DECIMAL(14,2) NULL COMMENT 'Budget contractuel, fige, modifiable par avenant seulement',
  montant_gestion DECIMAL(14,2) NULL COMMENT 'Budget de gestion : realloc. et provisions ; historique au journal d''audit',
  quantite_gestion DECIMAL(10,2) NULL,
  ordre           SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_lignes_code (projet_id, code),
  KEY idx_lignes_parent (parent_code),
  KEY idx_lignes_budgetaires_projet (projet_id),
  CONSTRAINT fk_lignes_budgetaires_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contrats (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  tiers_id            INT UNSIGNED NOT NULL,
  ligne_id            SMALLINT UNSIGNED NULL COMMENT 'Facultatif : une convention de partenariat non remuneree n''en a aucune',
  type                ENUM('service','travail','convention_partenariat') NOT NULL DEFAULT 'service',
  devise              CHAR(3) NOT NULL DEFAULT 'HTG' COMMENT 'Une delegation peut etre libellee en devise etrangere',
  avance_autorisee    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Interdite aux contrats mensuels recurrents',
  part_avance         DECIMAL(5,2) NULL COMMENT 'Pourcentage du brut verse a la signature',
  numero              VARCHAR(30) NULL,
  fonction            VARCHAR(120) NOT NULL,
  date_debut          DATE NOT NULL,
  date_fin            DATE NOT NULL,
  unite               ENUM('mois','jour','unite','personne','forfait') NOT NULL,
  quantite            DECIMAL(10,2) NOT NULL,
  montant_unitaire    DECIMAL(14,2) NOT NULL,
  montant_total       DECIMAL(14,2) NOT NULL,
  taux_acompte_defaut DECIMAL(5,2) NOT NULL DEFAULT 2.00,
  autorite_acceptation ENUM('coordinateur','assemblee_generale') NOT NULL DEFAULT 'coordinateur',
  fichier_id          INT UNSIGNED NULL COMMENT 'Contrat numerise',
  statut              ENUM('actif','suspendu','clos') NOT NULL DEFAULT 'actif',
  created_by          INT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_contrats_tiers FOREIGN KEY (tiers_id) REFERENCES tiers(id),
  CONSTRAINT fk_contrats_ligne FOREIGN KEY (ligne_id) REFERENCES lignes_budgetaires(id),
  CONSTRAINT fk_contrats_fichier FOREIGN KEY (fichier_id) REFERENCES fichiers(id),
  CONSTRAINT fk_contrats_user FOREIGN KEY (created_by) REFERENCES utilisateurs(id),
  KEY idx_contrats_projet (projet_id),
  CONSTRAINT fk_contrats_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- MODULE BUDGET (3) - lignes_budgetaires est cree plus haut
-- =====================================================================



-- =====================================================================
-- MODULE COMPTES (8)
-- =====================================================================

CREATE TABLE comptes (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  code        VARCHAR(12) NOT NULL,
  libelle     VARCHAR(150) NOT NULL,
  type        ENUM('banque','caisse','tiers','dette_dgi','avances','charge','financement','produit') NOT NULL,
  ligne_id    SMALLINT UNSIGNED NULL COMMENT 'Compte de charge rattache a une ligne budgetaire',
  compte_bancaire_id SMALLINT UNSIGNED NULL COMMENT 'Compte de tresorerie du referentiel partage',
  actif       TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uk_comptes_code (projet_id, code),
  CONSTRAINT fk_comptes_ligne FOREIGN KEY (ligne_id) REFERENCES lignes_budgetaires(id),
  CONSTRAINT fk_comptes_bancaire FOREIGN KEY (compte_bancaire_id) REFERENCES comptes_bancaires(id),
  KEY idx_comptes_projet (projet_id),
  CONSTRAINT fk_comptes_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reglements (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  numero              VARCHAR(20) NOT NULL,
  mode                ENUM('virement','cheque','especes_caisse') NOT NULL,
  numero_cheque       VARCHAR(25) NULL,
  beneficiaire_id     INT UNSIGNED NOT NULL COMMENT 'tiers.id - rend le conflit d''interets verifiable',
  compte_id           SMALLINT UNSIGNED NOT NULL COMMENT 'Compte de tresorerie du plan comptable',
  compte_bancaire_id  SMALLINT UNSIGNED NULL COMMENT 'Permet le rapprochement par compte, ventile par projet',
  montant             DECIMAL(14,2) NOT NULL,
  devise              CHAR(3) NOT NULL DEFAULT 'HTG',
  montant_devise      DECIMAL(14,2) NULL,
  taux_change         DECIMAL(12,6) NULL,
  preuve_taux_fichier_id INT UNSIGNED NULL,
  objet               VARCHAR(255) NOT NULL,
  origine_module      VARCHAR(20) NOT NULL,
  origine_ref         VARCHAR(40) NOT NULL COMMENT 'Ex: dossier:123',
  date_reglement      DATE NULL,
  document_id         INT UNSIGNED NULL COMMENT 'Bon de decaissement signe',
  statut              ENUM('demande','autorise','execute','annule') NOT NULL DEFAULT 'demande',
  motif_annulation    VARCHAR(255) NULL,
  created_by          INT UNSIGNED NOT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_reglements_numero (projet_id, numero),
  KEY idx_reglements_origine (origine_module, origine_ref),
  CONSTRAINT fk_reglements_benef FOREIGN KEY (beneficiaire_id) REFERENCES tiers(id),
  CONSTRAINT fk_reglements_compte FOREIGN KEY (compte_id) REFERENCES comptes(id),
  CONSTRAINT fk_reglements_bancaire FOREIGN KEY (compte_bancaire_id) REFERENCES comptes_bancaires(id),
  CONSTRAINT fk_reglements_taux FOREIGN KEY (preuve_taux_fichier_id) REFERENCES fichiers(id),
  CONSTRAINT fk_reglements_document FOREIGN KEY (document_id) REFERENCES documents(id),
  CONSTRAINT fk_reglements_user FOREIGN KEY (created_by) REFERENCES utilisateurs(id),
  KEY idx_reglements_projet (projet_id),
  CONSTRAINT fk_reglements_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE validations_reglement (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  reglement_id  INT UNSIGNED NOT NULL,
  mandataire_id INT UNSIGNED NOT NULL COMMENT 'tiers.id (personne mandataire)',
  nature        ENUM('autorisation_electronique','signature_bancaire') NOT NULL,
  apposition_id INT UNSIGNED NULL COMMENT 'Pour l''autorisation electronique',
  date          DATE NOT NULL,
  saisi_par     INT UNSIGNED NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_validation (reglement_id, mandataire_id, nature),
  CONSTRAINT fk_vr_reglement FOREIGN KEY (reglement_id) REFERENCES reglements(id),
  CONSTRAINT fk_vr_mandataire FOREIGN KEY (mandataire_id) REFERENCES tiers(id),
  CONSTRAINT fk_vr_apposition FOREIGN KEY (apposition_id) REFERENCES appositions(id),
  CONSTRAINT fk_vr_user FOREIGN KEY (saisi_par) REFERENCES utilisateurs(id),
  KEY idx_validations_reglement_projet (projet_id),
  CONSTRAINT fk_validations_reglement_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ecritures (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  date           DATE NOT NULL,
  periode_id     INT UNSIGNED NULL,
  libelle        VARCHAR(255) NOT NULL,
  type           ENUM('encaissement_tranche','facture','reglement','honoraires','versement_dgi','remboursement_frais','caisse','produit','autre') NOT NULL,
  origine_module VARCHAR(20) NOT NULL,
  origine_ref    VARCHAR(40) NOT NULL,
  reglement_id   INT UNSIGNED NULL COMMENT 'Au plus un reglement par ecriture',
  created_by     INT UNSIGNED NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_ecritures_reglement (reglement_id),
  KEY idx_ecritures_periode (periode_id),
  CONSTRAINT fk_ecritures_periode FOREIGN KEY (periode_id) REFERENCES periodes(id),
  CONSTRAINT fk_ecritures_reglement FOREIGN KEY (reglement_id) REFERENCES reglements(id),
  CONSTRAINT fk_ecritures_user FOREIGN KEY (created_by) REFERENCES utilisateurs(id),
  KEY idx_ecritures_projet (projet_id),
  CONSTRAINT fk_ecritures_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mouvements (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  ecriture_id      INT UNSIGNED NOT NULL,
  compte_id        SMALLINT UNSIGNED NOT NULL,
  tiers_id         INT UNSIGNED NULL COMMENT 'Sous-analyse du compte de tiers',
  sens             ENUM('D','C') NOT NULL,
  montant          DECIMAL(14,2) NOT NULL,
  depense_reportee TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Mention exigee par le formulaire de caisse PAIESC',
  observation      VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_mouvements_compte (compte_id),
  CONSTRAINT fk_mouvements_ecriture FOREIGN KEY (ecriture_id) REFERENCES ecritures(id),
  CONSTRAINT fk_mouvements_compte FOREIGN KEY (compte_id) REFERENCES comptes(id),
  CONSTRAINT fk_mouvements_tiers FOREIGN KEY (tiers_id) REFERENCES tiers(id),
  KEY idx_mouvements_projet (projet_id),
  CONSTRAINT fk_mouvements_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rapprochements (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  periode_id         INT UNSIGNED NOT NULL,
  compte_id          SMALLINT UNSIGNED NOT NULL,
  date_releve        DATE NOT NULL,
  solde_releve       DECIMAL(14,2) NOT NULL,
  solde_reconstitue  DECIMAL(14,2) NOT NULL,
  ecart              DECIMAL(14,2) NOT NULL,
  commentaire_ecart  TEXT NULL,
  releve_fichier_id  INT UNSIGNED NULL,
  document_id        INT UNSIGNED NULL,
  statut             ENUM('brouillon','valide') NOT NULL DEFAULT 'brouillon',
  created_by         INT UNSIGNED NOT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_rapprochement (periode_id, compte_id),
  CONSTRAINT fk_rappro_periode FOREIGN KEY (periode_id) REFERENCES periodes(id),
  CONSTRAINT fk_rappro_compte FOREIGN KEY (compte_id) REFERENCES comptes(id),
  CONSTRAINT fk_rappro_fichier FOREIGN KEY (releve_fichier_id) REFERENCES fichiers(id),
  CONSTRAINT fk_rappro_document FOREIGN KEY (document_id) REFERENCES documents(id),
  CONSTRAINT fk_rappro_user FOREIGN KEY (created_by) REFERENCES utilisateurs(id),
  KEY idx_rapprochements_projet (projet_id),
  CONSTRAINT fk_rapprochements_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lignes_rapprochement (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  rapprochement_id  INT UNSIGNED NOT NULL,
  sens              ENUM('plus','moins') NOT NULL,
  nature            ENUM('encaissement_transit','cheque_non_encaisse','mouvement_etranger','autre') NOT NULL,
  objet             VARCHAR(255) NOT NULL,
  montant           DECIMAL(14,2) NOT NULL,
  reglement_id      INT UNSIGNED NULL,
  motif_non_concordance VARCHAR(255) NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_lr_rapprochement FOREIGN KEY (rapprochement_id) REFERENCES rapprochements(id),
  CONSTRAINT fk_lr_reglement FOREIGN KEY (reglement_id) REFERENCES reglements(id),
  KEY idx_lignes_rapprochement_projet (projet_id),
  CONSTRAINT fk_lignes_rapprochement_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE arretes_caisse (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  compte_id         SMALLINT UNSIGNED NOT NULL,
  date              DATE NOT NULL,
  solde_theorique   DECIMAL(14,2) NOT NULL,
  solde_constate    DECIMAL(14,2) NOT NULL,
  ecart             DECIMAL(14,2) NOT NULL,
  commentaire       TEXT NULL,
  detenteur_id      INT UNSIGNED NULL COMMENT 'tiers.id de la personne intermediaire nommement designee',
  document_id       INT UNSIGNED NULL,
  renflouement_reglement_id INT UNSIGNED NULL,
  created_by        INT UNSIGNED NOT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_ac_compte FOREIGN KEY (compte_id) REFERENCES comptes(id),
  CONSTRAINT fk_ac_detenteur FOREIGN KEY (detenteur_id) REFERENCES tiers(id),
  CONSTRAINT fk_ac_document FOREIGN KEY (document_id) REFERENCES documents(id),
  CONSTRAINT fk_ac_reglement FOREIGN KEY (renflouement_reglement_id) REFERENCES reglements(id),
  CONSTRAINT fk_ac_user FOREIGN KEY (created_by) REFERENCES utilisateurs(id),
  KEY idx_arretes_caisse_projet (projet_id),
  CONSTRAINT fk_arretes_caisse_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- MODULE ACTIVITES (11)
-- =====================================================================

CREATE TABLE versions_cadre (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  numero     SMALLINT UNSIGNED NOT NULL,
  date       DATE NOT NULL,
  motif      VARCHAR(255) NOT NULL,
  auteur_id  INT UNSIGNED NOT NULL,
  figee      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Figee avec un rapport transmis',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_versions_cadre (projet_id, numero),
  CONSTRAINT fk_vc_auteur FOREIGN KEY (auteur_id) REFERENCES utilisateurs(id),
  KEY idx_versions_cadre_projet (projet_id),
  CONSTRAINT fk_versions_cadre_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cadre_elements (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  code        VARCHAR(10) NOT NULL,
  parent_id   INT UNSIGNED NULL,
  niveau      ENUM('objectif_general','objectif_specifique','resultat') NOT NULL,
  libelle     TEXT NOT NULL,
  risque      TEXT NULL,
  attenuation TEXT NULL,
  ordre       SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_cadre_code (projet_id, code),
  CONSTRAINT fk_cadre_parent FOREIGN KEY (parent_id) REFERENCES cadre_elements(id),
  KEY idx_cadre_elements_projet (projet_id),
  CONSTRAINT fk_cadre_elements_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE indicateurs (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  element_id     INT UNSIGNED NOT NULL,
  libelle        TEXT NOT NULL,
  cible_valeur   VARCHAR(60) NULL,
  cible_texte    VARCHAR(255) NULL,
  echeance_mois  TINYINT UNSIGNED NULL COMMENT 'Numero de mois de projet ; > duree = phase 2',
  verification   TEXT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_indicateurs_element FOREIGN KEY (element_id) REFERENCES cadre_elements(id),
  KEY idx_indicateurs_projet (projet_id),
  CONSTRAINT fk_indicateurs_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE releves (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  indicateur_id  INT UNSIGNED NOT NULL,
  version_id     INT UNSIGNED NOT NULL,
  date           DATE NOT NULL,
  valeur_atteinte VARCHAR(60) NULL,
  commentaire    TEXT NULL,
  auteur_id      INT UNSIGNED NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_releves_indicateur FOREIGN KEY (indicateur_id) REFERENCES indicateurs(id),
  CONSTRAINT fk_releves_version FOREIGN KEY (version_id) REFERENCES versions_cadre(id),
  CONSTRAINT fk_releves_auteur FOREIGN KEY (auteur_id) REFERENCES utilisateurs(id),
  KEY idx_releves_projet (projet_id),
  CONSTRAINT fk_releves_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE activites (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  code               VARCHAR(10) NOT NULL,
  element_id         INT UNSIGNED NULL COMMENT 'NULL pour les activites de visibilite',
  categorie          ENUM('cadre_logique','visibilite') NOT NULL DEFAULT 'cadre_logique',
  libelle            TEXT NOT NULL,
  ligne_id           SMALLINT UNSIGNED NULL,
  mois_debut         TINYINT UNSIGNED NULL,
  mois_fin           TINYINT UNSIGNED NULL,
  statut             ENUM('non_demarree','en_cours','realisee','abandonnee') NOT NULL DEFAULT 'non_demarree',
  livrable_attendu   VARCHAR(255) NULL,
  livrable_fichier_id INT UNSIGNED NULL,
  intervenants       VARCHAR(255) NULL,
  ordre              SMALLINT UNSIGNED NOT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_activites_code (projet_id, code),
  CONSTRAINT fk_activites_element FOREIGN KEY (element_id) REFERENCES cadre_elements(id),
  CONSTRAINT fk_activites_ligne FOREIGN KEY (ligne_id) REFERENCES lignes_budgetaires(id),
  CONSTRAINT fk_activites_fichier FOREIGN KEY (livrable_fichier_id) REFERENCES fichiers(id),
  KEY idx_activites_projet (projet_id),
  CONSTRAINT fk_activites_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE difficultes (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  activite_id       INT UNSIGNED NOT NULL,
  date              DATE NOT NULL,
  description       TEXT NOT NULL,
  mesure_corrective TEXT NULL,
  auteur_id         INT UNSIGNED NOT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_difficultes_activite FOREIGN KEY (activite_id) REFERENCES activites(id),
  CONSTRAINT fk_difficultes_auteur FOREIGN KEY (auteur_id) REFERENCES utilisateurs(id),
  KEY idx_difficultes_projet (projet_id),
  CONSTRAINT fk_difficultes_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sessions_formation (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  activite_id            INT UNSIGNED NOT NULL,
  numero                 TINYINT UNSIGNED NOT NULL,
  date_debut             DATE NOT NULL,
  date_fin               DATE NOT NULL,
  lieu                   VARCHAR(150) NOT NULL,
  formateur_id           INT UNSIGNED NOT NULL COMMENT 'tiers.id',
  feuille_presence_fichier_id INT UNSIGNED NULL,
  statut                 ENUM('planifiee','tenue','close') NOT NULL DEFAULT 'planifiee',
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_sessions_activite FOREIGN KEY (activite_id) REFERENCES activites(id),
  CONSTRAINT fk_sessions_formateur FOREIGN KEY (formateur_id) REFERENCES tiers(id),
  CONSTRAINT fk_sessions_feuille FOREIGN KEY (feuille_presence_fichier_id) REFERENCES fichiers(id),
  KEY idx_sessions_formation_projet (projet_id),
  CONSTRAINT fk_sessions_formation_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE participations (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  session_id       INT UNSIGNED NOT NULL,
  beneficiaire_id  INT UNSIGNED NOT NULL,
  jour             DATE NOT NULL COMMENT 'Presence au jour : controle des couverts 5.2',
  present          TINYINT(1) NOT NULL DEFAULT 1,
  resultat         ENUM('reussite','echec') NULL,
  fiche_evaluation_fichier_id INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_participation (session_id, beneficiaire_id, jour),
  CONSTRAINT fk_part_session FOREIGN KEY (session_id) REFERENCES sessions_formation(id),
  CONSTRAINT fk_part_beneficiaire FOREIGN KEY (beneficiaire_id) REFERENCES beneficiaires(id),
  CONSTRAINT fk_part_fiche FOREIGN KEY (fiche_evaluation_fichier_id) REFERENCES fichiers(id),
  KEY idx_participations_projet (projet_id),
  CONSTRAINT fk_participations_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE versions_application (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  numero          VARCHAR(20) NOT NULL,
  date            DATE NOT NULL,
  nature          ENUM('test_interne','validation','publication','correctif') NOT NULL,
  modules_touches SET('m1','m2','m3','m4','m5') NULL COMMENT 'Les cinq modules du resultat 1.1',
  canal           VARCHAR(60) NULL,
  etat_diffusion  ENUM('preparee','diffusee','retiree') NOT NULL DEFAULT 'preparee',
  verification_google ENUM('non_soumis','soumis','valide','refuse') NULL,
  activite_code   VARCHAR(10) NULL,
  saisi_par       INT UNSIGNED NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_versions_app (projet_id, numero),
  CONSTRAINT fk_va_user FOREIGN KEY (saisi_par) REFERENCES utilisateurs(id),
  KEY idx_versions_application_projet (projet_id),
  CONSTRAINT fk_versions_application_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE anomalies (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  declarant_id     INT UNSIGNED NULL COMMENT 'tiers.id (organisation)',
  date             DATE NOT NULL,
  description      TEXT NOT NULL,
  gravite          ENUM('faible','moyenne','critique') NOT NULL,
  canal            VARCHAR(60) NULL,
  date_accuse      DATE NULL,
  reponse          TEXT NULL,
  date_resolution  DATE NULL,
  version_id       INT UNSIGNED NULL COMMENT 'Correctif rattache, facultatif',
  nature           ENUM('anomalie','conseil_usage') NOT NULL DEFAULT 'anomalie',
  saisi_par        INT UNSIGNED NOT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_anomalies_declarant FOREIGN KEY (declarant_id) REFERENCES tiers(id),
  CONSTRAINT fk_anomalies_version FOREIGN KEY (version_id) REFERENCES versions_application(id),
  CONSTRAINT fk_anomalies_user FOREIGN KEY (saisi_par) REFERENCES utilisateurs(id),
  KEY idx_anomalies_projet (projet_id),
  CONSTRAINT fk_anomalies_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE enquetes_adoption (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  organisation_id INT UNSIGNED NOT NULL,
  date            DATE NOT NULL,
  usage_actif     TINYINT(1) NOT NULL,
  observations    TEXT NULL,
  saisi_par       INT UNSIGNED NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_ea_org FOREIGN KEY (organisation_id) REFERENCES tiers(id),
  CONSTRAINT fk_ea_user FOREIGN KEY (saisi_par) REFERENCES utilisateurs(id),
  KEY idx_enquetes_adoption_projet (projet_id),
  CONSTRAINT fk_enquetes_adoption_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- MODULE DEPENSES (4)
-- =====================================================================

CREATE TABLE dossiers (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  numero          VARCHAR(20) NOT NULL COMMENT 'Numero de dossier interne (a l''ouverture)',
  type            VARCHAR(30) NOT NULL COMMENT 'Code TYPES_DOSSIER',
  tiers_id        INT UNSIGNED NOT NULL COMMENT 'Beneficiaire',
  objet           VARCHAR(255) NOT NULL,
  periode_id      INT UNSIGNED NULL,
  statut          ENUM('brouillon','impute','en_concurrence','commande','receptionne','approuve','regle','clos','abandonne') NOT NULL DEFAULT 'brouillon',
  approuve_par    INT UNSIGNED NULL,
  approuve_le     DATETIME NULL,
  reglement_ref   VARCHAR(40) NULL COMMENT 'Reference au module Comptes (valeur)',
  derogation_quantite_motif TEXT NULL,
  created_by      INT UNSIGNED NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_dossiers_numero (projet_id, numero),
  KEY idx_dossiers_statut (statut),
  CONSTRAINT fk_dossiers_tiers FOREIGN KEY (tiers_id) REFERENCES tiers(id),
  CONSTRAINT fk_dossiers_periode FOREIGN KEY (periode_id) REFERENCES periodes(id),
  CONSTRAINT fk_dossiers_approbateur FOREIGN KEY (approuve_par) REFERENCES utilisateurs(id),
  CONSTRAINT fk_dossiers_user FOREIGN KEY (created_by) REFERENCES utilisateurs(id),
  KEY idx_dossiers_projet (projet_id),
  CONSTRAINT fk_dossiers_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE imputations (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  dossier_id      INT UNSIGNED NOT NULL,
  ligne_id        SMALLINT UNSIGNED NOT NULL COMMENT 'Une seule ligne par dossier',
  unite           ENUM('mois','jour','unite','personne','forfait') NOT NULL,
  quantite        DECIMAL(10,2) NOT NULL,
  valeur_unitaire DECIMAL(14,2) NOT NULL,
  montant         DECIMAL(14,2) NOT NULL COMMENT 'quantite x valeur_unitaire, TTC',
  nature          ENUM('consommation','memoire') NOT NULL DEFAULT 'consommation',
  numero_piece    VARCHAR(10) NULL COMMENT 'RR-SSS attribue au reglement',
  date_imputation DATE NOT NULL,
  version_budget_ref SMALLINT UNSIGNED NULL COMMENT 'Version du budget de gestion en vigueur',
  PRIMARY KEY (id),
  UNIQUE KEY uk_imputation_dossier (dossier_id),
  UNIQUE KEY uk_imputation_piece (projet_id, numero_piece),
  CONSTRAINT fk_imputations_dossier FOREIGN KEY (dossier_id) REFERENCES dossiers(id),
  CONSTRAINT fk_imputations_ligne FOREIGN KEY (ligne_id) REFERENCES lignes_budgetaires(id),
  KEY idx_imputations_projet (projet_id),
  CONSTRAINT fk_imputations_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pieces (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  dossier_id   INT UNSIGNED NOT NULL,
  type         VARCHAR(40) NOT NULL,
  libelle      VARCHAR(120) NOT NULL,
  obligatoire  TINYINT(1) NOT NULL DEFAULT 1,
  moment       ENUM('avant','apres') NOT NULL,
  fichier_id   INT UNSIGNED NULL,
  document_id  INT UNSIGNED NULL COMMENT 'Si la piece est un document genere',
  statut       ENUM('attendue','recue','sans_objet') NOT NULL DEFAULT 'attendue',
  date_piece   DATE NULL,
  ordre        TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pieces_dossier (dossier_id),
  CONSTRAINT fk_pieces_dossier FOREIGN KEY (dossier_id) REFERENCES dossiers(id),
  CONSTRAINT fk_pieces_fichier FOREIGN KEY (fichier_id) REFERENCES fichiers(id),
  CONSTRAINT fk_pieces_document FOREIGN KEY (document_id) REFERENCES documents(id),
  KEY idx_pieces_projet (projet_id),
  CONSTRAINT fk_pieces_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Case de checklist creee vide a l''ouverture selon le type';

CREATE TABLE proformas (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  dossier_id     INT UNSIGNED NOT NULL,
  fournisseur_id INT UNSIGNED NOT NULL,
  montant        DECIMAL(14,2) NOT NULL,
  fichier_id     INT UNSIGNED NULL,
  retenu         TINYINT(1) NOT NULL DEFAULT 0,
  motif_choix    TEXT NULL COMMENT 'Obligatoire si l''offre retenue n''est pas la moins-disante',
  PRIMARY KEY (id),
  CONSTRAINT fk_proformas_dossier FOREIGN KEY (dossier_id) REFERENCES dossiers(id),
  CONSTRAINT fk_proformas_fournisseur FOREIGN KEY (fournisseur_id) REFERENCES tiers(id),
  CONSTRAINT fk_proformas_fichier FOREIGN KEY (fichier_id) REFERENCES fichiers(id),
  KEY idx_proformas_projet (projet_id),
  CONSTRAINT fk_proformas_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- MODULE REMUNERATION (3)
-- =====================================================================

CREATE TABLE rapports_execution (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  contrat_id            INT UNSIGNED NOT NULL,
  mois                  TINYINT UNSIGNED NOT NULL COMMENT 'Mois de projet',
  date_remise           DATE NOT NULL,
  date_versement        DATE NOT NULL COMMENT 'Versement au dossier par le RAF',
  fichier_id            INT UNSIGNED NOT NULL,
  autorite              ENUM('coordinateur','assemblee_generale') NOT NULL,
  statut                ENUM('recu','accepte','refuse') NOT NULL DEFAULT 'recu',
  certificat_document_id INT UNSIGNED NULL,
  accepte_par           INT UNSIGNED NULL,
  accepte_le            DATETIME NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_rapport_contrat_mois (contrat_id, mois),
  CONSTRAINT fk_re_contrat FOREIGN KEY (contrat_id) REFERENCES contrats(id),
  CONSTRAINT fk_re_fichier FOREIGN KEY (fichier_id) REFERENCES fichiers(id),
  CONSTRAINT fk_re_certificat FOREIGN KEY (certificat_document_id) REFERENCES documents(id),
  CONSTRAINT fk_re_user FOREIGN KEY (accepte_par) REFERENCES utilisateurs(id),
  KEY idx_rapports_execution_projet (projet_id),
  CONSTRAINT fk_rapports_execution_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prestations (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  contrat_id           INT UNSIGNED NOT NULL,
  rapport_id           INT UNSIGNED NOT NULL,
  mois                 TINYINT UNSIGNED NOT NULL,
  quantite             DECIMAL(10,2) NOT NULL,
  brut                 DECIMAL(14,2) NOT NULL,
  taux_acompte         DECIMAL(5,2) NOT NULL COMMENT 'Fige au calcul',
  acompte              DECIMAL(14,2) NOT NULL,
  net                  DECIMAL(14,2) NOT NULL,
  dossier_ref          VARCHAR(40) NULL COMMENT 'Dossier de depense (valeur)',
  ratification         ENUM('sans_objet','provisoire','ratifiee') NOT NULL DEFAULT 'sans_objet',
  resolution_fichier_id INT UNSIGNED NULL COMMENT 'Resolution de l''AG couvrant la prestation',
  versement_dgi_id     INT UNSIGNED NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_prestation_contrat_mois (contrat_id, mois),
  CONSTRAINT fk_prestations_contrat FOREIGN KEY (contrat_id) REFERENCES contrats(id),
  CONSTRAINT fk_prestations_rapport FOREIGN KEY (rapport_id) REFERENCES rapports_execution(id),
  CONSTRAINT fk_prestations_resolution FOREIGN KEY (resolution_fichier_id) REFERENCES fichiers(id),
  KEY idx_prestations_projet (projet_id),
  CONSTRAINT fk_prestations_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE versements_dgi (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  mois           TINYINT UNSIGNED NOT NULL,
  montant_total  DECIMAL(14,2) NOT NULL,
  dossier_ref    VARCHAR(40) NULL COMMENT 'Dossier versement_dgi (valeur)',
  recu_scelle_fichier_id INT UNSIGNED NULL,
  statut         ENUM('a_verser','verse','justifie') NOT NULL DEFAULT 'a_verser',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_versement_mois (projet_id, mois),
  CONSTRAINT fk_vd_fichier FOREIGN KEY (recu_scelle_fichier_id) REFERENCES fichiers(id),
  KEY idx_versements_dgi_projet (projet_id),
  CONSTRAINT fk_versements_dgi_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE prestations ADD CONSTRAINT fk_prestations_vdgi FOREIGN KEY (versement_dgi_id) REFERENCES versements_dgi(id);

-- =====================================================================
-- MODULE RESTITUTION (3)
-- =====================================================================

CREATE TABLE rapports (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  type               ENUM('mensuel','intermediaire','final','rectificatif') NOT NULL,
  periode_debut      DATE NOT NULL,
  periode_fin        DATE NOT NULL,
  periode_id         INT UNSIGNED NULL,
  version            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  rectifie_id        INT UNSIGNED NULL COMMENT 'Rapport transmis que celui-ci rectifie',
  statut             ENUM('brouillon','valide','transmis') NOT NULL DEFAULT 'brouillon',
  version_cadre_ref  SMALLINT UNSIGNED NULL COMMENT 'Version du cadre logique jointe (valeur)',
  contenu_json       JSON NULL COMMENT 'Sections redigees et donnees figees',
  narratif_document_id  INT UNSIGNED NULL,
  financier_document_id INT UNSIGNED NULL,
  date_transmission  DATE NULL,
  accuse_fichier_id  INT UNSIGNED NULL,
  valide_par         INT UNSIGNED NULL,
  created_by         INT UNSIGNED NOT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_rapports_periode FOREIGN KEY (periode_id) REFERENCES periodes(id),
  CONSTRAINT fk_rapports_rectifie FOREIGN KEY (rectifie_id) REFERENCES rapports(id),
  CONSTRAINT fk_rapports_narratif FOREIGN KEY (narratif_document_id) REFERENCES documents(id),
  CONSTRAINT fk_rapports_financier FOREIGN KEY (financier_document_id) REFERENCES documents(id),
  CONSTRAINT fk_rapports_accuse FOREIGN KEY (accuse_fichier_id) REFERENCES fichiers(id),
  CONSTRAINT fk_rapports_valideur FOREIGN KEY (valide_par) REFERENCES utilisateurs(id),
  CONSTRAINT fk_rapports_user FOREIGN KEY (created_by) REFERENCES utilisateurs(id),
  KEY idx_rapports_projet (projet_id),
  CONSTRAINT fk_rapports_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lignes_financieres (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  rapport_id          INT UNSIGNED NOT NULL,
  ligne_id            SMALLINT UNSIGNED NOT NULL,
  budget_unite        VARCHAR(20) NULL,
  budget_quantite     DECIMAL(10,2) NULL,
  budget_valeur       DECIMAL(14,2) NULL,
  budget_total        DECIMAL(14,2) NULL COMMENT '(a) budget contractuel',
  periode_quantite    DECIMAL(10,2) NULL,
  periode_valeur      DECIMAL(14,2) NULL COMMENT 'Valeur unitaire moyenne',
  periode_total       DECIMAL(14,2) NULL COMMENT '(b)',
  cumul_anterieur     DECIMAL(14,2) NULL COMMENT '(c)',
  cumul_total         DECIMAL(14,2) NULL COMMENT '(d)',
  difference          DECIMAL(14,2) NULL COMMENT '(d-a)',
  PRIMARY KEY (id),
  UNIQUE KEY uk_lf (rapport_id, ligne_id),
  CONSTRAINT fk_lf_rapport FOREIGN KEY (rapport_id) REFERENCES rapports(id),
  CONSTRAINT fk_lf_ligne FOREIGN KEY (ligne_id) REFERENCES lignes_budgetaires(id),
  KEY idx_lignes_financieres_projet (projet_id),
  CONSTRAINT fk_lignes_financieres_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Stockees, jamais recalculees apres transmission';

CREATE TABLE liasses (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  rapport_id     INT UNSIGNED NULL,
  type           ENUM('dossier','periode','classement') NOT NULL,
  objet_ref      VARCHAR(40) NULL COMMENT 'dossier:ID pour une liasse de dossier',
  fichier_id     INT UNSIGNED NOT NULL,
  nombre_pieces  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_by     INT UNSIGNED NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_liasses_rapport FOREIGN KEY (rapport_id) REFERENCES rapports(id),
  CONSTRAINT fk_liasses_fichier FOREIGN KEY (fichier_id) REFERENCES fichiers(id),
  CONSTRAINT fk_liasses_user FOREIGN KEY (created_by) REFERENCES utilisateurs(id),
  KEY idx_liasses_projet (projet_id),
  CONSTRAINT fk_liasses_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- MODULE FINANCEMENT (3)
-- =====================================================================

CREATE TABLE sources_revenu (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id       INT UNSIGNED NOT NULL,
  origine         ENUM('subvention','fondation','parrainage','vente','inscription','don','apport_propre','autre') NOT NULL,
  libelle         VARCHAR(150) NOT NULL,
  montant_attendu DECIMAL(14,2) NOT NULL,
  montant_acquis  DECIMAL(14,2) NOT NULL DEFAULT 0,
  statut          ENUM('en_cours','acquis','abandonne') NOT NULL DEFAULT 'en_cours',
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sources_projet (projet_id),
  CONSTRAINT fk_sources_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Le PAIESC n''en declare qu''une, decoupee en tranches ; la FOKAL plusieurs';

CREATE TABLE tranches (
  id                  TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  source_revenu_id    INT UNSIGNED NOT NULL,
  numero              TINYINT UNSIGNED NOT NULL,
  taux                DECIMAL(5,2) NULL COMMENT 'Pourcentage contractuel, la ou le bailleur en fixe un',
  declencheur         VARCHAR(255) NULL COMMENT 'Condition de versement, saisie quand elle n''est pas un taux',
  montant_contractuel DECIMAL(14,2) NULL COMMENT 'Saisi a la signature',
  montant_recu        DECIMAL(14,2) NULL COMMENT 'Constate sur avis de credit',
  date_reception      DATE NULL,
  avis_credit_fichier_id INT UNSIGNED NULL,
  ecriture_ref        VARCHAR(40) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_tranches_numero (projet_id, numero),
  CONSTRAINT fk_tranches_fichier FOREIGN KEY (avis_credit_fichier_id) REFERENCES fichiers(id),
  CONSTRAINT fk_tranches_source FOREIGN KEY (source_revenu_id) REFERENCES sources_revenu(id),
  KEY idx_tranches_projet (projet_id),
  CONSTRAINT fk_tranches_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE demandes_paiement (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  tranche_id           TINYINT UNSIGNED NOT NULL,
  date                 DATE NOT NULL,
  montant              DECIMAL(14,2) NOT NULL,
  statut               ENUM('preparation','validee','transmise','complement_demande','complement_repondu','payee') NOT NULL DEFAULT 'preparation',
  document_id          INT UNSIGNED NULL,
  date_transmission    DATE NULL,
  accuse_fichier_id    INT UNSIGNED NULL,
  date_demande_complement DATE NULL,
  date_reponse_complement DATE NULL,
  rapport_ref          VARCHAR(40) NULL COMMENT 'Rapport fige joint (valeur)',
  created_by           INT UNSIGNED NOT NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_dp_tranche FOREIGN KEY (tranche_id) REFERENCES tranches(id),
  CONSTRAINT fk_dp_document FOREIGN KEY (document_id) REFERENCES documents(id),
  CONSTRAINT fk_dp_accuse FOREIGN KEY (accuse_fichier_id) REFERENCES fichiers(id),
  CONSTRAINT fk_dp_user FOREIGN KEY (created_by) REFERENCES utilisateurs(id),
  KEY idx_demandes_paiement_projet (projet_id),
  CONSTRAINT fk_demandes_paiement_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pieces_demande (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projet_id        INT UNSIGNED NOT NULL,
  demande_id   INT UNSIGNED NOT NULL,
  type         VARCHAR(40) NOT NULL,
  libelle      VARCHAR(150) NOT NULL,
  obligatoire  TINYINT(1) NOT NULL DEFAULT 1,
  fichier_id   INT UNSIGNED NULL,
  statut       ENUM('attendue','recue','sans_objet') NOT NULL DEFAULT 'attendue',
  ordre        TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_pd_demande FOREIGN KEY (demande_id) REFERENCES demandes_paiement(id),
  CONSTRAINT fk_pd_fichier FOREIGN KEY (fichier_id) REFERENCES fichiers(id),
  KEY idx_pieces_demande_projet (projet_id),
  CONSTRAINT fk_pieces_demande_projet FOREIGN KEY (projet_id) REFERENCES projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;




-- Reference Noyau -> fichier (piece d'identite) ajoutee apres creation de fichiers
ALTER TABLE tiers ADD CONSTRAINT fk_tiers_piece_identite FOREIGN KEY (piece_identite_fichier_id) REFERENCES fichiers(id);

SET FOREIGN_KEY_CHECKS = 1;

-- Les triggers d'immuabilite sont dans database/schema_triggers.sql, a importer
-- ensuite : sur un hebergement mutualise avec journalisation binaire, leur creation
-- peut etre refusee a un utilisateur sans SUPER (erreur 1419). Les separer rend cet
-- echec visible au lieu de le noyer dans l'import du schema.
