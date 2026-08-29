<?php
/**
 * VENTILATION DETAILLEE DES DEPENSES (CDC 6.5, formulaire PAIESC).
 * Variables : $rapport, $ventilation (['banque' => [], 'caisse' => []])
 */
$titre_document = 'VENTILATION DÉTAILLÉE DES DÉPENSES';
$sous_titre = 'Du ' . date_fr($rapport['periode_debut']) . ' au ' . date_fr($rapport['periode_fin']);
$orientation = 'L';
include __DIR__ . '/_entete.php';
$journal = function (array $lignes, bool $avecMode) use ($entete) {
    $total = 0.0;
    ?>
    <table class="grille">
      <tr><th style="width:9%">N° pièce</th><th style="width:9%">Date</th><th>Description</th>
          <th>Bénéficiaire</th><?php if ($avecMode): ?><th>Mode</th><?php endif; ?>
          <th>Ligne</th><th>Montant</th></tr>
      <?php foreach ($lignes as $l): $total += (float)$l['montant']; ?>
      <tr>
        <td><?= e($l['numero_piece'] ?? '—') ?></td>
        <td><?= e(date_fr($l['date_imputation'])) ?></td>
        <td><?= e(mb_substr($l['objet'], 0, 58)) ?></td>
        <td><?= e(mb_substr($l['beneficiaire'], 0, 28)) ?></td>
        <?php if ($avecMode): ?><td><?= e(MODES_REGLEMENT[$l['mode']] ?? '—') ?></td><?php endif; ?>
        <td><?= e($l['ligne_code']) ?></td>
        <td class="n"><?= e(htg((float)$l['montant'], false)) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$lignes): ?><tr><td colspan="<?= $avecMode ? 7 : 6 ?>">Aucune dépense sur la période.</td></tr><?php endif; ?>
      <tr class="total"><td colspan="<?= $avecMode ? 6 : 5 ?>">Total</td>
          <td class="n"><?= e(htg($total)) ?></td></tr>
    </table>
    <?php
};
?>
<table class="meta">
  <tr><td class="l">Financement</td><td><?= e($entete['bailleur']) ?></td>
      <td class="l">Contrat</td><td><?= e($entete['contrat']) ?></td></tr>
  <tr><td class="l">Compte</td><td colspan="3"><?= e($entete['compte']) ?: '—' ?></td></tr>
</table>

<p class="note" style="margin-top:6px"><b>Journal chronologique — dépenses par chèque et virement</b></p>
<?php $journal($ventilation['banque'], true); ?>

<p class="note" style="margin-top:10px"><b>Feuille distincte — petite caisse</b></p>
<?php $journal($ventilation['caisse'], false); ?>

<table class="sig">
  <tr>
    <td><span class="q">Établie par</span><br><span class="note">Responsable Administratif et Financier<br>Date et signature :</span></td>
    <td><span class="q">Vérifiée par le Coordinateur</span><br><span class="note">Date et signature :</span></td>
    <td><span class="q">Pièces jointes</span><br><span class="note">Liasse de la période</span></td>
  </tr>
</table>
<div class="pied">Bousòl &middot; ventilation détaillée &middot; article 4.2 du contrat de subvention</div>
