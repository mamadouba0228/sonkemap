<?php
// json_store.php — lecture / écriture d'un fichier JSON de server/data/ avec verrou

function store_read(string $file): array {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function store_write(string $file, array $data): void {
    file_put_contents($file, json_encode(array_values($data)), LOCK_EX);
}
