<?php
declare(strict_types=1);
require_once __DIR__ . "/../../includes/layout.php";
require_login();
page_module_stub('financement', 'Phase 6', [
    'Tranches de préfinancement (50 / 45 / 5 %)',
    'Demandes de paiement au modèle UGP avec checklist',
    'Suivi des demandes de complément',
    'Solde de clôture',
]);
