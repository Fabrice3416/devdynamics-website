<?php
declare(strict_types=1);

/**
 * Referentiels fixes issus du cahier des charges (annexes B, D, E, 7.2).
 * Ce qui est ici est une regle du guide ou du CDC, pas un parametre :
 * les valeurs modifiables par le Coordinateur vivent dans la table `parametres`.
 */

const MODULES = [
    // code => [libelle, dependances]
    'noyau'        => ['Noyau',        []],
    'signature'    => ['Signature',    ['noyau']],
    'tiers'        => ['Tiers',        ['noyau', 'budget']],
    'budget'       => ['Budget',       ['noyau']],
    'comptes'      => ['Comptes',      ['noyau', 'signature', 'tiers', 'budget']],
    'activites'    => ['Activités',    ['noyau', 'tiers', 'budget']],
    'depenses'     => ['Dépenses',     ['noyau', 'signature', 'tiers', 'budget', 'comptes']],
    'remuneration' => ['Rémunération', ['noyau', 'tiers', 'budget', 'depenses']],
    'restitution'  => ['Restitution',  ['noyau', 'budget', 'comptes', 'activites', 'depenses']],
    'financement'  => ['Financement',  ['noyau', 'signature', 'comptes', 'restitution']],
];

/** Les sept modules entierement cloisonnes par projet (CDC 1.4). */
const MODULES_CLOISONNES = ['budget', 'comptes', 'depenses', 'remuneration', 'activites', 'restitution', 'financement'];

const ROLES_LIBELLES = [
    'coordinateur' => 'Coordinateur',
    'raf'          => 'Responsable Administratif et Financier',
    'mandataire'   => 'Mandataire',
];

/**
 * L'administrateur de l'outil n'est pas un role de projet : il cree les projets,
 * y designe les coordinateurs et n'y saisit rien. La matrice de l'annexe B ne le
 * mentionne pas, ses droits etant exterieurs a tout projet.
 */
const ADMIN_OUTIL_LIBELLE = 'Administrateur de l\'outil';

/** Qualite au titre de laquelle une apposition est faite (CDC 1.8). */
const QUALITES_SIGNATURE = [
    'approbation' => 'Signature d\'approbation interne',
    'reglement'   => 'Signature de règlement (mandataire)',
];

const UNITES = ['mois' => 'mois', 'jour' => 'jour', 'unite' => 'unité', 'personne' => 'personne', 'forfait' => 'forfait'];

/** Les quatre qualites que couvre la table unique des tiers (CDC 8.2). */
const TYPES_TIERS = [
    'personne'       => 'Personne',
    'fournisseur'    => 'Fournisseur',
    'organisation'   => 'Organisation',
    'administration' => 'Administration',
];

/** Avancement d'une organisation beneficiaire, de son identification a son suivi. */
const STATUTS_AVANCEMENT = [
    'identifiee' => 'Identifiée', 'confirmee' => 'Confirmée', 'formee' => 'Formée',
    'equipee'    => 'Équipée',    'active'    => 'Active',    'inactive' => 'Inactive',
];

/** Le modele de rapport d'activites exige les beneficiaires ventiles par sexe et par jeunesse (CDC 3.2). */
const SEXES = ['F' => 'Féminin', 'M' => 'Masculin', 'autre' => 'Autre'];
const TRANCHES_AGE = [
    'moins_18' => 'Moins de 18 ans', '18_24' => '18 à 24 ans', '25_35' => '25 à 35 ans',
    '36_50'    => '36 à 50 ans',     'plus_50' => 'Plus de 50 ans',
];

/** Le contrat couvre aussi la convention d'un partenaire non remunere (CDC 3.4). */
const TYPES_CONTRAT = [
    'service'               => 'Contrat de service',
    'travail'               => 'Contrat de travail',
    'convention_partenariat' => 'Convention de partenariat',
];

/**
 * Annexe D - listes de pieces par type de dossier.
 * moment : 'avant' = exigee avant le reglement ; 'apres' = attendue apres.
 * condition : 'seuil_proformas' = exigee seulement au-dessus du seuil parametre.
 */
const TYPES_DOSSIER = [
    'achat_bien' => [
        'libelle' => 'Achat de bien',
        'actif'   => true,
        'pieces'  => [
            ['fiche_imputation',   'Fiche d\'imputation budgétaire', 'avant', null],
            ['proforma',           'Proforma',                        'avant', 'seuil_proformas'],
            ['bon_commande',       'Bon de commande',                 'avant', null],
            ['facture',            'Facture',                         'avant', null],
            ['bon_decaissement',   'Bon de décaissement',             'avant', null],
            ['recu_beneficiaire',  'Reçu signé du fournisseur',       'avant', null],
            ['bon_reception',      'Bon de réception',                'avant', null],
            ['preuve_paiement',    'Preuve de paiement',              'apres', null],
        ],
    ],
    'service_compagnie' => [
        'libelle' => 'Service, compagnie formelle',
        'actif'   => true,
        'pieces'  => [
            ['fiche_imputation',   'Fiche d\'imputation budgétaire',            'avant', null],
            ['proforma',           'Proformas (deux ou trois)',                  'avant', 'seuil_proformas'],
            ['bon_commande',       'Bon de commande',                            'avant', null],
            ['contrat_services',   'Contrat de services avec patente à jour',    'avant', null],
            ['facture',            'Facture',                                    'avant', null],
            ['bon_decaissement',   'Bon de décaissement',                        'avant', null],
            ['recu_beneficiaire',  'Reçu signé',                                 'avant', null],
            ['preuve_paiement',    'Preuve de paiement',                         'apres', null],
        ],
    ],
    'service_particulier' => [
        'libelle' => 'Service, particulier',
        'actif'   => true,
        'pieces'  => [
            ['fiche_imputation',       'Fiche d\'imputation budgétaire', 'avant', null],
            ['contrat_service',        'Contrat de service',             'avant', null],
            ['piece_identite',         'Pièce d\'identité',              'avant', null],
            ['facture_prestataire',    'Facture du prestataire',         'avant', null],
            ['rapport_execution',      'Rapport d\'exécution',           'avant', null],
            ['certificat_acceptation', 'Certificat d\'acceptation',      'avant', null],
            ['bon_decaissement',       'Bon de décaissement',            'avant', null],
            ['recu_beneficiaire',      'Reçu signé',                     'avant', null],
            ['preuve_paiement',        'Preuve de paiement',             'apres', null],
            ['recu_dgi',               'Reçu de la DGI',                 'apres', null],
        ],
    ],
    'frais_voyage' => [
        'libelle' => 'Frais de voyage et per diem',
        'actif'   => true,
        'pieces'  => [
            ['fiche_imputation',  'Fiche d\'imputation budgétaire', 'avant', null],
            ['ordre_mission',     'Ordre de mission',               'avant', null],
            ['fiche_calcul',      'Fiche de calcul des frais',      'avant', null],
            ['piece_identite',    'Pièce d\'identité',              'avant', null],
            ['bon_decaissement',  'Bon de décaissement',            'avant', null],
            ['recu_beneficiaire', 'Reçu',                           'avant', null],
            ['preuve_paiement',   'Preuve de paiement',             'apres', null],
        ],
    ],
    'versement_dgi' => [
        'libelle' => 'Versement de taxes à la DGI',
        'actif'   => true,
        'pieces'  => [
            ['fiche_imputation_memoire', 'Fiche d\'imputation pour mémoire',          'avant', null],
            ['bordereau_decaissement',   'Bordereau de décaissement',                 'avant', null],
            ['ordre_paiement_dgi',       'Ordre de paiement de la DGI',               'avant', null],
            ['etat_recap_acomptes',      'État récapitulatif des acomptes retenus',   'avant', null],
            ['recu_scelle_dgi',          'Reçu scellé de la DGI',                     'apres', null],
        ],
    ],
    'remboursement_frais' => [
        'libelle' => 'Remboursement de frais avancés',
        'actif'   => true,
        'pieces'  => [
            ['fiche_imputation',        'Fiche d\'imputation budgétaire', 'avant', null],
            ['justificatif_fournisseur','Justificatif du fournisseur',    'avant', null],
            ['preuve_debit',            'Preuve du débit',                'avant', null],
            ['releve_taux',             'Relevé portant le taux',         'avant', null],
            ['note_remboursement',      'Note de remboursement',          'avant', null],
            ['bon_decaissement',        'Bon de décaissement',            'avant', null],
            ['recu_beneficiaire',       'Reçu signé',                     'avant', null],
            ['preuve_paiement',         'Preuve de virement',             'apres', null],
        ],
    ],
    'petite_caisse' => [
        'libelle' => 'Dépense de petite caisse',
        'actif'   => true,
        'pieces'  => [
            ['fiche_imputation',   'Fiche d\'imputation budgétaire', 'avant', null],
            ['justificatif_achat', 'Justificatif d\'achat',          'avant', null],
            ['recu_beneficiaire',  'Reçu',                           'avant', null],
            ['mention_journal',    'Mention au journal de caisse',   'avant', null],
        ],
    ],
    'contrat_travail' => [
        'libelle' => 'Contrat de travail (désactivé : tous les intervenants sont sous contrat de service)',
        'actif'   => false,
        'pieces'  => [],
    ],
];

/** Annexe E - catalogue des documents generes : code => [libelle, signataires, regime]. */
const DOCUMENTS_GENERES = [
    'fiche_imputation'      => ['Fiche d\'imputation budgétaire',        ['raf', 'coordinateur'],        'mixte'],
    'bon_commande'          => ['Bon de commande',                        ['coordinateur'],               'mixte'],
    'bon_decaissement'      => ['Bon de décaissement',                    ['raf', 'coordinateur'],        'mixte'],
    'recu_beneficiaire'     => ['Reçu du bénéficiaire',                   ['beneficiaire'],               'papier_scanne'],
    'bon_reception'         => ['Bon de réception',                       ['raf'],                        'mixte'],
    'ordre_mission'         => ['Ordre de mission',                       ['coordinateur'],               'mixte'],
    'fiche_calcul'          => ['Fiche de calcul des frais',              ['raf'],                        'mixte'],
    'facture_prestataire'   => ['Facture de prestataire',                 ['prestataire'],                'papier_scanne'],
    'certificat_acceptation'=> ['Certificat d\'acceptation',              ['coordinateur|assemblee'],     'mixte'],
    'etat_recap_acomptes'   => ['État récapitulatif des acomptes',        ['raf'],                        'mixte'],
    'journal_caisse'        => ['Journal de caisse et arrêté',            ['raf'],                        'mixte'],
    'rapprochement'         => ['Rapprochement bancaire',                 ['raf', 'coordinateur'],        'mixte'],
    'demande_paiement'      => ['Demande de paiement de tranche',         ['representant_legal'],         'mixte'],
    'rapport_mensuel'       => ['Rapport mensuel',                        ['coordinateur'],               'mixte'],
    'rapport_narratif'      => ['Rapport narratif, modèle Annexe 4',      ['representant_legal'],         'mixte'],
    'rapport_financier'     => ['Rapport financier, modèle Annexe G',     ['representant_legal'],         'mixte'],
    'ventilation'           => ['Ventilation détaillée des dépenses',     ['raf'],                        'mixte'],
    'liasse'                => ['Liasse de dossier / de période',         [],                             'generation'],
];

/** Tranches de prefinancement (contrat art. 4.1) : numero => taux sur le total hors reserve. */
const TRANCHES = [1 => 50.00, 2 => 45.00, 3 => 5.00];

/**
 * Pieces d'une demande de versement (CDC 4.10).
 *
 * « La troisieme etape n'existe pas pour la premiere tranche, qui ne demande que
 * le contrat signe, la demande de paiement, la fiche signaletique validee par la
 * banque et la photocopie des pieces d'identite des signataires. » Les tranches
 * suivantes y ajoutent les rapports figes de la periode.
 */
const PIECES_DEMANDE = [
    'premiere' => [
        ['contrat_signe',      'Contrat de subvention signé'],
        ['demande_paiement',   'Demande de paiement au modèle de l\'UGP'],
        ['fiche_signaletique', 'Fiche signalétique validée par la banque'],
        ['identite_signataires', 'Photocopie des pièces d\'identité des signataires'],
    ],
    'suivante' => [
        ['demande_paiement',   'Demande de paiement au modèle de l\'UGP'],
        ['rapport_narratif',   'Rapport narratif figé, modèle Annexe 4'],
        ['rapport_financier',  'Rapport financier figé, modèle Annexe G'],
        ['rapprochement',      'Rapprochement bancaire de la période'],
        ['ventilation',        'Ventilation détaillée des dépenses'],
    ],
];

/** Impression papier : mentions d'exemplaire (CDC 1.8). */
const EXEMPLAIRES = ['Exemplaire 1 - Organisation', 'Exemplaire 2 - Bailleur', 'Exemplaire 3 - Bailleur'];

function type_dossier_libelle(string $code): string
{
    return TYPES_DOSSIER[$code]['libelle'] ?? $code;
}

/**
 * Annexe F - registre des parametres modifiables par le Coordinateur.
 * type : date | int | decimal | texte | choix ; 'avant_ecriture' = modifiable tant qu'aucune ecriture n'existe.
 */
const PARAMETRES_REGISTRE = [
    // cle => [libelle, type, options, modifiable]
    // modifiable : true | false | 'avant_ecriture' | 'admin_outil'
    'numero_contrat'                => ['Numéro de la convention de subvention',      'texte',   null, true],
    'date_debut_execution'          => ['Date d\'ancrage du calendrier',              'date',    null, 'avant_ecriture'],
    'duree_execution_mois'          => ['Durée d\'exécution (mois)',                  'int',     null, 'avant_ecriture'],
    'plafond_contractuel'           => ['Plafond contractuel du projet (HTG)',        'decimal', null, true],
    'suivi_post_cloture'            => ['Phase de suivi post-clôture',                'choix',   ['0' => 'Désactivée', '1' => 'Activée'], true],
    'seconde_borne'                 => ['Seconde borne de la phase de suivi',         'date',    null, true],
    'duree_regularisation_jours'    => ['Durée de la période de régularisation (jours)', 'int',  null, true],
    'plafond_petite_caisse'         => ['Plafond de la petite caisse (HTG)',          'decimal', null, true],
    'plafond_depense_especes'       => ['Plafond de dépense en espèces (HTG)',        'decimal', null, true],
    'seuil_proformas'               => ['Seuil déclenchant trois proformas',          'decimal', null, true],
    'seuil_concurrence_devise'      => ['Devise du seuil de mise en concurrence',     'choix',   ['HTG' => 'Gourde', 'EUR' => 'Euro', 'USD' => 'Dollar'], true],
    'seuil_concurrence_perimetre'   => ['Périmètre de la mise en concurrence',        'choix',   ['tout_achat' => 'Tous achats', 'equipements_materiels' => 'Équipements et matériels'], true],
    'seuil_alerte_variation_pct'    => ['Seuil d\'alerte de variation (%)',           'int',     null, true],
    'seuil_blocage_variation_pct'   => ['Seuil de blocage de variation (%)',          'int',     null, false],
    'granularite_variation'         => ['Granularité du contrôle de variation',       'choix',   ['rubrique' => 'Rubrique principale', 'ligne' => 'Ligne budgétaire'], true],
    'regime_provision'              => ['Régime de la provision pour imprévus',       'choix',   ['ligne_dediee' => 'Ligne dédiée, mobilisable', 'ligne_mixte' => 'Ligne mixte, frais bancaires seuls', 'aucune' => 'Aucune provision'], true],
    'ligne_salle_code'              => ['Code de la ligne finançant la salle de formation', 'texte', null, true],
    'ligne_couverts_code'           => ['Code de la ligne finançant la restauration',  'texte',   null, true],
    'ligne_provision_code'          => ['Code de la ligne portant la provision',      'texte',   null, true],
    'ligne_couts_indirects_code'    => ['Code de la ligne des coûts indirects',       'texte',   null, true],
    'taux_acompte_defaut_pct'       => ['Taux d\'acompte par défaut (%)',             'decimal', null, true],
    'avances_honoraires'            => ['Avances sur honoraires',                     'choix',   ['0' => 'Interdites', '1' => 'Autorisées, rémunérations non récurrentes'], true],
    'mode_reglement_defaut'         => ['Mode de règlement par défaut',               'choix',   ['virement' => 'Virement', 'cheque' => 'Chèque'], true],
    'ecart_recu_reglement_jours'    => ['Écart toléré entre reçu et règlement (jours)', 'int',   null, true],
    'regime_signature_defaut'       => ['Régime de signature par défaut',             'choix',   ['papier' => 'Papier', 'electronique' => 'Électronique'], true],
    'exemplaires_mention'           => ['Mentions d\'exemplaire, séparées par |',     'texte',   null, true],
    'delai_accuse_phase2_heures'    => ['Délai d\'accusé de réception en phase 2 (h ouvrables)', 'int', null, true],
    'delai_correctif_phase2_jours'  => ['Délai de correctif non critique en phase 2 (jours)', 'int', null, true],
    'delai_alerte_sauvegarde_jours' => ['Délai d\'alerte d\'absence de sauvegarde (jours)', 'int', null, true],
    'enveloppe_indirecte_figee'     => ['Enveloppe indirecte figée à la bascule (HTG)', 'decimal', null, false],
    'mention_bailleur'              => ['Mention longue du bailleur sur les documents', 'texte', null, true],
    'representant_legal'            => ['Représentant légal (nom et fonction)',       'texte',   null, true],
];

/**
 * Valeurs initiales du registre de parametres d'un projet nouvellement cree (annexe F).
 * Chaque projet en service porte ensuite les siennes, historisees.
 */
const PARAMETRES_INITIAUX = [
    'numero_contrat'                => null,
    'date_debut_execution'          => null,
    'duree_execution_mois'          => '8',
    'plafond_contractuel'           => null,
    'suivi_post_cloture'            => '0',
    'seconde_borne'                 => null,
    'duree_regularisation_jours'    => '30',
    'plafond_petite_caisse'         => null,
    'plafond_depense_especes'       => null,
    'seuil_proformas'               => null,
    'seuil_concurrence_devise'      => 'HTG',
    'seuil_concurrence_perimetre'   => 'tout_achat',
    'seuil_alerte_variation_pct'    => '20',
    'seuil_blocage_variation_pct'   => '25',
    'granularite_variation'         => 'rubrique',
    'regime_provision'              => 'ligne_dediee',
    'ligne_salle_code'              => null,
    'ligne_couverts_code'           => null,
    'ligne_provision_code'          => null,
    'ligne_couts_indirects_code'    => null,
    'taux_acompte_defaut_pct'       => '2',
    'avances_honoraires'            => '0',
    'mode_reglement_defaut'         => 'virement',
    'ecart_recu_reglement_jours'    => '0',
    'regime_signature_defaut'       => 'papier',
    'exemplaires_mention'           => 'Organisation|Bailleur|Bailleur',
    'delai_accuse_phase2_heures'    => '48',
    'delai_correctif_phase2_jours'  => '15',
    'delai_alerte_sauvegarde_jours' => null,
    'enveloppe_indirecte_figee'     => null,
    'mention_bailleur'              => null,
    'representant_legal'            => null,
];

/** Plan de comptes de base pose a la creation d'un projet (CDC 4.8, six familles). */
const COMPTES_INITIAUX = [
    ['BQ',   'Banque',                              'banque'],
    ['CA',   'Petite caisse',                       'caisse'],
    ['TI',   'Tiers - fournisseurs et prestataires', 'tiers'],
    ['DGI',  'Dette envers la DGI (acomptes)',      'dette_dgi'],
    ['AV',   'Avances à régulariser',               'avances'],
    ['FIN',  'Financement',                         'financement'],
    ['PROD', 'Produits financiers',                 'produit'],
];
