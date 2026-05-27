<?php
declare(strict_types=1);

/**
 * Modele d'acces a la table imputations (F01).
 */

require_once __DIR__ . '/../includes/db.php';

final class ImputationModel
{
    public static function find(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT i.*,
                    c.numero AS contrat_numero, c.type_contrat, c.is_cps01,
                    p.nom_complet AS prestataire,
                    lb.code AS ligne_code, lb.libelle AS ligne_libelle,
                    u.nom_complet AS soumis_par_nom
               FROM imputations i
               JOIN contrats c          ON i.contrat_id = c.id
               JOIN prestataires p      ON c.prestataire_id = p.id
               JOIN lignes_budgetaires lb ON i.ligne_budgetaire_id = lb.id
               JOIN users u             ON i.soumis_par = u.id
              WHERE i.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @param array $filters ['statut'=>?, 'mois'=>?, 'contrat_id'=>?, 'ligne_id'=>?, 'rubrique'=>?, 'search'=>?]
     * @return array{rows:array, total:int}
     */
    public static function paginate(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['statut'])) {
            $where[] = 'i.statut = ?';
            $params[] = $filters['statut'];
        }
        if (!empty($filters['mois'])) {
            $where[] = 'MONTH(i.date_depense) = ?';
            $params[] = (int)$filters['mois'];
        }
        if (!empty($filters['contrat_id'])) {
            $where[] = 'i.contrat_id = ?';
            $params[] = (int)$filters['contrat_id'];
        }
        if (!empty($filters['ligne_id'])) {
            $where[] = 'i.ligne_budgetaire_id = ?';
            $params[] = (int)$filters['ligne_id'];
        }
        if (!empty($filters['rubrique'])) {
            $where[] = 'i.rubrique = ?';
            $params[] = $filters['rubrique'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(i.numero LIKE ? OR i.description LIKE ? OR p.nom_complet LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = db()->prepare(
            "SELECT COUNT(*) FROM imputations i
               JOIN contrats c ON i.contrat_id = c.id
               JOIN prestataires p ON c.prestataire_id = p.id
               $whereSql"
        );
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $listStmt = db()->prepare(
            "SELECT i.*, c.numero AS contrat_numero, c.type_contrat, c.is_cps01,
                    p.nom_complet AS prestataire,
                    lb.code AS ligne_code, lb.libelle AS ligne_libelle,
                    (SELECT id FROM decaissements d WHERE d.imputation_id=i.id LIMIT 1) AS decaissement_id
               FROM imputations i
               JOIN contrats c           ON i.contrat_id = c.id
               JOIN prestataires p       ON c.prestataire_id = p.id
               JOIN lignes_budgetaires lb ON i.ligne_budgetaire_id = lb.id
               $whereSql
              ORDER BY i.id DESC
              LIMIT $perPage OFFSET $offset"
        );
        $listStmt->execute($params);
        $rows = $listStmt->fetchAll();

        return ['rows' => $rows, 'total' => $total];
    }

    public static function create(array $data): int
    {
        $stmt = db()->prepare(
            'INSERT INTO imputations
                (numero, date_depense, contrat_id, ligne_budgetaire_id, rubrique, nature_paiement,
                 description, montant, montant_allocation, statut, peut_rappeler,
                 is_retroactif, date_saisie_reelle, soumis_par, date_soumission)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $data['numero'],
            $data['date_depense'],
            (int)$data['contrat_id'],
            (int)$data['ligne_budgetaire_id'],
            $data['rubrique'],
            $data['nature_paiement'],
            $data['description'],
            $data['montant'],
            $data['montant_allocation'] ?? null,
            $data['statut'] ?? 'brouillon',
            1,
            (int)($data['is_retroactif'] ?? 0),
            !empty($data['is_retroactif']) ? date('Y-m-d H:i:s') : null,
            (int)$data['soumis_par'],
            ($data['statut'] ?? 'brouillon') === 'soumis' ? date('Y-m-d H:i:s') : null,
        ]);
        return (int)db()->lastInsertId();
    }

    public static function submit(int $id): void
    {
        $stmt = db()->prepare(
            "UPDATE imputations SET statut='soumis', date_soumission=NOW() WHERE id=? AND statut='brouillon'"
        );
        $stmt->execute([$id]);
    }

    public static function rappeler(int $id): void
    {
        $stmt = db()->prepare(
            "UPDATE imputations SET statut='brouillon', date_soumission=NULL
              WHERE id=? AND statut='soumis' AND peut_rappeler=1"
        );
        $stmt->execute([$id]);
    }

    /**
     * Liste les contrats actifs disponibles pour la creation d'un F01.
     */
    public static function contratsActifs(): array
    {
        $stmt = db()->query(
            "SELECT c.id, c.numero, c.type_contrat, c.is_cps01, c.montant_mensuel,
                    p.nom_complet AS prestataire
               FROM contrats c
               JOIN prestataires p ON c.prestataire_id = p.id
              WHERE c.statut = 'actif'
              ORDER BY c.numero ASC"
        );
        return $stmt->fetchAll();
    }

    public static function lignesBudgetairesActives(): array
    {
        $stmt = db()->query(
            'SELECT id, code, libelle, budget_initial_htg
               FROM lignes_budgetaires
              WHERE actif = 1
              ORDER BY code ASC'
        );
        return $stmt->fetchAll();
    }

    /**
     * Verifie si un renflouement PC est en cours (gel des saisies - AJUST-02).
     */
    public static function renflouementEnCours(): bool
    {
        $stmt = db()->query(
            "SELECT COUNT(*) FROM caisse_renflouements WHERE statut IN ('demande','valide')"
        );
        return ((int)$stmt->fetchColumn()) > 0;
    }

    /**
     * Verifie qu'un BC associe a un contrat CASI biens existe et est au statut 'recu'.
     * Retourne null si pas applicable (CPS, CPSP, CPSI, CASI services).
     */
    public static function casiBiensBcOk(int $contratId): ?bool
    {
        $stmt = db()->prepare(
            "SELECT type_contrat FROM contrats WHERE id = ?"
        );
        $stmt->execute([$contratId]);
        $type = $stmt->fetchColumn();
        if ($type !== 'CASI') {
            return null;
        }
        $stmt = db()->prepare(
            "SELECT type_commande, statut FROM bons_commande WHERE contrat_id = ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$contratId]);
        $bc = $stmt->fetch();
        if (!$bc || $bc['type_commande'] !== 'biens_materiels') {
            return null; // CASI services - pas de blocage
        }
        return $bc['statut'] === 'recu';
    }
}
