<?php
/**
 * ORDRE DE MISSION (annexe D, frais de voyage et per diem).
 * Variables : $dossier, $missionnaire (array)
 */
$titre_document = 'ORDRE DE MISSION';
$numero_document = $dossier['numero'];
include __DIR__ . '/_entete.php';
?>
<table class="meta">
  <tr><td class="l">Missionnaire</td><td><?= e($missionnaire['nom']) ?></td>
      <td class="l">Fonction</td><td><?= e($missionnaire['fonction'] ?? '—') ?></td></tr>
  <tr><td class="l">Pièce d'identité</td><td><?= e($missionnaire['nif'] ?? '—') ?></td>
      <td class="l">Dossier</td><td><?= e($dossier['numero']) ?></td></tr>
  <tr><td class="l">Objet de la mission</td><td colspan="3"><?= e($dossier['objet']) ?></td></tr>
  <tr><td class="l">Destination</td><td>__________________________</td>
      <td class="l">Moyen de transport</td><td>__________________________</td></tr>
  <tr><td class="l">Départ le</td><td>___ / ___ / ______</td>
      <td class="l">Retour le</td><td>___ / ___ / ______</td></tr>
</table>

<p class="note" style="margin-top:8px">Le Coordinateur du projet <?= e($entete['projet']) ?> donne mission à la personne
  désignée ci-dessus de se rendre à la destination indiquée, aux fins de l'objet mentionné, dans le cadre du contrat
  de subvention n° <?= e($entete['contrat']) ?>.</p>
<p class="note">Les frais engagés font l'objet d'une fiche de calcul distincte, et leur remboursement est
  subordonné à la présentation des justificatifs originaux.</p>

<table class="sig">
  <tr>
    <td><span class="q">Le Coordinateur</span><br><span class="note">Date, nom et signature :</span></td>
    <td><span class="q">Le missionnaire</span><br><span class="note">Lu et accepté, date et signature :</span></td>
    <td><span class="q">Visa de retour de mission</span><br><span class="note">Date et signature :</span></td>
  </tr>
</table>
<div class="pied">Bousòl &middot; ordre de mission du dossier <?= e($dossier['numero']) ?></div>
