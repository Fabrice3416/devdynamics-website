<?php
declare(strict_types=1);

/**
 * Noyau - comptes utilisateurs, partages entre tous les projets.
 *
 * Une meme personne intervient sur plusieurs projets sans changer d'identite (CDC 1.4),
 * la creation d'un acces est donc un acte d'administration de l'outil et non de projet.
 * Le role, lui, n'est pas ici : il se donne par affectation, projet par projet, sur
 * presentation d'un acte de delegation.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_admin_outil();

$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'creer') {
        $nom = trim((string)($_POST['nom'] ?? ''));
        $fonction = trim((string)($_POST['fonction'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $mandataire = !empty($_POST['est_mandataire']) ? 1 : 0;
        $telephone = trim((string)($_POST['telephone'] ?? ''));

        if ($nom === '' || $fonction === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreur = 'Nom, fonction et email valide sont obligatoires.';
        } else {
            $st = db()->prepare('SELECT COUNT(*) FROM utilisateurs WHERE email = ?');
            $st->execute([$email]);
            if ((int)$st->fetchColumn() > 0) {
                $erreur = 'Un accès existe déjà avec cet email.';
            } else {
                $temp = substr(strtr(base64_encode(random_bytes(12)), '+/', 'Aa'), 0, 12) . '!7';
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('INSERT INTO tiers (type, nom, fonction, est_mandataire, telephone, email) VALUES (\'personne\',?,?,?,?,?)')
                        ->execute([$nom, $fonction, $mandataire, $telephone ?: null, $email]);
                    $tiersId = (int)$pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO utilisateurs (tiers_id, email, mot_de_passe, doit_changer_mdp) VALUES (?,?,?,1)')
                        ->execute([$tiersId, $email, hash_password($temp)]);
                    $uid = (int)$pdo->lastInsertId();
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    error_log('creer utilisateur: ' . $e->getMessage());
                    $erreur = 'Création impossible.';
                }
                if ($erreur === null) {
                    audit('noyau', 'utilisateur_cree', 'utilisateur', $uid, $email . ($mandataire ? ' · mandataire du compte' : ''));
                    flash_set('success', "Accès créé pour $nom. Mot de passe temporaire à transmettre en main propre : $temp (changement imposé à la première connexion). Il faut maintenant l'affecter à un projet.");
                    redirect(base_path('modules/noyau/utilisateurs.php'));
                }
            }
        }
    } elseif ($action === 'actif') {
        $uid = (int)($_POST['id'] ?? 0);
        $actif = (int)($_POST['valeur'] ?? 0) === 1 ? 1 : 0;
        if ($uid === user_id()) {
            $erreur = 'Vous ne pouvez pas désactiver votre propre accès.';
        } else {
            db()->prepare('UPDATE utilisateurs SET actif = ? WHERE id = ?')->execute([$actif, $uid]);
            audit('noyau', $actif ? 'utilisateur_active' : 'utilisateur_desactive', 'utilisateur', $uid);
            flash_set('success', $actif ? 'Accès réactivé.' : 'Accès désactivé.');
            redirect(base_path('modules/noyau/utilisateurs.php'));
        }
    } elseif ($action === 'mandataire') {
        $tid = (int)($_POST['tiers_id'] ?? 0);
        $val = (int)($_POST['valeur'] ?? 0) === 1 ? 1 : 0;
        db()->prepare('UPDATE tiers SET est_mandataire = ? WHERE id = ? AND type = \'personne\'')->execute([$val, $tid]);
        audit('noyau', 'qualite_mandataire', 'tiers', $tid, $val ? 'Mandataire du compte' : 'Retrait de la qualité de mandataire');
        flash_set('success', 'Qualité de mandataire mise à jour (effective à la prochaine connexion de la personne).');
        redirect(base_path('modules/noyau/utilisateurs.php'));
    } elseif ($action === 'reinit') {
        $uid = (int)($_POST['id'] ?? 0);
        $temp = substr(strtr(base64_encode(random_bytes(12)), '+/', 'Aa'), 0, 12) . '!7';
        db()->prepare('UPDATE utilisateurs SET mot_de_passe = ?, doit_changer_mdp = 1 WHERE id = ?')->execute([hash_password($temp), $uid]);
        audit('noyau', 'mot_de_passe_reinitialise', 'utilisateur', $uid);
        flash_set('success', "Mot de passe temporaire : $temp (à transmettre en main propre ; changement imposé à la connexion).");
        redirect(base_path('modules/noyau/utilisateurs.php'));
    }
}

$users = db()->query(
    "SELECT u.*, t.nom, t.fonction, t.est_mandataire, t.telephone,
            (SELECT COUNT(*) FROM specimens s WHERE s.titulaire_id = u.id AND s.date_revocation IS NULL) AS specimen,
            (SELECT GROUP_CONCAT(CONCAT(p.code, ' : ', a.role) ORDER BY p.code SEPARATOR ' · ')
               FROM affectations a JOIN projets p ON p.id = a.projet_id
              WHERE a.utilisateur_id = u.id
                AND a.date_debut <= CURDATE() AND (a.date_fin IS NULL OR a.date_fin >= CURDATE())) AS affectations
       FROM utilisateurs u JOIN tiers t ON t.id = u.tiers_id ORDER BY u.actif DESC, t.nom"
)->fetchAll();
$nbMandataires = (int)db()->query('SELECT COUNT(*) FROM tiers t JOIN utilisateurs u ON u.tiers_id = t.id WHERE t.est_mandataire = 1 AND u.actif = 1')->fetchColumn();

page_start('Personnes et accès', 'administration');
$ongletOutil = 'utilisateurs';
require __DIR__ . '/_nav_outil.php';
?>
<div class="mb-4">
    <h1 class="h4 mb-1">Personnes et accès</h1>
    <p class="text-muted small mb-0">Le référentiel des personnes est partagé : une même personne intervient sur plusieurs projets sans changer d'identité.</p>
</div>
<div class="row g-4">
    <div class="col-lg-8">
        <?php if ($nbMandataires < 3): ?>
        <div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle"></i> <?= $nbMandataires ?> mandataire(s) actif(s). Tout règlement exige deux signatures de mandataires non bénéficiaires : trois sont nécessaires au minimum, un quatrième extérieur au projet est recommandé (CDC 1.6).</div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header bg-white fw-semibold">Accès</div>
            <div class="table-responsive">
            <table class="table table-sm table-striped mb-0 align-middle">
                <thead><tr><th>Personne</th><th>Email</th><th>Affectations</th><th>Mandataire</th><th>Spécimen</th><th>Dernière connexion</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                <tr class="<?= $u['actif'] ? '' : 'text-muted' ?>">
                    <td><?= e($u['nom']) ?><br><small class="text-muted"><?= e($u['fonction']) ?></small></td>
                    <td class="small"><?= e($u['email']) ?></td>
                    <td class="small"><?php if ($u['admin_outil']): ?><span class="badge badge-a-definir">outil</span><br><?php endif; ?><?= e($u['affectations'] ?? '') ?: '<span class="text-muted">aucune</span>' ?></td>
                    <td>
                        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="mandataire"><input type="hidden" name="tiers_id" value="<?= (int)$u['tiers_id'] ?>"><input type="hidden" name="valeur" value="<?= $u['est_mandataire'] ? 0 : 1 ?>">
                            <button class="btn btn-sm <?= $u['est_mandataire'] ? 'btn-primary' : 'btn-outline-secondary' ?>" title="Basculer la qualité de mandataire"><?= $u['est_mandataire'] ? 'Oui' : 'Non' ?></button>
                        </form>
                    </td>
                    <td><?= $u['specimen'] ? '<i class="bi bi-check-circle text-success"></i> déposé' : '<span class="text-muted">aucun</span>' ?></td>
                    <td class="small text-muted"><?= e(datetime_fr($u['derniere_connexion'])) ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ((int)$u['id'] !== user_id()): ?>
                        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="reinit"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary" title="Réinitialiser le mot de passe"><i class="bi bi-key"></i></button></form>
                        <form method="post" class="d-inline" data-confirm="<?= $u['actif'] ? 'Désactiver cet accès ?' : 'Réactiver cet accès ?' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="actif"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="valeur" value="<?= $u['actif'] ? 0 : 1 ?>">
                            <button class="btn btn-sm <?= $u['actif'] ? 'btn-outline-danger' : 'btn-outline-success' ?>"><?= $u['actif'] ? 'Désactiver' : 'Réactiver' ?></button></form>
                        <?php else: ?><small class="text-muted">vous</small><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white fw-semibold">Nouvel accès</div>
            <div class="card-body">
                <?php if ($erreur): ?><div class="alert alert-danger py-2"><?= e($erreur) ?></div><?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="creer">
                    <div class="mb-2"><label class="form-label">Nom complet</label><input name="nom" class="form-control" required maxlength="150"></div>
                    <div class="mb-2"><label class="form-label">Fonction contractuelle</label><input name="fonction" class="form-control" required maxlength="120" placeholder="ex. Responsable Administratif et Financier"></div>
                    <div class="mb-2"><label class="form-label">Email (identifiant)</label><input type="email" name="email" class="form-control" required maxlength="120"></div>
                    <div class="mb-2"><label class="form-label">Téléphone</label><input name="telephone" class="form-control" maxlength="40"></div>
                    <div class="alert alert-info py-2 small">
                        Le rôle ne se donne pas ici. Une fois l'accès créé, affectez la personne à un projet
                        depuis <a href="<?= e(base_path('modules/noyau/projets.php')) ?>">l'administration</a>,
                        sur présentation de son acte de délégation.
                    </div>
                    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="est_mandataire" id="mand" value="1"><label class="form-check-label" for="mand">Mandataire du compte bancaire</label></div>
                    <button class="btn btn-primary">Créer l'accès</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php page_end();
