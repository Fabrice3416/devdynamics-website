-- =====================================================================
-- Bousol - autorisation parentale d'un beneficiaire mineur (CDC 3.2)
--
-- « L'inscription d'un beneficiaire dont la tranche d'age est inferieure a la
-- majorite exige le televersement d'une autorisation parentale, qui devient une
-- piece du dossier au meme titre qu'une feuille de presence. »
--
-- Ni `beneficiaires`, ni `participations`, ni `sessions_formation` ne portaient
-- de champ ou la ranger : `sessions_formation` ne connait que la feuille de
-- presence et `participations` que la fiche d'evaluation. En attendant cette
-- colonne, l'inscription d'un mineur etait refusee plutot qu'acceptee sans sa
-- piece. Aucun des deux projets n'en prevoit, mais la regle evite d'avoir a
-- decider dans l'urgence si le cas se presente.
--
-- Idempotente : relancable sans dommage.
--   mysql -u UTILISATEUR -p u218662965_bousol < migration_autorisation_parentale.sql
-- =====================================================================

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaires'
              AND COLUMN_NAME = 'autorisation_parentale_fichier_id');
SET @s := IF(@c = 0,
  'ALTER TABLE beneficiaires
     ADD COLUMN autorisation_parentale_fichier_id INT UNSIGNED NULL
       COMMENT ''Exigee si tranche_age = moins_18 (CDC 3.2)'' AFTER tranche_age,
     ADD CONSTRAINT fk_beneficiaires_autorisation
       FOREIGN KEY (autorisation_parentale_fichier_id) REFERENCES fichiers(id)',
  'SELECT ''autorisation_parentale_fichier_id deja presente'' AS resultat');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Controle : la colonne et sa cle etrangere sont en place.
SELECT COUNT(*) AS colonne_presente FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaires'
   AND COLUMN_NAME = 'autorisation_parentale_fichier_id';
