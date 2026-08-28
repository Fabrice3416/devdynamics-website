<?php
declare(strict_types=1);

/**
 * Capture des erreurs a la frontiere du module (CDC 7.2).
 *
 * « Une exception produit une erreur circonscrite et non une page d'erreur
 * globale, et le journal d'audit enregistre l'incident avec son module
 * d'origine. »
 *
 * Sans cela, une erreur de programmation se traduit en production par une page
 * blanche ou, pire, par une page coupee en plein milieu : l'hebergement mutualise
 * tourne avec display_errors a Off, et le message part dans un log que personne ne
 * lit. C'est exactement ce qui a rendu le module Budget muet le 28/08/2026.
 *
 * Le visiteur ne voit jamais le detail technique - il pourrait renseigner un
 * attaquant - mais il recoit une reference courte, que le Coordinateur retrouve au
 * journal d'audit et dans le log du serveur.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/referentiels.php';   // MODULES : le libelle du module fautif

/** Le module d'ou vient l'incident, deduit du script appele. */
function module_courant(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
    return preg_match('#/modules/([a-z_]+)/#', $script, $m) ? $m[1] : 'noyau';
}

/**
 * Rend l'incident au visiteur et le consigne. Deux cas : ou rien n'a encore ete
 * envoye et l'on sert une page complete, ou la reponse est deja commencee - une
 * page coupee au milieu de son tableau - et l'on ne peut qu'y accoler un bandeau.
 */
function erreur_bousol(Throwable $e, string $nature = 'exception'): void
{
    static $deja = false;
    if ($deja) {
        return;
    }
    $deja = true;

    $module = module_courant();
    $ref    = strtoupper(bin2hex(random_bytes(3)));
    $detail = get_class($e) . ' : ' . $e->getMessage()
            . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();

    // Le log serveur d'abord : il reste lisible meme si c'est la base qui a lache.
    error_log('Bousol [' . $module . '] ' . $ref . ' ' . $detail);
    try {
        audit($module, 'erreur_' . $nature, 'incident', $ref, $detail);
    } catch (Throwable $ignore) {
        // Une panne de base ne doit pas empecher la page d'erreur de s'afficher.
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo page_erreur($module, $ref);
        return;
    }
    echo bandeau_erreur($module, $ref);
}

/** Page complete, dans la sobriete du reste de l'outil : pas de bleu, pas d'aplat. */
function page_erreur(string $module, string $ref): string
{
    $lib = MODULES[$module][0] ?? $module;
    $accueil = base_path('dashboard.php');
    return '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Incident &middot; Bousòl</title>'
        . '<style>body{margin:0;background:#f6f4ef;color:#2a2a28;font-family:Candara,Optima,"Segoe UI",sans-serif;'
        . 'display:flex;min-height:100vh;align-items:center;justify-content:center;padding:2rem}'
        . '.b{background:#fffdf9;border:1px solid #eae5db;border-radius:16px;box-shadow:0 2px 12px rgba(0,0,0,.05);'
        . 'padding:2.25rem;max-width:34rem}h1{font-family:"Palatino Linotype",Palatino,Georgia,serif;color:#4c5a47;'
        . 'font-size:1.4rem;margin:0 0 .75rem}p{line-height:1.55;margin:.6rem 0}code{background:#efebe2;'
        . 'border-radius:6px;padding:.15rem .45rem;font-size:.95em}a{color:#4c5a47}</style></head><body><div class="b">'
        . '<h1>Le module ' . e($lib) . ' a rencontré un incident</h1>'
        . '<p>L\'opération n\'a pas abouti. Rien n\'a été enregistré à moitié : '
        . 'les écritures de Bousòl sont transactionnelles.</p>'
        . '<p>L\'incident est consigné au journal d\'audit sous la référence <code>' . e($ref) . '</code>. '
        . 'Communiquez-la au Coordinateur, elle suffit à retrouver la cause.</p>'
        . '<p>Les autres modules restent utilisables. <a href="' . e($accueil) . '">Retour au tableau de bord</a></p>'
        . '</div></body></html>';
}

/** La reponse etait deja commencee : on signale au moins que la page est incomplete. */
function bandeau_erreur(string $module, string $ref): string
{
    $lib = MODULES[$module][0] ?? $module;
    return '<div style="margin:1.5rem;padding:1rem 1.25rem;border:1px solid #eae5db;border-radius:16px;'
        . 'background:#fffdf9;color:#2a2a28;font-family:Candara,Optima,\'Segoe UI\',sans-serif">'
        . '<strong style="color:#4c5a47">Page incomplète.</strong> Le module ' . e($lib)
        . ' a rencontré un incident en cours d\'affichage : ce qui précède peut être tronqué. '
        . 'Référence <code style="background:#efebe2;border-radius:6px;padding:.15rem .45rem">' . e($ref) . '</code>.'
        . '</div>';
}

/**
 * En ligne de commande, les recettes et le diagnostic posent leurs propres
 * gardiens : on ne leur vole pas leurs exceptions.
 */
if (PHP_SAPI !== 'cli') {
    set_exception_handler(static fn(Throwable $e) => erreur_bousol($e, 'exception'));
    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            erreur_bousol(new ErrorException($err['message'], 0, $err['type'], $err['file'], (int)$err['line']), 'fatale');
        }
    });
}
