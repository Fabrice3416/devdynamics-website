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
-- Idempotente, et sans lecture d'information_schema : la connexion de phpMyAdmin
-- se voit refuser cette base sur cet hebergement, la ou celle de PHP y accede.
-- IF NOT EXISTS sur un ALTER est une extension MariaDB, qui est le moteur ici.
--
--   Import phpMyAdmin, ou :
--   mysql -u UTILISATEUR -p u218662965_bousol < migration_autorisation_parentale.sql
-- =====================================================================

ALTER TABLE beneficiaires
  ADD COLUMN IF NOT EXISTS autorisation_parentale_fichier_id INT UNSIGNED NULL
    COMMENT 'Exigee si tranche_age = moins_18 (CDC 3.2)' AFTER tranche_age;

ALTER TABLE beneficiaires
  ADD CONSTRAINT IF NOT EXISTS fk_beneficiaires_autorisation
    FOREIGN KEY (autorisation_parentale_fichier_id) REFERENCES fichiers(id);

-- Controle : la ligne suivante doit apparaitre. Rien ne s'affiche si la colonne
-- manque, et l'ALTER ci-dessus aura alors signale son erreur.
SHOW COLUMNS FROM beneficiaires LIKE 'autorisation_parentale_fichier_id';
