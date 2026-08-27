<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
redirect(base_path(!is_logged_in() ? 'login.php' : (projet_id() === null ? 'projets.php' : 'dashboard.php')));
