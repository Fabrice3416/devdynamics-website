<?php
/**
 * Bloc d'en-tete commun (mPDF). Variables : $entete (PdfService::entete()),
 * $titre_document, $sous_titre (optionnel), $exemplaire (optionnel).
 */
?>
<style>
  /* Direction visuelle Bousol : sobre, organique, un seul accent olive */
  body { font-family: dejavuserif, serif; font-size: 9.5pt; color: #2a2a28; }
  .ent { width: 100%; border-bottom: 1.5px solid #4c5a47; margin-bottom: 8px; }
  .ent td { vertical-align: top; padding: 2px 0; }
  .ent .org { font-size: 12pt; font-weight: bold; color: #4c5a47; }
  .ent .bail { font-size: 8pt; color: #6b6a66; }
  .ent .ex { font-size: 8pt; text-align: right; color: #6b6a66; }
  .titre { font-size: 14pt; font-weight: bold; text-align: center; margin: 8px 0 2px 0; color: #4c5a47; }
  .sous { text-align: center; font-size: 9pt; color: #6b6a66; margin-bottom: 8px; }
  .meta { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
  .meta td { border: 1px solid #c9c4ba; padding: 3px 5px; font-size: 8.5pt; }
  .meta td.l { width: 26%; background: #efede8; font-weight: bold; }
  .grille { width: 100%; border-collapse: collapse; }
  .grille th { background: #efede8; color: #2a2a28; font-size: 8pt; padding: 4px 3px; border: 1px solid #c9c4ba; border-bottom: 1.5px solid #4c5a47; }
  .grille td { border: 1px solid #c9c4ba; padding: 3px 4px; font-size: 8.5pt; }
  .grille td.n { text-align: right; white-space: nowrap; }
  .grille tr.total td { background: #efede8; font-weight: bold; }
  .grille td.vide { height: 14px; }
  .sig { width: 100%; margin-top: 14px; border-collapse: collapse; }
  .sig td { width: 33%; border: 1px solid #c9c4ba; padding: 6px; vertical-align: top; height: 70px; font-size: 8.5pt; }
  .sig .q { font-weight: bold; }
  .pied { font-size: 7.5pt; color: #6b6a66; margin-top: 8px; border-top: 1px solid #c9c4ba; padding-top: 3px; }
  .note { font-size: 8pt; color: #2a2a28; }
</style>
<table class="ent">
  <tr>
    <td style="width:19%"><img src="<?= e(root_dir()) ?>/assets/images/logo.jpg" style="width:32mm"></td>
    <td style="width:56%">
      <span class="org"><?= e($entete['organisation']) ?></span><br>
      <span class="bail">Association éducative et technologique à but non lucratif &middot; <?= e($entete['lieu']) ?> &middot; dev-dynamics.org</span><br>
      <span class="bail">Projet <b><?= e($entete['projet']) ?></b></span><br>
      <span class="bail"><?= e($entete['bailleur']) ?></span><br>
      <span class="bail">Contrat de subvention n° <b><?= e($entete['contrat']) ?></b></span>
    </td>
    <td class="ex">
      <?php if (!empty($exemplaire)): ?><b><?= e($exemplaire) ?></b><br><?php endif; ?>
      <?php if (!empty($numero_document)): ?>N° <?= e($numero_document) ?><br><?php endif; ?>
      Édité le <?= e(date_fr(date('Y-m-d'))) ?>
    </td>
  </tr>
</table>
<div class="titre"><?= e($titre_document ?? '') ?></div>
<?php if (!empty($sous_titre)): ?><div class="sous"><?= e($sous_titre) ?></div><?php endif; ?>
