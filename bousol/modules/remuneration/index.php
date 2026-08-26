<?php
declare(strict_types=1);
require_once __DIR__ . "/../../includes/layout.php";
require_login();
page_module_stub('remuneration', 'Phase 4', [
    'Rapports d\'exécution et certificats d\'acceptation',
    'Prestations : brut, acompte 2 %, net',
    'Versement mensuel à la DGI adossé à la clôture',
    'Ratification par l\'Assemblée Générale',
]);
