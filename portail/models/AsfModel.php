<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

final class AsfModel
{
    public static function findByImputation(int $imputationId): ?array
    {
        $stmt = db()->prepare(
            'SELECT a.*, u.nom_complet AS certifie_par_nom
               FROM attestations_service_fait a
               LEFT JOIN users u ON a.certifie_par = u.id
              WHERE a.imputation_id = ?'
        );
        $stmt->execute([$imputationId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT a.*,
                    i.numero AS imputation_numero, i.date_depense, i.description AS imputation_description,
                    c.numero AS contrat_numero, c.type_contrat,
                    p.id AS prestataire_id, p.nom_complet AS prestataire, p.email AS prestataire_email,
                    u.nom_complet AS certifie_par_nom
               FROM attestations_service_fait a
               JOIN imputations i ON a.imputation_id = i.id
               JOIN contrats c    ON i.contrat_id = c.id
               JOIN prestataires p ON c.prestataire_id = p.id
               LEFT JOIN users u  ON a.certifie_par = u.id
              WHERE a.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $stmt = db()->prepare(
            'INSERT INTO attestations_service_fait
                (numero, imputation_id, livrables_realises, statut_livrables, taux_presence,
                 pieces_jointes_json, observations, certifie_coordinateur, sig_coord_scan,
                 date_certification, certifie_par)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $data['numero'],
            (int)$data['imputation_id'],
            $data['livrables_realises'],
            $data['statut_livrables'],
            $data['taux_presence'] ?? null,
            !empty($data['pieces_jointes_json']) ? json_encode($data['pieces_jointes_json']) : null,
            $data['observations'] ?? null,
            (int)($data['certifie_coordinateur'] ?? 1),
            $data['sig_coord_scan'] ?? null,
            $data['date_certification'] ?? date('Y-m-d H:i:s'),
            (int)$data['certifie_par'],
        ]);
        return (int)db()->lastInsertId();
    }

    /**
     * Liste des F02 valides en attente d'ASF.
     */
    public static function pendingList(): array
    {
        $sql = "SELECT i.id AS imputation_id, i.numero AS f01_numero, i.date_depense,
                       i.description, i.montant,
                       c.numero AS contrat_numero, c.type_contrat,
                       p.nom_complet AS prestataire,
                       d.id AS f02_id, d.numero AS f02_numero
                  FROM decaissements d
                  JOIN imputations i  ON d.imputation_id = i.id
                  JOIN contrats c     ON i.contrat_id = c.id
                  JOIN prestataires p ON c.prestataire_id = p.id
                  LEFT JOIN attestations_service_fait a ON a.imputation_id = i.id
                 WHERE d.valide_administrateur = 1 AND a.id IS NULL
                 ORDER BY d.date_validation DESC LIMIT 200";
        return db()->query($sql)->fetchAll();
    }
}
