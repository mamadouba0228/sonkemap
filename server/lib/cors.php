<?php
// cors.php — autorise uniquement les domaines de config.php et répond aux preflight

function apply_cors(array $allowedOrigins, string $methods = 'GET, POST, OPTIONS'): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allow  = in_array($origin, $allowedOrigins, true) ? $origin : $allowedOrigins[0];
    header("Access-Control-Allow-Origin: $allow");
    header("Access-Control-Allow-Methods: $methods");
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
