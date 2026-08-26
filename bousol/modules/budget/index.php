<?php
declare(strict_types=1);
require_once __DIR__ . "/../../includes/layout.php";
require_login();
page_module_stub('budget', 'Phase 2', [
    'Nomenclature annexe A (31 lignes)',
    'Budget contractuel figé et budget de gestion versionné',
    'Six contrôles d\'imputation, alertes 20 % / blocage 25 %',
    'Mobilisation de la provision sur autorisation',
]);
