-- =====================================================================
-- Bousol - donnees d'initialisation
-- Ordre : schema.sql puis seed.sql
-- =====================================================================
SET NAMES utf8mb4;

-- 1. Modules (CDC 7.2) --------------------------------------------------
INSERT INTO module_etats (module, libelle, version, critique) VALUES
('noyau',        'Noyau',        '1.0.0', 1),
('signature',    'Signature',    '1.0.0', 0),
('tiers',        'Tiers',        '0.1.0', 0),
('budget',       'Budget',       '0.1.0', 1),
('comptes',      'Comptes',      '0.1.0', 0),
('activites',    'Activités',    '0.1.0', 0),
('depenses',     'Dépenses',     '0.1.0', 0),
('remuneration', 'Rémunération', '0.1.0', 0),
('restitution',  'Restitution',  '0.1.0', 0),
('financement',  'Financement',  '0.1.0', 0),
('formulaires',  'Formulaires',  '0.1.0', 0);

-- 2. Projets -----------------------------------------------------------
-- Le projet est une dimension de toute donnee d'execution (addendum 1, section 2).
INSERT INTO projets (id, code, intitule, bailleur, referentiel, duree_mois, plafond_contractuel, suivi_post_cloture, statut) VALUES
(1, 'KESKLE', 'KèsKlè',        'UGP DP-PAIESC',                   'PAIESC',      8, 5600000.00, 1, 'actif'),
(2, 'KKP',    'Koulè Ki Pale', 'FOKAL, programme REVIV, appel 5', 'FOKAL_REVIV', 4,  974556.00, 0, 'actif');

-- 3. Phases, propres a chaque projet ------------------------------------
INSERT INTO phases (projet_id, code, statut) VALUES
(1, 'projet_actif', 'en_cours'), (1, 'regularisation', 'a_venir'), (1, 'post_cloture', 'a_venir'),
(2, 'projet_actif', 'en_cours'), (2, 'regularisation', 'a_venir');
-- Koule Ki Pale n'a pas de suivi post-cloture : le projet se clot en decembre 2026.

-- 4. Comptes bancaires, referentiel partage ------------------------------
INSERT INTO comptes_bancaires (id, etablissement, numero, titulaire, devise, type) VALUES
(1, 'SOGEBANK', '3306006788', 'DÉVELOPPEMENT ET DYNAMISME', 'HTG', 'banque');
INSERT INTO projets_comptes (projet_id, compte_bancaire_id, role, dedie, date_rattachement) VALUES
(1, 1, 'principal', 0, CURDATE()),
(2, 1, 'principal', 0, CURDATE());
-- Compte partage : le rapprochement se fait par compte et se ventile par projet (addendum, section 5).

-- 5. Administrateur de l'outil ------------------------------------------
-- Cree les projets et n'y saisit rien ; il n'est affecte a aucun projet.
-- A ne pas confondre avec l'Administrateur des budgets, qui est le RAF.
-- Mot de passe temporaire : Bousol!2026  (changement force au premier login)
-- Aucune affectation n'est creee ici : elle exige un acte de delegation televerse.
INSERT INTO tiers (id, type, nom, fonction, est_mandataire, email) VALUES
(1, 'personne', 'Administrateur initial', 'Administrateur de l''outil', 0, 'admin@dev-dynamics.org');
INSERT INTO utilisateurs (id, tiers_id, email, mot_de_passe, admin_outil, doit_changer_mdp) VALUES
(1, 1, 'admin@dev-dynamics.org', '$2y$12$zDD9u2koNBl81wP8vO0GcOP8KbXLA4ajN3nJTckInOmEgXDebT6Ra', 1, 1);

-- Organisation beneficiaire du contrat et bailleur (tiers de reference)
INSERT INTO tiers (id, type, nom, sigle, commune, adresse) VALUES
(2, 'organisation', 'DÉVELOPPEMENT ET DYNAMISME', 'DevDynamics', 'Cap-Haïtien', NULL),
(3, 'administration', 'Direction Générale des Impôts', 'DGI', NULL, NULL),
(4, 'administration', 'UGP DP-PAIESC', 'PAIESC', 'Port-au-Prince', '#04, Rue Morelly, Christ-Roi');

-- 6. Parametres (annexe F), lus projet par projet - une ligne par version, jamais d'UPDATE -------
INSERT INTO parametres (projet_id, cle, valeur, date_effet, motif, auteur_id) VALUES
(1, 'duree_regularisation_jours', '30', '2026-01-01', 'Annexe F', 1),
(1, 'taux_acompte_defaut_pct', '2', '2026-01-01', 'Guide de procedures', 1),
(1, 'mode_reglement_defaut', 'virement', '2026-01-01', 'Annexe F', 1),
(1, 'ecart_recu_reglement_jours', '0', '2026-01-01', 'Meme jour', 1),
(1, 'regime_signature_defaut', 'papier', '2026-01-01', 'Annexe F', 1),
(1, 'signature_par_lot', '0', '2026-01-01', 'Desactivee a la livraison', 1),
(1, 'seuil_alerte_variation_pct', '20', '2026-01-01', 'Annexe F', 1),
(1, 'seuil_blocage_variation_pct', '25', '2026-01-01', 'Annexe F - non modifiable', 1),
(1, 'delai_alerte_sauvegarde_jours', NULL, '2026-01-01', 'A definir', 1),
(1, 'plafond_petite_caisse', NULL, '2026-01-01', 'A definir', 1),
(1, 'plafond_depense_especes', NULL, '2026-01-01', 'A definir', 1),
(1, 'representant_legal', NULL, '2026-01-01', 'A saisir', 1),
(1, 'nom_organisation', 'DÉVELOPPEMENT ET DYNAMISME', '2026-01-01', 'Porteur', 1),
(1, 'numero_contrat', NULL, '2026-01-01', 'A saisir a la signature', 1),
(1, 'date_debut_execution', NULL, '2026-01-01', 'A saisir', 1),
(1, 'duree_execution_mois', '8', '2026-01-01', 'Contrat', 1),
(1, 'seconde_borne', '2028-04-30', '2026-01-01', 'Fin du programme PAIESC (absolue)', 1),
(1, 'seuil_proformas', NULL, '2026-01-01', 'A definir, en gourdes, tous achats', 1),
(1, 'seuil_concurrence_devise', 'HTG', '2026-01-01', 'Le PAIESC exprime le seuil en gourdes', 1),
(1, 'seuil_concurrence_perimetre', 'tout_achat', '2026-01-01', 'Tous achats', 1),
(1, 'granularite_variation', 'rubrique', '2026-01-01', '25 % entre rubriques principales', 1),
(1, 'regime_provision', 'ligne_dediee', '2026-01-01', 'Ligne 10, mobilisable sur autorisation', 1),
(1, 'exemplaires_mention', 'Organisation|Bailleur|Bailleur', '2026-01-01', 'Un organisation, deux bailleur', 1),
(1, 'delai_accuse_phase2_heures', '48', '2026-01-01', 'Heures ouvrables', 1),
(1, 'delai_correctif_phase2_jours', '15', '2026-01-01', 'Annexe F', 1),
(1, 'nom_projet', 'KèsKlè', '2026-01-01', 'Intitule de laction', 1),
(2, 'duree_regularisation_jours', '30', '2026-01-01', 'Annexe F', 1),
(2, 'taux_acompte_defaut_pct', '2', '2026-01-01', 'Guide de procedures', 1),
(2, 'mode_reglement_defaut', 'virement', '2026-01-01', 'Annexe F', 1),
(2, 'ecart_recu_reglement_jours', '0', '2026-01-01', 'Meme jour', 1),
(2, 'regime_signature_defaut', 'papier', '2026-01-01', 'Annexe F', 1),
(2, 'signature_par_lot', '0', '2026-01-01', 'Desactivee a la livraison', 1),
(2, 'seuil_alerte_variation_pct', '20', '2026-01-01', 'Annexe F', 1),
(2, 'seuil_blocage_variation_pct', '25', '2026-01-01', 'Annexe F - non modifiable', 1),
(2, 'delai_alerte_sauvegarde_jours', NULL, '2026-01-01', 'A definir', 1),
(2, 'plafond_petite_caisse', NULL, '2026-01-01', 'A definir', 1),
(2, 'plafond_depense_especes', NULL, '2026-01-01', 'A definir', 1),
(2, 'representant_legal', NULL, '2026-01-01', 'A saisir', 1),
(2, 'nom_organisation', 'DÉVELOPPEMENT ET DYNAMISME', '2026-01-01', 'Porteur', 1),
(2, 'numero_contrat', NULL, '2026-01-01', 'A saisir a la signature de la convention FOKAL', 1),
(2, 'date_debut_execution', NULL, '2026-01-01', 'A saisir - ancrage du calendrier relatif', 1),
(2, 'duree_execution_mois', '4', '2026-01-01', 'Septembre a decembre 2026', 1),
(2, 'seuil_proformas', NULL, '2026-01-01', 'Contre-valeur des 500 euros, a figer une fois pour toutes', 1),
(2, 'seuil_concurrence_devise', 'EUR', '2026-01-01', 'Le guide REVIV exprime le seuil en euros', 1),
(2, 'seuil_concurrence_perimetre', 'equipements_materiels', '2026-01-01', 'Equipements et materiels seulement', 1),
(2, 'granularite_variation', 'ligne', '2026-01-01', 'Lecture la plus restrictive : 25 % entre lignes', 1),
(2, 'regime_provision', 'ligne_mixte', '2026-01-01', 'Imputable pour les seuls frais bancaires', 1),
(2, 'exemplaires_mention', 'Organisation|FOKAL|Union européenne', '2026-01-01', 'Un organisation, deux FOKAL dont un UE', 1),
(2, 'nom_projet', 'Koulè Ki Pale', '2026-01-01', 'Intitule de laction', 1);

-- 7. Nomenclature budgetaire de KesKle (annexe A) - 31 lignes, budget contractuel fige
INSERT INTO lignes_budgetaires (projet_id, code, parent_code, rubrique, niveau, nature, libelle, unite, quantite, valeur_unitaire, montant, ordre) VALUES
(1, '1',     NULL,  1, 1, 'rubrique',  'Ressources humaines de l''association',                        NULL,       NULL, NULL,      1360000.00, 1),
(1, '1.1',   '1',   1, 2, 'imputable', 'Coordonnateur du projet',                                      'mois',     8,    120000.00, 960000.00,  2),
(1, '1.2',   '1',   1, 2, 'imputable', 'Responsable Administratif et Financier',                       'mois',     8,    50000.00,  400000.00,  3),
(1, '2',     NULL,  2, 1, 'rubrique',  'Équipement et fournitures',                                    NULL,       NULL, NULL,      83325.00,   4),
(1, '2.1',   '2',   2, 2, 'imputable', 'Achats de téléphones de test multi-versions',                  'unite',    4,    20000.00,  80000.00,   5),
(1, '2.2',   '2',   2, 2, 'imputable', 'Compte développeur Google Play',                               'forfait',  1,    3325.00,   3325.00,    6),
(1, '3',     NULL,  3, 1, 'rubrique',  'Bureau local',                                                 NULL,       NULL, NULL,      334000.00,  7),
(1, '3.1',   '3',   3, 2, 'imputable', 'Consommables et fournitures de bureau',                        'mois',     8,    13000.00,  104000.00,  8),
(1, '3.2',   '3',   3, 2, 'imputable', 'Autres services, internet, téléphone, carburant, entretien',   'mois',     8,    25000.00,  200000.00,  9),
(1, '3.3',   '3',   3, 2, 'imputable', 'Déplacements de coordination',                                 'forfait',  1,    30000.00,  30000.00,   10),
(1, '4',     NULL,  4, 1, 'rubrique',  'Communication',                                                NULL,       NULL, NULL,      480000.00,  11),
(1, '4.1',   '4',   4, 2, 'imputable', 'Chargé de communication',                                      'forfait',  1,    160000.00, 160000.00,  12),
(1, '4.2',   '4',   4, 2, 'imputable', 'Atelier de lancement',                                         'forfait',  1,    135000.00, 135000.00,  13),
(1, '4.3',   '4',   4, 2, 'imputable', 'Atelier de clôture',                                           'forfait',  1,    135000.00, 135000.00,  14),
(1, '4.4',   '4',   4, 2, 'imputable', 'Impression de roll-up et back-drop',                           'forfait',  1,    50000.00,  50000.00,   15),
(1, '5',     NULL,  5, 1, 'rubrique',  'Autres coûts et services',                                     NULL,       NULL, NULL,      264000.00,  16),
(1, '5.1',   '5',   5, 2, 'imputable', 'Location de salle de formation',                               'jour',     6,    20000.00,  120000.00,  17),
(1, '5.2',   '5',   5, 2, 'imputable', 'Restauration et collation',                                    'personne', 90,   1600.00,   144000.00,  18),
(1, '6',     NULL,  6, 1, 'rubrique',  'Activités',                                                    NULL,       NULL, NULL,      2463000.00, 19),
(1, 'AE1',   '6',   6, 2, 'axe',       'Développement et distribution de l''application',              NULL,       NULL, NULL,      2180000.00, 20),
(1, 'AE1.1', 'AE1', 6, 3, 'imputable', 'Développeur lead technique',                                   'mois',     7,    130000.00, 910000.00,  21),
(1, 'AE1.2', 'AE1', 6, 3, 'imputable', 'Développeur back-end et intégration',                          'mois',     6,    120000.00, 720000.00,  22),
(1, 'AE1.3', 'AE1', 6, 3, 'imputable', 'Développeur mobile Flutter',                                   'mois',     5,    110000.00, 550000.00,  23),
(1, 'AE2',   '6',   6, 2, 'axe',       'Formation, renforcement et déploiement',                       NULL,       NULL, NULL,      283000.00,  24),
(1, 'AE2.1', 'AE2', 6, 3, 'imputable', 'Formateur',                                                    'jour',     6,    40000.00,  240000.00,  25),
(1, 'AE2.2', 'AE2', 6, 3, 'imputable', 'Achat de matériels de formation',                              'forfait',  1,    43000.00,  43000.00,   26),
(1, '7',     NULL,  NULL, 1, 'calculee', 'Sous-total des coûts directs éligibles',                     NULL,       NULL, NULL,      4984325.00, 27),
(1, '8',     NULL,  NULL, 1, 'calculee', 'Coûts indirects, maximum 7 % de la ligne 7',                 NULL,       NULL, NULL,      348902.75,  28),
(1, '9',     NULL,  NULL, 1, 'calculee', 'Total hors réserve pour imprévus',                           NULL,       NULL, NULL,      5333227.75, 29),
(1, '10',    NULL,  NULL, 1, 'calculee', 'Provision pour imprévus, maximum 5 % de la ligne 9',         NULL,       NULL, NULL,      266661.39,  30),
(1, '11',    NULL,  NULL, 1, 'calculee', 'Total des coûts éligibles',                                  NULL,       NULL, NULL,      5599889.14, 31);

-- 7 bis. Nomenclature budgetaire de Koule Ki Pale (modele FOKAL)
-- Deux niveaux, deux unites, onze lignes imputables et une ligne calculee.
-- Les sous-totaux de rubrique sont ceux de la convention ; le detail par ligne
-- reste a saisir, le budget approuve ne le communiquant pas.
INSERT INTO lignes_budgetaires (projet_id, code, parent_code, rubrique, niveau, nature, libelle, unite, montant, ordre) VALUES
(2, '1',   NULL, 1, 1, 'rubrique',  'Ressources humaines',                NULL,      240000.00, 1),
(2, '1.1', '1',  1, 2, 'imputable', 'Coordinateur',                       'forfait', NULL,      2),
(2, '1.2', '1',  1, 2, 'imputable', 'Administrateur',                     'forfait', NULL,      3),
(2, '2',   NULL, 2, 1, 'rubrique',  'Logistique',                         NULL,      143000.00, 4),
(2, '2.1', '2',  2, 2, 'imputable', 'Location de salle',                  'jour',    NULL,      5),
(2, '2.2', '2',  2, 2, 'imputable', 'Frais d''organisation',              'forfait', NULL,      6),
(2, '3',   NULL, 3, 1, 'rubrique',  'Coûts pédagogiques et techniques',   NULL,      354800.00, 7),
(2, '3.1', '3',  3, 2, 'imputable', 'Facilitatrice',                      'forfait', NULL,      8),
(2, '3.2', '3',  3, 2, 'imputable', 'Assistante-facilitatrice',           'forfait', NULL,      9),
(2, '3.3', '3',  3, 2, 'imputable', 'Podcast',                            'forfait', NULL,      10),
(2, '3.4', '3',  3, 2, 'imputable', 'Fournitures',                        'forfait', NULL,      11),
(2, '4',   NULL, 4, 1, 'rubrique',  'Communication et capitalisation',    NULL,      133000.00, 12),
(2, '4.1', '4',  4, 2, 'imputable', 'Chargé de communication',            'forfait', NULL,      13),
(2, '4.2', '4',  4, 2, 'imputable', 'Session de capitalisation',          'forfait', NULL,      14),
(2, '5',   NULL, 5, 1, 'rubrique',  'Imprévus et frais bancaires',        NULL,       40000.00, 15),
(2, '5.1', '5',  5, 2, 'imputable', 'Imprévus et frais bancaires',        'forfait',  40000.00, 16),
(2, '6',   NULL, 6, 1, 'calculee',  'Frais administratifs, 7 % des coûts directs', NULL, 63756.00, 17);
-- Controle : 240 000 + 143 000 + 354 800 + 133 000 + 40 000 = 910 800 de couts directs,
-- dont 7 % font exactement les 63 756 de frais administratifs, soit 974 556 au total.

-- 8. Plan de comptes en partie double allegee (CDC 4.7) --------------------
INSERT INTO comptes (projet_id, code, libelle, type, compte_bancaire_id) VALUES
(1, 'BQ',   'Banque SOGEBANK - compte 3306006788', 'banque',      1),
(1, 'CA',   'Petite caisse (fonds fixe)',           'caisse',      NULL),
(1, 'TI',   'Tiers - fournisseurs et prestataires', 'tiers',       NULL),
(1, 'DGI',  'Dette envers la DGI (acomptes 2 %)',   'dette_dgi',   NULL),
(1, 'AV',   'Avances à régulariser',                'avances',     NULL),
(1, 'FIN',  'Préfinancement PAIESC',                'financement', NULL),
(1, 'PROD', 'Produits financiers',                  'produit',     NULL),
(2, 'BQ',   'Banque SOGEBANK - compte 3306006788', 'banque',      1),
(2, 'CA',   'Petite caisse (module désactivé par défaut)', 'caisse', NULL),
(2, 'TI',   'Tiers - fournisseurs et prestataires', 'tiers',       NULL),
(2, 'DGI',  'Dette envers la DGI (acomptes 2 %)',   'dette_dgi',   NULL),
(2, 'AV',   'Avances à régulariser',                'avances',     NULL),
(2, 'FIN',  'Financement FOKAL',                    'financement', NULL),
(2, 'PROD', 'Produits financiers',                  'produit',     NULL);
-- Un compte de charge par ligne imputable, dans chaque projet
INSERT INTO comptes (projet_id, code, libelle, type, ligne_id)
SELECT projet_id, CONCAT('CH-', code), CONCAT('Charges - ', libelle), 'charge', id
  FROM lignes_budgetaires WHERE nature = 'imputable' ORDER BY projet_id, ordre;

-- 9. Sources de revenus et versements attendus - montants saisis a la signature ----------
INSERT INTO sources_revenu (id, projet_id, origine, libelle, montant_attendu) VALUES
(1, 1, 'subvention', 'Subvention PAIESC', 5600000.00),
(2, 2, 'subvention', 'Subvention FOKAL, programme REVIV', 974556.00);
-- KesKle : trois tranches contractuelles a 50, 45 et 5 % du total hors reserve.
-- Koule Ki Pale : trois versements dont les montants et declencheurs sont saisis a la signature.
INSERT INTO versements (projet_id, source_revenu_id, numero, taux) VALUES
(1, 1, 1, 50.00), (1, 1, 2, 45.00), (1, 1, 3, 5.00),
(2, 2, 1, NULL),  (2, 2, 2, NULL),  (2, 2, 3, NULL);

-- 10. Trace d'initialisation ---------------------------------------------
INSERT INTO journal_audit (module, action, objet_type, objet_id, detail, utilisateur_id, utilisateur_nom)
VALUES ('noyau', 'initialisation', 'base', 'seed', 'Chargement des donnees initiales (annexes A, F, plan de comptes)', 1, 'Administrateur initial');

-- NOTE : le cadre logique (objectifs, resultats, 19 activites, indicateurs)
-- est charge en phase 5 depuis l'annexe C du contrat, non disponible ici.
