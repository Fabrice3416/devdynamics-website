<?php
declare(strict_types=1);

/**
 * Modele d'acces a la table decaissements (F02).
 * Le calcul DGI 2% est garanti par le trigger MySQL trg_dgi_insert/update.
 */

require_once __DIR__ . '/../includes/db.php';

final class DecaissementModel
{
    public static function findByImputation(int $imputationId): ?array
    {
        $stmt = db()->prepare('SELECT * FROM decaissements WHERE imputation_id = ?');
        $stmt->execute([$imputationId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT d.*,
                    i.numero AS imputation_numero, i.date_depense, i.description,
                    i.rubrique, i.nature_paiement,
                    c.numero AS contrat_numero, c.type_contrat, c.is_cps01,
                    p.nom_complet AS prestataire,
                    lb.code AS ligne_code, lb.libelle AS ligne_libelle,
                    u.nom_complet AS valide_par_nom
               FROM decaissements d
               JOIN imputations i  ON d.imputation_id = i.id
               JOIN contrats c     ON i.contrat_id = c.id
               JOIN prestataires p ON c.prestataire_id = p.id
               JOIN lignes_budgetaires lb ON i.ligne_budgetaire_id = lb.id
               LEFT JOIN users u   ON d.valide_par = u.id
              WHERE d.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Cree un F02 a partir d'un F01. Le trigger MySQL calcule DGI et net automatiquement.
     */
    public static function create(array $data): int
    {
        $stmt = db()->prepare(
            'INSERT INTO decaissements
                (numero, imputation_id, montant_brut, montant_allocation,
                 mode_paiement, numero_cheque, numero_cheque_allocation,
                 justification_virement, preuve_paiement_scan, facture_scan,
                 valide_administrateur, sig_admin_scan, date_validation, valide_par, observations)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $data['numero'],
            (int)$data['imputation_id'],
            $data['montant_brut'],
            $data['montant_allocation'] ?? null,
            $data['mode_paiement'],
            $data['numero_cheque'] ?? null,
            $data['numero_cheque_allocation'] ?? null,
            $data['justification_virement'] ?? null,
            $data['preuve_paiement_scan'] ?? null,
            $data['facture_scan'] ?? null,
            (int)($data['valide_administrateur'] ?? 1),
            $data['sig_admin_scan'] ?? null,
            $data['date_validation'] ?? date('Y-m-d H:i:s'),
            (int)$data['valide_par'],
            $data['observations'] ?? null,
        ]);
        return (int)db()->lastInsertId();
    }

    /**
     * Liste les F02 d'un mois donne (pour rapprochement et journal).
     */
    public static function listByMonth(int $mois, int $annee): array
    {
        $stmt = db()->prepare(
            'SELECT d.*, i.date_depense, i.description,
                    c.numero AS contrat_numero,
                    p.nom_complet AS prestataire
               FROM decaissements d
               JOIN imputations i  ON d.imputation_id = i.id
               JOIN contrats c     ON i.contrat_id = c.id
               JOIN prestataires p ON c.prestataire_id = p.id
              WHERE MONTH(i.date_depense) = ? AND YEAR(i.date_depense) = ?
              ORDER BY i.date_depense ASC'
        );
        $stmt->execute([$mois, $annee]);
        return $stmt->fetchAll();
    }
}
