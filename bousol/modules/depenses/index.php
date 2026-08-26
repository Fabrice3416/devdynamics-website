<?php
declare(strict_types=1);
require_once __DIR__ . "/../../includes/layout.php";
require_login();
page_module_stub('depenses', 'Phase 4', [
    'Dossiers par type avec checklist de pièces (annexe D)',
    'Cycle en neuf étapes, imputation et règlement bloquants',
    'Numérotation des pièces par rubrique',
    'Liasses de dossier',
]);
