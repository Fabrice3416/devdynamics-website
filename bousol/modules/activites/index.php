<?php
declare(strict_types=1);
require_once __DIR__ . "/../../includes/layout.php";
require_login();
page_module_stub('activites', 'Phase 5', [
    'Cadre logique versionné, indicateurs et relevés',
    'Sessions de formation, participations et évaluations',
    'Registre des versions et anomalies de l\'application',
    'Journal des difficultés',
]);
