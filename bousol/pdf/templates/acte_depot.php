<?php
/**
 * ACTE DE DEPOT DE SPECIMEN DE SIGNATURE (CDC 1.8).
 * Imprime, signe a la main par le titulaire, scanne, puis televerse avec le specimen.
 * Variables : $titulaire (nom), $fonction, $role (libelle), $mandataire (bool), $email
 */
$titre_document = 'ACTE DE DÉPÔT DE SPÉCIMEN DE SIGNATURE';
$sous_titre = 'Outil de pilotage Bousòl · Projet ' . $entete['projet'];
include __DIR__ . '/_entete.php';
?>
<table class="meta">
  <tr><td class="l">Titulaire</td><td><?= e($titulaire) ?></td><td class="l">Fonction</td><td><?= e($fonction) ?></td></tr>
  <tr><td class="l">Rôle dans Bousòl</td><td><?= e($role) ?></td><td class="l">Mandataire du compte</td><td><?= $mandataire ? 'Oui — signataire du compte ' . e($entete['compte']) : 'Non' ?></td></tr>
  <tr><td class="l">Identifiant</td><td><?= e($email) ?></td><td class="l">Date de l'acte</td><td>___ / ___ / ______</td></tr>
</table>

<p class="note" style="margin-top:8px">Je soussigné(e) <b><?= e($titulaire) ?></b>, <?= e($fonction) ?> au sein de <?= e($entete['organisation']) ?>, déclare ce qui suit :</p>
<ol class="note" style="margin-top:4px">
  <li>Je dépose ci-dessous le spécimen de ma signature, dont l'image sera conservée chiffrée dans le coffre de l'outil Bousòl.</li>
  <li>J'autorise Bousòl à apposer cette image, en mon nom et à ma seule demande, sur les documents que je valide dans l'outil au titre de mon rôle<?= $mandataire ? ' et, pour les règlements, de ma qualité de mandataire du compte' : '' ?>.</li>
  <li>Je reconnais que mon authentification dans Bousòl, suivie d'une réauthentification distincte au moment de chaque apposition, vaut signature de ma part, et que chaque apposition est horodatée, nominative, non répudiable et vérifiable par le code imprimé sous le bloc de signature.</li>
  <li>Je m'engage à ne communiquer mes identifiants à personne, à signaler sans délai toute compromission, et je sais que je peux révoquer ce spécimen à tout moment sans effet sur les appositions déjà faites.</li>
  <li>Je n'apposerai jamais ma signature de règlement sur un règlement dont je suis le bénéficiaire (article 5 du contrat de subvention, conflits d'intérêts).</li>
</ol>

<table class="sig" style="margin-top:10px">
  <tr>
    <td style="height:38mm"><span class="q">Spécimen de signature du titulaire</span><br><span class="note">(signer à la main dans ce cadre, à l'encre foncée)</span></td>
    <td style="height:38mm"><span class="q">Signature manuscrite du titulaire</span><br><span class="note">Lu et approuvé, date :</span></td>
    <td style="height:38mm"><span class="q">Reçu par le Coordinateur</span><br><span class="note">Date, nom et signature :</span></td>
  </tr>
</table>
<div class="pied">Cet acte, signé à la main puis numérisé, est la pièce sans laquelle aucune apposition électronique n'est possible (cahier des charges, § 1.8).</div>
