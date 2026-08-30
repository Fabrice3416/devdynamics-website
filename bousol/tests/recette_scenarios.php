<?php
declare(strict_types=1);

/**
 * Les trois essais de bout en bout de l'annexe G.
 *
 * Les neuf recettes de phase eprouvent une regle a la fois. Trois cas de l'annexe G
 * n'entrent dans aucune d'elles parce qu'ils demandent une sequence : parcourir une
 * matrice entiere, modifier un seuil et regarder ce que deviennent les dossiers
 * anterieurs, signer puis modifier puis signer de nouveau. Ils sont ici.
 *
 *   Droits      Parcourir la matrice de l'annexe B, role par role et phase par phase
 *               → chaque autorisation et chaque refus sont conformes
 *   Parametres  Modifier un seuil en cours de projet
 *               → les dossiers anterieurs restent inchanges, les suivants suivent la nouvelle valeur
 *   Signature   Signer un document, le modifier, le signer de nouveau
 *               → deux versions, deux appositions, deux codes de verification
 *
 *   BOUSOL_RECETTE=oui php bousol/tests/recette_scenarios.php
 *
 * La recette deplace la phase du projet pour eprouver la colonne « Phase 2 » du
 * tableau, et la rend a l'execution avant de finir. Le nettoyage commun la rend a
 * l'execution de toute facon, meme si elle s'interrompt.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bascule.php';
require_once __DIR__ . '/../includes/signature.php';
require_once __DIR__ . '/_garde.php';

recette_garde('Recette des scenarios - annexe G, essais de bout en bout');
$pdo = db();
$ok = 0; $ko = 0;

function cas(string $lib, bool $reussi, string $detail = ''): void
{
    global $ok, $ko;
    $reussi ? $ok++ : $ko++;
    echo ($reussi ? '  OK  ' : ' ECHEC') . ' ' . $lib . ($detail !== '' ? '  [' . mb_substr($detail, 0, 95) . ']' : '') . PHP_EOL;
}

function refuse_avec(string $lib, array $resultat, string $attendu = ''): void
{
    $refuse = empty($resultat['success']);
    cas($lib, $refuse && ($attendu === '' || str_contains(mb_strtolower($resultat['error'] ?? ''), mb_strtolower($attendu))),
        $resultat['error'] ?? 'accepte');
}

/** Session simulee. Un mandataire porte sa qualite ; les deux autres roles ne l'ont pas. */
function endosser(string $role): void
{
    $_SESSION['role_projet']    = $role;
    $_SESSION['est_mandataire'] = $role === 'mandataire';
    param_oublier();
}

$_SESSION['user_id'] = 1; $_SESSION['user_nom'] = 'Recette'; $_SESSION['tiers_id'] = 1;
$_SESSION['admin_outil'] = true; $_SESSION['projet_id'] = 1;
$_SESSION['projet_code'] = 'KESKLE'; $_SESSION['role_projet'] = 'coordinateur';
$_SESSION['est_mandataire'] = false;
param_oublier();
recette_nettoyer($pdo);

// ---------------------------------------------------------------------
// Mise en place
// ---------------------------------------------------------------------

echo "== Mise en place\n";
if (date_debut() === null) {
    param_set('date_debut_execution', '2026-01-01', 'RECB ancrage posé par la recette des scénarios');
    param_oublier();
    generer_periodes();
}
cas('Le calendrier relatif est ancre', date_debut() !== null, (string)date_debut());
cas('Le projet est en execution', phase_code() === 'projet_actif', (string)phase_code());

// Un document du catalogue dont le seul signataire attendu est le RAF : il sert a
// eprouver la ligne « Signer en qualite de preparateur ». Le controle de qualite
// precede celui du fichier rendu, la sonde n'a donc pas besoin d'un PDF.
$docPreparateur = (int)etape('Un document attendant la seule signature du RAF est posé',
    fn() => creer_document('bon_reception', 'depenses', 'dossier', 0, null, 'a_signer', 'electronique'));

// ---------------------------------------------------------------------
// 1. Droits : la matrice de l'annexe B, role par role et phase par phase
// ---------------------------------------------------------------------

echo "\n== Droits : la matrice de l'annexe B\n";

foreach (ANNEXE_B_LECTURES as $cle => $lecture) {
    cas('Lecture assumée sur « ' . ANNEXE_B[$cle][0] . ' », phase ' . $lecture['phase'], true,
        implode(' ', array_map(fn($r, $v) => substr($r, 0, 4) . ':' . $v,
            array_keys($lecture['cellules']), $lecture['cellules'])));
    echo '       ' . wordwrap($lecture['raison'], 96, "\n       ", false) . PHP_EOL;
}

/**
 * Les sondes. Chacune tente l'acte reellement, avec des arguments qui ne peuvent
 * rien creer : ce qui est mesure est le refus, pas l'effet. Un acte permis echoue
 * alors sur l'objet introuvable ou sur l'argument vide, et ce refus-la ne porte pas
 * la marque « (annexe B) ».
 *
 * « Parametrer » n'a pas de fonction de bibliotheque : param_set() est
 * l'historiseur, appele aussi par la bascule pour figer l'enveloppe indirecte.
 * L'acte est garde par l'ecran, avec l'expression que la sonde appelle ici. Ce que
 * la modification d'un seuil produit vraiment est eprouve au chapitre suivant.
 */
$sondes = [
    'parametrer'        => fn() => ['success' => droit_ecriture('parametrer') === null,
                                    'error'   => droit_ecriture('parametrer') ?? ''],
    'dossier_ouvrir'    => fn() => dossier_ouvrir(['type' => 'achat_bien', 'tiers_id' => 0, 'objet' => '']),
    'imputer'           => fn() => dossier_imputer(0, 0, 1.0, 1.0, 'unite'),
    'dossier_approuver' => fn() => dossier_approuver(0),
    'certificat'        => fn() => rapport_accepter(0),
    'signer_reglement'  => fn() => apposer(0, 'reglement', ''),
    'signer_preparateur'=> fn() => apposer($docPreparateur, 'approbation', ''),
    'televerser_scan'   => fn() => piece_verser(0, []),
    'rapprochement'     => fn() => rapprochement_valider(0),
    'demande_tranche'   => fn() => demande_ouvrir(0),
    'rapport_valider'   => fn() => rapport_valider(0),
    'provision'         => function () {
        $r = budget_controle_reallocation([]);
        $droit = array_values(array_filter($r['refus'], fn($x) => ($x['regle'] ?? '') === 'droit'));
        return ['success' => $droit === [], 'error' => $droit[0]['message'] ?? ''];
    },
    'bascule'           => fn() => basculer(''),
    'reouverture'       => fn() => reouverture_ouvrir('', ''),
    'journal_support'   => fn() => anomalie_declarer([]),
    'correctif'         => fn() => version_application_creer(['numero' => '', 'nature' => '']),
];

/** Un refus de la matrice se reconnait a sa marque ; tout autre refus est un refus d'etat. */
$refuseParLaMatrice = function (array $res): bool {
    return empty($res['success']) && str_contains((string)($res['error'] ?? ''), '(annexe B)');
};

$phases = [
    'phase1' => ['libelle' => 'exécution',        'sql' => "CASE code WHEN 'projet_actif' THEN 'en_cours' ELSE 'a_venir' END"],
    'phase2' => ['libelle' => 'suivi post-clôture', 'sql' => "CASE code WHEN 'post_cloture' THEN 'en_cours' ELSE 'close' END"],
];

foreach ($phases as $phase => $def) {
    $pdo->exec("UPDATE phases SET statut = " . $def['sql'] . " WHERE projet_id = 1");
    cas('La phase du projet est « ' . $def['libelle'] . ' »', phase_matrice() === $phase, (string)phase_code());

    foreach (ANNEXE_B as $action => $ligne) {
        $attendu = [];
        $constate = [];
        $conforme = true;

        foreach (array_keys(ROLES_LIBELLES) as $role) {
            endosser($role);
            $cellule = droit($action, $role, $phase);
            $attendu[] = substr($role, 0, 4) . ' ' . $cellule;

            if ($action === 'journal_audit') {
                // Ligne en lecture seule pour les trois roles : ce qui se verifie est
                // que l'ecran s'ouvre, et non qu'un acte soit refuse.
                ob_start();
                try {
                    $_SERVER['REQUEST_METHOD'] = 'GET';
                    require __DIR__ . '/../modules/noyau/audit.php';
                    $html = (string)ob_get_clean();
                    $lu = str_contains($html, '</html>');
                } catch (Throwable $e) {
                    ob_end_clean();
                    $lu = false;
                }
                $constate[] = substr($role, 0, 4) . ' ' . ($lu ? 'L' : '?');
                $conforme = $conforme && $lu && $cellule === 'L';
                continue;
            }

            $res = ($sondes[$action])();
            $permis = !$refuseParLaMatrice($res);
            $constate[] = substr($role, 0, 4) . ' ' . ($permis ? 'E' : '-');
            $conforme = $conforme && ($permis === ($cellule === 'E'));
        }

        cas('« ' . $ligne[0] . ' »', $conforme,
            $conforme ? implode(' · ', $attendu) : 'attendu ' . implode(' ', $attendu) . ' / constaté ' . implode(' ', $constate));
    }
}

// La phase 2 a ete traversee : on rend le projet a son execution avant la suite.
$pdo->exec("UPDATE phases SET statut = " . $phases['phase1']['sql'] . " WHERE projet_id = 1");
cas('Le projet est rendu a son execution', phase_code() === 'projet_actif', (string)phase_code());

echo "\n== Droits : ce que la qualite de mandataire change\n";
endosser('coordinateur');
refuse_avec('Le Coordinateur qui n\'est pas mandataire ne signe pas un reglement',
    apposer(0, 'reglement', ''), 'mandataires du compte');
$_SESSION['est_mandataire'] = true;
$res = apposer(0, 'reglement', '');
cas('Le Coordinateur qui est mandataire y est admis',
    !str_contains((string)($res['error'] ?? ''), 'mandataires du compte'), (string)($res['error'] ?? ''));
$_SESSION['est_mandataire'] = false;

// ---------------------------------------------------------------------
// 2. Parametres : modifier un seuil en cours de projet
// ---------------------------------------------------------------------

echo "\n== Parametres : un seuil modifie en cours de projet\n";

// Le registre est en ajout seul : la recette ne peut pas effacer ses versions, et
// n'essaie pas. Elle retient ce qui etait en vigueur, compte des ecarts plutot que
// des totaux, et rend la valeur d'avant en en versant une nouvelle - ce que ferait
// le Coordinateur.
endosser('coordinateur');
$seuilAvant = param('seuil_proformas');
$histAvant  = count(param_historique('seuil_proformas'));
param_set('seuil_proformas', '50000', 'RECB seuil initial');
param_oublier();
cas('Le seuil de mise en concurrence est pose a 50 000', param('seuil_proformas') === '50000',
    (string)param('seuil_proformas'));

endosser('raf');
$avant = dossier_ouvrir(['type' => 'achat_bien', 'tiers_id' => 2,
                         'objet' => 'RECB dossier ouvert sous l\'ancien seuil', 'montant_prevu' => 20000]);
cas('Un dossier de 20 000 s\'ouvre sans mise en concurrence',
    !empty($avant['success']) && empty($avant['concurrence']), $avant['error'] ?? ($avant['numero'] ?? ''));
$dosAvant = (int)($avant['id'] ?? 0);

$pieceProforma = function (int $dossierId) use ($pdo): array {
    $st = $pdo->prepare("SELECT statut, obligatoire FROM pieces WHERE dossier_id = ? AND type = 'proforma'");
    $st->execute([$dossierId]);
    return $st->fetch() ?: ['statut' => '(absente)', 'obligatoire' => 0];
};
$etatAvant = $pieceProforma($dosAvant);
cas('Sa case « proforma » est sans objet et facultative',
    $etatAvant['statut'] === 'sans_objet' && (int)$etatAvant['obligatoire'] === 0,
    $etatAvant['statut'] . ' · obligatoire ' . (int)$etatAvant['obligatoire']);

endosser('coordinateur');
param_set('seuil_proformas', '10000', 'RECB seuil abaissé en cours de projet');
param_oublier();
cas('Le seuil descend a 10 000', param('seuil_proformas') === '10000', (string)param('seuil_proformas'));

$etatApres = $pieceProforma($dosAvant);
cas('Le dossier anterieur n\'a pas bouge',
    $etatApres === $etatAvant, $etatApres['statut'] . ' · obligatoire ' . (int)$etatApres['obligatoire']);

endosser('raf');
$apres = dossier_ouvrir(['type' => 'achat_bien', 'tiers_id' => 2,
                         'objet' => 'RECB dossier ouvert sous le nouveau seuil', 'montant_prevu' => 20000]);
cas('Le dossier suivant, au meme montant, exige la mise en concurrence',
    !empty($apres['success']) && !empty($apres['concurrence']), $apres['error'] ?? ($apres['numero'] ?? ''));
$etatSuivant = $pieceProforma((int)($apres['id'] ?? 0));
cas('Sa case « proforma » est attendue et obligatoire',
    $etatSuivant['statut'] === 'attendue' && (int)$etatSuivant['obligatoire'] === 1,
    $etatSuivant['statut'] . ' · obligatoire ' . (int)$etatSuivant['obligatoire']);

endosser('coordinateur');
$hist = param_historique('seuil_proformas');
cas('Les deux modifications ajoutent deux versions, sans en remplacer aucune',
    count($hist) === $histAvant + 2, count($hist) . ' version(s) pour ' . $histAvant . ' avant');

// « La plus recente en tete » se lit parmi les versions applicables : une version
// datee du futur est enregistree, elle n'est pas en vigueur.
$applicables = array_values(array_filter($hist, fn($h) => $h['date_effet'] <= date('Y-m-d')));
cas('La version en vigueur est la plus recente des versions applicables',
    $applicables !== [] && $applicables[0]['valeur'] === '10000' && param('seuil_proformas') === '10000',
    implode(' ← ', array_slice(array_column($applicables, 'valeur'), 0, 4)));
cas('Chaque version porte son motif et son auteur',
    $applicables !== [] && $applicables[0]['motif'] !== null && $applicables[0]['auteur_id'] !== null,
    (string)($applicables[0]['motif'] ?? ''));

// Une version datee du futur est enregistree mais ne s'applique pas encore : c'est
// la date d'effet qui decide. Elle est datee de loin, pour que la base de test ne
// se reveille pas un matin avec un seuil pose par une recette.
$differe = count(array_filter($hist, fn($h) => $h['valeur'] === '999999'));
param_set('seuil_proformas', '999999', 'RECB valeur à effet différé', date('Y-m-d', strtotime('+5 years')));
param_oublier();
cas('Une version a effet differe ne s\'applique pas encore', param('seuil_proformas') === '10000',
    'en vigueur ' . (string)param('seuil_proformas'));
cas('Elle figure pourtant a l\'historique',
    count(array_filter(param_historique('seuil_proformas'), fn($h) => $h['valeur'] === '999999')) === $differe + 1);

// ---------------------------------------------------------------------
// 3. Signature : signer un document, le modifier, le signer de nouveau
// ---------------------------------------------------------------------

echo "\n== Signature : signer, modifier, signer de nouveau\n";

// Un signataire dedie : la reauthentification exige un mot de passe connu, et on
// ne touche pas a celui d'un compte reel. Il est cree une fois et resservi.
const RECS_EMAIL = 'recette.signataire@bousol.test';
const RECS_MOTDEPASSE = 'RecetteBousol!2026';

// Un JPEG de 120x48, uni : le specimen n'a pas besoin d'etre lisible, il doit
// etre une image valide que mPDF sache estampiller.
const RECS_IMAGE_B64 =
    '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAYEBQYFBAYGBQYHBwYIChAKCgkJChQODwwQFxQYGBcUFhYaHSUfGhsjHBYWICwgIyYnKSopGR8t'
  . 'MC0oMCUoKSj/2wBDAQcHBwoIChMKChMoGhYaKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCj/wAAR'
  . 'CAAwAHgDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEG'
  . 'E1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWG'
  . 'h4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEB'
  . 'AQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYk'
  . 'NOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0'
  . 'tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD6pooooAKKKKACiiigAooooAKKKKACiiigA'
  . 'ooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigD//Z';

$signataire = etape('Le compte signataire de la recette existe', function () use ($pdo) {
    $st = $pdo->prepare('SELECT id, tiers_id FROM utilisateurs WHERE email = ?');
    $st->execute([RECS_EMAIL]);
    $u = $st->fetch();
    if ($u !== false) {
        // Le mot de passe est repose a chaque passage : un changement exterieur ne
        // doit pas faire echouer la recette pour une raison qui n'est pas la sienne.
        $pdo->prepare('UPDATE utilisateurs SET mot_de_passe = ?, actif = 1 WHERE id = ?')
            ->execute([hash_password(RECS_MOTDEPASSE), (int)$u['id']]);
        return ['id' => (int)$u['id'], 'tiers_id' => (int)$u['tiers_id']];
    }
    $pdo->prepare("INSERT INTO tiers (type, nom, fonction) VALUES ('personne', ?, 'Signataire de recette')")
        ->execute(['RECS Signataire']);
    $tiersId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO utilisateurs (tiers_id, email, mot_de_passe, doit_changer_mdp) VALUES (?,?,?,0)')
        ->execute([$tiersId, RECS_EMAIL, hash_password(RECS_MOTDEPASSE)]);
    return ['id' => (int)$pdo->lastInsertId(), 'tiers_id' => $tiersId];
});

if ($signataire === null) {
    echo "  (la suite du chapitre est sautee : sans signataire, rien a apposer)\n";
} else {
    $_SESSION['user_id']  = $signataire['id'];
    $_SESSION['tiers_id'] = $signataire['tiers_id'];
    $_SESSION['user_nom'] = 'RECS Signataire';
    endosser('coordinateur');

    // Un specimen laisse par un passage precedent peut avoir perdu son fichier :
    // storage/ n'est pas dans le depot, et ce serveur a deja vu un deploiement
    // emporter ce qui n'est pas suivi. On le lit avant de s'y fier, et on le
    // redepose s'il ne se lit plus - une recette doit se remettre d'aplomb seule.
    $specExistant = specimen_actif($signataire['id']);
    if ($specExistant !== null) {
        $fimg = fichier((int)$specExistant['image_fichier_id']);
        if ($fimg === null || lire_fichier($fimg) === null) {
            cas('Le specimen laisse par un passage precedent ne se lit plus : il est redepose', true,
                $fimg === null ? 'ligne fichier absente'
                    : (is_file(storage_dir() . '/' . $fimg['chemin'])
                        ? 'fichier présent, déchiffrement impossible'
                        : 'fichier absent de storage/ : ' . $fimg['chemin']));
            revoquer_specimen($signataire['id'], 'RECS spécimen illisible, redéposé par la recette');
        }
    }

    // Le depot passe normalement par deposer_specimen(), qui exige un televersement
    // HTTP : la recette pose les memes lignes directement. Ce qu'elle eprouve est
    // l'apposition, pas le depot, deja couvert ailleurs.
    if (specimen_actif($signataire['id']) === null) {
        etape('Un specimen actif est depose pour le signataire', function () use ($pdo, $signataire) {
            $img  = enregistrer_contenu((string)base64_decode(RECS_IMAGE_B64), 'jpg', 'image/jpeg',
                                        'coffre', 'RECS-specimen.jpg', true);
            $acte = enregistrer_contenu("%PDF-1.4\n% acte de depot de recette\n", 'pdf', 'application/pdf',
                                        'coffre', 'RECS-acte-de-depot.pdf', true);
            if (empty($img['success']) || empty($acte['success'])) {
                throw new RuntimeException($img['error'] ?? $acte['error'] ?? 'enregistrement impossible');
            }
            $pdo->prepare('INSERT INTO specimens (titulaire_id, image_fichier_id, acte_depot_fichier_id, date_depot)
                           VALUES (?,?,?,CURDATE())')
                ->execute([$signataire['id'], (int)$img['id'], (int)$acte['id']]);
            return (int)$pdo->lastInsertId();
        });
    }
    $specActif = specimen_actif($signataire['id']);
    $imageSpecimen = $specActif === null ? null : lire_fichier((array)fichier((int)$specActif['image_fichier_id']));
    cas('Le signataire a un specimen actif dont l\'image se lit',
        $imageSpecimen !== null && $imageSpecimen !== '',
        $imageSpecimen === null ? 'image illisible' : strlen($imageSpecimen) . ' octets déchiffrés');

    // Regime electronique : c'est lui qui met un document dans la file de signature.
    $regimeAvant = (string)param('regime_signature_defaut', 'papier');
    param_set('regime_signature_defaut', 'electronique', 'RECS régime électronique le temps de la recette');
    param_oublier();

    endosser('raf');
    $ligne = budget_ligne('2.1');
    $imp = $ligne === null ? ['success' => false, 'error' => 'ligne 2.1 absente du budget']
                           : dossier_imputer($dosAvant, (int)$ligne['id'], 1.0, 20000.0, 'unite');
    cas('Le dossier est impute, condition pour produire ses pieces',
        !empty($imp['success']), $imp['error'] ?? '');

    // Le bon de reception n'attend qu'une signature, celle du RAF (annexe E) : c'est
    // le document qui laisse voir un cycle complet de signature sans en attendre une
    // seconde d'un autre compte.
    $st = $pdo->prepare("SELECT id FROM pieces WHERE dossier_id = ? AND type = 'bon_reception'");
    $st->execute([$dosAvant]);
    $pieceBon = (int)($st->fetchColumn() ?: 0);

    $v1 = $pieceBon === 0 ? ['success' => false, 'error' => 'pièce bon_reception introuvable']
                          : dossier_generer_piece($pieceBon);
    cas('Le bon de reception est produit en version 1',
        !empty($v1['success']) && (int)($v1['version'] ?? 0) === 1,
        $v1['error'] ?? ('version ' . ($v1['version'] ?? '?') . ' · ' . ($v1['statut'] ?? '')));
    cas('Il entre dans la file de signature', ($v1['statut'] ?? '') === 'a_signer', (string)($v1['statut'] ?? ''));

    if (empty($v1['success'])) {
        echo "  (les appositions sont sautees : sans document rendu, rien a signer)\n";
    } else {
        $doc1 = (int)$v1['document_id'];

        // apposer() ne rend pas la raison technique d'une estampille manquee : elle
        // va au journal. La sonde l'appelle directement pour que la recette la
        // nomme - un « Impossible » sans cause a deja coute un aller-retour.
        // Les deux entrees sont lues avant d'etre passees : un cast en chaine
        // transformerait un null en image vide, et l'estampille rendrait un PDF
        // sans signature en annoncant sa reussite - le silence qu'on traque.
        $pdfRendu = lire_fichier((array)fichier((int)$v1['fichier_id']));
        cas('Le PDF rendu se relit', $pdfRendu !== null && $pdfRendu !== '',
            $pdfRendu === null ? 'illisible' : strlen($pdfRendu) . ' octets');
        if ($pdfRendu !== null && $imageSpecimen !== null) {
            try {
                $estampille = estampiller_pdf($pdfRendu, $imageSpecimen,
                    ['nom' => 'RECS Signataire', 'qualite' => 'Sonde de recette',
                     'horodatage' => date('Y-m-d H:i:s'), 'code' => 'RECE-TTE0-01'], 0);
                cas('L\'estampille se pose sur le PDF rendu', strlen($estampille) > strlen($pdfRendu),
                    strlen($estampille) . ' octets pour ' . strlen($pdfRendu) . ' en entrée');
            } catch (Throwable $e) {
                cas('L\'estampille se pose sur le PDF rendu', false,
                    get_class($e) . ' : ' . $e->getMessage());
            }
        }

        $a1 = apposer($doc1, 'approbation', RECS_MOTDEPASSE);
        cas('La premiere apposition est posee', !empty($a1['success']),
            $a1['error'] ?? ('code ' . ($a1['code'] ?? '')));
        cas('Le document est des lors signe', ($a1['statut'] ?? '') === 'signe', (string)($a1['statut'] ?? ''));

        refuse_avec('Une version signee ne se resigne pas',
            apposer($doc1, 'approbation', RECS_MOTDEPASSE), 'attente de signature');

        // « Le document est fige apres apposition, toute modification imposant une
        // nouvelle version et une nouvelle signature » (CDC 1.8).
        $v2 = dossier_generer_piece($pieceBon);
        cas('Le document modifie devient la version 2',
            !empty($v2['success']) && (int)($v2['version'] ?? 0) === 2,
            $v2['error'] ?? ('version ' . ($v2['version'] ?? '?')));

        $st = $pdo->prepare('SELECT statut FROM documents WHERE id = ?');
        $st->execute([$doc1]);
        cas('La version signee passe a « remplace » sans etre detruite',
            (string)$st->fetchColumn() === 'remplace');

        $doc2 = (int)($v2['document_id'] ?? 0);
        $a2 = $doc2 === 0 ? ['success' => false, 'error' => 'version 2 absente']
                          : apposer($doc2, 'approbation', RECS_MOTDEPASSE);
        cas('La seconde apposition est posee sur la version 2', !empty($a2['success']),
            $a2['error'] ?? ('code ' . ($a2['code'] ?? '')));

        $st = $pdo->prepare(
            'SELECT d.version, d.statut, a.code_verification, a.empreinte_avant, a.empreinte_apres
               FROM documents d JOIN appositions a ON a.document_id = d.id
              WHERE d.type = ? AND d.objet_type = ? AND d.objet_id = ? ORDER BY d.version'
        );
        $st->execute(['bon_reception', 'dossier', $dosAvant]);
        $lignesSignees = $st->fetchAll();
        cas('Deux versions, deux appositions', count($lignesSignees) === 2,
            count($lignesSignees) . ' apposition(s) · versions ' . implode(', ', array_column($lignesSignees, 'version')));
        $codes = array_unique(array_column($lignesSignees, 'code_verification'));
        cas('Deux codes de verification distincts', count($codes) === 2, implode(' / ', $codes));
        $chainees = true;
        foreach ($lignesSignees as $l) {
            $chainees = $chainees && $l['empreinte_avant'] !== $l['empreinte_apres']
                        && $l['empreinte_avant'] !== null && $l['empreinte_apres'] !== null;
        }
        cas('Chaque apposition porte l\'empreinte du document avant et apres elle', $chainees);

        // « Deux appositions ne peuvent venir ni du meme compte ni de la meme
        // session » (CDC 1.8). La regle du meme compte ne se laisse eprouver que sur
        // un document qui attend deux signatures : a signature unique, le statut
        // ferme le document avant qu'elle ait a jouer.
        $st = $pdo->prepare("SELECT id FROM pieces WHERE dossier_id = ? AND type = 'bon_decaissement'");
        $st->execute([$dosAvant]);
        $pieceDec = (int)($st->fetchColumn() ?: 0);
        $vd = $pieceDec === 0 ? ['success' => false, 'error' => 'pièce bon_decaissement introuvable']
                              : dossier_generer_piece($pieceDec);
        cas('Le bon de decaissement attend deux signatures',
            !empty($vd['success']) && signatures_attendues('bon_decaissement') === 2,
            $vd['error'] ?? (signatures_attendues('bon_decaissement') . ' attendue(s)'));
        if (!empty($vd['success'])) {
            $ad = apposer((int)$vd['document_id'], 'approbation', RECS_MOTDEPASSE);
            cas('Une premiere signature y est posee, le document restant a signer',
                !empty($ad['success']) && ($ad['statut'] ?? '') === 'a_signer',
                $ad['error'] ?? ('statut ' . ($ad['statut'] ?? '')));
            refuse_avec('Le meme compte ne signe pas deux fois le meme document',
                apposer((int)$vd['document_id'], 'approbation', RECS_MOTDEPASSE), 'déjà signé');
        }

        // Le versionnement suit l'apposition, et elle seule : un document jamais
        // signe se reproduit sans devenir une version nouvelle - sans quoi les trois
        // exemplaires d'un meme rapport en feraient trois.
        $st = $pdo->prepare("SELECT id FROM pieces WHERE dossier_id = ? AND type = 'fiche_imputation'");
        $st->execute([$dosAvant]);
        $pieceFiche = (int)($st->fetchColumn() ?: 0);
        if ($pieceFiche > 0) {
            dossier_generer_piece($pieceFiche);
            $f2 = dossier_generer_piece($pieceFiche);
            cas('Un document jamais signe reste a sa version en se reproduisant',
                (int)($f2['version'] ?? 0) === 1, 'version ' . ($f2['version'] ?? $f2['error'] ?? '?'));
        }
    }

    param_set('regime_signature_defaut', $regimeAvant, 'RECS retour au régime précédent');
    param_oublier();
    cas('Le regime de signature est rendu a sa valeur',
        (string)param('regime_signature_defaut', 'papier') === $regimeAvant, $regimeAvant);
}

$_SESSION['user_id'] = 1; $_SESSION['tiers_id'] = 1; $_SESSION['user_nom'] = 'Recette';
endosser('coordinateur');

// ---------------------------------------------------------------------
// 4. Un seul fichier ne peut pas cocher deux cases du meme dossier
// ---------------------------------------------------------------------

echo "\n== Un fichier, une piece\n";

// Le televersement lui-meme ne se rejoue pas en ligne de commande : enregistrer_upload()
// exige is_uploaded_file(), qui n'est vrai que d'une requete HTTP. Ce qui est eprouve
// ici est la decision que piece_verser() prend juste apres avoir enregistre le
// fichier - le meme appel, sur les memes donnees.
endosser('raf');
// Le contenu porte l'horodatage du passage : deux recettes successives ne se
// gênent pas, et les deux fichiers d'un même passage sont bien identiques.
$contenuScan = "%PDF-1.4\n% scan de recette " . date('YmdHis') . "\n";
$scanA = enregistrer_contenu($contenuScan, 'pdf', 'application/pdf', 'scans', 'RECB-scan.pdf');
cas('Un scan est enregistre et rattache a une premiere case', !empty($scanA['success']),
    $scanA['error'] ?? ('fichier #' . ($scanA['id'] ?? '')));

if (!empty($scanA['success']) && $dosAvant > 0) {
    $st = $pdo->prepare("SELECT id, type FROM pieces WHERE dossier_id = ?
                          AND type IN ('facture','bon_decaissement') ORDER BY ordre");
    $st->execute([$dosAvant]);
    $deuxCases = $st->fetchAll();
    cas('Le dossier offre bien deux cases distinctes', count($deuxCases) === 2,
        implode(', ', array_column($deuxCases, 'type')));

    if (count($deuxCases) === 2) {
        $pdo->prepare("UPDATE pieces SET fichier_id = ?, statut = 'recue', date_piece = CURDATE() WHERE id = ?")
            ->execute([(int)$scanA['id'], (int)$deuxCases[0]['id']]);

        // Le meme scan est represente pour la seconde case : c'est le controle que
        // piece_verser() fait juste apres l'enregistrement du fichier televerse.
        $scanB = enregistrer_contenu($contenuScan, 'pdf', 'application/pdf', 'scans', 'RECB-scan-bis.pdf');
        [$deja, $motif] = empreinte_deja_utilisee((string)$scanB['empreinte'], (int)$scanB['id']);
        cas('Satisfaire deux pieces d\'un meme dossier avec un seul fichier est refuse', $deja, $motif);

        [$autre] = empreinte_deja_utilisee(hash('sha256', $contenuScan . ' autre'), (int)$scanB['id']);
        cas('Un scan different reste accepte', !$autre);
    }
}
endosser('coordinateur');

// Un registre en ajout seul ne se nettoie pas, il se corrige : la valeur d'avant
// la recette revient par une version nouvelle, motivee comme les autres.
param_set('seuil_proformas', $seuilAvant, 'RECB retour à la valeur d\'avant la recette');
param_oublier();
cas('Le seuil est rendu a la valeur d\'avant la recette',
    param('seuil_proformas') === $seuilAvant, 'en vigueur ' . (param('seuil_proformas') ?? '(vide)'));

echo "\n$ok OK, $ko ECHEC\n";
exit($ko > 0 ? 1 : 0);
