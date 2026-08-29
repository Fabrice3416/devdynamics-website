<?php
/**
 * BON DE DECAISSEMENT (annexe D, presque tous les types).
 * Piece qui autorise la sortie de fonds : deux mandataires la signent.
 * Variables : $dossier, $imputation, $beneficiaire (array), $montant, $mode, $compte
 */
$titre_document = 'BON DE DÉCAISSEMENT';
$numero_document = $dossier['numero'];
include __DIR__ . '/_entete.php';
?>
<table class="meta">
  <tr><td class="l">Bénéficiaire</td><td><?= e($beneficiaire['nom']) ?></td>
      <td class="l">NIF</td><td><?= e($beneficiaire['nif'] ?? '—') ?></td></tr>
  <tr><td class="l">Dossier</td><td><?= e($dossier['numero']) ?></td>
      <td class="l">Objet</td><td><?= e($dossier['objet']) ?></td></tr>
  <tr><td class="l">Mode de règlement</td><td><?= e($mode) ?></td>
      <td class="l">Compte débité</td><td><?= e($compte) ?></td></tr>
</table>

<table class="grille">
  <tr><th style="width:70%">Imputation budgétaire</th><th>Montant</th></tr>
  <tr><td><b><?= e($imputation['ligne_code']) ?></b> <?= e($imputation['ligne_libelle']) ?></td>
      <td class="n"><?= e(htg((float)$imputation['montant'], false)) ?></td></tr>
  <tr class="total"><td>Montant à décaisser, en gourdes</td><td class="n"><?= e(htg($montant)) ?></td></tr>
</table>
<p class="note">Somme arrêtée à la présente fiche. Tout décaissement se fait par chèque ou virement bancaire ;
  l'espèce n'est admise que par la petite caisse.</p>

<table class="sig">
  <tr>
    <td><span class="q">Préparé par</span><br><span class="note">Responsable Administratif et Financier<br>Date et signature :</span></td>
    <td><span class="q">Premier mandataire</span><br><span class="note">Nom, date et signature :</span></td>
    <td><span class="q">Second mandataire</span><br><span class="note">Nom, date et signature :</span></td>
  </tr>
</table>
<p class="note">Un mandataire bénéficiaire de ce décaissement est exclu du couple signataire.</p>
<div class="pied">Bousòl &middot; bon de décaissement du dossier <?= e($dossier['numero']) ?></div>
