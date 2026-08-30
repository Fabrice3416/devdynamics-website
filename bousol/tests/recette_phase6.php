<?php
declare(strict_types=1);

/**
 * Recette de la phase 6 : module Activites.
 * Les regles des sections 3.3, 3.5 et 3.6, plus les cas qui doivent reussir.
 *
 *   BOUSOL_RECETTE=oui php bousol/tests/recette_phase6.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/activites.php';
require_once __DIR__ . '/_garde.php';

recette_garde('Recette de la phase 6 - Activites');
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

/** Le televersement exige un vrai POST : on pose le fichier et sa reference en base. */
function poser_fichier(string $nom): int
{
    $f = enregistrer_contenu('REC6 ' . $nom . ' ' . random_int(1, 999999999),
        'pdf', 'application/pdf', 'scans', 'REC6-' . $nom . '.pdf');
    return (int)$f['id'];
}

$_SESSION['user_id'] = 1; $_SESSION['user_nom'] = 'Recette'; $_SESSION['tiers_id'] = 1;
$_SESSION['admin_outil'] = true; $_SESSION['projet_id'] = 1;
$_SESSION['projet_code'] = 'KESKLE'; $_SESSION['role_projet'] = 'coordinateur';
$_SESSION['est_mandataire'] = false;
param_oublier();
recette_nettoyer($pdo);

/**
 * L'identifiant rendu par une creation, ou zero si elle a echoue. Sans cela, une
 * etape ratee fait suivre vingt avertissements « Undefined array key » qui noient
 * la cause : la fonction appelee refuse alors proprement, et le refus se lit.
 */
function id_de(?array $res): int
{
    return (int)($res['id'] ?? 0);
}

echo "== Versions du cadre logique\n";
$_SESSION['role_projet'] = 'raf';
refuse_avec('Le RAF ne touche pas au cadre logique',
    cadre_version_creer('REC6 tentative'), 'Coordinateur');
$_SESSION['role_projet'] = 'coordinateur';
refuse_avec('Une version sans motif est refusee', cadre_version_creer(''), 'motif');
$v1 = cadre_version_creer('REC6 version initiale');
cas('La premiere version s\'ouvre', !empty($v1['success']), 'numéro ' . ($v1['numero'] ?? ($v1['error'] ?? '')));
$v2 = cadre_version_creer('REC6 logique revue apres le rapport intermediaire');
cas('La version suivante s\'incremente', ($v2['numero'] ?? 0) === ($v1['numero'] ?? 0) + 1,
    (string)($v2['numero'] ?? ''));
cas('La version courante est la derniere',
    (int)(cadre_version_courante()['numero'] ?? 0) === (int)$v2['numero']);

echo "\n== Elements du cadre\n";
refuse_avec('Un element sans code ni libelle est refuse',
    cadre_element_creer(['niveau' => 'resultat', 'code' => '', 'libelle' => '']), 'obligatoires');
refuse_avec('Seul l\'objectif general n\'a pas de parent',
    cadre_element_creer(['niveau' => 'resultat', 'code' => 'REC6R', 'libelle' => 'REC6 orphelin']), 'objectif général');
$og = cadre_element_creer(['niveau' => 'objectif_general', 'code' => 'REC6OG',
                           'libelle' => 'REC6 objectif general', 'risque' => 'REC6 risque',
                           'attenuation' => 'REC6 attenuation']);
cas('L\'objectif general se cree sans parent', !empty($og['success']), $og['error'] ?? '');
$os = cadre_element_creer(['niveau' => 'objectif_specifique', 'code' => 'REC6OS',
                           'libelle' => 'REC6 objectif specifique', 'parent_id' => id_de($og)]);
$res1 = cadre_element_creer(['niveau' => 'resultat', 'code' => 'REC6R1',
                             'libelle' => 'REC6 resultat', 'parent_id' => id_de($os)]);
cas('Un seul mecanisme absorbe les trois niveaux',
    !empty($os['success']) && !empty($res1['success']), $res1['error'] ?? '');
refuse_avec('Un code deja pris est refuse',
    cadre_element_creer(['niveau' => 'resultat', 'code' => 'REC6R1', 'libelle' => 'REC6 doublon',
                         'parent_id' => id_de($os)]), 'existe déjà');

echo "\n== Indicateurs et releves\n";
$ind = indicateur_creer(['element_id' => id_de($res1), 'libelle' => 'REC6 taux de reussite',
                         'cible_valeur' => '80 %', 'echeance_mois' => 6,
                         'verification' => 'Fiches d\'evaluation']);
cas('Un indicateur se rattache a l\'element qu\'il mesure', !empty($ind['success']), $ind['error'] ?? '');
$indApres = indicateur_creer(['element_id' => id_de($og), 'libelle' => 'REC6 adoption a trois mois',
                              'cible_valeur' => '9 sur 12', 'echeance_mois' => 10]);
$ech = echeance_indicateur(10);
cas('Une echeance au-dela de la duree tombe apres la cloture',
    $ech['apres_cloture'] === true, 'M10 sur ' . duree_mois() . ' mois');
$ech6 = echeance_indicateur(6);
cas('Une echeance en mois se convertit par le calendrier relatif',
    $ech6['apres_cloture'] === false, $ech6['date'] ?? 'sans date, calendrier non initialise');

$rel = releve_poser(id_de($ind), '85 %', 'REC6 releve du mois 6');
cas('Le releve se pose', !empty($rel['success']), $rel['error'] ?? '');
$releves = releves_indicateur(id_de($ind));
cas('Le releve se rattache a la version du cadre en vigueur',
    ($releves[0]['version_numero'] ?? 0) === (int)$v2['numero'],
    'version ' . ($releves[0]['version_numero'] ?? '?'));
refuse_avec('Un releve sans valeur est refuse', releve_poser(id_de($ind), ''), 'obligatoire');

echo "\n== Activites et visibilite\n";
refuse_avec('Une activite du cadre logique sans resultat est refusee',
    activite_creer(['code' => 'REC6A1', 'categorie' => 'cadre_logique', 'libelle' => 'REC6 sans resultat']),
    'se rattache à son résultat');
refuse_avec('Une activite de visibilite rattachee a un resultat est refusee',
    activite_creer(['code' => 'REC6A2', 'categorie' => 'visibilite', 'libelle' => 'REC6 mal rattachee',
                    'element_id' => id_de($res1)]), 'aucun résultat');
$a1 = activite_creer(['code' => 'REC6A1', 'categorie' => 'cadre_logique', 'element_id' => id_de($res1),
                      'libelle' => 'REC6 formation des organisations', 'mois_debut' => 4, 'mois_fin' => 6,
                      'livrable_attendu' => 'REC6 rapport de formation']);
cas('Une activite du cadre logique se cree', !empty($a1['success']), $a1['error'] ?? '');
$lAtelier = budget_ligne('4.2');
$a2 = activite_creer(['code' => 'REC6A2', 'categorie' => 'visibilite',
                      'libelle' => 'REC6 atelier de lancement', 'ligne_id' => id_de($lAtelier)]);
cas('Une activite de visibilite se rattache a sa ligne, pas a un resultat',
    !empty($a2['success']), $a2['error'] ?? '');

refuse_avec('Une activite ne se declare pas realisee sans son livrable',
    activite_avancer(id_de($a1), 'realisee'), 'attend son livrable');
$manquants = livrables_manquants();
cas('Le registre des livrables nomme ce qui manque',
    count(array_filter($manquants, fn($m) => $m['code'] === 'REC6A1')) === 1, count($manquants) . ' attendu(s)');
$pdo->prepare('UPDATE activites SET livrable_fichier_id = ? WHERE id = ?')
    ->execute([poser_fichier('LIVRABLE'), id_de($a1)]);
$res = activite_avancer(id_de($a1), 'realisee');
cas('Avec son livrable, l\'activite se declare realisee', !empty($res['success']), $res['error'] ?? '');

$dif = difficulte_ajouter(id_de($a1), 'REC6 retard de livraison des materiels', 'REC6 report d\'une semaine');
cas('Une difficulte et sa mesure corrective s\'enregistrent', !empty($dif['success']), $dif['error'] ?? '');

echo "\n== Sessions de formation\n";
$pdo->exec("INSERT INTO tiers (type, nom, fonction) VALUES ('personne', 'REC6 Formateur', 'Formateur')");
$formateur = (int)$pdo->lastInsertId();
refuse_avec('Une session dont la fin precede le debut est refusee',
    session_creer(['activite_id' => id_de($a1), 'numero' => 1, 'date_debut' => '2026-06-10',
                   'date_fin' => '2026-06-01', 'lieu' => 'REC6 salle', 'formateur_id' => $formateur]),
    'précède');
refuse_avec('Une session sans activite est refusee',
    session_creer(['activite_id' => 999999, 'numero' => 1, 'date_debut' => '2026-06-01',
                   'date_fin' => '2026-06-02', 'lieu' => 'REC6 salle', 'formateur_id' => $formateur]),
    'Activité inconnue');
$sess = session_creer(['activite_id' => id_de($a1), 'numero' => 1, 'date_debut' => '2026-06-01',
                       'date_fin' => '2026-06-02', 'lieu' => 'REC6 salle de formation',
                       'formateur_id' => $formateur]);
cas('La session se cree', !empty($sess['success']), $sess['error'] ?? '');
$sid = id_de($sess);
cas('Une session naît planifiee', (session($sid)['statut'] ?? '') === 'planifiee', session($sid)['statut'] ?? '');

refuse_avec('Clore une session sans feuille de presence est refuse',
    session_clore($sid), 'feuille de présence');

$pdo->prepare('INSERT INTO beneficiaires (projet_id, nom, sexe, tranche_age) VALUES (1, ?, ?, ?)')
    ->execute(['REC6 Beneficiaire A', 'F', '18_24']);
$bA = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO beneficiaires (projet_id, nom, sexe, tranche_age) VALUES (1, ?, ?, ?)')
    ->execute(['REC6 Beneficiaire B', 'M', '25_35']);
$bB = (int)$pdo->lastInsertId();

refuse_avec('Une presence hors des dates de la session est refusee',
    participation_saisir($sid, $bA, '2026-07-15', true), 'hors des dates');
$res = participation_saisir($sid, $bA, '2026-06-01', true, 'reussite');
cas('Une presence se saisit', !empty($res['success']), $res['error'] ?? '');
cas('La premiere preuve fait passer la session a tenue',
    (session($sid)['statut'] ?? '') === 'tenue', session($sid)['statut'] ?? '');
participation_saisir($sid, $bB, '2026-06-01', true, 'echec');
participation_saisir($sid, $bA, '2026-06-02', true, 'reussite');

$pdo->prepare('UPDATE sessions_formation SET feuille_presence_fichier_id = ? WHERE id = ?')
    ->execute([poser_fichier('PRESENCE'), $sid]);
refuse_avec('Clore avec des fiches d\'evaluation manquantes est refuse',
    session_clore($sid), 'fiche');
$pdo->prepare("UPDATE participations SET fiche_evaluation_fichier_id = ? WHERE session_id = ? AND present = 1")
    ->execute([poser_fichier('EVAL'), $sid]);
$coh = session_coherence($sid);
cas('La coherence confronte les presents saisis a la piece probante',
    $coh['ok'] === true, $coh['presents'] . ' present(s) sur ' . $coh['jours'] . ' jour(s)');
$res = session_clore($sid);
cas('Le dossier se clot au retour des deux flux', !empty($res['success']), $res['error'] ?? '');
refuse_avec('Une session close ne se reclot pas', session_clore($sid), 'déjà close');

$taux = taux_reussite();
cas('La moyenne des evaluations alimente l\'indicateur du resultat 2.2',
    $taux['evalues'] >= 3 && $taux['taux'] !== null,
    ($taux['taux'] ?? 0) . ' % sur ' . $taux['evalues'] . ' evaluation(s)');

echo "\n== Coherence physique des lignes 5.1 et 5.2\n";
$controles = coherence_lignes_physiques();
cas('Les deux lignes physiques sont nommees par un parametre du projet',
    is_array($controles), count($controles) . ' controle(s) actif(s)');
foreach ($controles as $c) {
    cas('Controle de ' . mb_substr($c['ligne'], 0, 30), is_float($c['ecart']),
        $c['detail'] . ' · imputé ' . $c['impute'] . ' · écart ' . $c['ecart']);
}

echo "\n== Registre des versions de l'application\n";
$va = version_application_creer(['numero' => 'REC6-1.0.0', 'nature' => 'publication',
                                 'modules_touches' => ['m1', 'm2'], 'canal' => 'Google Play',
                                 'activite_code' => '1.1.1']);
cas('Une version s\'inscrit au registre', !empty($va['success']), $va['error'] ?? '');
refuse_avec('Un numero deja pris est refuse',
    version_application_creer(['numero' => 'REC6-1.0.0', 'nature' => 'correctif']), 'existe déjà');
refuse_avec('Une publication sans verification Google validee ne se diffuse pas',
    version_application_diffuser(id_de($va)), 'vérification Google');
version_application_verification(id_de($va), 'valide');
$res = version_application_diffuser(id_de($va));
cas('La verification validee ouvre la publication', !empty($res['success']), $res['error'] ?? '');
$vc = version_application_creer(['numero' => 'REC6-1.0.1', 'nature' => 'correctif']);
$res = version_application_diffuser(id_de($vc));
cas('Un correctif se diffuse sans verification Google', !empty($res['success']), $res['error'] ?? '');

echo "\n== Anomalies\n";
refuse_avec('Une anomalie sans description est refusee',
    anomalie_declarer(['description' => '', 'gravite' => 'faible']), 'obligatoire');
refuse_avec('Une gravite hors liste est refusee',
    anomalie_declarer(['description' => 'REC6 test', 'gravite' => 'enorme']), 'hors liste');
$an = anomalie_declarer(['description' => 'REC6 la saisie plante au troisieme ecran',
                         'gravite' => 'critique', 'canal' => 'WhatsApp', 'declarant_id' => 2]);
cas('Un signalement s\'enregistre', !empty($an['success']), $an['error'] ?? '');
$ouvertes = array_filter(anomalies(), fn($a) => $a['date_resolution'] === null);
cas('Un signalement non corrige est l\'etat normal d\'un ticket', count($ouvertes) >= 1, count($ouvertes) . ' ouverte(s)');
$res = anomalie_accuser(id_de($an));
cas('L\'accuse de reception s\'enregistre', !empty($res['success']), $res['error'] ?? '');
refuse_avec('Resoudre sans reponse est refuse', anomalie_resoudre(id_de($an), ''), 'obligatoire');
$res = anomalie_resoudre(id_de($an), 'REC6 corrige au correctif 1.0.1', id_de($vc));
cas('La resolution rattache le correctif, facultativement', !empty($res['success']), $res['error'] ?? '');

echo "\n== Enquete d'adoption\n";
$res = enquete_saisir(2, true, 'REC6 usage quotidien constate');
cas('Une enquete d\'adoption s\'enregistre', !empty($res['success']), $res['error'] ?? '');
refuse_avec('Une organisation inconnue est refusee', enquete_saisir(999999, true, 'REC6 inconnue'), 'inconnue');
$ad = adoption();
cas('L\'adoption se compte en organisations actives',
    $ad['enquetees'] >= 1 && $ad['actives'] >= 1, $ad['actives'] . ' / ' . $ad['enquetees']);

echo "\n== Rendu des ecrans\n";
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
foreach ([1 => 'KESKLE', 2 => 'KKP'] as $pid => $code) {
    $_SESSION['projet_id'] = $pid;
    $_SESSION['projet_code'] = $code;
    param_oublier();
    foreach (['Activités - cadre logique' => 'modules/activites/index.php',
              'Activités - formations'    => 'modules/activites/sessions.php',
              'Activités - registre'      => 'modules/activites/registre.php'] as $lib => $page) {
        [$r1, $d1] = $rendre($page);
        cas($lib . ' (' . $code . ')', $r1, $d1);
    }
}

echo "\n$ok OK, $ko ECHEC\n";
exit($ko > 0 ? 1 : 0);
