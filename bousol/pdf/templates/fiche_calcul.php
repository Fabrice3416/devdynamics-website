<?php
/**
 * FICHE DE CALCUL DES FRAIS (annexe D, frais de voyage et per diem).
 * Variables : $dossier, $imputation, $missionnaire
 */
$titre_document = 'FICHE DE CALCUL DES FRAIS';
$numero_document = $dossier['numero'];
include __DIR__ . '/_entete.php';
?>
<table class="meta">
  <tr><td class="l">Missionnaire</td><td><?= e($missionnaire['nom']) ?></td>
      <td class="l">Dossier</td><td><?= e($dossier['numero']) ?></td></tr>
  <tr><td class="l">Objet</td><td colspan="3"><?= e($dossier['objet']) ?></td></tr>
</table>

<table class="grille">
  <tr><th style="width:44%">Nature du frais</th><th>Unité</th><th>Nombre</th><th>Taux unitaire</th><th>Montant</th></tr>
  <tr>
    <td><?= e($dossier['objet']) ?></td>
    <td><?= e(UNITES[$imputation['unite']] ?? $imputation['unite']) ?></td>
    <td class="n"><?= e(rtrim(rtrim(number_format((float)$imputation['quantite'], 2, ',', ' '), '0'), ',')) ?></td>
    <td class="n"><?= e(htg((float)$imputation['valeur_unitaire'], false)) ?></td>
    <td class="n"><?= e(htg((float)$imputation['montant'], false)) ?></td>
  </tr>
  <tr><td class="vide" colspan="5"></td></tr>
  <tr><td class="vide" colspan="5"></td></tr>
  <tr class="total"><td colspan="4">Total des frais, en gourdes</td>
      <td class="n"><?= e(htg((float)$imputation['montant'])) ?></td></tr>
</table>
<p class="note">Imputé sur la ligne <b><?= e($imputation['ligne_code']) ?></b> <?= e($imputation['ligne_libelle']) ?>.</p>

<table class="sig">
  <tr>
    <td><span class="q">Établie par</span><br><span class="note">Responsable Administratif et Financier<br>Date et signature :</span></td>
    <td><span class="q">Vérifiée et approuvée</span><br><span class="note">Le Coordinateur<br>Date et signature :</span></td>
    <td><span class="q">Reçu du missionnaire</span><br><span class="note">Date, nom et signature :</span></td>
  </tr>
</table>
<div class="pied">Bousòl &middot; fiche de calcul du dossier <?= e($dossier['numero']) ?></div>
