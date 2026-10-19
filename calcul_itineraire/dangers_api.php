<?php
// ═══════════════════════════════════════════════════════
// dangers_api.php — VERSION SÉCURISÉE
// Remplace l'ancienne version sans protection
// ═══════════════════════════════════════════════════════

// ── Configuration ──
define('DATA_FILE',   __DIR__ . '/dangers_data.json');
define('TTL_SECONDS', 86400);     // 24h : durée de vie d'un danger
define('MAX_DANGERS', 500);       // max dangers stockés simultanément
define('MAX_BODY_SIZE', 51200);   // 50 Ko max par requête (sans photo)
define('RATE_LIMIT',  20);        // max requêtes par IP par minute
define('RATE_WINDOW', 60);        // fenêtre en secondes

// ── CORS ──
$allowed_origins = [
    'https://lamadgeo.alwaysdata.net',
    'http://lamadgeo.alwaysdata.net',
    'https://mamadouba0228.github.io',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: https://lamadgeo.alwaysdata.net");
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}

// ── Rate limiting ──
function check_rate_limit() {
    $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $file    = sys_get_temp_dir() . '/snk_rate_' . md5($ip) . '.json';
    $now     = time();
    $data    = ['count' => 0, 'start' => $now];

    if (file_exists($file)) {
        $raw = json_decode(file_get_contents($file), true);
        if ($raw && ($now - $raw['start']) < RATE_WINDOW) {
            $data = $raw;
        }
    }
    $data['count']++;
    file_put_contents($file, json_encode($data));

    if ($data['count'] > RATE_LIMIT) {
        http_response_code(429);
        echo json_encode(['error' => 'Trop de requêtes. Attendez 1 minute.']);
        exit;
    }
}
check_rate_limit();

// ── Types de danger valides ──
$VALID_TYPES = ['car','truck','road_closed','accident','pothole','police','flood','fire','other'];

// ── Lire les données ──
function read_dangers() {
    if (!file_exists(DATA_FILE)) return [];
    $raw = file_get_contents(DATA_FILE);
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];

    // Nettoyer les dangers expirés
    $now  = time() * 1000; // millisecondes comme JS
    $data = array_filter($data, function($d) use ($now) {
        return isset($d['ts']) && ($now - $d['ts']) <= (TTL_SECONDS * 1000);
    });
    return array_values($data);
}

// ── Écrire les données ──
function write_dangers($dangers) {
    // Garder seulement les MAX_DANGERS les plus récents
    usort($dangers, fn($a,$b) => ($b['ts'] ?? 0) - ($a['ts'] ?? 0));
    $dangers = array_slice($dangers, 0, MAX_DANGERS);
    file_put_contents(DATA_FILE, json_encode(array_values($dangers)));
}

// ── Valider un objet danger ──
function validate_danger($d) {
    global $VALID_TYPES;

    if (!is_array($d))                              return 'Format invalide';
    if (!isset($d['id']) || !preg_match('/^D[a-z0-9]+$/i', $d['id']))
                                                    return 'ID invalide';
    if (!isset($d['lat']) || !is_numeric($d['lat'])) return 'Latitude invalide';
    if (!isset($d['lon']) || !is_numeric($d['lon'])) return 'Longitude invalide';
    if ((float)$d['lat'] < -90  || (float)$d['lat'] > 90)  return 'Latitude hors limites';
    if ((float)$d['lon'] < -180 || (float)$d['lon'] > 180) return 'Longitude hors limites';
    if (!isset($d['type']) || !in_array($d['type'], $VALID_TYPES)) return 'Type de danger invalide';
    if (!isset($d['ts'])   || !is_numeric($d['ts']))  return 'Timestamp invalide';
    if (!isset($d['deviceId']) || strlen($d['deviceId']) > 40) return 'DeviceId invalide';

    // Vérifier que le timestamp est raisonnable (pas dans le futur de plus d'1 min)
    $now_ms = time() * 1000;
    if ($d['ts'] > $now_ms + 60000) return 'Timestamp dans le futur';

    return null; // OK
}

// ── Échapper les données pour la sortie ──
function sanitize_danger($d) {
    return [
        'id'       => htmlspecialchars($d['id'],       ENT_QUOTES, 'UTF-8'),
        'lat'      => (float)$d['lat'],
        'lon'      => (float)$d['lon'],
        'type'     => htmlspecialchars($d['type'],     ENT_QUOTES, 'UTF-8'),
        'ts'       => (int)$d['ts'],
        'deviceId' => htmlspecialchars($d['deviceId'], ENT_QUOTES, 'UTF-8'),
    ];
}

// ════════════════════════════════
// ROUTING
// ════════════════════════════════

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ?action=read ──
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'read') {
        $dangers = read_dangers();
        $dangers = array_map('sanitize_danger', $dangers);
        echo json_encode($dangers);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'Action GET inconnue']);
    exit;
}

// ── POST ──
if ($method === 'POST') {
    // Vérifier la taille du corps
    $body_raw = file_get_contents('php://input');
    if (strlen($body_raw) > MAX_BODY_SIZE) {
        http_response_code(413);
        echo json_encode(['error' => 'Requête trop volumineuse']);
        exit;
    }

    $body = json_decode($body_raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'JSON invalide']);
        exit;
    }

    $action = $body['action'] ?? '';

    // ── write ──
    if ($action === 'write') {
        $danger = $body['danger'] ?? null;
        $err = validate_danger($danger);
        if ($err) {
            http_response_code(400);
            echo json_encode(['error' => $err]);
            exit;
        }
        $danger = sanitize_danger($danger);
        $dangers = read_dangers();

        // Éviter les doublons (même ID)
        $dangers = array_filter($dangers, fn($d) => $d['id'] !== $danger['id']);
        $dangers[] = $danger;
        write_dangers(array_values($dangers));
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── delete ──
    if ($action === 'delete') {
        $id = $body['id'] ?? '';
        if (!preg_match('/^D[a-z0-9]+$/i', $id)) {
            http_response_code(400);
            echo json_encode(['error' => 'ID invalide']);
            exit;
        }
        $dangers = read_dangers();
        $dangers = array_filter($dangers, fn($d) => $d['id'] !== $id);
        write_dangers(array_values($dangers));
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Action POST inconnue']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Méthode non autorisée']);
