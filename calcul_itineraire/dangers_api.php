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
define('MAX_WRITES_PER_DEVICE_HOUR', 10); // anti-spam : signalements par appareil et par heure
define('MAX_WRITES_PER_IP_HOUR',     20); // anti-spam : signalements et votes par IP et par heure
define('GONE_VOTES_TO_REMOVE',        2); // « Plus là » de 2 usagers différents → danger retiré

// L'identifiant d'appareil est un secret connu du seul téléphone qui signale.
// Le serveur n'en garde que l'empreinte (« owner ») : elle est publique,
// mais seul le détenteur de l'identifiant peut supprimer directement ses dangers.
// Les autres usagers votent « Plus là » / « Toujours là », comme dans Waze.
function owner_of($deviceId) {
    return hash('sha256', 'snk:' . $deviceId);
}

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
        // « Toujours là » (seen) prolonge la durée de vie
        $last = max($d['ts'] ?? 0, $d['seen'] ?? 0);
        return isset($d['ts']) && ($now - $last) <= (TTL_SECONDS * 1000);
    });
    // Anciens enregistrements : remplacer l'identifiant en clair par son empreinte
    $data = array_map(function($d) {
        if (!isset($d['owner']) && isset($d['deviceId'])) $d['owner'] = owner_of($d['deviceId']);
        unset($d['deviceId']);
        return $d;
    }, $data);
    return array_values($data);
}

// ── Écrire les données ──
function write_dangers($dangers) {
    // Garder seulement les MAX_DANGERS les plus récents
    usort($dangers, fn($a,$b) => ($b['ts'] ?? 0) - ($a['ts'] ?? 0));
    $dangers = array_slice($dangers, 0, MAX_DANGERS);
    file_put_contents(DATA_FILE, json_encode(array_values($dangers)), LOCK_EX);
}

// ── Anti-spam : nombre de signalements d'une IP sur la dernière heure ──
function check_ip_write_limit() {
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $file = sys_get_temp_dir() . '/snk_writes_' . md5($ip) . '.json';
    $now  = time();
    $list = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    $list = array_values(array_filter($list, fn($t) => ($now - $t) < 3600));
    if (count($list) >= MAX_WRITES_PER_IP_HOUR) {
        http_response_code(429);
        echo json_encode(['error' => 'Trop de signalements depuis cette connexion. Réessayez plus tard.']);
        exit;
    }
    $list[] = $now;
    file_put_contents($file, json_encode($list), LOCK_EX);
}

// ── Valider un objet danger ──
function validate_danger($d) {
    global $VALID_TYPES;

    if (!is_array($d))                              return 'Format invalide';
    if (!isset($d['id']) || !is_string($d['id']) || !preg_match('/^D[a-z0-9]+$/i', $d['id']))
                                                    return 'ID invalide';
    if (!isset($d['lat']) || !is_numeric($d['lat'])) return 'Latitude invalide';
    if (!isset($d['lon']) || !is_numeric($d['lon'])) return 'Longitude invalide';
    if ((float)$d['lat'] < -90  || (float)$d['lat'] > 90)  return 'Latitude hors limites';
    if ((float)$d['lon'] < -180 || (float)$d['lon'] > 180) return 'Longitude hors limites';
    if (!isset($d['type']) || !in_array($d['type'], $VALID_TYPES)) return 'Type de danger invalide';
    if (!isset($d['ts'])   || !is_numeric($d['ts']))  return 'Timestamp invalide';
    if (!isset($d['deviceId']) || !is_string($d['deviceId']) || !preg_match('/^[A-Za-z0-9]{8,40}$/', $d['deviceId']))
                                                    return 'DeviceId invalide';

    // Vérifier que le timestamp est raisonnable (pas dans le futur de plus d'1 min)
    $now_ms = time() * 1000;
    if ($d['ts'] > $now_ms + 60000) return 'Timestamp dans le futur';

    return null; // OK
}

// ── Échapper les données pour la sortie (jamais d'identifiant d'appareil en clair) ──
function sanitize_danger($d) {
    return [
        'id'    => htmlspecialchars($d['id'],   ENT_QUOTES, 'UTF-8'),
        'lat'   => (float)$d['lat'],
        'lon'   => (float)$d['lon'],
        'type'  => htmlspecialchars($d['type'], ENT_QUOTES, 'UTF-8'),
        'ts'    => (int)$d['ts'],
        'owner' => preg_match('/^[0-9a-f]{64}$/', $d['owner'] ?? '') ? $d['owner'] : '',
    ];
}

// ── Version publique : votes résumés (on ne publie pas qui a voté) ──
function public_danger($d) {
    $p = sanitize_danger($d);
    $p['gone'] = count($d['gone'] ?? []);   // nombre de « Plus là »
    $p['seen'] = (int)($d['seen'] ?? 0);    // dernier « Toujours là » (ms), 0 si aucun
    return $p;
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
        $dangers = array_map('public_danger', $dangers);
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
        $owner   = owner_of($danger['deviceId']);
        $danger['owner'] = $owner;
        $danger  = sanitize_danger($danger);
        $dangers = read_dangers();

        // Anti-spam : signalements récents du même appareil
        $hour_ago = (time() - 3600) * 1000;
        $recent = array_filter($dangers, fn($d) => ($d['owner'] ?? '') === $owner && ($d['ts'] ?? 0) > $hour_ago);
        if (count($recent) >= MAX_WRITES_PER_DEVICE_HOUR) {
            http_response_code(429);
            echo json_encode(['error' => 'Trop de signalements depuis cet appareil. Réessayez dans une heure.']);
            exit;
        }
        check_ip_write_limit();

        // Un identifiant déjà pris par un autre appareil ne peut pas être écrasé
        foreach ($dangers as $d) {
            if ($d['id'] === $danger['id'] && ($d['owner'] ?? '') !== $owner) {
                http_response_code(409);
                echo json_encode(['error' => 'Identifiant déjà utilisé']);
                exit;
            }
        }
        $dangers = array_filter($dangers, fn($d) => $d['id'] !== $danger['id']);
        $dangers[] = $danger;
        write_dangers(array_values($dangers));
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── delete ──
    if ($action === 'delete') {
        $id = $body['id'] ?? '';
        if (!is_string($id) || !preg_match('/^D[a-z0-9]+$/i', $id)) {
            http_response_code(400);
            echo json_encode(['error' => 'ID invalide']);
            exit;
        }
        // Seul l'appareil qui a signalé le danger peut le supprimer
        $deviceId = $body['deviceId'] ?? '';
        if (!is_string($deviceId) || !preg_match('/^[A-Za-z0-9]{8,40}$/', $deviceId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Suppression réservée à l\'auteur du signalement']);
            exit;
        }
        $owner   = owner_of($deviceId);
        $dangers = read_dangers();
        foreach ($dangers as $d) {
            if ($d['id'] === $id && ($d['owner'] ?? '') !== $owner) {
                http_response_code(403);
                echo json_encode(['error' => 'Suppression réservée à l\'auteur du signalement']);
                exit;
            }
        }
        $dangers = array_filter($dangers, fn($d) => $d['id'] !== $id);
        write_dangers(array_values($dangers));
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── vote : « Plus là » (gone) ou « Toujours là » (still), par n'importe quel usager ──
    if ($action === 'vote') {
        $id       = $body['id'] ?? '';
        $vote     = $body['vote'] ?? '';
        $deviceId = $body['deviceId'] ?? '';
        if (!is_string($id) || !preg_match('/^D[a-z0-9]+$/i', $id)
            || !in_array($vote, ['gone', 'still'], true)
            || !is_string($deviceId) || !preg_match('/^[A-Za-z0-9]{8,40}$/', $deviceId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Vote invalide']);
            exit;
        }
        $owner  = owner_of($deviceId);
        $iphash = substr(hash('sha256', 'snk-ip:' . ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 16);
        $dangers = read_dangers();
        $idx = null;
        foreach ($dangers as $i => $d) { if ($d['id'] === $id) { $idx = $i; break; } }
        if ($idx === null) {
            echo json_encode(['ok' => true, 'removed' => true]); // déjà retiré ou expiré
            exit;
        }
        check_ip_write_limit();
        $d = $dangers[$idx];
        $removed = false;

        if ($vote === 'still') {
            // Confirmé : les votes « Plus là » sont effacés et la durée de vie repart
            $d['gone'] = [];
            $d['seen'] = time() * 1000;
            $dangers[$idx] = $d;
        } else {
            $votes = $d['gone'] ?? [];
            $already = false;
            foreach ($votes as $v) { if (($v['o'] ?? '') === $owner) { $already = true; break; } }
            if (!$already) $votes[] = ['o' => $owner, 'ip' => $iphash];
            // Il faut des usagers sur des connexions différentes (un seul téléphone ne suffit pas)
            $ips = count(array_unique(array_column($votes, 'ip')));
            if (($d['owner'] ?? '') === $owner || $ips >= GONE_VOTES_TO_REMOVE) {
                array_splice($dangers, $idx, 1);
                $removed = true;
            } else {
                $d['gone'] = $votes;
                $dangers[$idx] = $d;
            }
        }
        write_dangers(array_values($dangers));
        echo json_encode(['ok' => true, 'removed' => $removed, 'gone' => $removed ? 0 : count($d['gone'] ?? [])]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Action POST inconnue']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Méthode non autorisée']);
