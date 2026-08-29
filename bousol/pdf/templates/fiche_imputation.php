<?php
/**
 * FICHE D'IMPUTATION BUDGETAIRE (annexe D, premiere piece de tous les types).
 * Variables : $dossier (array), $imputation (array), $solde_avant, $solde_apres,
 *             $consomme, $derogation (string|null)
 */
$titre_document = 'FICHE D\'IMPUTATION BUDGÉTAIRE';
$numero_document = $dossier['numero'];
include __DIR__ . '/_entete.php';
?>
<table class="meta">
  <tr><td class="l">Dossier</td><td><?= e($dossier['numero']) ?></td>
      <td class="l">Type</td><td><?= e(type_dossier_libelle($dossier['type'])) ?></td></tr>
  <tr><td class="l">Objet</td><td colspan="3"><?= e($dossier['objet']) ?></td></tr>
  <tr><td class="l">Bénéficiaire</td><td><?= e($dossier['tiers_nom']) ?></td>
      <td class="l">Ouvert le</td><td><?= e(date_fr(substr((string)$dossier['created_at'], 0, 10))) ?></td></tr>
</table>

<table class="grille">
  <tr><th>Ligne budgétaire</th><th>Unité</th><th>Quantité</th><th>Valeur unitaire</th><th>Montant imputé</th></tr>
  <tr>
    <td><b><?= e($imputation['ligne_code']) ?></b> <?= e($imputation['ligne_libelle']) ?></td>
    <td><?= e(UNITES[$imputation['unite']] ?? $imputation['unite']) ?></td>
    <td class="n"><?= e(rtrim(rtrim(number_format((float)$imputation['quantite'], 2, ',', ' '), '0'), ',')) ?></td>
    <td class="n"><?= e(htg((float)$imputation['valeur_unitaire'], false)) ?></td>
    <td class="n"><b><?= e(htg((float)$imputation['montant'], false)) ?></b></td>
  </tr>
  <tr class="total"><td colspan="4">Nature de l'imputation</td>
      <td class="n"><?= $imputation['nature'] === 'memoire' ? 'POUR MÉMOIRE' : 'CONSOMMATION' ?></td></tr>
</table>

<table class="grille" style="margin-top:8px">
  <tr><th colspan="2">Disponibilité de la ligne au budget de gestion</th></tr>
  <tr><td>Budget de gestion de la ligne</td><td class="n"><?= e(htg($solde_avant, false)) ?></td></tr>
  <tr><td>Déjà consommé, cette imputation comprise</td><td class="n"><?= e(htg($consomme, false)) ?></td></tr>
  <tr class="total"><td>Solde disponible après imputation</td><td class="n"><?= e(htg($solde_apres, false)) ?></td></tr>
</table>

<?php if (!empty($derogation)): ?>
<p class="note" style="margin-top:8px"><b>Dérogation au contrôle de quantité</b>, accordée par le Coordinateur :
  <?= e($derogation) ?></p>
<?php endif; ?>

<p class="note" style="margin-top:8px">L'imputation ne porte que sur une seule ligne budgétaire.
  Une facture couvrant deux lignes se scinde en deux dossiers et deux règlements distincts.</p>

<table class="sig">
  <tr>
    <td><span class="q">Établie par le Responsable Administratif et Financier</span><br>
        <span class="note">Date, nom et signature :</span></td>
    <td><span class="q">Visa du Coordinateur</span><br>
        <span class="note">Date, nom et signature :</span></td>
    <td><span class="q">Numéro de pièce comptable</span><br>
        <span class="note"><?= !empty($imputation['numero_piece']) ? e($imputation['numero_piece']) : 'attribué au règlement' ?></span></td>
  </tr>
</table>
<div class="pied">Bousòl &middot; fiche d'imputation du dossier <?= e($dossier['numero']) ?>
  &middot; projet <?= e($entete['projet']) ?></div>
