<?php
declare(strict_types=1);

/**
 * Journal d'audit en ajout seul (CDC 7.5).
 * Les triggers SQL interdisent UPDATE et DELETE sur journal_audit.
 * Les identifiants d'objet sont stockes en valeurs (pas de cle etrangere).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

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
    } catch (Throwable $e) {
        // Un echec d'audit ne doit jamais casser la page, mais doit etre visible dans les logs serveur.
        error_log('journal_audit insert failed: ' . $e->getMessage());
    }
}
