<?php
declare(strict_types=1);

/**
 * Service de rendu documentaire (annexe E).
 *
 * « Le service de rendu documentaire ne possede aucune donnee propre et n'est donc
 * pas un module » (CDC 7.2) : c'est une couche mince, appelee par les modules qui
 * produisent des documents, et qui ne connait d'eux que ce qu'ils lui passent.
 *
 * Deux regimes, et le parametre du projet tranche. En regime papier, le document
 * genere s'imprime, se signe a la main et revient scanne : c'est le scan qui
 * satisfait la case de la checklist. En regime electronique, le document entre
 * dans la file de signature et la case est satisfaite quand toutes les appositions
 * attendues sont posees. Le CDC retient le papier par defaut.
 */

require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/signature.php';
require_once __DIR__ . '/../pdf/generate.php';

/**
 * Genere un document du catalogue et l'enregistre.
 *
 * @param string $type      code de DOCUMENTS_GENERES
 * @param array  $donnees   variables du gabarit
 * @param string $objetType type de l'objet porteur (dossier, prestation, rapprochement...)
 * @param int    $objetId   son identifiant
 * @return array{success: bool, document_id?: int, fichier_id?: int, error?: string}
 */
function document_generer(string $type, array $donnees, string $objetType, int $objetId, string $module,
                          string $exemplaire = ''): array
{
    $def = DOCUMENTS_GENERES[$type] ?? null;
    if ($def === null) {
        return ['success' => false, 'error' => 'Document hors du catalogue de l\'annexe E.'];
    }
    if ($def[2] === 'papier_scanne') {
        return ['success' => false, 'error' => 'Ce document est établi sur papier par un tiers : '
            . 'l\'outil ne le génère pas, il le reçoit numérisé.'];
    }

    $regime = param('regime_signature_defaut', 'papier');
    $svc = new PdfService();
    $nom = projet_code() . '-' . strtoupper($type) . '-' . $objetId
         . ($exemplaire !== '' ? '-' . preg_replace('/[^A-Za-z0-9]+/', '', $exemplaire) : '') . '.pdf';
    $res = $svc->generer($type, $donnees, $nom, $exemplaire);
    if (empty($res['success'])) {
        return ['success' => false, 'error' => $res['error'] ?? 'Génération impossible.'];
    }

    // Un document a signer entre dans la file ; un document sans signataire
    // applicatif est produit et classe tel quel.
    $signataires = array_filter($def[1], fn($r) => !in_array($r, ['beneficiaire', 'prestataire'], true));
    $statut = ($regime === 'electronique' && $signataires) ? 'a_signer' : 'brouillon';
    $documentId = creer_document($type, $module, $objetType, $objetId, (int)$res['id'], $statut, $regime);

    audit($module, 'document_genere', 'document', $documentId,
        $def[0] . ' · ' . $objetType . ':' . $objetId . ' · régime ' . $regime
        . ($exemplaire !== '' ? ' · ' . $exemplaire : '')
        . ($statut === 'a_signer' ? ' · ' . count($signataires) . ' signature(s) attendue(s)' : ''));
    return ['success' => true, 'document_id' => $documentId, 'fichier_id' => (int)$res['id'], 'statut' => $statut];
}

/**
 * Les mentions d'exemplaire du projet. « Impression : trois exemplaires avec
 * mention d'exemplaire » (annexe H), et la mention varie selon le bailleur : un
 * pour l'organisation et deux pour l'UGP sur KesKle, un pour l'organisation et deux
 * pour la FOKAL dont un pour l'Union europeenne sur Koule Ki Pale.
 *
 * @return string[] les mentions, ou une liste vide si le parametre n'est pas saisi
 */
function mentions_exemplaires(?int $projetId = null): array
{
    $brut = param('exemplaires_mention', null, $projetId);
    if ($brut === null || trim($brut) === '') {
        return [];
    }
    $mentions = [];
    foreach (explode('|', $brut) as $i => $m) {
        $m = trim($m);
        if ($m !== '') {
            $mentions[] = 'Exemplaire ' . ($i + 1) . ' — ' . $m;
        }
    }
    return $mentions;
}

/** Le dernier document d'un type produit pour un objet. */
function document_de(string $type, string $objetType, int $objetId): ?array
{
    $st = db()->prepare(
        'SELECT d.*, f.nom_genere, f.empreinte,
                (SELECT COUNT(*) FROM appositions a WHERE a.document_id = d.id) AS nb_appositions
           FROM documents d LEFT JOIN fichiers f ON f.id = d.fichier_id
          WHERE d.type = ? AND d.objet_type = ? AND d.objet_id = ? AND d.projet_code = ?
          ORDER BY d.id DESC LIMIT 1'
    );
    $st->execute([$type, $objetType, $objetId, projet_code()]);
    $d = $st->fetch();
    return $d === false ? null : $d;
}

/**
 * Un document signable est-il completement signe ? En regime papier la question ne
 * se pose pas : c'est le scan qui fait foi, et la case de checklist l'attend.
 */
function document_signe(array $document): bool
{
    if (($document['regime'] ?? 'papier') !== 'electronique') {
        return false;
    }
    return (int)$document['nb_appositions'] >= signatures_attendues((string)$document['type']);
}

/**
 * Les documents que l'outil sait produire pour une case de checklist. Les autres
 * cases attendent une piece etablie par un tiers - une facture, un recu - que
 * l'outil ne genere pas et ne pourrait pas generer sans la fabriquer.
 */
function piece_generable(string $typePiece): bool
{
    $def = DOCUMENTS_GENERES[$typePiece] ?? null;
    return $def !== null && $def[2] !== 'papier_scanne' && is_file(root_dir() . '/pdf/templates/' . $typePiece . '.php');
}
