<?php
/**
 * RAPPORT FINANCIER AU MODELE DE L'ANNEXE G (CDC 6.4).
 * Onze lignes, sept colonnes, une feuille par periode.
 * Variables : $rapport, $lignes (lignes_financieres), $solde
 */
$titre_document = 'RAPPORT FINANCIER';
$sous_titre = 'Modèle Annexe G · période du ' . date_fr($rapport['periode_debut'])
            . ' au ' . date_fr($rapport['periode_fin'])
            . ($rapport['version'] > 1 ? ' · version rectificative ' . (int)$rapport['version'] : '');
$orientation = 'L';
include __DIR__ . '/_entete.php';
$provisionMobilisee = (float)($solde['provision_mobilisee'] ?? 0);
?>
<table class="grille">
  <tr>
    <th rowspan="2" style="width:26%">Ligne budgétaire</th>
    <th colspan="4">Budget approuvé</th>
    <th colspan="3">Dépenses supportées sur la période</th>
    <th>Cumul antérieur</th><th>Cumul total</th><th>Différence</th>
  </tr>
  <tr>
    <th>Unité</th><th>Qté</th><th>Valeur unit.</th><th>Total (a)</th>
    <th>Qté</th><th>Valeur unit.</th><th>Total (b)</th>
    <th>(c)</th><th>(d = b + c)</th><th>(d − a)</th>
  </tr>
  <?php foreach ($lignes as $lf): $fort = $lf['nature'] !== 'imputable'; ?>
  <tr<?= $fort ? ' class="total"' : '' ?>>
    <td><?= e($lf['code']) ?> <?= e(mb_substr($lf['libelle'], 0, 46)) ?></td>
    <td><?= $lf['budget_unite'] ? e(UNITES[$lf['budget_unite']] ?? $lf['budget_unite']) : '' ?></td>
    <td class="n"><?= $lf['budget_quantite'] === null ? '' : e(rtrim(rtrim(number_format((float)$lf['budget_quantite'], 2, ',', ' '), '0'), ',')) ?></td>
    <td class="n"><?= $lf['budget_valeur'] === null ? '' : e(htg((float)$lf['budget_valeur'], false)) ?></td>
    <td class="n"><?= $lf['budget_total'] === null ? '' : e(htg((float)$lf['budget_total'], false)) ?></td>
    <td class="n"><?= $lf['periode_quantite'] === null ? '' : e(rtrim(rtrim(number_format((float)$lf['periode_quantite'], 2, ',', ' '), '0'), ',')) ?></td>
    <td class="n"><?= $lf['periode_valeur'] === null ? '' : e(htg((float)$lf['periode_valeur'], false)) ?></td>
    <td class="n"><?= (float)$lf['periode_total'] != 0.0 ? e(htg((float)$lf['periode_total'], false)) : '' ?></td>
    <td class="n"><?= (float)$lf['cumul_anterieur'] != 0.0 ? e(htg((float)$lf['cumul_anterieur'], false)) : '' ?></td>
    <td class="n"><?= (float)$lf['cumul_total'] != 0.0 ? e(htg((float)$lf['cumul_total'], false)) : '' ?></td>
    <td class="n"><?= $lf['difference'] === null ? '' : e(htg((float)$lf['difference'], false)) ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<p class="note" style="margin-top:8px">La colonne budget reproduit le budget contractuel figé, jamais le budget
  de gestion. La valeur unitaire des dépenses est une moyenne, le coût total divisé par la quantité, ce qui reste
  exact lorsqu'une même ligne reçoit des dépenses à des valeurs unitaires différentes ; sur une ligne non encore
  consommée, la quantité étant nulle, la colonne reste vide.</p>
<p class="note">La provision pour imprévus n'étant jamais imputée directement, sa ligne reste à zéro en dépenses,
  et le total des coûts éligibles est égal au total hors réserve.
  <?php if ($provisionMobilisee > 0): ?>
  <b>Provision mobilisée sur la période : <?= e(htg($provisionMobilisee)) ?></b>, portée aux lignes de destination
  par réallocation du budget de gestion, sur autorisation écrite téléversée.
  <?php endif; ?></p>

<table class="sig">
  <tr>
    <td><span class="q">Établi par</span><br><span class="note">Responsable Administratif et Financier<br>Date et signature :</span></td>
    <td><span class="q">Validé par le Coordinateur</span><br><span class="note">Date et signature :</span></td>
    <td><span class="q">Le représentant légal</span><br><span class="note">Date, nom et signature :</span></td>
  </tr>
</table>
<div class="pied">Bousòl &middot; rapport financier &middot; <?= e($entete['projet']) ?>
  &middot; contrat <?= e($entete['contrat']) ?></div>
