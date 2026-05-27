<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

final class NoteHonoraireModel
{
    public static function findByImputation(int $imputationId): ?array
    {
        $stmt = db()->prepare('SELECT * FROM notes_honoraires WHERE imputation_id = ?');
        $stmt->execute([$imputationId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT nh.*, i.numero AS imputation_numero,
                    c.numero AS contrat_numero, c.type_contrat,
                    p.nom_complet AS prestataire, p.email AS prestataire_email
               FROM notes_honoraires nh
               JOIN imputations i ON nh.imputation_id = i.id
               JOIN contrats c    ON i.contrat_id = c.id
               JOIN prestataires p ON c.prestataire_id = p.id
              WHERE nh.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $stmt = db()->prepare(
            'INSERT INTO notes_honoraires
                (numero, imputation_id, token_id, description_prestation, montant_brut,
                 mode_paiement, coordonnees_bancaires, certifie_prestataire,
                 date_soumission, sig_presta_scan)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $data['numero'],
            (int)$data['imputation_id'],
            isset($data['token_id']) ? (int)$data['token_id'] : null,
            $data['description_prestation'],
            $data['montant_brut'],
            $data['mode_paiement'],
            $data['coordonnees_bancaires'] ?? null,
            (int)($data['certifie_prestataire'] ?? 1),
            $data['date_soumission'] ?? date('Y-m-d H:i:s'),
            $data['sig_presta_scan'] ?? null,
        ]);
        return (int)db()->lastInsertId();
    }

    public static function listSubmitted(): array
    {
        $sql = "SELECT nh.*, i.numero AS f01_numero, i.date_depense,
                       c.numero AS contrat_numero,
                       p.nom_complet AS prestataire,
                       (SELECT id FROM fiches_reglement fr WHERE fr.imputation_id = i.id LIMIT 1) AS frp_id
                  FROM notes_honoraires nh
                  JOIN imputations i  ON nh.imputation_id = i.id
                  JOIN contrats c     ON i.contrat_id = c.id
                  JOIN prestataires p ON c.prestataire_id = p.id
                 ORDER BY nh.date_soumission DESC LIMIT 200";
        return db()->query($sql)->fetchAll();
    }
}
