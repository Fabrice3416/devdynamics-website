<?php
declare(strict_types=1);

/**
 * Journal d'audit en ajout seul (CDC 7.5).
 * Les triggers SQL interdisent UPDATE et DELETE sur journal_audit.
 * Les identifiants d'objet sont stockes en valeurs (pas de cle etrangere).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/**
 * Ecrit la ligne du journal. Les deux entrees publiques ci-dessous ne different
 * que par ce qu'elles font d'un echec.
 */
function audit_ecrire(
    string $module,
    string $action,
    ?string $objetType = null,
    int|string|null $objetId = null,
    ?string $detail = null,
    ?string $empreinteAvant = null,
    ?string $empreinteApres = null,
    ?int $utilisateurId = null,
    ?string $utilisateurNom = null
): void {
    {
        $uid = $utilisateurId ?? (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
        $nom = $utilisateurNom ?? ($_SESSION['user_nom'] ?? null);
        $stmt = db()->prepare(
            'INSERT INTO journal_audit
               (module, action, objet_type, objet_id, projet_code, detail, utilisateur_id, utilisateur_nom,
                ip, agent, empreinte_avant, empreinte_apres)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $module, $action, $objetType, $objetId === null ? null : (string)$objetId,
            $_SESSION['projet_code'] ?? null,
            $detail, $uid, $nom, client_ip(), client_agent(), $empreinteAvant, $empreinteApres,
        ]);
    }
}

/**
 * Trace ordinaire : une connexion, une consultation, un televersement. Un echec
 * d'audit ne doit pas casser la page, mais doit rester visible au log serveur.
 */
function audit(
    string $module,
    string $action,
    ?string $objetType = null,
    int|string|null $objetId = null,
    ?string $detail = null,
    ?string $empreinteAvant = null,
    ?string $empreinteApres = null,
    ?int $utilisateurId = null,
    ?string $utilisateurNom = null
): void {
    try {
        audit_ecrire($module, $action, $objetType, $objetId, $detail, $empreinteAvant, $empreinteApres, $utilisateurId, $utilisateurNom);
    } catch (Throwable $e) {
        error_log('journal_audit insert failed: ' . $e->getMessage());
    }
}

/**
 * Trace d'un mouvement d'argent : reglement, ecriture, reallocation budgetaire.
 * L'exception remonte, donc la transaction qui l'entoure est annulee : aucun
 * mouvement ne subsiste sans la trace qui dit qui l'a fait et quand.
 *
 * Le CDC 2.2 fait reposer tout l'historique du budget sur ce journal - « cette
 * trace suffit a etablir qui a realloue quoi et quand, sans qu'il soit necessaire
 * de conserver des versions successives ». Une trace qui peut se perdre en silence
 * ruinerait cette construction. A n'employer qu'a l'interieur d'une transaction.
 */
function audit_strict(
    string $module,
    string $action,
    ?string $objetType = null,
    int|string|null $objetId = null,
    ?string $detail = null,
    ?string $empreinteAvant = null,
    ?string $empreinteApres = null,
    ?int $utilisateurId = null,
    ?string $utilisateurNom = null
): void {
    audit_ecrire($module, $action, $objetType, $objetId, $detail, $empreinteAvant, $empreinteApres, $utilisateurId, $utilisateurNom);
}
