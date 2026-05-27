<?php
declare(strict_types=1);

/**
 * Template de configuration - A COPIER en config.php sur le serveur de production
 * Ne JAMAIS committer config.php (deja exclu via .gitignore)
 */

return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'u<id>_portail',
        'user'    => 'u<id>_portail_user',
        'pass'    => 'REMPLACER_PAR_MOT_DE_PASSE',
        'charset' => 'utf8mb4',
    ],

    // Emails : utilise mail() natif PHP via le relais Hostinger
    // (meme mecanisme que api/utils/Mailer.php du site public, SPF deja configure)
    'smtp' => [
        'from_email' => 'noreply@dev-dynamics.org',
        'from_name'  => 'Portail DEVDYNAMICS / ACP',
    ],

    'app' => [
        'url'           => 'https://dev-dynamics.org/portail/',
        'name'          => 'Portail DEVDYNAMICS / ACP',
        'env'           => 'production',   // 'production' | 'development'
        'session_ttl'   => 3600,            // 60 minutes
        'timezone'      => 'America/Port-au-Prince',
        'budget_total'  => 5600000.00,      // HTG
        'caisse_fonds'  => 30000.00,        // HTG
        'caisse_seuil'  => 9000.00,         // HTG (30% du fonds)
        'caisse_plafond_op' => 10000.00,    // HTG max par operation PC
    ],

    'security' => [
        'csrf_lifetime'        => 3600,
        'reset_token_lifetime' => 3600,     // 1h pour reset password
        'token_lifetime'       => 259200,   // 72h pour tokens prestataires (NH, FRP)
        'login_max_attempts'   => 5,
        'login_window'         => 300,      // 5 minutes
    ],
];
