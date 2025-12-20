<?php
// Clear PHP opcode cache
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✓ OpCache cleared\n";
} else {
    echo "✓ OpCache not enabled\n";
}

if (function_exists('apc_clear_cache')) {
    apc_clear_cache();
    echo "✓ APC cache cleared\n";
}

echo "\nPlease try to login again now.\n";
