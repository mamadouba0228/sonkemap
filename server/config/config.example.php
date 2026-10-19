<?php
// config.example.php — copier en config.php et remplir (config.php n'est jamais versionné)

return [
    'ors_api_key'     => 'VOTRE_CLE_ORS',
    'ors_base'        => 'https://api.openrouteservice.org',
    'allowed_origins' => [
        'https://lamadgeo.alwaysdata.net',
        'http://lamadgeo.alwaysdata.net',
        'https://mamadouba0228.github.io',
    ],
    'data_dir'        => __DIR__ . '/../data',
];
