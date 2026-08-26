<?php
declare(strict_types=1);
require_once __DIR__ . "/../../includes/layout.php";
require_login();
page_module_stub('comptes', 'Phase 3', [
    'Partie double allégée (six familles de comptes)',
    'Règlements à double signature, exclusion du bénéficiaire',
    'Validation bancaire manuscrite',
    'Rapprochement bancaire et petite caisse',
]);
