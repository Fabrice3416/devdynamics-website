<?php
declare(strict_types=1);

/**
 * Module Activites - cadre logique, indicateurs, sessions de formation et registre
 * des versions (CDC 3.3, 3.5, 3.6).
 *
 * Le cadre logique est versionne. Chaque releve d'indicateur est rattache a une
 * version, ce qui rend le tableau du rapport reproductible a l'identique des annees
 * plus tard : sans cette attache, un cadre remanie rendrait illisibles les rapports
 * deja transmis.
 */

require_once __DIR__ . '/calendrier.php';
require_once __DIR__ . '/budget.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/audit.php';

const NIVEAUX_CADRE = [
    'objectif_general'    => 'Objectif général',
    'objectif_specifique' => 'Objectif spécifique',
    'resultat'            => 'Résultat',
];

const CATEGORIES_ACTIVITE = [
    'cadre_logique' => 'Cadre logique',
    'visibilite'    => 'Visibilité',
];

const STATUTS_ACTIVITE = [
    'non_demarree' => 'Non démarrée',
    'en_cours'     => 'En cours',
    'realisee'     => 'Réalisée',
    'abandonnee'   => 'Abandonnée',
];

const NATURES_VERSION_APP = [
    'test_interne' => 'Test interne',
    'validation'   => 'Validation',
    'publication'  => 'Publication',
    'correctif'    => 'Correctif',
];

const ETATS_DIFFUSION = ['preparee' => 'Préparée', 'diffusee' => 'Diffusée', 'retiree' => 'Retirée'];

const VERIFICATIONS_GOOGLE = [
    'non_soumis' => 'Non soumise', 'soumis' => 'Soumise', 'valide' => 'Validée', 'refuse' => 'Refusée',
];

const GRAVITES_ANOMALIE = ['faible' => 'Faible', 'moyenne' => 'Moyenne', 'critique' => 'Critique'];
const NATURES_ANOMALIE = ['anomalie' => 'Anomalie', 'conseil_usage' => 'Conseil d\'usage'];

// ---------------------------------------------------------------------
// Versions du cadre logique
// ---------------------------------------------------------------------

/**
 * La version en vigueur. Le cadre logique est versionne : « le rapport final exige
 * sa remise a jour en annexe, et le rapport intermediaire demande si la logique
 * d'intervention reste valide » (CDC 3.5).
 */
function cadre_version_courante(?int $projetId = null): ?array
{
    $st = db()->prepare('SELECT * FROM versions_cadre WHERE projet_id = ? ORDER BY numero DESC LIMIT 1');
    $st->execute([$projetId ?? projet_id()]);
    $v = $st->fetch();
    return $v === false ? null : $v;
}

function cadre_versions(?int $projetId = null): array
{
    $st = db()->prepare(
        'SELECT v.*, t.nom AS auteur FROM versions_cadre v
           JOIN utilisateurs u ON u.id = v.auteur_id JOIN tiers t ON t.id = u.tiers_id
          WHERE v.projet_id = ? ORDER BY v.numero DESC'
    );
    $st->execute([$projetId ?? projet_id()]);
    return $st->fetchAll();
}

/**
 * Ouvre une nouvelle version du cadre. Chaque version porte sa date, son auteur et
 * son motif ; une version figee avec un rapport transmis ne se modifie plus.
 *
 * @return array{success: bool, id?: int, numero?: int, error?: string}
 */
function cadre_version_creer(string $motif): array
{
    if (user_role() !== 'coordinateur') {
        return ['success' => false, 'error' => 'Le cadre logique relève du Coordinateur.'];
    }
    if (trim($motif) === '') {
        return ['success' => false, 'error' => 'Le motif de la nouvelle version est obligatoire : '
            . 'c\'est lui qui explique ce que la logique d\'intervention a changé.'];
    }
    $courante = cadre_version_courante();
    $numero = $courante === null ? 1 : ((int)$courante['numero'] + 1);
    db()->prepare('INSERT INTO versions_cadre (projet_id, numero, date, motif, auteur_id) VALUES (?,?,CURDATE(),?,?)')
        ->execute([projet_id(), $numero, mb_substr(trim($motif), 0, 255), user_id()]);
    $id = (int)db()->lastInsertId();
    audit('activites', 'cadre_version_creee', 'versions_cadre', $id, 'Version ' . $numero . ' · ' . trim($motif));
    return ['success' => true, 'id' => $id, 'numero' => $numero];
}

/** « La version transmise avec un rapport est figee avec lui » (CDC 3.5). */
function cadre_version_figer(int $versionId): array
{
    $st = db()->prepare('SELECT * FROM versions_cadre WHERE id = ? AND projet_id = ?');
    $st->execute([$versionId, projet_id()]);
    $v = $st->fetch();
    if ($v === false) {
        return ['success' => false, 'error' => 'Version inconnue dans ce projet.'];
    }
    if ($v['figee']) {
        return ['success' => false, 'error' => 'Cette version est déjà figée.'];
    }
    db()->prepare('UPDATE versions_cadre SET figee = 1 WHERE id = ?')->execute([$versionId]);
    audit('activites', 'cadre_version_figee', 'versions_cadre', $versionId,
        'Version ' . $v['numero'] . ' · figée avec le rapport transmis');
    return ['success' => true];
}

// ---------------------------------------------------------------------
// Elements du cadre logique
// ---------------------------------------------------------------------

/**
 * L'arbre du cadre : « un seul mecanisme absorbe les trois niveaux, objectif
 * general, objectifs specifiques et resultats » (CDC 8.4).
 */
function cadre_elements(?int $projetId = null): array
{
    $st = db()->prepare('SELECT * FROM cadre_elements WHERE projet_id = ? ORDER BY ordre');
    $st->execute([$projetId ?? projet_id()]);
    $par = [];
    foreach ($st->fetchAll() as $e) {
        $par[$e['code']] = $e;
    }
    return $par;
}

function cadre_element_creer(array $d): array
{
    if (user_role() !== 'coordinateur') {
        return ['success' => false, 'error' => 'Le cadre logique relève du Coordinateur.'];
    }
    $niveau = (string)($d['niveau'] ?? '');
    $code = trim((string)($d['code'] ?? ''));
    if (!array_key_exists($niveau, NIVEAUX_CADRE) || $code === '' || trim((string)($d['libelle'] ?? '')) === '') {
        return ['success' => false, 'error' => 'Code, niveau et libellé sont obligatoires.'];
    }
    $parentId = ($d['parent_id'] ?? null) ? (int)$d['parent_id'] : null;
    if ($niveau !== 'objectif_general' && $parentId === null) {
        return ['success' => false, 'error' => 'Seul l\'objectif général n\'a pas de parent : '
            . 'un objectif spécifique se rattache à lui, un résultat à son objectif spécifique.'];
    }
    $st = db()->prepare('SELECT COALESCE(MAX(ordre), 0) + 1 FROM cadre_elements WHERE projet_id = ?');
    $st->execute([projet_id()]);
    try {
        db()->prepare(
            'INSERT INTO cadre_elements (projet_id, code, parent_id, niveau, libelle, risque, attenuation, ordre)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([projet_id(), $code, $parentId, $niveau, trim((string)$d['libelle']),
                    trim((string)($d['risque'] ?? '')) ?: null,
                    trim((string)($d['attenuation'] ?? '')) ?: null, (int)$st->fetchColumn()]);
        $id = (int)db()->lastInsertId();
    } catch (Throwable $e) {
        return ['success' => false, 'error' => str_contains($e->getMessage(), 'uk_cadre_code')
            ? 'Le code ' . $code . ' existe déjà dans ce projet.'
            : 'Enregistrement impossible.'];
    }
    audit('activites', 'cadre_element_cree', 'cadre_element', $id, $code . ' · ' . NIVEAUX_CADRE[$niveau]);
    return ['success' => true, 'id' => $id];
}

// ---------------------------------------------------------------------
// Indicateurs et releves
// ---------------------------------------------------------------------

function indicateurs(?int $elementId = null, ?int $projetId = null): array
{
    $sql = 'SELECT i.*, c.code AS element_code, c.libelle AS element_libelle, c.niveau
              FROM indicateurs i JOIN cadre_elements c ON c.id = i.element_id
             WHERE i.projet_id = ?';
    $args = [$projetId ?? projet_id()];
    if ($elementId !== null) {
        $sql .= ' AND i.element_id = ?';
        $args[] = $elementId;
    }
    $st = db()->prepare($sql . ' ORDER BY c.ordre, i.id');
    $st->execute($args);
    return $st->fetchAll();
}

function indicateur_creer(array $d): array
{
    if (user_role() !== 'coordinateur') {
        return ['success' => false, 'error' => 'Les indicateurs relèvent du Coordinateur.'];
    }
    if (trim((string)($d['libelle'] ?? '')) === '' || (int)($d['element_id'] ?? 0) <= 0) {
        return ['success' => false, 'error' => 'Un indicateur porte son libellé et l\'élément qu\'il mesure.'];
    }
    $st = db()->prepare('SELECT COUNT(*) FROM cadre_elements WHERE id = ? AND projet_id = ?');
    $st->execute([(int)$d['element_id'], projet_id()]);
    if ((int)$st->fetchColumn() === 0) {
        return ['success' => false, 'error' => 'Élément de cadre logique inconnu dans ce projet.'];
    }
    db()->prepare(
        'INSERT INTO indicateurs (projet_id, element_id, libelle, cible_valeur, cible_texte, echeance_mois, verification)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([projet_id(), (int)$d['element_id'], trim((string)$d['libelle']),
                trim((string)($d['cible_valeur'] ?? '')) ?: null,
                trim((string)($d['cible_texte'] ?? '')) ?: null,
                ($d['echeance_mois'] ?? '') !== '' ? (int)$d['echeance_mois'] : null,
                trim((string)($d['verification'] ?? '')) ?: null]);
    $id = (int)db()->lastInsertId();
    audit('activites', 'indicateur_cree', 'indicateur', $id, trim((string)$d['libelle']));
    return ['success' => true, 'id' => $id];
}

/**
 * « Les echeances en mois sont converties en dates reelles par le calendrier
 * relatif » (CDC 3.5). Un mois au-dela de la duree d'execution tombe apres la
 * cloture : il releve du registre d'enquete d'adoption de la phase 2.
 *
 * @return array{date: ?string, apres_cloture: bool}
 */
function echeance_indicateur(?int $moisProjet): array
{
    if ($moisProjet === null) {
        return ['date' => null, 'apres_cloture' => false];
    }
    $periode = periode_mois($moisProjet);
    return [
        'date'          => $periode['fin'] ?? null,
        'apres_cloture' => $moisProjet > (int)duree_mois(),
    ];
}

/**
 * Pose un releve, rattache a la version du cadre en vigueur : « ce qui rend le
 * tableau du rapport reproductible a l'identique des annees plus tard » (CDC 8.4).
 */
function releve_poser(int $indicateurId, string $valeur, string $commentaire = '', ?string $date = null): array
{
    $version = cadre_version_courante();
    if ($version === null) {
        return ['success' => false, 'error' => 'Aucune version du cadre logique : un relevé s\'y rattache toujours.'];
    }
    $st = db()->prepare('SELECT COUNT(*) FROM indicateurs WHERE id = ? AND projet_id = ?');
    $st->execute([$indicateurId, projet_id()]);
    if ((int)$st->fetchColumn() === 0) {
        return ['success' => false, 'error' => 'Indicateur inconnu dans ce projet.'];
    }
    if (trim($valeur) === '') {
        return ['success' => false, 'error' => 'La valeur atteinte est obligatoire.'];
    }
    db()->prepare(
        'INSERT INTO releves (projet_id, indicateur_id, version_id, date, valeur_atteinte, commentaire, auteur_id)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([projet_id(), $indicateurId, (int)$version['id'], $date ?? date('Y-m-d'),
                mb_substr(trim($valeur), 0, 60), trim($commentaire) ?: null, user_id()]);
    $id = (int)db()->lastInsertId();
    audit('activites', 'releve_pose', 'releve', $id,
        'Indicateur ' . $indicateurId . ' · ' . trim($valeur) . ' · version ' . $version['numero']);
    return ['success' => true, 'id' => $id];
}

function releves_indicateur(int $indicateurId): array
{
    $st = db()->prepare(
        'SELECT r.*, v.numero AS version_numero, v.figee FROM releves r
           JOIN versions_cadre v ON v.id = r.version_id
          WHERE r.indicateur_id = ? ORDER BY r.date DESC, r.id DESC'
    );
    $st->execute([$indicateurId]);
    return $st->fetchAll();
}

// ---------------------------------------------------------------------
// Activites
// ---------------------------------------------------------------------

function activites(?string $categorie = null, ?int $projetId = null): array
{
    $sql = 'SELECT a.*, c.code AS element_code, c.libelle AS element_libelle,
                   l.code AS ligne_code, f.nom_genere AS livrable_nom
              FROM activites a
              LEFT JOIN cadre_elements c ON c.id = a.element_id
              LEFT JOIN lignes_budgetaires l ON l.id = a.ligne_id
              LEFT JOIN fichiers f ON f.id = a.livrable_fichier_id
             WHERE a.projet_id = ?';
    $args = [$projetId ?? projet_id()];
    if ($categorie !== null) {
        $sql .= ' AND a.categorie = ?';
        $args[] = $categorie;
    }
    $st = db()->prepare($sql . ' ORDER BY a.ordre, a.code');
    $st->execute($args);
    return $st->fetchAll();
}

/**
 * « Bousol ouvre une categorie d'activites de visibilite, rattachees a leurs lignes
 * budgetaires mais a aucun resultat » (CDC 3.5) : le budget finance un charge de
 * communication, deux ateliers et des impressions que le cadre logique ignore.
 */
function activite_creer(array $d): array
{
    if (user_role() !== 'coordinateur') {
        return ['success' => false, 'error' => 'Les activités relèvent du Coordinateur.'];
    }
    $code = trim((string)($d['code'] ?? ''));
    $categorie = (string)($d['categorie'] ?? 'cadre_logique');
    $elementId = ($d['element_id'] ?? null) ? (int)$d['element_id'] : null;
    if ($code === '' || trim((string)($d['libelle'] ?? '')) === '') {
        return ['success' => false, 'error' => 'Code et libellé sont obligatoires.'];
    }
    if (!array_key_exists($categorie, CATEGORIES_ACTIVITE)) {
        return ['success' => false, 'error' => 'Catégorie hors liste.'];
    }
    if ($categorie === 'cadre_logique' && $elementId === null) {
        return ['success' => false, 'error' => 'Une activité du cadre logique se rattache à son résultat.'];
    }
    if ($categorie === 'visibilite' && $elementId !== null) {
        return ['success' => false, 'error' => 'Une activité de visibilité ne se rattache à aucun résultat : '
            . 'elle se rattache à sa ligne budgétaire, et ses preuves alimentent la section visibilité du rapport narratif.'];
    }
    $st = db()->prepare('SELECT COALESCE(MAX(ordre), 0) + 1 FROM activites WHERE projet_id = ?');
    $st->execute([projet_id()]);
    try {
        db()->prepare(
            'INSERT INTO activites (projet_id, code, element_id, categorie, libelle, ligne_id,
                                    mois_debut, mois_fin, livrable_attendu, intervenants, ordre)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([projet_id(), $code, $elementId, $categorie, trim((string)$d['libelle']),
                    ($d['ligne_id'] ?? null) ? (int)$d['ligne_id'] : null,
                    ($d['mois_debut'] ?? '') !== '' ? (int)$d['mois_debut'] : null,
                    ($d['mois_fin'] ?? '') !== '' ? (int)$d['mois_fin'] : null,
                    trim((string)($d['livrable_attendu'] ?? '')) ?: null,
                    trim((string)($d['intervenants'] ?? '')) ?: null, (int)$st->fetchColumn()]);
        $id = (int)db()->lastInsertId();
    } catch (Throwable $e) {
        return ['success' => false, 'error' => str_contains($e->getMessage(), 'uk_activites_code')
            ? 'Le code ' . $code . ' existe déjà dans ce projet.'
            : 'Enregistrement impossible.'];
    }
    audit('activites', 'activite_creee', 'activite', $id, $code . ' · ' . CATEGORIES_ACTIVITE[$categorie]);
    return ['success' => true, 'id' => $id];
}

function activite(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM activites WHERE id = ? AND projet_id = ?');
    $st->execute([$id, projet_id()]);
    $a = $st->fetch();
    return $a === false ? null : $a;
}

function activite_avancer(int $id, string $statut): array
{
    if (!array_key_exists($statut, STATUTS_ACTIVITE)) {
        return ['success' => false, 'error' => 'Statut hors liste.'];
    }
    $a = activite($id);
    if ($a === null) {
        return ['success' => false, 'error' => 'Activité inconnue dans ce projet.'];
    }
    if ($statut === 'realisee' && $a['livrable_attendu'] !== null && $a['livrable_fichier_id'] === null) {
        return ['success' => false, 'error' => 'Cette activité attend son livrable, « ' . $a['livrable_attendu']
            . ' » : c\'est lui qui alimente la rubrique des documents produits du rapport final.'];
    }
    db()->prepare('UPDATE activites SET statut = ? WHERE id = ? AND projet_id = ?')->execute([$statut, $id, projet_id()]);
    audit('activites', 'activite_avancee', 'activite', $id, $a['code'] . ' · ' . STATUTS_ACTIVITE[$statut]);
    return ['success' => true];
}

/** Verse le livrable attendu d'une activite : le registre des dix-huit documents. */
function activite_livrable(int $id, ?array $fichier): array
{
    $a = activite($id);
    if ($a === null) {
        return ['success' => false, 'error' => 'Activité inconnue dans ce projet.'];
    }
    if (empty($fichier['name'])) {
        return ['success' => false, 'error' => 'Le livrable numérisé est obligatoire.'];
    }
    $up = enregistrer_upload($fichier, 'documents',
        projet_code() . '-LIVRABLE-' . $a['code'] . '.pdf', ALLOWED_DOCUMENT, false,
        $a['livrable_fichier_id'] ? (int)$a['livrable_fichier_id'] : null);
    if (!$up['success']) {
        return ['success' => false, 'error' => 'Livrable : ' . $up['error']];
    }
    db()->prepare('UPDATE activites SET livrable_fichier_id = ? WHERE id = ?')->execute([(int)$up['id'], $id]);
    audit('activites', 'livrable_verse', 'activite', $id, $a['code'] . ' · ' . ($a['livrable_attendu'] ?? ''));
    return ['success' => true];
}

/** Le registre des livrables attendus et non encore produits. */
function livrables_manquants(?int $projetId = null): array
{
    $st = db()->prepare(
        "SELECT code, libelle, livrable_attendu FROM activites
          WHERE projet_id = ? AND livrable_attendu IS NOT NULL AND livrable_fichier_id IS NULL
            AND statut <> 'abandonnee' ORDER BY ordre"
    );
    $st->execute([$projetId ?? projet_id()]);
    return $st->fetchAll();
}

/** Une difficulte rencontree et sa mesure corrective, pour le rapport narratif. */
function difficulte_ajouter(int $activiteId, string $description, string $mesure, ?string $date = null): array
{
    $a = activite($activiteId);
    if ($a === null) {
        return ['success' => false, 'error' => 'Activité inconnue dans ce projet.'];
    }
    if (trim($description) === '') {
        return ['success' => false, 'error' => 'La description de la difficulté est obligatoire.'];
    }
    db()->prepare(
        'INSERT INTO difficultes (projet_id, activite_id, date, description, mesure_corrective, auteur_id)
         VALUES (?,?,?,?,?,?)'
    )->execute([projet_id(), $activiteId, $date ?? date('Y-m-d'), trim($description),
                trim($mesure) ?: null, user_id()]);
    $id = (int)db()->lastInsertId();
    audit('activites', 'difficulte_ajoutee', 'difficulte', $id, $a['code'] . ' · ' . mb_substr(trim($description), 0, 80));
    return ['success' => true, 'id' => $id];
}

function difficultes(?int $projetId = null): array
{
    $st = db()->prepare(
        'SELECT d.*, a.code AS activite_code FROM difficultes d JOIN activites a ON a.id = d.activite_id
          WHERE d.projet_id = ? ORDER BY d.date DESC, d.id DESC'
    );
    $st->execute([$projetId ?? projet_id()]);
    return $st->fetchAll();
}

// ---------------------------------------------------------------------
// Sessions de formation
// ---------------------------------------------------------------------

/**
 * La feuille de presence et les fiches d'evaluation sont etablies sur papier,
 * signees a la main, puis numerisees et versees au dossier. « Elles constituent les
 * pieces probantes, la table des participations n'etant que la donnee saisie qui en
 * derive » (CDC 3.3).
 */
function sessions(?int $projetId = null): array
{
    $st = db()->prepare(
        'SELECT s.*, a.code AS activite_code, a.libelle AS activite_libelle, t.nom AS formateur,
                (SELECT COUNT(DISTINCT beneficiaire_id) FROM participations p
                  WHERE p.session_id = s.id AND p.present = 1) AS presents,
                (SELECT COUNT(*) FROM participations p
                  WHERE p.session_id = s.id AND p.present = 1 AND p.fiche_evaluation_fichier_id IS NULL) AS fiches_manquantes
           FROM sessions_formation s
           JOIN activites a ON a.id = s.activite_id
           JOIN tiers t ON t.id = s.formateur_id
          WHERE s.projet_id = ? ORDER BY s.date_debut DESC'
    );
    $st->execute([$projetId ?? projet_id()]);
    return $st->fetchAll();
}

function session(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM sessions_formation WHERE id = ? AND projet_id = ?');
    $st->execute([$id, projet_id()]);
    $s = $st->fetch();
    return $s === false ? null : $s;
}

function session_creer(array $d): array
{
    if (!in_array(user_role(), ['coordinateur', 'raf'], true)) {
        return ['success' => false, 'error' => 'La tenue des sessions revient au Coordinateur et au RAF.'];
    }
    $debut = (string)($d['date_debut'] ?? '');
    $fin = (string)($d['date_fin'] ?? '');
    if ($debut === '' || $fin === '' || $fin < $debut) {
        return ['success' => false, 'error' => 'Les dates de début et de fin sont obligatoires, et la fin ne précède pas le début.'];
    }
    if (trim((string)($d['lieu'] ?? '')) === '') {
        return ['success' => false, 'error' => 'Le lieu de la session est obligatoire.'];
    }
    $sa = db()->prepare('SELECT COUNT(*) FROM activites WHERE id = ? AND projet_id = ?');
    $sa->execute([(int)($d['activite_id'] ?? 0), projet_id()]);
    if ((int)$sa->fetchColumn() === 0) {
        return ['success' => false, 'error' => 'Activité inconnue dans ce projet : une session se rattache à l\'activité qu\'elle réalise.'];
    }
    $sf = db()->prepare("SELECT COUNT(*) FROM tiers WHERE id = ? AND type = 'personne'");
    $sf->execute([(int)($d['formateur_id'] ?? 0)]);
    if ((int)$sf->fetchColumn() === 0) {
        return ['success' => false, 'error' => 'Formateur inconnu au référentiel des tiers.'];
    }
    db()->prepare(
        'INSERT INTO sessions_formation (projet_id, activite_id, numero, date_debut, date_fin, lieu, formateur_id)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([projet_id(), (int)$d['activite_id'], (int)($d['numero'] ?? 1), $debut, $fin,
                trim((string)$d['lieu']), (int)$d['formateur_id']]);
    $id = (int)db()->lastInsertId();
    audit('activites', 'session_creee', 'session_formation', $id,
        'Session ' . ($d['numero'] ?? 1) . ' · ' . date_fr($debut) . ' → ' . date_fr($fin) . ' · ' . trim((string)$d['lieu']));
    return ['success' => true, 'id' => $id];
}

/** La feuille de presence numerisee, piece probante de la session. */
function session_feuille_presence(int $sessionId, ?array $fichier): array
{
    $s = session($sessionId);
    if ($s === null) {
        return ['success' => false, 'error' => 'Session inconnue dans ce projet.'];
    }
    if (empty($fichier['name'])) {
        return ['success' => false, 'error' => 'La feuille de présence numérisée est obligatoire.'];
    }
    $up = enregistrer_upload($fichier, 'scans',
        projet_code() . '-PRESENCE-S' . (int)$s['numero'] . '.pdf', ALLOWED_DOCUMENT, false,
        $s['feuille_presence_fichier_id'] ? (int)$s['feuille_presence_fichier_id'] : null);
    if (!$up['success']) {
        return ['success' => false, 'error' => 'Feuille de présence : ' . $up['error']];
    }
    db()->prepare('UPDATE sessions_formation SET feuille_presence_fichier_id = ? WHERE id = ?')
        ->execute([(int)$up['id'], $sessionId]);
    session_constater_tenue($sessionId);
    audit('activites', 'feuille_presence_versee', 'session_formation', $sessionId, 'Fichier #' . (int)$up['id']);
    return ['success' => true];
}

/**
 * La participation fusionne la presence et l'evaluation (CDC 8.4). Elle alimente a
 * la fois l'indicateur du resultat 2.2 et le controle de coherence des couverts.
 */
function participation_saisir(int $sessionId, int $beneficiaireId, string $jour, bool $present,
                              ?string $resultat = null, ?array $fiche = null): array
{
    $s = session($sessionId);
    if ($s === null) {
        return ['success' => false, 'error' => 'Session inconnue dans ce projet.'];
    }
    if ($jour < (string)$s['date_debut'] || $jour > (string)$s['date_fin']) {
        return ['success' => false, 'error' => 'Ce jour est hors des dates de la session.'];
    }
    $sb = db()->prepare('SELECT COUNT(*) FROM beneficiaires WHERE id = ? AND projet_id = ?');
    $sb->execute([$beneficiaireId, projet_id()]);
    if ((int)$sb->fetchColumn() === 0) {
        return ['success' => false, 'error' => 'Bénéficiaire inconnu au registre de ce projet.'];
    }
    if ($resultat !== null && !in_array($resultat, ['reussite', 'echec'], true)) {
        return ['success' => false, 'error' => 'Le résultat de l\'exercice est une réussite ou un échec.'];
    }
    $ficheId = null;
    if (!empty($fiche['name'])) {
        $up = enregistrer_upload($fiche, 'scans',
            projet_code() . '-EVAL-S' . (int)$s['numero'] . '-' . $beneficiaireId . '.pdf');
        if (!$up['success']) {
            return ['success' => false, 'error' => 'Fiche d\'évaluation : ' . $up['error']];
        }
        $ficheId = (int)$up['id'];
    }
    try {
        db()->prepare(
            'INSERT INTO participations (projet_id, session_id, beneficiaire_id, jour, present, resultat, fiche_evaluation_fichier_id)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE present = VALUES(present), resultat = COALESCE(VALUES(resultat), resultat),
                                     fiche_evaluation_fichier_id = COALESCE(VALUES(fiche_evaluation_fichier_id), fiche_evaluation_fichier_id)'
        )->execute([projet_id(), $sessionId, $beneficiaireId, $jour, $present ? 1 : 0, $resultat, $ficheId]);
    } catch (Throwable $e) {
        error_log('participation_saisir: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Enregistrement impossible.'];
    }
    session_constater_tenue($sessionId);
    return ['success' => true];
}

/**
 * Une session planifiee devient tenue des qu'une preuve arrive - la feuille de
 * presence ou la premiere presence saisie. Elle ne se clot qu'au retour des deux
 * flux : l'etat intermediaire dit exactement cela, la seance a eu lieu mais son
 * dossier n'est pas complet.
 */
function session_constater_tenue(int $sessionId): void
{
    db()->prepare("UPDATE sessions_formation SET statut = 'tenue' WHERE id = ? AND statut = 'planifiee'")
        ->execute([$sessionId]);
}

function participations_session(int $sessionId): array
{
    $st = db()->prepare(
        'SELECT p.*, b.nom, b.sexe, b.tranche_age, o.nom AS organisation
           FROM participations p
           JOIN beneficiaires b ON b.id = p.beneficiaire_id
           LEFT JOIN tiers o ON o.id = b.organisation_id
          WHERE p.session_id = ? ORDER BY p.jour, b.nom'
    );
    $st->execute([$sessionId]);
    return $st->fetchAll();
}

/**
 * « Un controle de coherence confronte le nombre de presents enregistres a la
 * feuille scannee, et le dossier de session ne peut etre clos qu'au retour des deux
 * flux » (CDC 3.3).
 *
 * L'outil ne sait pas lire une feuille scannee : il rapproche donc ce qu'il peut,
 * la presence de la piece et la completude des fiches, et affiche le nombre saisi
 * pour que l'oeil fasse le reste.
 *
 * @return array{ok: bool, motifs: string[], presents: int, jours: int}
 */
function session_coherence(int $sessionId): array
{
    $s = session($sessionId);
    if ($s === null) {
        return ['ok' => false, 'motifs' => ['Session inconnue.'], 'presents' => 0, 'jours' => 0];
    }
    $motifs = [];
    if ($s['feuille_presence_fichier_id'] === null) {
        $motifs[] = 'La feuille de présence numérisée manque : c\'est la pièce probante, la saisie n\'en est que la dérivée.';
    }
    $st = db()->prepare(
        "SELECT COUNT(DISTINCT beneficiaire_id) AS presents,
                COUNT(DISTINCT jour) AS jours,
                SUM(CASE WHEN present = 1 AND fiche_evaluation_fichier_id IS NULL THEN 1 ELSE 0 END) AS sans_fiche
           FROM participations WHERE session_id = ? AND present = 1"
    );
    $st->execute([$sessionId]);
    $c = $st->fetch();
    if ((int)$c['presents'] === 0) {
        $motifs[] = 'Aucune présence saisie.';
    }
    if ((int)$c['sans_fiche'] > 0) {
        $motifs[] = (int)$c['sans_fiche'] . ' fiche(s) d\'évaluation individuelle manquante(s).';
    }
    return ['ok' => $motifs === [], 'motifs' => $motifs,
            'presents' => (int)$c['presents'], 'jours' => (int)$c['jours']];
}

function session_clore(int $sessionId): array
{
    $s = session($sessionId);
    if ($s === null) {
        return ['success' => false, 'error' => 'Session inconnue dans ce projet.'];
    }
    if ($s['statut'] === 'close') {
        return ['success' => false, 'error' => 'Cette session est déjà close.'];
    }
    $coherence = session_coherence($sessionId);
    if (!$coherence['ok']) {
        return ['success' => false, 'error' => 'Le dossier de session ne se clôt qu\'au retour des deux flux : '
            . implode(' ', $coherence['motifs'])];
    }
    db()->prepare("UPDATE sessions_formation SET statut = 'close' WHERE id = ?")->execute([$sessionId]);
    audit('activites', 'session_close', 'session_formation', $sessionId,
        $coherence['presents'] . ' présent(s) sur ' . $coherence['jours'] . ' jour(s)');
    return ['success' => true];
}

/**
 * « L'exercice pratique de fin de seance produit une fiche d'evaluation
 * individuelle avec un resultat, reussite ou echec, dont la moyenne alimente
 * l'indicateur des quatre-vingts pour cent du resultat 2.2 » (CDC 3.3).
 */
function taux_reussite(?int $projetId = null): array
{
    $st = db()->prepare(
        "SELECT COUNT(*) AS evalues, SUM(CASE WHEN resultat = 'reussite' THEN 1 ELSE 0 END) AS reussites
           FROM participations WHERE projet_id = ? AND resultat IS NOT NULL"
    );
    $st->execute([$projetId ?? projet_id()]);
    $c = $st->fetch();
    $evalues = (int)$c['evalues'];
    return [
        'evalues'   => $evalues,
        'reussites' => (int)$c['reussites'],
        'taux'      => $evalues > 0 ? round((int)$c['reussites'] / $evalues * 100, 1) : null,
    ];
}

/**
 * Controles de coherence physique du CDC 2.4.
 *
 * La ligne de salle finance des jours : la consommation en unites doit egaler le
 * nombre de jours de seance effectivement tenus. La ligne de restauration compte
 * des couverts et non des participants distincts : le controle se fait au jour, en
 * confrontant les couverts imputes au nombre de personnes presentes ce jour-la,
 * participants et encadrement confondus - le formateur en fait partie.
 *
 * Les deux lignes sont nommees par un parametre du projet : leur code differe d'un
 * bailleur a l'autre, comme celui de la provision.
 *
 * @return array<int, array{ligne: string, attendu: float, impute: float, ecart: float, detail: string}>
 */
function coherence_lignes_physiques(?int $projetId = null): array
{
    $pid = $projetId ?? projet_id();
    $controles = [];

    $st = db()->prepare(
        'SELECT COUNT(DISTINCT p.jour) AS jours,
                COUNT(DISTINCT CONCAT(p.jour, ":", p.beneficiaire_id)) AS couverts_participants,
                COUNT(DISTINCT p.session_id) AS sessions
           FROM participations p WHERE p.projet_id = ? AND p.present = 1'
    );
    $st->execute([$pid]);
    $c = $st->fetch();

    foreach ([['ligne_salle_code', 'jours'], ['ligne_couverts_code', 'couverts']] as [$cle, $nature]) {
        $code = param($cle, null, $pid);
        if ($code === null) {
            continue;
        }
        $ligne = budget_ligne($code, $pid);
        if ($ligne === null) {
            continue;
        }
        $impute = budget_consomme_ligne((int)$ligne['id'])['quantite'];
        if ($nature === 'jours') {
            $attendu = (float)$c['jours'];
            $detail = (int)$c['jours'] . ' jour(s) de séance tenus sur ' . (int)$c['sessions'] . ' session(s)';
        } else {
            // Un couvert par personne presente et par jour, encadrement compris :
            // le formateur mange aussi, une seance sans lui n'existe pas.
            $attendu = (float)$c['couverts_participants'] + (float)$c['jours'];
            $detail = (int)$c['couverts_participants'] . ' couvert(s) participants + '
                    . (int)$c['jours'] . ' pour l\'encadrement';
        }
        $controles[] = [
            'ligne'   => $ligne['code'] . ' ' . $ligne['libelle'],
            'attendu' => $attendu,
            'impute'  => $impute,
            'ecart'   => round($impute - $attendu, 2),
            'detail'  => $detail,
        ];
    }
    return $controles;
}

// ---------------------------------------------------------------------
// Registre des versions et correctifs (CDC 3.6)
// ---------------------------------------------------------------------

const MODULES_APPLICATION = ['m1' => 'Module 1', 'm2' => 'Module 2', 'm3' => 'Module 3',
                             'm4' => 'Module 4', 'm5' => 'Module 5'];

function versions_application(?int $projetId = null): array
{
    $st = db()->prepare(
        'SELECT v.*, t.nom AS auteur FROM versions_application v
           JOIN utilisateurs u ON u.id = v.saisi_par JOIN tiers t ON t.id = u.tiers_id
          WHERE v.projet_id = ? ORDER BY v.date DESC, v.id DESC'
    );
    $st->execute([$projetId ?? projet_id()]);
    return $st->fetchAll();
}

/**
 * « En phase 1, les entrees sont saisies au titre des activites 1.1.1 a 1.3.2. En
 * phase 2, elles sont saisies par le Coordinateur au titre de l'activite 3.3.2, le
 * Lead Developpeur ne disposant plus de compte apres la cloture » (CDC 3.6).
 */
function version_application_creer(array $d): array
{
    $numero = trim((string)($d['numero'] ?? ''));
    $nature = (string)($d['nature'] ?? '');
    if ($numero === '' || !array_key_exists($nature, NATURES_VERSION_APP)) {
        return ['success' => false, 'error' => 'Le numéro de version et la nature de l\'intervention sont obligatoires.'];
    }
    if (phase_code() === 'post_cloture' && user_role() !== 'coordinateur') {
        return ['success' => false, 'error' => 'En phase de suivi post-clôture, le registre est tenu par le Coordinateur : '
            . 'le Lead Développeur ne dispose plus de compte après la clôture.'];
    }
    $modules = array_values(array_filter((array)($d['modules_touches'] ?? []),
        fn($m) => array_key_exists($m, MODULES_APPLICATION)));
    try {
        db()->prepare(
            'INSERT INTO versions_application (projet_id, numero, date, nature, modules_touches, canal,
                                               etat_diffusion, verification_google, activite_code, saisi_par)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([projet_id(), $numero, (string)($d['date'] ?? date('Y-m-d')), $nature,
                    $modules ? implode(',', $modules) : null,
                    trim((string)($d['canal'] ?? '')) ?: null,
                    'preparee',
                    (string)($d['verification_google'] ?? '') ?: null,
                    trim((string)($d['activite_code'] ?? '')) ?: null, user_id()]);
        $id = (int)db()->lastInsertId();
    } catch (Throwable $e) {
        return ['success' => false, 'error' => str_contains($e->getMessage(), 'uk_versions_app')
            ? 'La version ' . $numero . ' existe déjà dans ce projet.'
            : 'Enregistrement impossible.'];
    }
    audit('activites', 'version_application', 'version_application', $id,
        $numero . ' · ' . NATURES_VERSION_APP[$nature] . ($modules ? ' · ' . implode(', ', $modules) : ''));
    return ['success' => true, 'id' => $id];
}

/**
 * « Le registre suit egalement l'etat de la verification Google, qui conditionne la
 * publication » (CDC 3.6). Une version de nature publication ne se diffuse donc pas
 * tant que la verification n'est pas validee.
 */
function version_application_diffuser(int $versionId): array
{
    $st = db()->prepare('SELECT * FROM versions_application WHERE id = ? AND projet_id = ?');
    $st->execute([$versionId, projet_id()]);
    $v = $st->fetch();
    if ($v === false) {
        return ['success' => false, 'error' => 'Version inconnue dans ce projet.'];
    }
    if ($v['etat_diffusion'] === 'diffusee') {
        return ['success' => false, 'error' => 'Cette version est déjà diffusée.'];
    }
    if ($v['nature'] === 'publication' && $v['verification_google'] !== 'valide') {
        return ['success' => false, 'error' => 'La vérification Google conditionne la publication : '
            . 'elle est « ' . (VERIFICATIONS_GOOGLE[$v['verification_google'] ?? 'non_soumis'] ?? 'non renseignée') . ' ».'];
    }
    db()->prepare("UPDATE versions_application SET etat_diffusion = 'diffusee' WHERE id = ?")->execute([$versionId]);
    audit('activites', 'version_diffusee', 'version_application', $versionId,
        $v['numero'] . ' · ' . NATURES_VERSION_APP[$v['nature']]);
    return ['success' => true];
}

function version_application_verification(int $versionId, string $etat): array
{
    if (!array_key_exists($etat, VERIFICATIONS_GOOGLE)) {
        return ['success' => false, 'error' => 'État de vérification hors liste.'];
    }
    db()->prepare('UPDATE versions_application SET verification_google = ? WHERE id = ? AND projet_id = ?')
        ->execute([$etat, $versionId, projet_id()]);
    audit('activites', 'verification_google', 'version_application', $versionId, VERIFICATIONS_GOOGLE[$etat]);
    return ['success' => true];
}

// ---------------------------------------------------------------------
// Anomalies
// ---------------------------------------------------------------------

function anomalies(?int $projetId = null): array
{
    $st = db()->prepare(
        'SELECT a.*, o.nom AS declarant, v.numero AS version_numero
           FROM anomalies a
           LEFT JOIN tiers o ON o.id = a.declarant_id
           LEFT JOIN versions_application v ON v.id = a.version_id
          WHERE a.projet_id = ? ORDER BY a.date DESC, a.id DESC'
    );
    $st->execute([$projetId ?? projet_id()]);
    return $st->fetchAll();
}

/**
 * « Son rattachement a une version corrective reste facultatif puisqu'un
 * signalement non encore corrige est l'etat normal d'un ticket » (CDC 8.4).
 */
function anomalie_declarer(array $d): array
{
    if (trim((string)($d['description'] ?? '')) === '') {
        return ['success' => false, 'error' => 'La description de l\'anomalie est obligatoire.'];
    }
    $gravite = (string)($d['gravite'] ?? '');
    if (!array_key_exists($gravite, GRAVITES_ANOMALIE)) {
        return ['success' => false, 'error' => 'Gravité hors liste.'];
    }
    $nature = (string)($d['nature'] ?? 'anomalie');
    if (!array_key_exists($nature, NATURES_ANOMALIE)) {
        return ['success' => false, 'error' => 'Nature hors liste.'];
    }
    db()->prepare(
        'INSERT INTO anomalies (projet_id, declarant_id, date, description, gravite, canal, nature, saisi_par)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([projet_id(), ($d['declarant_id'] ?? null) ? (int)$d['declarant_id'] : null,
                (string)($d['date'] ?? date('Y-m-d')), trim((string)$d['description']), $gravite,
                trim((string)($d['canal'] ?? '')) ?: null, $nature, user_id()]);
    $id = (int)db()->lastInsertId();
    audit('activites', 'anomalie_declaree', 'anomalie', $id,
        GRAVITES_ANOMALIE[$gravite] . ' · ' . mb_substr(trim((string)$d['description']), 0, 80));
    return ['success' => true, 'id' => $id];
}

/**
 * L'accuse de reception. L'engagement de support est de quarante-huit heures
 * ouvrables en phase 2 (annexe H) : le delai est un parametre du projet.
 */
function anomalie_accuser(int $anomalieId, ?string $date = null): array
{
    $st = db()->prepare('SELECT * FROM anomalies WHERE id = ? AND projet_id = ?');
    $st->execute([$anomalieId, projet_id()]);
    if ($st->fetch() === false) {
        return ['success' => false, 'error' => 'Anomalie inconnue dans ce projet.'];
    }
    db()->prepare('UPDATE anomalies SET date_accuse = ? WHERE id = ?')->execute([$date ?? date('Y-m-d'), $anomalieId]);
    audit('activites', 'anomalie_accusee', 'anomalie', $anomalieId, date_fr($date ?? date('Y-m-d')));
    return ['success' => true];
}

function anomalie_resoudre(int $anomalieId, string $reponse, ?int $versionId = null, ?string $date = null): array
{
    $st = db()->prepare('SELECT * FROM anomalies WHERE id = ? AND projet_id = ?');
    $st->execute([$anomalieId, projet_id()]);
    $a = $st->fetch();
    if ($a === false) {
        return ['success' => false, 'error' => 'Anomalie inconnue dans ce projet.'];
    }
    if (trim($reponse) === '') {
        return ['success' => false, 'error' => 'La réponse apportée est obligatoire : c\'est elle qui clôt le signalement.'];
    }
    db()->prepare('UPDATE anomalies SET reponse = ?, date_resolution = ?, version_id = ? WHERE id = ?')
        ->execute([trim($reponse), $date ?? date('Y-m-d'), $versionId, $anomalieId]);
    audit('activites', 'anomalie_resolue', 'anomalie', $anomalieId,
        mb_substr(trim($reponse), 0, 80) . ($versionId ? ' · correctif version ' . $versionId : ''));
    return ['success' => true];
}

/** Les anomalies dont l'accuse de reception a depasse le delai parametre. */
function anomalies_sans_accuse(?int $projetId = null): array
{
    $delai = param('delai_accuse_phase2_heures', null, $projetId);
    if ($delai === null) {
        return [];
    }
    $st = db()->prepare(
        'SELECT id, date, description FROM anomalies
          WHERE projet_id = ? AND date_accuse IS NULL AND date < DATE_SUB(CURDATE(), INTERVAL ? HOUR)
          ORDER BY date'
    );
    $st->execute([$projetId ?? projet_id(), (int)$delai]);
    return $st->fetchAll();
}

// ---------------------------------------------------------------------
// Enquete d'adoption (phase 2)
// ---------------------------------------------------------------------

/**
 * « L'indicateur de l'objectif general, qui demande que neuf des douze
 * organisations utilisent activement l'application trois mois apres leur formation,
 * tombe au mois 10, soit apres la cloture. Il releve du registre d'enquete
 * d'adoption de la phase 2 » (CDC 3.5).
 */
function enquete_saisir(int $organisationId, bool $usageActif, string $observations = '', ?string $date = null): array
{
    $st = db()->prepare("SELECT COUNT(*) FROM tiers WHERE id = ? AND type = 'organisation'");
    $st->execute([$organisationId]);
    if ((int)$st->fetchColumn() === 0) {
        return ['success' => false, 'error' => 'Organisation inconnue au référentiel.'];
    }
    db()->prepare(
        'INSERT INTO enquetes_adoption (projet_id, organisation_id, date, usage_actif, observations, saisi_par)
         VALUES (?,?,?,?,?,?)'
    )->execute([projet_id(), $organisationId, $date ?? date('Y-m-d'), $usageActif ? 1 : 0,
                trim($observations) ?: null, user_id()]);
    $id = (int)db()->lastInsertId();
    audit('activites', 'enquete_adoption', 'enquete_adoption', $id,
        'Organisation ' . $organisationId . ' · ' . ($usageActif ? 'usage actif' : 'sans usage'));
    return ['success' => true, 'id' => $id];
}

function adoption(?int $projetId = null): array
{
    $st = db()->prepare(
        'SELECT COUNT(DISTINCT organisation_id) AS enquetees,
                COUNT(DISTINCT CASE WHEN usage_actif = 1 THEN organisation_id END) AS actives
           FROM enquetes_adoption WHERE projet_id = ?'
    );
    $st->execute([$projetId ?? projet_id()]);
    $c = $st->fetch();
    return ['enquetees' => (int)$c['enquetees'], 'actives' => (int)$c['actives']];
}
