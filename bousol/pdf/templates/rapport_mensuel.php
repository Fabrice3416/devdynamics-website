<?php
/**
 * RAPPORT MENSUEL (CDC 6.2). Aucun gabarit n'est impose : ce rapport releve de
 * l'engagement de suivi pris dans la note complete et non du contrat. Bousol le
 * produit selon la structure annoncee, la seule saisie propre etant le commentaire.
 * Variables : $rapport, $commentaire, $activites, $difficultes, $reussite, $adoption, $solde
 */
$titre_document = 'RAPPORT MENSUEL D\'AVANCEMENT';
$sous_titre = 'Du ' . date_fr($rapport['periode_debut']) . ' au ' . date_fr($rapport['periode_fin']);
include __DIR__ . '/_entete.php';
$mois = (int)date('n', strtotime((string)$rapport['periode_fin']));
?>
<p class="note"><b>1. État d'avancement des activités au regard du calendrier prévisionnel</b></p>
<table class="grille">
  <tr><th style="width:9%">Code</th><th>Activité</th><th style="width:12%">Période prévue</th><th style="width:14%">État</th></tr>
  <?php foreach ($activites as $a): ?>
  <tr>
    <td><?= e($a['code']) ?></td>
    <td><?= e(mb_substr($a['libelle'], 0, 78)) ?>
        <?= $a['categorie'] === 'visibilite' ? ' <i>(visibilité)</i>' : '' ?></td>
    <td><?= $a['mois_debut'] ? 'M' . (int)$a['mois_debut'] . ($a['mois_fin'] ? ' → M' . (int)$a['mois_fin'] : '') : '' ?></td>
    <td><?= e(STATUTS_ACTIVITE[$a['statut']] ?? $a['statut']) ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$activites): ?><tr><td colspan="4">Aucune activité enregistrée.</td></tr><?php endif; ?>
</table>

<p class="note" style="margin-top:8px"><b>2. Difficultés rencontrées et ajustements apportés</b></p>
<table class="grille">
  <tr><th style="width:11%">Date</th><th style="width:9%">Activité</th><th>Difficulté</th><th>Mesure corrective</th></tr>
  <?php foreach ($difficultes as $d): ?>
  <tr>
    <td><?= e(date_fr($d['date'])) ?></td>
    <td><?= e($d['activite_code']) ?></td>
    <td><?= e(mb_substr($d['description'], 0, 70)) ?></td>
    <td><?= e(mb_substr((string)$d['mesure_corrective'], 0, 70)) ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$difficultes): ?><tr><td colspan="4">Aucune difficulté consignée sur la période.</td></tr><?php endif; ?>
</table>

<p class="note" style="margin-top:8px"><b>3. Formation et adoption</b></p>
<table class="meta">
  <tr><td class="l">Exercices pratiques</td>
      <td><?= (int)$reussite['reussites'] ?> réussites sur <?= (int)$reussite['evalues'] ?> évaluations
          <?= $reussite['taux'] === null ? '' : '(' . number_format((float)$reussite['taux'], 1, ',', ' ') . ' %, cible 80 %)' ?></td></tr>
  <tr><td class="l">Adoption</td>
      <td><?= (int)$adoption['actives'] ?> organisation(s) en usage actif sur <?= (int)$adoption['enquetees'] ?> enquêtée(s)</td></tr>
</table>

<p class="note" style="margin-top:8px"><b>4. Situation financière</b></p>
<table class="meta">
  <tr><td class="l">Coûts directs constatés</td><td><?= e(htg($solde['directs'])) ?></td>
      <td class="l">Enveloppe indirecte</td><td><?= e(htg($solde['indirects'])) ?></td></tr>
  <tr><td class="l">Préfinancements reçus</td><td><?= e(htg($solde['prefinancements'])) ?></td>
      <td class="l">Solde <?= $solde['sens'] === 'a_recevoir' ? 'à recevoir' : 'à rembourser' ?></td>
      <td><?= e(htg($solde['solde'])) ?></td></tr>
</table>

<p class="note" style="margin-top:8px"><b>5. Commentaire d'analyse</b></p>
<table class="grille"><tr><td style="min-height:22mm"><?= nl2br(e($commentaire ?: '')) ?></td></tr></table>

<table class="sig">
  <tr>
    <td><span class="q">Le Coordinateur du projet</span><br><span class="note">Date, nom et signature :</span></td>
    <td><span class="q">Transmis à</span><br><span class="note"><?= e($entete['bailleur']) ?><br>Date :</span></td>
    <td><span class="q">Accusé de réception</span><br><span class="note">Date et cachet :</span></td>
  </tr>
</table>
<div class="pied">Bousòl &middot; rapport mensuel &middot; <?= e($entete['projet']) ?> &middot; mois <?= $mois ?></div>
