<?php
/**
 * DEMANDE DE PAIEMENT DE TRANCHE, au modele transmis par l'UGP (CDC 4.10).
 * Variables : $demande, $pieces, $tresorerie
 */
$titre_document = 'DEMANDE DE PAIEMENT';
$sous_titre = 'Tranche n° ' . (int)$demande['tranche_numero'] . ' du contrat de subvention';
include __DIR__ . '/_entete.php';
?>
<table class="meta">
  <tr><td class="l">Bénéficiaire</td><td><?= e($entete['organisation']) ?></td>
      <td class="l">Projet</td><td><?= e($entete['projet']) ?></td></tr>
  <tr><td class="l">Contrat de subvention</td><td><?= e($entete['contrat']) ?></td>
      <td class="l">Date de la demande</td><td><?= e(date_fr($demande['date'])) ?></td></tr>
  <tr><td class="l">Compte à créditer</td><td colspan="3"><?= e($entete['compte']) ?: '—' ?></td></tr>
</table>

<table class="grille" style="margin-top:8px">
  <tr><th style="width:70%">Objet de la demande</th><th>Montant</th></tr>
  <tr><td>Versement de la tranche n° <?= (int)$demande['tranche_numero'] ?>
      <?php if (!empty($demande['declencheur'])): ?><br><span style="font-size:8pt"><?= e($demande['declencheur']) ?></span><?php endif; ?></td>
      <td class="n"><?= e(htg((float)$demande['montant'], false)) ?></td></tr>
  <tr class="total"><td>Montant demandé, en gourdes</td><td class="n"><?= e(htg((float)$demande['montant'])) ?></td></tr>
</table>

<table class="grille" style="margin-top:8px">
  <tr><th colspan="2">Situation des versements</th></tr>
  <tr><td style="width:70%">Total contractuel des tranches</td><td class="n"><?= e(htg($tresorerie['attendu'], false)) ?></td></tr>
  <tr><td>Déjà reçu, constaté sur avis de crédit</td><td class="n"><?= e(htg($tresorerie['recu'], false)) ?></td></tr>
  <tr class="total"><td>Reste à recevoir après le présent versement</td>
      <td class="n"><?= e(htg(round($tresorerie['a_recevoir'] - (float)$demande['montant'], 2))) ?></td></tr>
</table>

<p class="note" style="margin-top:8px"><b>Pièces jointes à la présente demande</b></p>
<table class="grille">
  <tr><th style="width:8%">N°</th><th>Pièce</th><th style="width:16%">Jointe</th></tr>
  <?php foreach ($pieces as $p): ?>
  <tr>
    <td><?= (int)$p['ordre'] ?></td>
    <td><?= e($p['libelle']) ?></td>
    <td><?= $p['statut'] === 'recue' ? 'Oui' : ($p['statut'] === 'sans_objet' ? 'Sans objet' : 'Attendue') ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<?php if ((int)$demande['tranche_numero'] !== 1): ?>
<p class="note">Le rapport joint est figé : la copie transmise et la version conservée par le bénéficiaire sont
  rigoureusement identiques.</p>
<?php endif; ?>
<p class="note">Le versement intervient dans les cinq jours suivant la validation des documents. La date de
  transmission de la présente demande ouvre ce délai.</p>

<table class="sig">
  <tr>
    <td><span class="q">Le représentant légal</span><br><span class="note">Date, nom et signature :</span></td>
    <td><span class="q">Le Coordinateur du projet</span><br><span class="note">Date, nom et signature :</span></td>
    <td><span class="q">Réception par l'UGP</span><br><span class="note">Date, cachet et signature :</span></td>
  </tr>
</table>
<div class="pied">Bousòl &middot; demande de paiement &middot; tranche <?= (int)$demande['tranche_numero'] ?>
  &middot; <?= e($entete['projet']) ?></div>
