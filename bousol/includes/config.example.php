<?php
declare(strict_types=1);

/**
 * Bousol - modele de configuration.
 * Copier en config.php (jamais committe, chmod 600, hors depot).
 *
 * Les parametres metier (date de debut, seuils, plafonds) ne sont PAS ici :
 * ils vivent dans la table `parametres` (annexe F) et sont modifies par le
 * Coordinateur depuis l'application, avec historisation.
 */

return [
    'db' => [
        'host'    => 'localhost',
        'socket'  => null,                 // chemin du socket UNIX si different de host (dev local)
        'name'    => 'u218662965_bousol',
        'user'    => 'u218662965_bousol_app',
        'pass'    => 'REMPLACER',
        'charset' => 'utf8mb4',
    ],

    'app' => [
        'name'        => 'Bousòl',
        'base_path'   => '/bousol/',
        'url'         => 'https://dev-dynamics.org/bousol/',
        'env'         => 'production',          // production | development
        'timezone'    => 'America/Port-au-Prince',
        'session_ttl' => 3600,                  // 60 min d'inactivite

        // Chemin de l'autoload de mPDF. La bibliotheque n'est pas dans le depot
        // (95 Mo, .gitignore) et un deploiement qui synchronise l'arborescence la
        // supprime : la placer hors de la racine web, a cote de ce fichier de
        // configuration, la met hors de portee de tout deploiement.
        // Laisser null pour la chercher a l'emplacement historique, bousol/lib/mpdf/.
        'mpdf'        => null,                  // ex. '/home/uXXXX/domains/exemple.org/lib/mpdf/autoload.php'
    ],

    'mail' => [
        'from_email' => 'noreply@dev-dynamics.org',
        'from_name'  => 'Bousòl - DevDynamics',
    ],

    'security' => [
        'login_max_attempts' => 5,
        'login_window'       => 300,   // secondes
        'reauth_ttl'         => 120,   // fenetre de reauthentification pour signer (s)
        'bcrypt_cost'        => 12,
        // Cle de chiffrement du coffre (specimens, pieces d'identite, exports).
        // 32 octets en hex (64 caracteres) : php -r 'echo bin2hex(random_bytes(32));'
        'coffre_key_hex'     => 'REMPLACER_PAR_64_CARACTERES_HEX',
    ],
];
