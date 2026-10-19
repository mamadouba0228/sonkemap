<?php
// bootstrap.php — chargé par chaque fichier de public/api/

$configFile = __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Configuration manquante (copier config.example.php en config.php)']);
    exit;
}
$CONFIG = require $configFile;

require __DIR__ . '/lib/response.php';
require __DIR__ . '/lib/cors.php';
require __DIR__ . '/lib/rate_limit.php';
require __DIR__ . '/lib/json_store.php';
