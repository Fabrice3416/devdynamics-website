<?php
declare(strict_types=1);

/**
 * Garde-fou commun aux recettes.
 *
 * Une recette n'est pas un controle de sante : elle ecrit. Elle cree des tiers, des
 * dossiers, des imputations, deplace du budget de gestion, et depose des entrees au
 * journal d'audit - lequel est en ajout seul, donc impossible a nettoyer ensuite.
 * Lancee par megarde sur la base de production, elle y laisse des traces definitives.
 *
 * D'ou deux verrous : la ligne de commande seulement, et un accord explicite qui
 * nomme la base visee.
 *
 *   BOUSOL_RECETTE=oui php bousol/tests/recette_phase2.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI seulement\n");
}

/**
 * Sur un mutualise, le PHP en ligne de commande tourne souvent avec display_errors
 * a Off : une exception non rattrapee arrete alors la recette sans un mot, et on
 * cherche la panne la ou elle n'est pas. Une recette muette est pire qu'une recette
 * qui echoue, donc on force l'affichage et on nomme ce qui l'a interrompue.
 */
ini_set('display_errors', 'stderr');
ini_set('log_errors', '1');
error_reporting(E_ALL);
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, "\n INTERROMPU  " . get_class($e) . "\n"
        . '             ' . $e->getMessage() . "\n"
        . '             ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(3);
});

function recette_garde(string $titre): void
{
    $base = (string)db()->query('SELECT DATABASE()')->fetchColumn();
    echo "=== $titre\n";
    echo "Base visee : $base\n";
    if (getenv('BOUSOL_RECETTE') !== 'oui') {
        echo "\nREFUS : cette recette ecrit dans la base et son passage au journal d'audit\n"
           . "est irreversible. Verifier qu'il s'agit bien d'une base de TEST, puis relancer :\n\n"
           . "    BOUSOL_RECETTE=oui php " . ($_SERVER['argv'][0] ?? 'bousol/tests/recette.php') . "\n\n"
           . "Pour viser une autre base que celle du site, pointer sa configuration :\n\n"
           . "    BOUSOL_CONFIG=/chemin/bousol-config-test.php BOUSOL_RECETTE=oui php ...\n";
        exit(2);
    }
    echo str_repeat('-', 60) . "\n";
}

/**
 * Une etape de mise en place - creer un tiers, poser une imputation - n'est pas un
 * cas de recette, mais si elle casse, tout ce qui suit devient faux. On la rend
 * visible comme un cas et on continue plutot que d'arreter la recette sur place.
 *
 * @return mixed la valeur rendue par $f, ou null si elle a leve
 */
function etape(string $lib, callable $f): mixed
{
    try {
        $v = $f();
        cas($lib, true);
        return $v;
    } catch (Throwable $e) {
        cas($lib, false, get_class($e) . ' : ' . $e->getMessage());
        return null;
    }
}

/**
 * Nettoyage commun a toutes les recettes.
 *
 * Chacune nettoyait ses propres traces, ce qui ne suffit pas : elles partagent une
 * base et se marchent dessus. La recette de la phase 3 impute 3 325 gourdes sur la
 * ligne 2.2 et laisse cette imputation derriere elle ; la recette de la phase 2,
 * lancee ensuite, trouve alors 5 325 gourdes consommees sur une ligne qui en porte
 * 3 325, et son cas « second versement sur une ligne au forfait » echoue pour une
 * raison qui n'a rien a voir avec ce qu'il teste.
 *
 * Une recette doit pouvoir se lancer dans n'importe quel ordre, autant de fois
 * qu'on veut. Le nettoyage est donc commun et couvre les trois phases.
 *
 * Le journal d'audit garde ses entrees : il est en ajout seul, et c'est voulu.
 */
function recette_nettoyer(PDO $pdo): void
{
    $etapes = [
        // Comptes : les mouvements avant les ecritures, les ecritures avant les reglements.
        "DELETE m FROM mouvements m JOIN ecritures e ON e.id = m.ecriture_id
          WHERE e.origine_ref LIKE 'REC3-%' OR e.libelle LIKE 'REC3-%'",
        "DELETE m FROM mouvements m JOIN ecritures e ON e.id = m.ecriture_id
           JOIN reglements r ON r.id = e.reglement_id
          WHERE r.objet LIKE 'REC3-%' OR r.origine_ref LIKE 'renflouement:%'",
        "DELETE FROM ecritures WHERE origine_ref LIKE 'REC3-%' OR libelle LIKE 'REC3-%'",
        "DELETE e FROM ecritures e JOIN reglements r ON r.id = e.reglement_id
          WHERE r.objet LIKE 'REC3-%' OR r.origine_ref LIKE 'renflouement:%'",
        "DELETE v FROM validations_reglement v JOIN reglements r ON r.id = v.reglement_id
          WHERE r.objet LIKE 'REC3-%' OR r.origine_ref LIKE 'renflouement:%'",
        "DELETE FROM reglements WHERE objet LIKE 'REC3-%' OR origine_ref LIKE 'renflouement:%'",
        "DELETE FROM lignes_rapprochement WHERE objet LIKE 'REC3-%'",
        "DELETE lr FROM lignes_rapprochement lr JOIN rapprochements ra ON ra.id = lr.rapprochement_id
          WHERE ra.date_releve = '2026-06-30'",
        "DELETE FROM rapprochements WHERE date_releve = '2026-06-30'",
        "DELETE FROM arretes_caisse WHERE commentaire LIKE 'REC3-%' OR date = '2026-06-30'",

        // Bascule : le projet revient en execution, ses reouvertures disparaissent.
        "DELETE FROM reouvertures WHERE projet_id = 1",
        "DELETE FROM liasses WHERE type = 'classement' AND projet_id = 1",
        "UPDATE phases SET statut = CASE code WHEN 'projet_actif' THEN 'en_cours' ELSE 'a_venir' END
          WHERE projet_id = 1",

        // Financement : les pieces avant les demandes, et les tranches se rouvrent.
        "DELETE p FROM pieces_demande p JOIN demandes_paiement d ON d.id = p.demande_id
          WHERE d.projet_id = 1",
        "DELETE FROM demandes_paiement WHERE projet_id = 1",
        "DELETE m FROM mouvements m JOIN ecritures e ON e.id = m.ecriture_id WHERE e.origine_ref LIKE 'tranche:%'",
        "DELETE FROM ecritures WHERE origine_ref LIKE 'tranche:%'",
        "UPDATE tranches SET montant_recu = NULL, date_reception = NULL, avis_credit_fichier_id = NULL,
                             ecriture_ref = NULL WHERE projet_id = 1",
        "UPDATE sources_revenu SET montant_acquis = 0, statut = 'en_cours' WHERE projet_id = 1",
        "DELETE FROM sources_revenu WHERE libelle LIKE 'REC8 %'",

        // Restitution : les lignes avant les rapports, les liasses avant tout.
        "DELETE FROM liasses WHERE fichier_id IN (SELECT id FROM fichiers WHERE nom_genere LIKE '%LIASSE%')",
        "DELETE lf FROM lignes_financieres lf JOIN rapports r ON r.id = lf.rapport_id
          WHERE r.contenu_json LIKE '%REC7%'",
        "DELETE FROM rapports WHERE contenu_json LIKE '%REC7%'",
        "UPDATE periodes SET statut = 'ouverte', figee_le = NULL, figee_par = NULL WHERE projet_id = 1",
        "UPDATE versions_cadre SET figee = 0 WHERE projet_id = 1",

        // Activites : participations, sessions, puis ce qui les porte.
        "DELETE p FROM participations p JOIN sessions_formation s ON s.id = p.session_id
          WHERE s.lieu LIKE 'REC6 %'",
        "DELETE FROM sessions_formation WHERE lieu LIKE 'REC6 %'",
        "DELETE FROM difficultes WHERE description LIKE 'REC6 %'",
        "DELETE r FROM releves r JOIN indicateurs i ON i.id = r.indicateur_id WHERE i.libelle LIKE 'REC6 %'",
        "DELETE FROM indicateurs WHERE libelle LIKE 'REC6 %'",
        "DELETE FROM activites WHERE code LIKE 'REC6%'",
        "DELETE FROM cadre_elements WHERE code LIKE 'REC6%'",
        "DELETE FROM releves WHERE version_id IN (SELECT id FROM versions_cadre WHERE motif LIKE 'REC6 %')",
        "DELETE FROM versions_cadre WHERE motif LIKE 'REC6 %'",
        "DELETE FROM anomalies WHERE description LIKE 'REC6 %'",
        "DELETE FROM versions_application WHERE numero LIKE 'REC6%'",
        "DELETE FROM enquetes_adoption WHERE observations LIKE 'REC6 %'",

        // Documents produits par les recettes : le fichier ne se supprime pas, mais
        // le document, lui, n'est qu'un rattachement.
        "DELETE FROM documents WHERE objet_type IN ('rapport_execution','versement_dgi')
          AND objet_id NOT IN (SELECT id FROM rapports_execution) AND objet_type = 'rapport_execution'",
        "DELETE d FROM documents d JOIN rapports_execution r ON r.id = d.objet_id
          JOIN contrats c ON c.id = r.contrat_id JOIN tiers t ON t.id = c.tiers_id
          WHERE d.objet_type = 'rapport_execution' AND t.nom LIKE 'REC5 %'",
        "DELETE d FROM documents d JOIN dossiers dd ON dd.id = d.objet_id
          WHERE d.objet_type = 'dossier' AND (dd.objet LIKE 'REC4-%' OR dd.objet LIKE '%REC5 %'
             OR dd.objet LIKE 'Acomptes retenus du mois 9%')",

        // Remuneration : prestations, rapports et versements avant les contrats.
        "DELETE m FROM mouvements m JOIN ecritures e ON e.id = m.ecriture_id WHERE e.origine_ref LIKE 'prestation:%'",
        "DELETE FROM ecritures WHERE origine_ref LIKE 'prestation:%'",
        "DELETE p FROM prestations p JOIN contrats c ON c.id = p.contrat_id JOIN tiers t ON t.id = c.tiers_id WHERE t.nom LIKE 'REC5 %'",
        "DELETE r FROM rapports_execution r JOIN contrats c ON c.id = r.contrat_id JOIN tiers t ON t.id = c.tiers_id WHERE t.nom LIKE 'REC5 %'",
        "DELETE FROM versements_dgi WHERE mois >= 90",
        "DELETE FROM contrats WHERE fonction LIKE 'REC5 %'",

        // Depenses. Les dossiers de la phase 4 portent un numero DOS-, comme les
        // vrais : on les retrouve par leur objet, sans risquer d'emporter un
        // dossier reel de la base de test.
        "DELETE v FROM validations_reglement v JOIN reglements r ON r.id = v.reglement_id WHERE r.objet LIKE '%REC4-%'",
        "DELETE m FROM mouvements m JOIN ecritures e ON e.id = m.ecriture_id
           JOIN reglements r ON r.id = e.reglement_id WHERE r.objet LIKE '%REC4-%'",
        "DELETE e FROM ecritures e JOIN reglements r ON r.id = e.reglement_id WHERE r.objet LIKE '%REC4-%'",
        "DELETE FROM reglements WHERE objet LIKE '%REC4-%'",
        "DELETE p FROM pieces p JOIN dossiers d ON d.id = p.dossier_id WHERE d.objet LIKE 'REC4-%'",
        "DELETE p FROM proformas p JOIN dossiers d ON d.id = p.dossier_id WHERE d.objet LIKE 'REC4-%'",
        "DELETE i FROM imputations i JOIN dossiers d ON d.id = i.dossier_id WHERE d.objet LIKE 'REC4-%'",
        "DELETE FROM dossiers WHERE objet LIKE 'REC4-%'",
        "DELETE p FROM pieces p JOIN dossiers d ON d.id = p.dossier_id WHERE d.objet LIKE 'REC7 %'",
        "DELETE i FROM imputations i JOIN dossiers d ON d.id = i.dossier_id WHERE d.objet LIKE 'REC7 %'",
        "DELETE FROM dossiers WHERE objet LIKE 'REC7 %' OR numero LIKE 'REC7-%'",
        "DELETE p FROM pieces p JOIN dossiers d ON d.id = p.dossier_id WHERE d.objet LIKE '%REC5 %' OR d.objet LIKE 'Acomptes retenus du mois 9%'",
        "DELETE i FROM imputations i JOIN dossiers d ON d.id = i.dossier_id WHERE d.objet LIKE '%REC5 %' OR d.objet LIKE 'Acomptes retenus du mois 9%'",
        "DELETE FROM dossiers WHERE objet LIKE '%REC5 %' OR objet LIKE 'Acomptes retenus du mois 9%'",

        // Les imputations de toutes les recettes, quel que soit leur prefixe.
        "DELETE i FROM imputations i JOIN dossiers d ON d.id = i.dossier_id WHERE d.numero LIKE 'REC%'",
        "DELETE FROM dossiers WHERE numero LIKE 'REC%'",

        // Scenarios de bout en bout : appositions, documents, puis les dossiers.
        // Les pieces pointent vers leurs documents : on defait le lien avant.
        "DELETE a FROM appositions a JOIN documents d ON d.id = a.document_id
           JOIN dossiers dd ON dd.id = d.objet_id
          WHERE d.objet_type = 'dossier' AND dd.objet LIKE 'RECB %'",
        "UPDATE pieces p JOIN dossiers d ON d.id = p.dossier_id SET p.document_id = NULL
          WHERE d.objet LIKE 'RECB %'",
        "DELETE d FROM documents d JOIN dossiers dd ON dd.id = d.objet_id
          WHERE d.objet_type = 'dossier' AND dd.objet LIKE 'RECB %'",
        "DELETE FROM documents WHERE type = 'bon_reception' AND objet_type = 'dossier' AND objet_id = 0",
        "DELETE p FROM pieces p JOIN dossiers d ON d.id = p.dossier_id WHERE d.objet LIKE 'RECB %'",
        "DELETE i FROM imputations i JOIN dossiers d ON d.id = i.dossier_id WHERE d.objet LIKE 'RECB %'",
        "DELETE FROM dossiers WHERE objet LIKE 'RECB %'",
        // Les parametres ne se nettoient pas : un trigger refuse la suppression, le
        // registre etant en ajout seul (annexe F). La recette des scenarios rend
        // leurs valeurs comme le ferait le Coordinateur, en versant une version
        // nouvelle - et ses assertions comptent des ecarts, non des totaux.

        // Tiers : beneficiaires puis personnes.
        "DELETE FROM beneficiaires WHERE nom LIKE 'REC2 %'",
        "DELETE FROM tiers WHERE nom LIKE 'REC3 %'",
        "DELETE FROM tiers WHERE nom LIKE 'REC5 %'",
        "DELETE FROM tiers WHERE nom IN ('Fournisseur Recette', 'Doublon Recette', 'Sans NIF 1', 'Sans NIF 2')",
        "UPDATE tiers SET nif = NULL WHERE nif = '001-234-567-8'",

        // Budget : le budget de gestion repart du contractuel, comme au chargement du seed.
        'UPDATE lignes_budgetaires SET montant_gestion = montant, quantite_gestion = quantite',
    ];
    foreach ($etapes as $q) {
        try {
            $pdo->exec($q);
        } catch (Throwable $e) {
            // Une trace absente est le cas nominal, et une cle etrangere qui retient
            // une ligne se verra a l'etape suivante.
        }
    }
}
