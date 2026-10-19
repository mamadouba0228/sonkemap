<?php
// ═══════════════════════════════════════════════════════
// ors_proxy.php — Proxy sécurisé pour OpenRouteService
// Déposer sur Alwaysdata dans /calcul_itineraire/
// ═══════════════════════════════════════════════════════

// ── Clé API ORS (jamais visible côté client) ──
define('ORS_API_KEY', 'VOTRE_CLE_ORS'); // copier ce fichier en ors_proxy.php et renseigner la clé
define('ORS_BASE',    'https://api.openrouteservice.org');

// ── CORS : autoriser uniquement votre domaine ──
$allowed_origins = [
    'https://lamadgeo.alwaysdata.net',
    'http://lamadgeo.alwaysdata.net',
    'https://mamadouba0228.github.io',
    // Ajoutez d'autres domaines si nécessaire
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: https://lamadgeo.alwaysdata.net");
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Répondre aux preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Rate limiting : max 30 requêtes par IP par minute ──
$ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ip_key   = 'ors_rate_' . md5($ip);
$rate_file = sys_get_temp_dir() . '/' . $ip_key . '.json';

$now      = time();
$window   = 60;   // 1 minute
$max_reqs = 30;   // max 30 requêtes par fenêtre

$rate_data = ['count' => 0, 'window_start' => $now];
if (file_exists($rate_file)) {
    $raw = json_decode(file_get_contents($rate_file), true);
    if ($raw && ($now - $raw['window_start']) < $window) {
        $rate_data = $raw;
    }
}
$rate_data['count']++;
file_put_contents($rate_file, json_encode($rate_data));

if ($rate_data['count'] > $max_reqs) {
    http_response_code(429);
    echo json_encode(['error' => 'Trop de requêtes. Réessayez dans une minute.']);
    exit;
}

// ── Récupérer le chemin ORS demandé ──
// Exemple : ?path=/v2/directions/driving-car&start=...&end=...
$path = $_GET['path'] ?? '';

// Valider le path : seulement les chemins ORS autorisés
$allowed_paths = [
    '/v2/directions/driving-car',
    '/v2/directions/foot-walking',
    '/v2/directions/cycling-regular',
    '/v2/directions/driving-car/geojson',
    '/v2/directions/foot-walking/geojson',
    '/v2/directions/cycling-regular/geojson',
    '/elevation/point',
];

$path_ok = false;
foreach ($allowed_paths as $ap) {
    if (strpos($path, $ap) === 0) { $path_ok = true; break; }
}
if (!$path_ok) {
    http_response_code(400);
    echo json_encode(['error' => 'Chemin ORS non autorisé : ' . htmlspecialchars($path)]);
    exit;
}

// ── Construire l'URL ORS ──
$method  = $_SERVER['REQUEST_METHOD'];
$qs      = $_SERVER['QUERY_STRING'] ?? '';
// Retirer le paramètre 'path' du query string
$qs = preg_replace('/(?:^|&)path=[^&]*/', '', $qs);
$qs = ltrim($qs, '&');

$url = ORS_BASE . $path . ($qs ? '?' . $qs : '');

// ── Appel cURL vers ORS ──
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: ' . ORS_API_KEY,
    'Content-Type: application/json',
    'Accept: application/json, application/geo+json',
]);

if ($method === 'POST') {
    $body = file_get_contents('php://input');
    // Limiter la taille du corps à 10 Ko
    if (strlen($body) > 10240) {
        http_response_code(413);
        echo json_encode(['error' => 'Corps de requête trop volumineux']);
        exit;
    }
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error     = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(502);
    echo json_encode(['error' => 'Erreur de connexion ORS : ' . $error]);
    exit;
}

http_response_code($http_code);
echo $response;
