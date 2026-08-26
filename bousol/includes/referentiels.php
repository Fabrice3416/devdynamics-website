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

const ROLES_LIBELLES = [
    'coordinateur' => 'Coordinateur',
    'raf'          => 'Responsable Administratif et Financier',
    'mandataire'   => 'Mandataire',
];

/** Qualite au titre de laquelle une apposition est faite (CDC 1.8). */
const QUALITES_SIGNATURE = [
    'approbation' => 'Signature d\'approbation interne',
    'reglement'   => 'Signature de règlement (mandataire)',
];

const UNITES = ['mois' => 'mois', 'jour' => 'jour', 'unite' => 'unité', 'personne' => 'personne', 'forfait' => 'forfait'];

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
    'numero_contrat'                => ['Numéro du contrat de subvention',            'texte',   null, true],
    'date_debut_execution'          => ['Date de début d\'exécution',                 'date',    null, 'avant_ecriture'],
    'duree_execution_mois'          => ['Durée d\'exécution (mois)',                  'int',     null, 'avant_ecriture'],
    'duree_regularisation_jours'    => ['Durée de la période de régularisation (jours)', 'int',  null, true],
    'seconde_borne'                 => ['Seconde borne (fin du programme PAIESC)',    'date',    null, true],
    'plafond_petite_caisse'         => ['Plafond de la petite caisse (HTG)',          'decimal', null, true],
    'plafond_depense_especes'       => ['Plafond de dépense en espèces (HTG)',        'decimal', null, true],
    'seuil_proformas'               => ['Seuil déclenchant trois proformas (HTG)',    'decimal', null, true],
    'seuil_alerte_variation_pct'    => ['Seuil d\'alerte de variation par rubrique (%)', 'int',  null, true],
    'seuil_blocage_variation_pct'   => ['Seuil de blocage de variation par rubrique (%)', 'int', null, false],
    'taux_acompte_defaut_pct'       => ['Taux d\'acompte par défaut (%)',             'decimal', null, true],
    'mode_reglement_defaut'         => ['Mode de règlement par défaut',               'choix',   ['virement' => 'Virement', 'cheque' => 'Chèque'], true],
    'ecart_recu_reglement_jours'    => ['Écart toléré entre reçu et règlement (jours)', 'int',   null, true],
    'delai_accuse_phase2_heures'    => ['Délai d\'accusé de réception en phase 2 (heures ouvrables)', 'int', null, true],
    'delai_correctif_phase2_jours'  => ['Délai de correctif non critique en phase 2 (jours)', 'int', null, true],
    'delai_alerte_sauvegarde_jours' => ['Délai d\'alerte d\'absence de sauvegarde (jours)', 'int', null, true],
    'regime_signature_defaut'       => ['Régime de signature par défaut',             'choix',   ['papier' => 'Papier', 'electronique' => 'Électronique'], true],
    'signature_par_lot'             => ['Signature par lot',                          'choix',   ['0' => 'Désactivée', '1' => 'Activée'], true],
    'montant_contractuel'           => ['Montant contractuel (HTG, art. 3.1)',        'decimal', null, true],
    'compte_bancaire'               => ['Compte bancaire du projet',                  'texte',   null, true],
    'nom_projet'                    => ['Intitulé du projet',                          'texte',   null, true],
    'nom_organisation'              => ['Nom de l\'organisation',                     'texte',   null, true],
    'representant_legal'            => ['Représentant légal (nom et fonction)',       'texte',   null, true],
];
