<?php
declare(strict_types=1);

/**
 * Annexe B - matrice des droits, ecrite une fois.
 *
 * Jusqu'ici chaque regle de la matrice vivait la ou elle s'appliquait : un
 * « user_role() !== 'coordinateur' » dans une fonction, un « $peutOuvrir »
 * dans un ecran, parfois les deux, parfois ni l'un ni l'autre. Rien ne
 * garantissait que l'ensemble redise le tableau, et l'essai de l'annexe G
 * « parcourir la matrice, role par role et phase par phase » n'avait aucun
 * objet a parcourir.
 *
 * Le tableau est donc transcrit ici tel qu'il est imprime, et les gardes le
 * consultent au lieu de le repeter. Une cellule fausse se voit d'un coup d'oeil,
 * et la recette la lit.
 *
 * Trois roles, deux phases, quatre valeurs :
 *   'E'            ecriture
 *   'E:mandataire' ecriture, mais seulement si l'utilisateur porte la qualite de mandataire
 *   'L'            lecture seule
 *   '-'            aucun acces
 *   'X'            sans objet dans cette phase
 *
 * La phase 1 couvre l'execution et la periode de regularisation ; la phase 2 est
 * le suivi post-cloture. Une ligne dont personne n'ecrit en phase 2 est une ligne
 * « Fermee » au sens du tableau.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/calendrier.php';

const ROLES_LIBELLE = [
    'coordinateur' => 'Coordinateur',
    'raf'          => 'Responsable Administratif et Financier',
    'mandataire'   => 'Mandataire',
];

/** cle => [libelle de l'action, cellules de la phase 1, cellules de la phase 2] */
const ANNEXE_B = [
    'parametrer' => ['Paramétrer les bornes et les seuils',
        ['coordinateur' => 'E', 'raf' => '-', 'mandataire' => '-'],
        ['coordinateur' => 'E', 'raf' => '-', 'mandataire' => '-']],

    'dossier_ouvrir' => ['Ouvrir et constituer un dossier de dépense',
        ['coordinateur' => 'L', 'raf' => 'E', 'mandataire' => '-'],
        ['coordinateur' => '-', 'raf' => '-', 'mandataire' => '-']],

    'imputer' => ['Imputer au budget',
        ['coordinateur' => 'L', 'raf' => 'E', 'mandataire' => '-'],
        ['coordinateur' => '-', 'raf' => '-', 'mandataire' => '-']],

    'dossier_approuver' => ['Approuver un dossier',
        ['coordinateur' => 'E', 'raf' => '-', 'mandataire' => '-'],
        ['coordinateur' => '-', 'raf' => '-', 'mandataire' => '-']],

    'certificat' => ['Délivrer un certificat d\'acceptation',
        ['coordinateur' => 'E', 'raf' => '-', 'mandataire' => '-'],
        ['coordinateur' => '-', 'raf' => '-', 'mandataire' => '-']],

    'signer_reglement' => ['Signer un règlement',
        ['coordinateur' => 'E:mandataire', 'raf' => '-', 'mandataire' => 'E'],
        ['coordinateur' => '-', 'raf' => '-', 'mandataire' => '-']],

    'signer_preparateur' => ['Signer en qualité de préparateur',
        ['coordinateur' => '-', 'raf' => 'E', 'mandataire' => '-'],
        ['coordinateur' => '-', 'raf' => '-', 'mandataire' => '-']],

    'televerser_scan' => ['Téléverser un scan',
        ['coordinateur' => 'E', 'raf' => 'E', 'mandataire' => '-'],
        ['coordinateur' => 'E', 'raf' => 'E', 'mandataire' => '-']],

    'rapprochement' => ['Établir le rapprochement bancaire',
        ['coordinateur' => 'L', 'raf' => 'E', 'mandataire' => '-'],
        ['coordinateur' => '-', 'raf' => '-', 'mandataire' => '-']],

    'demande_tranche' => ['Préparer une demande de tranche',
        ['coordinateur' => 'L', 'raf' => 'E', 'mandataire' => '-'],
        ['coordinateur' => '-', 'raf' => '-', 'mandataire' => '-']],

    'rapport_valider' => ['Valider et signer un rapport',
        ['coordinateur' => 'E', 'raf' => '-', 'mandataire' => '-'],
        ['coordinateur' => '-', 'raf' => '-', 'mandataire' => '-']],

    'provision' => ['Mobiliser la provision',
        ['coordinateur' => 'E', 'raf' => '-', 'mandataire' => '-'],
        ['coordinateur' => '-', 'raf' => '-', 'mandataire' => '-']],

    'bascule' => ['Déclencher la bascule',
        ['coordinateur' => 'E', 'raf' => '-', 'mandataire' => '-'],
        ['coordinateur' => 'X', 'raf' => 'X', 'mandataire' => 'X']],

    'reouverture' => ['Autoriser une réouverture',
        ['coordinateur' => 'E', 'raf' => '-', 'mandataire' => '-'],
        ['coordinateur' => 'E', 'raf' => '-', 'mandataire' => '-']],

    'journal_support' => ['Tenir le journal de support',
        ['coordinateur' => 'E', 'raf' => 'E', 'mandataire' => '-'],
        ['coordinateur' => 'E', 'raf' => 'E', 'mandataire' => '-']],

    'correctif' => ['Consigner un correctif',
        ['coordinateur' => 'L', 'raf' => 'L', 'mandataire' => '-'],
        ['coordinateur' => 'E', 'raf' => '-', 'mandataire' => '-']],

    'journal_audit' => ['Consulter le journal d\'audit',
        ['coordinateur' => 'L', 'raf' => 'L', 'mandataire' => 'L'],
        ['coordinateur' => 'L', 'raf' => 'L', 'mandataire' => 'L']],
];

/**
 * Lectures retenues la ou le tableau et le corps du cahier des charges ne disent
 * pas la meme chose. Chaque entree porte les cellules appliquees et la raison de
 * s'ecarter de l'imprime ; la recette les affiche a chaque passage, pour qu'un
 * ecart assume ne se confonde jamais avec un ecart oublie.
 *
 * @var array<string, array{phase: int, cellules: array<string,string>, raison: string}>
 */
const ANNEXE_B_LECTURES = [
    'correctif' => [
        'phase'    => 1,
        'cellules' => ['coordinateur' => 'E', 'raf' => 'E', 'mandataire' => '-'],
        'raison'   => 'Le tableau donne L au Coordinateur et au RAF, mais le 3.6 dit qu\'en phase 1 '
                    . 'les entrées du registre « sont saisies au titre des activités 1.1.1 à 1.3.2 », et le '
                    . 'Lead Développeur qui les saisit ne figure pas dans la matrice. La lecture retenue est '
                    . 'que le L du tableau porte sur l\'acte de support de la phase 2, où le 9.3 réserve '
                    . 'la consignation au Coordinateur seul.',
    ],
];

/** 'phase1' pendant l'execution et la regularisation, 'phase2' apres la bascule. */
function phase_matrice(): string
{
    return phase_code() === 'post_cloture' ? 'phase2' : 'phase1';
}

/**
 * La cellule de la matrice pour une action, un role et une phase.
 *
 * @param string      $action une cle de ANNEXE_B
 * @param string|null $role   coordinateur | raf | mandataire ; le role courant par defaut
 * @param string|null $phase  'phase1' | 'phase2' ; la phase courante par defaut
 * @return string 'E' | 'E:mandataire' | 'L' | '-' | 'X' ; '-' pour une action ou un role inconnus
 */
function droit(string $action, ?string $role = null, ?string $phase = null): string
{
    $ligne = ANNEXE_B[$action] ?? null;
    if ($ligne === null) {
        return '-';
    }
    $role  = $role ?? user_role();
    $phase = $phase ?? phase_matrice();
    $rang  = $phase === 'phase2' ? 2 : 1;

    $lecture = ANNEXE_B_LECTURES[$action] ?? null;
    if ($lecture !== null && $lecture['phase'] === $rang) {
        return $lecture['cellules'][$role] ?? '-';
    }
    return $ligne[$rang][$role] ?? '-';
}

/** Les roles qui ecrivent une action dans une phase, dans l'ordre du tableau. */
function droit_ecrivains(string $action, ?string $phase = null): array
{
    $roles = [];
    foreach (array_keys(ROLES_LIBELLE) as $r) {
        if (str_starts_with(droit($action, $r, $phase), 'E')) {
            $roles[] = $r;
        }
    }
    return $roles;
}

/**
 * Le refus oppose a l'utilisateur courant s'il n'a pas l'ecriture, ou null s'il l'a.
 *
 * Tous les refus de la matrice se terminent par « (annexe B). » : c'est a cette
 * marque que la recette reconnait un refus de droit d'un refus d'etat.
 *
 * @param string      $action une cle de ANNEXE_B
 * @param string|null $acte   libelle a citer a la place de celui du tableau, quand
 *                            l'appelant couvre un acte plus large que la ligne
 */
function droit_ecriture(string $action, ?string $acte = null): ?string
{
    $ligne = ANNEXE_B[$action] ?? null;
    if ($ligne === null) {
        return 'Action hors de la matrice de l\'annexe B.';
    }
    $lib = '« ' . ($acte ?? $ligne[0]) . ' »';
    $cellule = droit($action);

    if ($cellule === 'E') {
        return null;
    }
    if ($cellule === 'E:mandataire') {
        return user_est_mandataire() ? null
            : $lib . ' est réservé aux mandataires du compte (annexe B).';
    }
    if ($cellule === 'X') {
        return $lib . ' est sans objet une fois la bascule prononcée (annexe B).';
    }

    $ecrivains = droit_ecrivains($action);
    if ($ecrivains === []) {
        return $lib . ' est fermé pendant la phase de suivi post-clôture (annexe B).';
    }
    $noms = array_map(fn($r) => ROLES_LIBELLE[$r], $ecrivains);
    return $lib . ' revient au ' . implode(' et au ', $noms) . ' (annexe B).';
}

/** La meme question, en booleen, pour les ecrans qui affichent ou masquent un bouton. */
function peut_ecrire(string $action): bool
{
    return droit_ecriture($action) === null;
}
