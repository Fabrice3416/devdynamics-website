<?php
/**
 * JOURNAL DE CAISSE ET ARRETE (CDC 4.6, format du formulaire transmis par le PAIESC).
 * Variables : $debut, $fin, $journal (array de caisse_journal), $arrete (array|null), $detenteur (string|null)
 */
$titre_document = 'JOURNAL DE PETITE CAISSE';
$sous_titre = 'Du ' . date_fr($debut) . ' au ' . date_fr($fin);
include __DIR__ . '/_entete.php';
?>
<table class="grille">
  <tr><th style="width:11%">Date</th><th style="width:41%">Libellé</th><th>Entrée</th><th>Sortie</th><th>Balance</th></tr>
  <tr class="total"><td colspan="4">Solde initial au <?= e(date_fr($debut)) ?></td>
      <td class="n"><?= e(htg($journal['solde_initial'], false)) ?></td></tr>
  <?php foreach ($journal['lignes'] as $l): ?>
  <tr>
    <td><?= e(date_fr($l['date'])) ?></td>
    <td><?= e(mb_substr((string)$l['libelle'], 0, 62)) ?>
        <?= !empty($l['depense_reportee']) ? ' <b>(dépense reportée)</b>' : '' ?>
        <?php if (!empty($l['observation'])): ?><br><span style="font-size:7.5pt"><?= e($l['observation']) ?></span><?php endif; ?></td>
    <td class="n"><?= $l['entree'] > 0 ? e(htg($l['entree'], false)) : '' ?></td>
    <td class="n"><?= $l['sortie'] > 0 ? e(htg($l['sortie'], false)) : '' ?></td>
    <td class="n"><?= e(htg($l['balance'], false)) ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$journal['lignes']): ?><tr><td colspan="5">Aucun mouvement sur la période.</td></tr><?php endif; ?>
  <tr class="total"><td colspan="4">Solde théorique au <?= e(date_fr($fin)) ?></td>
      <td class="n"><?= e(htg($journal['solde_final'])) ?></td></tr>
</table>

<?php if (!empty($arrete)): ?>
<table class="grille" style="margin-top:8px">
  <tr><th colspan="2">Arrêté de caisse du <?= e(date_fr($arrete['date'])) ?></th></tr>
  <tr><td>Solde théorique</td><td class="n"><?= e(htg((float)$arrete['solde_theorique'], false)) ?></td></tr>
  <tr><td>Espèces comptées</td><td class="n"><?= e(htg((float)$arrete['solde_constate'], false)) ?></td></tr>
  <tr class="total"><td>Écart</td><td class="n"><?= e(htg((float)$arrete['ecart'])) ?></td></tr>
</table>
<?php if (!empty($arrete['commentaire'])): ?>
<p class="note"><b>Explication de l'écart :</b> <?= e($arrete['commentaire']) ?></p>
<?php endif; ?>
<?php endif; ?>

<p class="note" style="margin-top:8px">La caisse fonctionne en fonds fixe. Son approvisionnement se fait par chèque
  émis au nom d'une personne intermédiaire nommément désignée, jamais par un chèque au porteur. Le renflouement
  n'est possible qu'après justification des dépenses antérieures et arrêté de caisse daté et signé.</p>

<table class="sig">
  <tr>
    <td><span class="q">Détenteur du fonds</span><br>
        <span class="note"><?= !empty($detenteur) ? e($detenteur) . '<br>' : '' ?>Date et signature :</span></td>
    <td><span class="q">Responsable Administratif et Financier</span><br><span class="note">Date et signature :</span></td>
    <td><span class="q">Visa du Coordinateur</span><br><span class="note">Date et signature :</span></td>
  </tr>
</table>
<div class="pied">Bousòl &middot; journal de caisse &middot; projet <?= e($entete['projet']) ?></div>
