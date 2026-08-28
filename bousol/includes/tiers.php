<?php
declare(strict_types=1);

/**
 * Module Tiers - les regles du referentiel et du registre des beneficiaires.
 *
 * Ce qui est ici est ce qu'un ecran ne doit pas porter seul : une regle enfermee
 * dans un gabarit n'est atteignable par aucune recette, et c'est ainsi qu'une
 * regle bloquante finit par ne plus bloquer sans que personne s'en apercoive.
 */

require_once __DIR__ . '/calendrier.php';
require_once __DIR__ . '/referentiels.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/audit.php';

/**
 * Inscrit un beneficiaire au registre du projet courant.
 *
 * Le sexe et la tranche d'age ne sont pas facultatifs : le modele de rapport
 * d'activites exige les beneficiaires ventiles par sexe et par jeunesse, et la
 * section des questions transversales attend un rendu sur l'egalite de genre
 * (CDC 3.2). La collecte doit etre annoncee aux personnes concernees.
 *
 * L'inscription d'un mineur exige le televersement d'une autorisation parentale,
 * qui devient une piece du dossier au meme titre qu'une feuille de presence. La
 * piece va au coffre : elle porte le nom d'un mineur et de son representant legal.
 *
 * @param array      $d       organisation_id, nom, fonction, sexe, tranche_age, telephone
 * @param array|null $fichier entree $_FILES de l'autorisation parentale
 * @return array{success: bool, id?: int, error?: string}
 */
function beneficiaire_inscrire(array $d, ?array $fichier = null): array
{
    $nom     = trim((string)($d['nom'] ?? ''));
    $sexe    = (string)($d['sexe'] ?? '');
    $tranche = (string)($d['tranche_age'] ?? '');
    $orgId   = ($d['organisation_id'] ?? null) ? (int)$d['organisation_id'] : null;

    if ($nom === '' || !array_key_exists($sexe, SEXES) || !array_key_exists($tranche, TRANCHES_AGE)) {
        return ['success' => false, 'error' => 'Nom, sexe et tranche d\'âge sont obligatoires : le rapport d\'activités les exige.'];
    }
    if ($orgId !== null) {
        $so = db()->prepare("SELECT COUNT(*) FROM tiers WHERE id = ? AND type = 'organisation'");
        $so->execute([$orgId]);
        if ((int)$so->fetchColumn() === 0) {
            return ['success' => false, 'error' => 'Organisation inconnue au référentiel.'];
        }
    }

    $autorisationId = null;
    if ($tranche === 'moins_18') {
        if (empty($fichier['name'])) {
            return ['success' => false, 'error' => 'L\'inscription d\'un bénéficiaire mineur exige le téléversement de l\'autorisation parentale.'];
        }
        $up = enregistrer_upload($fichier, 'coffre',
            projet_code() . '-AUTORISATION-PARENTALE-' . date('Ymd-His') . '.pdf', ALLOWED_DOCUMENT, true);
        if (!$up['success']) {
            return ['success' => false, 'error' => 'Autorisation parentale : ' . $up['error']];
        }
        $autorisationId = (int)$up['id'];
    }

    try {
        db()->prepare(
            'INSERT INTO beneficiaires (projet_id, organisation_id, nom, fonction, sexe, tranche_age,
                                        autorisation_parentale_fichier_id, telephone)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([projet_id(), $orgId, $nom, trim((string)($d['fonction'] ?? '')) ?: null, $sexe, $tranche,
                    $autorisationId, trim((string)($d['telephone'] ?? '')) ?: null]);
        $id = (int)db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('beneficiaire_inscrire: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Inscription impossible.'];
    }
    audit('tiers', 'beneficiaire_inscrit', 'beneficiaire', $id,
        $nom . ' · ' . SEXES[$sexe] . ' · ' . TRANCHES_AGE[$tranche]
        . ($autorisationId ? ' · autorisation parentale fichier #' . $autorisationId : ''));
    return ['success' => true, 'id' => $id];
}

/** Les mineurs inscrits sans leur autorisation : la liste doit rester vide. */
function beneficiaires_mineurs_sans_autorisation(?int $projetId = null): array
{
    $st = db()->prepare(
        "SELECT id, nom FROM beneficiaires
          WHERE projet_id = ? AND tranche_age = 'moins_18'
            AND autorisation_parentale_fichier_id IS NULL AND actif = 1"
    );
    $st->execute([$projetId ?? projet_id()]);
    return $st->fetchAll();
}
