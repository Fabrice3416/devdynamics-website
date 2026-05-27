<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

final class CaisseModel
{
    public static function solde(): float
    {
        $cfg = config();
        $stmt = db()->query(
            "SELECT COALESCE(SUM(montant), 0) FROM caisse_transactions WHERE renflouement_id IS NULL"
        );
        return (float)$cfg['app']['caisse_fonds'] - (float)$stmt->fetchColumn();
    }

    public static function transactionsNonRenflouees(): array
    {
        $stmt = db()->query(
            "SELECT t.*, lb.code AS ligne_code, lb.libelle AS ligne_libelle,
                    u.nom_complet AS valide_par_nom
               FROM caisse_transactions t
               JOIN lignes_budgetaires lb ON t.ligne_budgetaire_id = lb.id
               LEFT JOIN users u ON t.valide_par = u.id
              WHERE t.renflouement_id IS NULL
              ORDER BY t.date_depense DESC"
        );
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT t.*, lb.code AS ligne_code, lb.libelle AS ligne_libelle
               FROM caisse_transactions t
               JOIN lignes_budgetaires lb ON t.ligne_budgetaire_id = lb.id
              WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $stmt = db()->prepare(
            'INSERT INTO caisse_transactions
                (numero, date_depense, description, rubrique, ligne_budgetaire_id,
                 montant, numero_recu, recu_scan, recu_scan_taille, recu_scan_type,
                 valide_administrateur, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,0,?)'
        );
        $stmt->execute([
            $data['numero'],
            $data['date_depense'],
            $data['description'],
            $data['rubrique'],
            (int)$data['ligne_budgetaire_id'],
            $data['montant'],
            $data['numero_recu'],
            $data['recu_scan'],
            $data['recu_scan_taille'] ?? null,
            $data['recu_scan_type'] ?? null,
            (int)$data['created_by'],
        ]);
        return (int)db()->lastInsertId();
    }

    public static function validate(int $id, int $adminId): void
    {
        $stmt = db()->prepare(
            'UPDATE caisse_transactions
                SET valide_administrateur = 1, date_validation = NOW(), valide_par = ?
              WHERE id = ?'
        );
        $stmt->execute([$adminId, $id]);
    }

    public static function renflouementEnCours(): ?array
    {
        $stmt = db()->query(
            "SELECT * FROM caisse_renflouements WHERE statut IN ('demande','valide') ORDER BY id DESC LIMIT 1"
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function demanderRenflouement(int $userId, string $motif): int
    {
        $solde = self::solde();
        $stmt = db()->query(
            "SELECT COALESCE(SUM(montant), 0)
               FROM caisse_transactions
              WHERE renflouement_id IS NULL AND valide_administrateur = 1"
        );
        $totalAValider = (float)$stmt->fetchColumn();

        $cfg = config();
        $fonds = (float)$cfg['app']['caisse_fonds'];
        $montant = $fonds - $solde; // ramene a 30 000

        $numero = generate_numero('RENF', 'caisse_renflouements');
        $stmt = db()->prepare(
            "INSERT INTO caisse_renflouements
                (numero, date_demande, motif_declenchement, solde_avant,
                 montant_renflouement, solde_apres, statut, created_by)
             VALUES (?, CURDATE(), ?, ?, ?, ?, 'demande', ?)"
        );
        $stmt->execute([$numero, $motif, $solde, $montant, $fonds, $userId]);
        return (int)db()->lastInsertId();
    }
}
