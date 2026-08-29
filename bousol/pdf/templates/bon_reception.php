<?php
/**
 * BON DE RECEPTION (annexe D, achat de bien).
 * Variables : $dossier, $imputation, $fournisseur
 */
$titre_document = 'BON DE RÉCEPTION';
$numero_document = $dossier['numero'];
include __DIR__ . '/_entete.php';
?>
<table class="meta">
  <tr><td class="l">Fournisseur</td><td><?= e($fournisseur['nom']) ?></td>
      <td class="l">Dossier</td><td><?= e($dossier['numero']) ?></td></tr>
  <tr><td class="l">Objet</td><td colspan="3"><?= e($dossier['objet']) ?></td></tr>
</table>

<table class="grille">
  <tr><th style="width:52%">Désignation</th><th>Quantité commandée</th><th>Quantité reçue</th><th>Conforme</th></tr>
  <tr>
    <td><?= e($dossier['objet']) ?></td>
    <td class="n"><?= e(rtrim(rtrim(number_format((float)$imputation['quantite'], 2, ',', ' '), '0'), ',')) ?>
        <?= e(UNITES[$imputation['unite']] ?? '') ?></td>
    <td class="n">&nbsp;</td>
    <td>&nbsp;</td>
  </tr>
  <tr><td class="vide" colspan="4"></td></tr>
  <tr><td class="vide" colspan="4"></td></tr>
</table>
<p class="note">Réserves éventuelles :</p>
<table class="grille"><tr><td class="vide"></td></tr><tr><td class="vide"></td></tr></table>

<table class="sig">
  <tr>
    <td><span class="q">Réceptionné par</span><br><span class="note">Responsable Administratif et Financier<br>Date, nom et signature :</span></td>
    <td><span class="q">Livré par</span><br><span class="note">Nom, date et signature :</span></td>
    <td><span class="q">Visa du Coordinateur</span><br><span class="note">Date et signature :</span></td>
  </tr>
</table>
<div class="pied">Bousòl &middot; bon de réception du dossier <?= e($dossier['numero']) ?></div>
