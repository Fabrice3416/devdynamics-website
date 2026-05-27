<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    header('Location: /portail/dashboard.php');
} else {
    header('Location: /portail/login.php');
}
exit;
