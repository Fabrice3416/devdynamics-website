<?php
/**
 * RAPPORT NARRATIF AU MODELE DE L'ANNEXE 4 (CDC 6.3).
 * Onze sections : quatre entierement produites par Bousol, sept assistees - Bousol
 * affiche les donnees de la periode a cote du champ de redaction.
 * Variables : $rapport, $commentaire, $elements, $indicateurs, $activites,
 *             $difficultes, $solde, $reussite, $adoption
 */
$titre_document = 'RAPPORT NARRATIF';
$sous_titre = 'Modèle Annexe 4 · du ' . date_fr($rapport['periode_debut'])
            . ' au ' . date_fr($rapport['periode_fin']);
include __DIR__ . '/_entete.php';
?>
<p class="note"><b>1. Identification du bénéficiaire et du projet</b> <i>— section produite</i></p>
<table class="meta">
  <tr><td class="l">Bénéficiaire</td><td><?= e($entete['organisation']) ?></td>
      <td class="l">Projet</td><td><?= e($entete['projet']) ?></td></tr>
  <tr><td class="l">Contrat de subvention</td><td><?= e($entete['contrat']) ?></td>
      <td class="l">Bailleur</td><td><?= e($entete['bailleur']) ?></td></tr>
  <tr><td class="l">Période d'exécution</td>
      <td colspan="3"><?= $entete['debut'] ? e(date_fr($entete['debut'])) . ' au ' . e(date_fr((string)$entete['fin'])) : 'à saisir' ?></td></tr>
</table>

<p class="note" style="margin-top:6px"><b>Bloc financier</b> <i>— calculé, jamais estimé</i></p>
<table class="grille">
  <tr><td style="width:62%">Préfinancements reçus</td><td class="n"><?= e(htg($solde['prefinancements'], false)) ?></td></tr>
  <tr><td>Total des dépenses encourues</td><td class="n"><?= e(htg($solde['total_eligible'], false)) ?></td></tr>
  <tr><td>Coûts directs constatés</td><td class="n"><?= e(htg($solde['directs'], false)) ?></td></tr>
  <tr><td>Coûts indirects, <?= number_format((float)$solde['taux_indirect'] * 100, 2, ',', ' ') ?> % plafonnés</td>
      <td class="n"><?= e(htg($solde['indirects'], false)) ?></td></tr>
  <tr class="total"><td>Solde <?= $solde['sens'] === 'a_recevoir' ? 'à recevoir' : 'à rembourser' ?></td>
      <td class="n"><?= e(htg($solde['solde'])) ?></td></tr>
</table>

<p class="note" style="margin-top:8px"><b>2. Tableau des résultats</b> <i>— section produite, reprise du cadre logique</i></p>
<table class="grille">
  <tr><th style="width:9%">Code</th><th>Intitulé</th><th>Indicateur et cible</th><th style="width:14%">Atteint</th></tr>
  <?php foreach ($elements as $el): ?>
  <tr class="total"><td><?= e($el['code']) ?></td><td colspan="3"><?= e(mb_substr($el['libelle'], 0, 96)) ?></td></tr>
    <?php foreach ($indicateurs as $i): if ((int)$i['element_id'] !== (int)$el['id']) continue;
          $rel = releves_indicateur((int)$i['id']); ?>
    <tr>
      <td></td>
      <td><?= e(mb_substr($i['libelle'], 0, 64)) ?></td>
      <td><?= e((string)$i['cible_valeur']) ?>
          <?= $i['echeance_mois'] ? ' — M' . (int)$i['echeance_mois'] : '' ?></td>
      <td><?= $rel ? e($rel[0]['valeur_atteinte']) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
  <?php endforeach; ?>
  <?php if (!$elements): ?><tr><td colspan="4">Le cadre logique n'est pas encore saisi.</td></tr><?php endif; ?>
</table>

<p class="note" style="margin-top:8px"><b>3. Description des activités réalisées</b> <i>— section assistée</i></p>
<table class="grille">
  <tr><th style="width:9%">Code</th><th>Activité</th><th style="width:14%">État</th><th style="width:22%">Livrable</th></tr>
  <?php foreach ($activites as $a): ?>
  <tr>
    <td><?= e($a['code']) ?></td>
    <td><?= e(mb_substr($a['libelle'], 0, 66)) ?></td>
    <td><?= e(STATUTS_ACTIVITE[$a['statut']] ?? $a['statut']) ?></td>
    <td><?= $a['livrable_attendu'] ? e(mb_substr($a['livrable_attendu'], 0, 30))
             . ($a['livrable_fichier_id'] ? ' ✓' : ' (attendu)') : '' ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<table class="grille"><tr><td style="height:20mm"><?= nl2br(e($commentaire ?: '')) ?></td></tr></table>

<p class="note" style="margin-top:8px"><b>4. Difficultés et ajustements</b> <i>— alimentée en continu</i></p>
<table class="grille">
  <tr><th style="width:11%">Date</th><th>Difficulté</th><th>Mesure corrective</th></tr>
  <?php foreach ($difficultes as $d): ?>
  <tr><td><?= e(date_fr($d['date'])) ?></td>
      <td><?= e(mb_substr($d['description'], 0, 74)) ?></td>
      <td><?= e(mb_substr((string)$d['mesure_corrective'], 0, 74)) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$difficultes): ?><tr><td colspan="3">Aucune difficulté consignée.</td></tr><?php endif; ?>
</table>

<p class="note" style="margin-top:8px"><b>5. Plan d'action pour la période suivante</b>
  <i>— section produite, à partir des activités non encore réalisées</i></p>
<table class="grille">
  <tr><th style="width:9%">Code</th><th>Activité restant à réaliser</th><th style="width:14%">Période prévue</th></tr>
  <?php $reste = array_filter($activites, fn($a) => !in_array($a['statut'], ['realisee', 'abandonnee'], true));
        foreach ($reste as $a): ?>
  <tr><td><?= e($a['code']) ?></td><td><?= e(mb_substr($a['libelle'], 0, 78)) ?></td>
      <td><?= $a['mois_debut'] ? 'M' . (int)$a['mois_debut'] . ($a['mois_fin'] ? ' → M' . (int)$a['mois_fin'] : '') : '' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$reste): ?><tr><td colspan="3">Toutes les activités sont réalisées.</td></tr><?php endif; ?>
</table>

<p class="note" style="margin-top:8px"><b>6. Bénéficiaires, questions transversales et visibilité</b> <i>— sections assistées</i></p>
<table class="meta">
  <tr><td class="l">Exercices pratiques</td>
      <td><?= (int)$reussite['reussites'] ?> / <?= (int)$reussite['evalues'] ?>
          <?= $reussite['taux'] === null ? '' : '(' . number_format((float)$reussite['taux'], 1, ',', ' ') . ' %)' ?></td>
      <td class="l">Adoption</td>
      <td><?= (int)$adoption['actives'] ?> / <?= (int)$adoption['enquetees'] ?></td></tr>
</table>
<table class="grille"><tr><td style="height:24mm"></td></tr></table>

<p class="note" style="margin-top:8px"><b>7. Liste des annexes</b> <i>— section produite</i></p>
<p class="note">Rapport financier au modèle de l'annexe G · ventilation détaillée des dépenses ·
  rapprochement bancaire de la période · liasse des pièces justificatives ·
  cadre logique actualisé, version <?= (int)($rapport['version_cadre_ref'] ?? 0) ?>, figée avec le présent rapport.</p>

<table class="sig">
  <tr>
    <td><span class="q">Le Coordinateur du projet</span><br><span class="note">Date, nom et signature :</span></td>
    <td><span class="q">Le représentant légal</span><br><span class="note">Date, nom et signature :</span></td>
    <td><span class="q">Accusé de réception du bailleur</span><br><span class="note">Date et cachet :</span></td>
  </tr>
</table>
<div class="pied">Bousòl &middot; rapport narratif, modèle Annexe 4 &middot; <?= e($entete['projet']) ?></div>
