<?php
/**
 * ETAT RECAPITULATIF DES ACOMPTES RETENUS (annexe D, versement a la DGI).
 * Variables : $mois (int), $acomptes (array), $total (float)
 */
$titre_document = 'ÉTAT RÉCAPITULATIF DES ACOMPTES RETENUS';
$sous_titre = 'Mois de projet ' . (int)$mois . ' — à verser à la Direction Générale des Impôts';
include __DIR__ . '/_entete.php';
?>
<table class="grille">
  <tr><th style="width:32%">Prestataire</th><th>Ligne d'origine</th><th>Montant brut</th><th>Taux</th><th>Acompte retenu</th></tr>
  <?php foreach ($acomptes as $a): ?>
  <tr>
    <td><?= e($a['intervenant']) ?></td>
    <td><?= e(($a['ligne_code'] ?? '—') . ' ' . mb_substr((string)($a['ligne_libelle'] ?? ''), 0, 34)) ?></td>
    <td class="n"><?= e(htg((float)$a['brut'], false)) ?></td>
    <td class="n"><?= e(rtrim(rtrim(number_format((float)($a['taux_acompte'] ?? 2), 2, ',', ' '), '0'), ',')) ?> %</td>
    <td class="n"><?= e(htg((float)$a['acompte'], false)) ?></td>
  </tr>
  <?php endforeach; ?>
  <tr class="total"><td colspan="4">Total dû à la DGI pour le mois <?= (int)$mois ?></td>
      <td class="n"><?= e(htg($total)) ?></td></tr>
</table>

<p class="note" style="margin-top:8px">L'acompte de deux pour cent est retenu sur les prestataires de services.
  Il est déjà compris dans le montant brut imputé aux lignes ci-dessus : le présent versement ne consomme
  donc aucune ligne budgétaire, et sa fiche d'imputation existe à titre de mémoire.</p>
<p class="note">Le versement est mensuel. Une période ne peut être figée tant que la dette née dans cette
  période n'est pas soldée.</p>

<table class="sig">
  <tr>
    <td><span class="q">Établi par</span><br><span class="note">Responsable Administratif et Financier<br>Date et signature :</span></td>
    <td><span class="q">Vérifié par le Coordinateur</span><br><span class="note">Date et signature :</span></td>
    <td><span class="q">Reçu par la DGI</span><br><span class="note">Date, cachet et signature :</span></td>
  </tr>
</table>
<div class="pied">Bousòl &middot; état récapitulatif des acomptes &middot; mois <?= (int)$mois ?>
  &middot; projet <?= e($entete['projet']) ?></div>
