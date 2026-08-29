<?php
/**
 * RAPPROCHEMENT BANCAIRE (CDC 4.9), par compte et par mois calendaire, ventile par projet.
 * Variables : $compte (string), $date_releve, $etat (array de rapprochement_consolide),
 *             $lignes (array), $commentaire (string|null), $partage (bool)
 */
$titre_document = 'RAPPROCHEMENT BANCAIRE';
$sous_titre = $compte . ' — relevé au ' . date_fr($date_releve);
include __DIR__ . '/_entete.php';
?>
<?php if ($partage): ?>
<p class="note">Ce compte sert plusieurs projets. Le rapprochement se produit par compte et se ventile par projet :
  chaque projet en extrait la part qui le concerne pour la joindre à son rapport. C'est la seule construction
  qui ne laisse aucun écart inexpliqué.</p>
<?php endif; ?>

<table class="grille">
  <tr><th style="width:70%">Solde reconstitué, ventilé par projet</th><th>Montant</th></tr>
  <?php foreach ($etat['par_projet'] as $p): ?>
  <tr><td><?= e($p['code']) ?> — <?= e($p['intitule']) ?></td><td class="n"><?= e(htg($p['solde'], false)) ?></td></tr>
  <?php endforeach; ?>
  <tr class="total"><td>Total reconstitué, tous projets rattachés</td><td class="n"><?= e(htg($etat['reconstitue'], false)) ?></td></tr>
</table>

<table class="grille" style="margin-top:8px">
  <tr><th style="width:52%">Ajustements de rapprochement</th><th>Nature</th><th>Sens</th><th>Montant</th></tr>
  <?php foreach ($lignes as $l): ?>
  <tr>
    <td><?= e($l['objet']) ?>
        <?php if (!empty($l['motif_non_concordance'])): ?><br><span style="font-size:7.5pt"><?= e($l['motif_non_concordance']) ?></span><?php endif; ?></td>
    <td><?= e(NATURES_LIGNE_RAPPROCHEMENT[$l['nature']] ?? $l['nature']) ?></td>
    <td><?= $l['sens'] === 'plus' ? '+' : '−' ?></td>
    <td class="n"><?= e(htg((float)$l['montant'], false)) ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$lignes): ?><tr><td colspan="4">Aucun ajustement.</td></tr><?php endif; ?>
  <tr class="total"><td colspan="3">Total des ajustements</td><td class="n"><?= e(htg($etat['ajustements'], false)) ?></td></tr>
</table>

<table class="grille" style="margin-top:8px">
  <tr><td style="width:70%"><b>Solde ajusté</b></td><td class="n"><b><?= e(htg($etat['solde_ajuste'], false)) ?></b></td></tr>
  <tr><td>Solde du relevé bancaire</td><td class="n"><?= e(htg($etat['solde_releve'], false)) ?></td></tr>
  <tr class="total"><td>Écart</td><td class="n"><?= e(htg($etat['ecart'])) ?></td></tr>
</table>

<?php if (!empty($commentaire)): ?>
<p class="note" style="margin-top:8px"><b>Explication de l'écart :</b> <?= e($commentaire) ?></p>
<?php endif; ?>
<p class="note">Tout écart non résolu exige un commentaire avant validation.</p>

<table class="sig">
  <tr>
    <td><span class="q">Établi par</span><br><span class="note">Responsable Administratif et Financier<br>Date et signature :</span></td>
    <td><span class="q">Vérifié par le Coordinateur</span><br><span class="note">Date et signature :</span></td>
    <td><span class="q">Pièces jointes</span><br><span class="note">Relevé bancaire du <?= e(date_fr($date_releve)) ?></span></td>
  </tr>
</table>
<div class="pied">Bousòl &middot; rapprochement bancaire &middot; <?= e($compte) ?>
  &middot; <?= e(date_fr($date_releve)) ?></div>
