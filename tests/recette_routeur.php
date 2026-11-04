<?php
// Routeur de php -S pour tests/recette.php : reproduit le renvoi de api/.htaccess.
if (strpos(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/api/') === 0) {
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    require dirname(__DIR__) . '/api/index.php';
    return true;
}
return false;
