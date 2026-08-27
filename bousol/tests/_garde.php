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
