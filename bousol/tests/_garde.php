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
