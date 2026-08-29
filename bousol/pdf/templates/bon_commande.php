<?php
/**
 * BON DE COMMANDE (annexe D, achat de bien et service aupres d'une compagnie).
 * Variables : $dossier, $imputation, $fournisseur (array), $offres (array), $offre_retenue (array|null)
 */
$titre_document = 'BON DE COMMANDE';
$numero_document = $dossier['numero'];
include __DIR__ . '/_entete.php';
?>
<table class="meta">
  <tr><td class="l">Fournisseur</td><td><?= e($fournisseur['nom']) ?></td>
      <td class="l">NIF</td><td><?= e($fournisseur['nif'] ?? '—') ?></td></tr>
  <tr><td class="l">Adresse</td><td colspan="3"><?= e($fournisseur['adresse'] ?? '—') ?></td></tr>
  <tr><td class="l">Dossier</td><td><?= e($dossier['numero']) ?></td>
      <td class="l">Date</td><td><?= e(date_fr(date('Y-m-d'))) ?></td></tr>
</table>

<table class="grille">
  <tr><th style="width:56%">Désignation</th><th>Unité</th><th>Quantité</th><th>Prix unitaire</th><th>Montant</th></tr>
  <tr>
    <td><?= e($dossier['objet']) ?></td>
    <td><?= e(UNITES[$imputation['unite']] ?? $imputation['unite']) ?></td>
    <td class="n"><?= e(rtrim(rtrim(number_format((float)$imputation['quantite'], 2, ',', ' '), '0'), ',')) ?></td>
    <td class="n"><?= e(htg((float)$imputation['valeur_unitaire'], false)) ?></td>
    <td class="n"><?= e(htg((float)$imputation['montant'], false)) ?></td>
  </tr>
  <tr class="total"><td colspan="4">Total à payer, taxes comprises</td>
      <td class="n"><?= e(htg((float)$imputation['montant'])) ?></td></tr>
</table>
<p class="note">La TCA est comprise dans le prix final : aucun montant hors taxe ne figure sur ce bon.</p>

<?php if (!empty($offres)): ?>
<table class="grille" style="margin-top:8px">
  <tr><th colspan="3">Mise en concurrence</th></tr>
  <tr><th style="width:56%">Fournisseur consulté</th><th>Montant proposé</th><th>Retenue</th></tr>
  <?php foreach ($offres as $o): ?>
  <tr>
    <td><?= e($o['fournisseur_nom']) ?></td>
    <td class="n"><?= e(htg((float)$o['montant'], false)) ?></td>
    <td><?= $o['retenu'] ? 'Oui' : '' ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php if (!empty($offre_retenue['motif_choix'])): ?>
<p class="note"><b>Motif du choix d'une offre autre que la moins-disante :</b> <?= e($offre_retenue['motif_choix']) ?></p>
<?php endif; ?>
<?php endif; ?>

<table class="sig">
  <tr>
    <td><span class="q">Préparé par le Responsable Administratif et Financier</span><br>
        <span class="note">Date, nom et signature :</span></td>
    <td><span class="q">Approuvé par le Coordinateur</span><br>
        <span class="note">Date, nom et signature :</span></td>
    <td><span class="q">Reçu par le fournisseur</span><br>
        <span class="note">Date, nom, cachet et signature :</span></td>
  </tr>
</table>
<div class="pied">Bousòl &middot; bon de commande du dossier <?= e($dossier['numero']) ?></div>
