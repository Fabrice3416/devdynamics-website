<?php
declare(strict_types=1);

/**
 * Administration de l'outil : creation des projets et affectation des coordinateurs.
 *
 * Reserve a l'administrateur de l'outil, seul habilite a creer un projet, a y designer
 * son coordinateur et a prononcer sa cloture definitive (CDC 1.8). Sans lui, le
 * coordinateur d'un projet pourrait s'attribuer l'acces a un autre.
 *
 * Aucune affectation n'est possible sans acte de delegation televerse : la trace devient
 * probante plutot que declarative.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/uploads.php';
require_admin_outil();

$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'creer') {
        $code       = strtoupper(trim((string)($_POST['code'] ?? '')));
        $intitule   = trim((string)($_POST['intitule'] ?? ''));
        $bailleur   = trim((string)($_POST['bailleur'] ?? ''));
        $referentiel = trim((string)($_POST['referentiel'] ?? ''));

        if (!preg_match('/^[A-Z0-9]{2,20}$/', $code)) {
            $erreur = 'Le code court n\'accepte que des lettres majuscules et des chiffres, de 2 à 20 caractères.';
        } elseif ($intitule === '' || $bailleur === '' || $referentiel === '') {
            $erreur = 'Intitulé, bailleur et référentiel applicable sont obligatoires.';
        } else {
            $st = db()->prepare('SELECT COUNT(*) FROM projets WHERE code = ?');
            $st->execute([$code]);
            if ((int)$st->fetchColumn() > 0) {
                $erreur = 'Ce code court est déjà utilisé.';
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('INSERT INTO projets (code, intitule, bailleur, referentiel, statut, cree_par) VALUES (?,?,?,?,\'actif\',?)')
                        ->execute([$code, $intitule, $bailleur, $referentiel, user_id()]);
                    $pid = (int)$pdo->lastInsertId();
                    // Les trois phases du projet. La double temporalite s'active ensuite par parametre.
                    $ph = $pdo->prepare('INSERT INTO phases (projet_id, code, statut) VALUES (?,?,?)');
                    $ph->execute([$pid, 'projet_actif', 'en_cours']);
                    $ph->execute([$pid, 'regularisation', 'a_venir']);
                    $ph->execute([$pid, 'post_cloture', 'a_venir']);
                    // Registre de parametres du projet, aux valeurs initiales de l'annexe F.
                    $pa = $pdo->prepare('INSERT INTO parametres (projet_id, cle, valeur, date_effet, motif, auteur_id) VALUES (?,?,?,CURDATE(),?,?)');
                    foreach (PARAMETRES_INITIAUX as $cle => $valeur) {
                        $pa->execute([$pid, $cle, $valeur, 'Création du projet', user_id()]);
                    }
                    // Plan de comptes de base, un par projet.
                    $co = $pdo->prepare('INSERT INTO comptes (projet_id, code, libelle, type) VALUES (?,?,?,?)');
                    foreach (COMPTES_INITIAUX as [$c, $lib, $type]) {
                        $co->execute([$pid, $c, $lib, $type]);
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    error_log('creation projet: ' . $e->getMessage());
                    $erreur = 'Création impossible.';
                }
                if ($erreur === null) {
                    audit('noyau', 'projet_cree', 'projet', $pid, $code . ' · ' . $intitule . ' · ' . $bailleur);
                    flash_set('success', "Projet $code créé. Désignez son coordinateur, puis il chargera la nomenclature et les paramètres.");
                    redirect(base_path('modules/noyau/projets.php'));
                }
            }
        }
    } elseif ($action === 'affecter') {
        $pid  = (int)($_POST['projet_id'] ?? 0);
        $uid  = (int)($_POST['utilisateur_id'] ?? 0);
        $role = (string)($_POST['role'] ?? '');
        $debut = (string)($_POST['date_debut'] ?? date('Y-m-d'));
        $fin   = trim((string)($_POST['date_fin'] ?? ''));

        if (!in_array($role, ROLES, true) || $pid <= 0 || $uid <= 0) {
            $erreur = 'Projet, personne et rôle sont obligatoires.';
        } elseif (empty($_FILES['acte']['name'])) {
            $erreur = 'L\'acte de délégation d\'autorité est obligatoire : sans acte, pas d\'affectation.';
        } elseif ($fin !== '' && $fin < $debut) {
            $erreur = 'La date de fin précède la date de début.';
        } else {
            $st = db()->prepare('SELECT code FROM projets WHERE id = ?');
            $st->execute([$pid]);
            $codeProjet = (string)$st->fetchColumn();
            $acte = enregistrer_upload($_FILES['acte'], 'coffre',
                $codeProjet . '-DELEGATION-' . $role . '-' . date('Ymd') . '.pdf', ALLOWED_DOCUMENT, true);
            if (!$acte['success']) {
                $erreur = 'Acte de délégation : ' . $acte['error'];
            } else {
                try {
                    db()->prepare(
                        'INSERT INTO affectations (utilisateur_id, projet_id, role, acte_delegation_fichier_id, date_debut, date_fin, affecte_par)
                         VALUES (?,?,?,?,?,?,?)'
                    )->execute([$uid, $pid, $role, $acte['id'], $debut, $fin ?: null, user_id()]);
                    audit('noyau', 'affectation_creee', 'projet', $pid, 'Utilisateur ' . $uid . ' · ' . $role . ' · acte ' . $acte['empreinte']);
                    flash_set('success', 'Affectation enregistrée, appuyée sur son acte de délégation.');
                    redirect(base_path('modules/noyau/projets.php'));
                } catch (Throwable $e) {
                    $erreur = str_contains($e->getMessage(), 'uk_affectation')
                        ? 'Cette personne tient déjà ce rôle sur ce projet.'
                        : 'Affectation impossible.';
                }
            }
        }
    } elseif ($action === 'clore') {
        $pid = (int)($_POST['projet_id'] ?? 0);
        db()->prepare("UPDATE projets SET statut = 'clos' WHERE id = ? AND statut = 'actif'")->execute([$pid]);
        audit('noyau', 'projet_clos', 'projet', $pid, 'Clôture définitive prononcée');
        flash_set('success', 'Clôture définitive prononcée.');
        redirect(base_path('modules/noyau/projets.php'));
    }
}

$projets = db()->query(
    'SELECT p.*, (SELECT COUNT(*) FROM affectations a WHERE a.projet_id = p.id) affectations,
            (SELECT COUNT(*) FROM lignes_budgetaires l WHERE l.projet_id = p.id) lignes,
            (SELECT COUNT(*) FROM dossiers d WHERE d.projet_id = p.id) dossiers
       FROM projets p ORDER BY p.statut, p.intitule'
)->fetchAll();
$utilisateurs = db()->query(
    'SELECT u.id, u.email, t.nom FROM utilisateurs u JOIN tiers t ON t.id = u.tiers_id WHERE u.actif = 1 ORDER BY t.nom'
)->fetchAll();
$affectations = db()->query(
    'SELECT a.*, t.nom, p.code AS projet_code FROM affectations a
       JOIN utilisateurs u ON u.id = a.utilisateur_id JOIN tiers t ON t.id = u.tiers_id
       JOIN projets p ON p.id = a.projet_id ORDER BY p.code, t.nom'
)->fetchAll();

page_start('Projets et affectations', 'administration');
$ongletOutil = 'projets';
require __DIR__ . '/_nav_outil.php';
?>
<div class="mb-4">
    <h1 class="h4 mb-1">Administration de l'outil</h1>
    <p class="text-muted small mb-0">
        Créer les projets, y désigner les coordinateurs et prononcer les clôtures. Ce rôle est extérieur aux projets :
        vous n'y saisissez rien. À ne pas confondre avec l'Administrateur des budgets, qui est le Responsable Administratif et Financier.
    </p>
</div>

<?php if ($erreur): ?><div class="alert alert-danger"><?= e($erreur) ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header bg-white fw-semibold">Projets</div>
            <div class="table-responsive">
            <table class="table table-sm table-striped mb-0 align-middle">
                <thead><tr><th>Code</th><th>Projet</th><th>Bailleur</th><th>Lignes</th><th>Affect.</th><th>Statut</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($projets as $p): ?>
                <tr>
                    <td><code><?= e($p['code']) ?></code></td>
                    <td><?= e($p['intitule']) ?><br><small class="text-muted"><?= e($p['referentiel']) ?></small></td>
                    <td class="small"><?= e($p['bailleur']) ?></td>
                    <td><?= (int)$p['lignes'] ?></td>
                    <td><?= (int)$p['affectations'] ?></td>
                    <td><span class="badge <?= $p['statut'] === 'actif' ? 'badge-module-actif' : 'badge-a-definir' ?>"><?= e($p['statut']) ?></span></td>
                    <td class="text-end">
                        <?php if ($p['statut'] === 'actif' && (int)$p['dossiers'] === 0): ?>
                        <form method="post" class="d-inline" data-confirm="Prononcer la clôture définitive de ce projet ?">
                            <?= csrf_field() ?><input type="hidden" name="action" value="clore"><input type="hidden" name="projet_id" value="<?= (int)$p['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger">Clore</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div class="card-footer bg-white small text-muted">
                Le code court préfixe les noms de fichiers et les séquences de numérotation. Il se fige à la première pièce produite.
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white fw-semibold">Affectations en vigueur</div>
            <?php if (!$affectations): ?>
                <div class="card-body small text-muted">Aucune affectation. Tant qu'un projet n'a pas de coordinateur, personne ne peut y travailler.</div>
            <?php else: ?>
            <table class="table table-sm table-striped mb-0 small">
                <thead><tr><th>Projet</th><th>Personne</th><th>Rôle</th><th>Du</th><th>Au</th><th>Acte</th></tr></thead>
                <tbody>
                <?php foreach ($affectations as $a): ?>
                <tr>
                    <td><code><?= e($a['projet_code']) ?></code></td>
                    <td><?= e($a['nom']) ?></td>
                    <td><?= e(ROLES_LIBELLES[$a['role']] ?? $a['role']) ?></td>
                    <td><?= e(date_fr($a['date_debut'])) ?></td>
                    <td><?= $a['date_fin'] ? e(date_fr($a['date_fin'])) : '—' ?></td>
                    <td><a href="<?= e(base_path('pdf/serve.php?id=' . (int)$a['acte_delegation_fichier_id'])) ?>" target="_blank"><i class="bi bi-file-earmark-text"></i></a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header bg-white fw-semibold">Nouveau projet</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="creer">
                    <div class="mb-2">
                        <label class="form-label">Code court</label>
                        <input name="code" class="form-control" required maxlength="20" style="text-transform:uppercase" placeholder="ex. KKP">
                        <div class="form-text">Préfixe des fichiers et des séquences ; il ne pourra plus changer.</div>
                    </div>
                    <div class="mb-2"><label class="form-label">Intitulé</label><input name="intitule" class="form-control" required maxlength="150"></div>
                    <div class="mb-2"><label class="form-label">Bailleur</label><input name="bailleur" class="form-control" required maxlength="120" placeholder="ex. FOKAL, programme REVIV"></div>
                    <div class="mb-3">
                        <label class="form-label">Référentiel applicable</label>
                        <input name="referentiel" class="form-control" required maxlength="60" placeholder="ex. FOKAL_REVIV" list="referentiels">
                        <datalist id="referentiels"><option value="PAIESC"><option value="FOKAL_REVIV"></datalist>
                        <div class="form-text">Détermine les modèles de rapport et le libellé des copies.</div>
                    </div>
                    <button class="btn btn-primary">Créer le projet</button>
                </form>
                <p class="text-muted small mt-3 mb-0">
                    Le projet naît avec ses trois phases, son registre de paramètres aux valeurs initiales et son plan de comptes.
                    Le coordinateur chargera ensuite la nomenclature et la date d'ancrage.
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white fw-semibold">Affecter une personne</div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="affecter">
                    <div class="mb-2"><label class="form-label">Projet</label>
                        <select name="projet_id" class="form-select" required>
                            <?php foreach ($projets as $p): if ($p['statut'] !== 'actif') continue; ?>
                            <option value="<?= (int)$p['id'] ?>"><?= e($p['code']) ?> — <?= e($p['intitule']) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="mb-2"><label class="form-label">Personne</label>
                        <select name="utilisateur_id" class="form-select" required>
                            <?php foreach ($utilisateurs as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['nom']) ?> — <?= e($u['email']) ?></option><?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            La personne n'est pas dans la liste ?
                            <a href="<?= e(base_path('modules/noyau/utilisateurs.php')) ?>">Créer son accès</a>,
                            puis revenir l'affecter.
                        </div>
                    </div>
                    <div class="mb-2"><label class="form-label">Rôle</label>
                        <select name="role" class="form-select">
                            <?php foreach (ROLES_LIBELLES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="row g-2 mb-2">
                        <div class="col"><label class="form-label">Du</label><input type="date" name="date_debut" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                        <div class="col"><label class="form-label">Au</label><input type="date" name="date_fin" class="form-control"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Acte de délégation d'autorité <span class="text-danger">*</span></label>
                        <input type="file" name="acte" accept="application/pdf,image/png,image/jpeg" class="form-control form-control-sm" required>
                        <div class="form-text">Signé par l'organe compétent et par l'intéressé, avec ses termes de référence.</div>
                    </div>
                    <button class="btn btn-primary">Affecter</button>
                </form>
                <p class="text-muted small mt-3 mb-0">
                    Une date de fin organise la suppléance : une délégation temporaire suffit, l'indisponibilité prolongée du
                    Coordinateur bloquerait sinon le projet entier.
                </p>
            </div>
        </div>
    </div>
</div>
<?php page_end();
