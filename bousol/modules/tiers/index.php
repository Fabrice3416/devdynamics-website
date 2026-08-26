<?php
declare(strict_types=1);
require_once __DIR__ . "/../../includes/layout.php";
require_login();
page_module_stub('tiers', 'Phase 2', [
    'Référentiel des intervenants et contrats de service',
    'Organisations bénéficiaires et représentants (sexe, tranche d\'âge)',
    'Fournisseurs et administrations',
]);
