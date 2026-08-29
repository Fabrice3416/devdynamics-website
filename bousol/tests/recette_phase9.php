<?php
declare(strict_types=1);

/**
 * Recette de la phase 9 : bascule vers le suivi post-cloture (CDC 1.7 et 9).
 *
 *   BOUSOL_RECETTE=oui php bousol/tests/recette_phase9.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bascule.php';
require_once __DIR__ . '/_garde.php';

recette_garde('Recette de la phase 9 - Bascule');
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

$_SESSION['user_id'] = 1; $_SESSION['user_nom'] = 'Recette'; $_SESSION['tiers_id'] = 1;
$_SESSION['admin_outil'] = true; $_SESSION['projet_id'] = 1;
$_SESSION['projet_code'] = 'KESKLE'; $_SESSION['role_projet'] = 'coordinateur';
$_SESSION['est_mandataire'] = false;
param_oublier();
recette_nettoyer($pdo);

echo "== Double temporalite\n";
cas('La double temporalite est un parametre du projet',
    in_array(param('suivi_post_cloture', '0'), ['0', '1'], true),
    'KESKLE = ' . param('suivi_post_cloture', '0') . ' · KKP = ' . param('suivi_post_cloture', '0', 2));
cas('Koule Ki Pale se clot sans phase de suivi',
    param('suivi_post_cloture', '0', 2) === '0', 'suivi_post_cloture = ' . param('suivi_post_cloture', '0', 2));
cas('Le projet part en execution', phase_code() === 'projet_actif', (string)phase_code());

echo "\n== Checklist de bascule\n";
$checklist = bascule_checklist();
cas('La checklist compte six points bloquants', count($checklist['points']) === 6,
    implode(' · ', array_column($checklist['points'], 'nom')));
foreach ($checklist['points'] as $p) {
    cas('Point « ' . $p['nom'] . ' » rend son motif', $p['motif'] !== '', mb_substr($p['motif'], 0, 66));
}

echo "\n== Sequence : regularisation avant bascule\n";
refuse_avec('On ne bascule pas depuis l\'execution',
    basculer('REC9 tentative directe'), 'suit la période de régularisation');
$_SESSION['role_projet'] = 'raf';
refuse_avec('Le RAF ne declenche pas la bascule',
    regularisation_ouvrir(), 'Coordinateur');
$_SESSION['role_projet'] = 'coordinateur';
$res = regularisation_ouvrir();
cas('La regularisation s\'ouvre', !empty($res['success']),
    ($res['duree'] ?? '') . ' jours · ' . ($res['error'] ?? ''));
cas('La phase courante devient la regularisation', phase_code() === 'regularisation', (string)phase_code());
refuse_avec('La regularisation ne s\'ouvre pas deux fois',
    regularisation_ouvrir(), 'déjà passée');

echo "\n== Ce que la regularisation ferme, et ce qu'elle laisse\n";
$_SESSION['role_projet'] = 'raf';
$avantDossiers = (int)$pdo->query("SELECT COUNT(*) FROM dossiers WHERE projet_id = 1")->fetchColumn();
$ouv = dossier_ouvrir(['type' => 'achat_bien', 'tiers_id' => 2, 'objet' => 'REC9 dossier tardif']);
$apresDossiers = (int)$pdo->query("SELECT COUNT(*) FROM dossiers WHERE projet_id = 1")->fetchColumn();
cas('Aucun dossier nouveau ne s\'ouvre pendant la regularisation',
    $apresDossiers === $avantDossiers, $avantDossiers . ' → ' . $apresDossiers);
cas('Le refus est un arret de page, non un message',
    empty($ouv['success']) || $apresDossiers === $avantDossiers,
    'require_creation_depense arrête la requête');

echo "\n== Bascule\n";
$_SESSION['role_projet'] = 'coordinateur';
refuse_avec('La bascule exige son motif', basculer(''), 'obligatoire');
$checklist = bascule_checklist();
$res = basculer('REC9 fin d\'exécution constatée');
if ($checklist['ok']) {
    cas('La checklist etant complete, la bascule aboutit', !empty($res['success']), $res['error'] ?? '');
} else {
    cas('Une checklist incomplete bloque la bascule', empty($res['success']), $res['error'] ?? 'accepte');
    cas('Le refus nomme les points qui manquent',
        str_contains($res['error'] ?? '', 'bloquante'), mb_substr($res['error'] ?? '', 0, 70));
    // On force la bascule pour eprouver ce qui en decoule.
    $pdo->exec("UPDATE phases SET statut = 'close' WHERE projet_id = 1 AND code = 'regularisation'");
    $pdo->exec("UPDATE phases SET statut = 'en_cours' WHERE projet_id = 1 AND code = 'post_cloture'");
    $solde = solde_cloture();
    param_set('enveloppe_indirecte_figee', number_format($solde['indirects'], 2, '.', ''),
        'Figée par la recette de la phase 9');
    param_oublier();
}
cas('La phase courante devient le suivi post-cloture', phase_code() === 'post_cloture', (string)phase_code());
cas('L\'enveloppe indirecte est figee et historisee',
    param('enveloppe_indirecte_figee') !== null, (string)param('enveloppe_indirecte_figee'));

echo "\n== Ce que le suivi post-cloture laisse ouvert\n";
$an = anomalie_declarer(['description' => 'REC9 signalement en phase de suivi', 'gravite' => 'moyenne',
                         'canal' => 'WhatsApp', 'declarant_id' => 2]);
cas('Le journal de support reste ouvert en ecriture', !empty($an['success']), $an['error'] ?? '');
$va = version_application_creer(['numero' => 'REC9-1.0.2', 'nature' => 'correctif', 'activite_code' => '3.3.2']);
cas('Le registre des correctifs reste ouvert', !empty($va['success']), $va['error'] ?? '');
$enq = enquete_saisir(2, true, 'REC9 usage constaté trois mois après');
cas('Le registre d\'enquete d\'adoption reste ouvert', !empty($enq['success']), $enq['error'] ?? '');

$_SESSION['role_projet'] = 'raf';
$vaRaf = version_application_creer(['numero' => 'REC9-1.0.3', 'nature' => 'correctif']);
cas('En phase 2 le registre est tenu par le Coordinateur',
    empty($vaRaf['success']), $vaRaf['error'] ?? 'accepte');
$_SESSION['role_projet'] = 'coordinateur';

$delai = param('delai_correctif_phase2_jours');
cas('Les deux delais opposables sont parametres',
    $delai !== null && param('delai_accuse_phase2_heures') !== null,
    'accusé ' . param('delai_accuse_phase2_heures', '—') . ' h · correctif ' . ($delai ?? '—') . ' j');
cas('Une anomalie critique n\'a pas de delai de correctif oppose',
    !in_array('critique', array_column(anomalies_sans_correctif(), 'gravite'), true));

echo "\n== Reouverture exceptionnelle\n";
refuse_avec('Une reouverture sans motif est refusee',
    reouverture_ouvrir('', date('Y-m-d', strtotime('+30 days'))), 'obligatoire');
refuse_avec('Une reouverture non bornee dans le futur est refusee',
    reouverture_ouvrir('REC9 correction tardive', date('Y-m-d', strtotime('-1 day'))), 'bornée dans le temps');
$reo = reouverture_ouvrir('REC9 pièce retrouvée après la bascule', date('Y-m-d', strtotime('+30 days')));
cas('La reouverture s\'ouvre, motivee et bornee', !empty($reo['success']), $reo['error'] ?? '');
cas('Elle ne rouvre que l\'etat de regularisation',
    phase_code() === 'regularisation', (string)phase_code());
$_SESSION['role_projet'] = 'raf';
$avant2 = (int)$pdo->query("SELECT COUNT(*) FROM dossiers WHERE projet_id = 1")->fetchColumn();
dossier_ouvrir(['type' => 'achat_bien', 'tiers_id' => 2, 'objet' => 'REC9 dossier apres reouverture']);
cas('Elle ne rouvre jamais la creation de depense',
    (int)$pdo->query("SELECT COUNT(*) FROM dossiers WHERE projet_id = 1")->fetchColumn() === $avant2);
$_SESSION['role_projet'] = 'coordinateur';
refuse_avec('Deux reouvertures ne cohabitent pas',
    reouverture_ouvrir('REC9 seconde', date('Y-m-d', strtotime('+10 days'))), 'déjà en cours');
$res = reouverture_clore((int)$reo['id']);
cas('La reouverture se clot et rend le projet au suivi', !empty($res['success']), $res['error'] ?? '');
cas('Le projet est revenu en suivi post-cloture', phase_code() === 'post_cloture', (string)phase_code());
refuse_avec('Une reouverture close ne se reclot pas',
    reouverture_clore((int)$reo['id']), 'déjà close');

echo "\n== Archive definitive\n";
$_SESSION['role_projet'] = 'raf';
refuse_avec('Le RAF ne produit pas l\'archive', archive_definitive(), 'Coordinateur');
$_SESSION['role_projet'] = 'coordinateur';
$arch = archive_definitive();
cas('L\'index d\'archive se produit', !empty($arch['success']), $arch['error'] ?? '');
$sl = $pdo->query("SELECT COUNT(*) FROM liasses WHERE projet_id = 1 AND type = 'classement'")->fetchColumn();
cas('Elle est enregistree comme liasse de classement', (int)$sl >= 1, $sl . ' liasse(s) de classement');

echo "\n== Rendu de l'ecran\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$rendre = function (string $page): array {
    ob_start();
    try {
        require __DIR__ . '/../' . $page;
        $html = (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        return [false, get_class($e) . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'];
    }
    return [str_contains($html, '</html>'), strlen($html) . ' octets'];
};
[$a, $b] = $rendre('modules/noyau/bascule.php');
cas('Noyau - bascule (KESKLE)', $a, $b);

echo "\n$ok OK, $ko ECHEC\n";
exit($ko > 0 ? 1 : 0);
