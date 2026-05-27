<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

final class FicheReglementModel
{
    public static function findByImputation(int $imputationId): ?array
    {
        $stmt = db()->prepare('SELECT * FROM fiches_reglement WHERE imputation_id = ?');
        $stmt->execute([$imputationId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT fr.*,
                    i.numero AS imputation_numero, i.date_depense, i.description AS imputation_description,
                    i.is_retroactif,
                    c.numero AS contrat_numero, c.type_contrat, c.is_cps01,
                    p.id AS prestataire_id, p.nom_complet AS prestataire, p.email AS prestataire_email,
                    d.montant_brut, d.dgi_2pct, d.net_honoraires, d.total_net_a_verser,
                    d.montant_allocation, d.mode_paiement, d.numero_cheque AS f02_numero_cheque,
                    d.numero_cheque_allocation
               FROM fiches_reglement fr
               JOIN imputations i  ON fr.imputation_id = i.id
               JOIN contrats c     ON i.contrat_id = c.id
               JOIN prestataires p ON c.prestataire_id = p.id
               JOIN decaissements d ON d.imputation_id = i.id
              WHERE fr.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $stmt = db()->prepare(
            'INSERT INTO fiches_reglement
                (numero, imputation_id, date_paiement, numero_cheque, evaluation_livrables)
             VALUES (?,?,?,?,?)'
        );
        $stmt->execute([
            $data['numero'],
            (int)$data['imputation_id'],
            $data['date_paiement'],
            $data['numero_cheque'] ?? null,
            $data['evaluation_livrables'],
        ]);
        return (int)db()->lastInsertId();
    }

    /**
     * Met a jour les signatures (le trigger MySQL ferme la date si les 3 sigs sont OK).
     */
    public static function updateSignatures(int $id, array $sigs): void
    {
        $allowed = ['sig_prestataire','sig_administrateur','sig_coordinateur'];
        $sets = [];
        $params = [];
        foreach ($sigs as $key => $val) {
            if (in_array($key, $allowed, true)) {
                $sets[] = "$key = ?";
                $params[] = (int)$val;
            }
        }
        if (empty($sets)) return;
        $params[] = $id;
        $sql = 'UPDATE fiches_reglement SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
    }

    public static function listAll(): array
    {
        $sql = "SELECT fr.*, i.numero AS f01_numero, i.is_retroactif,
                       c.numero AS contrat_numero, c.is_cps01,
                       p.nom_complet AS prestataire
                  FROM fiches_reglement fr
                  JOIN imputations i  ON fr.imputation_id = i.id
                  JOIN contrats c     ON i.contrat_id = c.id
                  JOIN prestataires p ON c.prestataire_id = p.id
                 ORDER BY fr.id DESC LIMIT 200";
        return db()->query($sql)->fetchAll();
    }
}
