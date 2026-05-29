<?php
return [
    // Optional: set a token and enter the same value in index.html as API_TOKEN.
    // Leave empty for local/private setups where no token is needed.
    'api_token' => '',

    // Optional: token for scheduled market snapshot calls.
    // Example URL: /api/?action=refresh-price-snapshots&token=change-me
    'cron_token' => '',

    // EVE Online SSO. Keep client_secret private and never commit api/config.php.
    'sso' => [
        'client_id' => '',
        'client_secret' => '',
        'callback_url' => 'https://orelytics.soluratec.de/api/index.php?action=sso-callback',
        'frontend_url' => 'https://orelytics.soluratec.de/',
        'scopes' => [
            'esi-industry.read_character_mining.v1',
        ],
    ],

    // MySQL/MariaDB connection. If this block is removed or pdo_mysql is missing,
    // the API falls back to api/data/eve-mining-state.json file storage.
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'eve_mining',
        'user' => 'eve_mining_user',
        'pass' => 'change-me',
        'charset' => 'utf8mb4',
    ],
];
