<?php
// rate_limit.php — limite le nombre de requêtes par IP sur une fenêtre glissante

function rate_limit(string $bucket, int $maxRequests, int $windowSeconds = 60): void {
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $file = sys_get_temp_dir() . "/snk_{$bucket}_" . md5($ip) . '.json';
    $now  = time();
    $data = ['count' => 0, 'start' => $now];

    if (file_exists($file)) {
        $raw = json_decode(file_get_contents($file), true);
        if ($raw && ($now - $raw['start']) < $windowSeconds) {
            $data = $raw;
        }
    }
    $data['count']++;
    file_put_contents($file, json_encode($data), LOCK_EX);

    if ($data['count'] > $maxRequests) {
        json_error('Trop de requêtes. Réessayez dans une minute.', 429);
    }
}
