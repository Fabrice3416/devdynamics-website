<?php
/**
 * CERTIFICAT D'ACCEPTATION (CDC 4.4, annexe D service a un particulier).
 * Variables : $rapport (array), $prestation (array|null)
 */
$titre_document = 'CERTIFICAT D\'ACCEPTATION';
$sous_titre = 'Rapport d\'exécution du mois de projet ' . (int)$rapport['mois'];
include __DIR__ . '/_entete.php';
?>
<table class="meta">
  <tr><td class="l">Intervenant</td><td><?= e($rapport['intervenant']) ?></td>
      <td class="l">Fonction</td><td><?= e($rapport['fonction']) ?></td></tr>
  <tr><td class="l">Mois de projet</td><td><?= (int)$rapport['mois'] ?></td>
      <td class="l">Ligne budgétaire</td><td><?= e(($rapport['ligne_code'] ?? '—') . ' ' . ($rapport['ligne_libelle'] ?? '')) ?></td></tr>
  <tr><td class="l">Rapport remis le</td><td><?= e(date_fr($rapport['date_remise'])) ?></td>
      <td class="l">Versé au dossier le</td><td><?= e(date_fr($rapport['date_versement'])) ?></td></tr>
  <tr><td class="l">Autorité d'acceptation</td><td colspan="3"><?= e(AUTORITES_ACCEPTATION[$rapport['autorite']] ?? $rapport['autorite']) ?></td></tr>
</table>

<p class="note" style="margin-top:8px">L'autorité désignée ci-dessus certifie que la prestation décrite au rapport
  d'exécution du mois <?= (int)$rapport['mois'] ?>, remis par <b><?= e($rapport['intervenant']) ?></b>, a été
  exécutée conformément aux termes de son contrat de service, et en accepte le service fait.</p>

<?php if (!empty($prestation)): ?>
<table class="grille" style="margin-top:8px">
  <tr><th colspan="2">Rémunération correspondante</th></tr>
  <tr><td>Montant brut, consommé sur la ligne budgétaire</td><td class="n"><?= e(htg((float)$prestation['brut'], false)) ?></td></tr>
  <tr><td>Acompte fiscal retenu, <?= e(rtrim(rtrim(number_format((float)$prestation['taux_acompte'], 2, ',', ' '), '0'), ',')) ?> %,
      dû à la Direction Générale des Impôts</td><td class="n"><?= e(htg((float)$prestation['acompte'], false)) ?></td></tr>
  <tr class="total"><td>Net à verser à l'intervenant</td><td class="n"><?= e(htg((float)$prestation['net'])) ?></td></tr>
</table>
<?php if (($prestation['ratification'] ?? '') === 'provisoire'): ?>
<p class="note"><b>Prestation provisoire</b> : l'acceptation relevant de l'Assemblée Générale, la présente
  rémunération est réglée à titre provisoire et attend la résolution écrite qui la couvre.</p>
<?php endif; ?>
<?php endif; ?>

<table class="sig">
  <tr>
    <td><span class="q"><?= $rapport['autorite'] === 'assemblee_generale' ? 'Pour l\'Assemblée Générale' : 'Le Coordinateur du projet' ?></span><br>
        <span class="note">Date, nom et signature :</span></td>
    <td><span class="q">Le Responsable Administratif et Financier</span><br>
        <span class="note">Réception du rapport, date et signature :</span></td>
    <td><span class="q">L'intervenant</span><br><span class="note">Date, nom et signature :</span></td>
  </tr>
</table>
<div class="pied">Bousòl &middot; certificat d'acceptation &middot; projet <?= e($entete['projet']) ?>
  &middot; mois <?= (int)$rapport['mois'] ?></div>
