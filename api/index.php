<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Token, X-Cron-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

function respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function respond_html(int $status, string $html): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

function request_header(string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return $_SERVER[$key] ?? '';
}

function default_state(): array {
    return [
        'entries' => [],
        'marketPrices' => new stdClass(),
        'marketSellPrices' => new stdClass(),
        'marketSettings' => new stdClass(),
        'salePlans' => new stdClass(),
        'excludedResources' => [],
        'updatedAt' => null,
    ];
}

function default_sales_materials(): array {
    return [
        ['typeId' => 34, 'name' => 'Tritanium'],
        ['typeId' => 35, 'name' => 'Pyerite'],
        ['typeId' => 36, 'name' => 'Mexallon'],
        ['typeId' => 37, 'name' => 'Isogen'],
        ['typeId' => 38, 'name' => 'Nocxium'],
        ['typeId' => 39, 'name' => 'Zydrine'],
        ['typeId' => 40, 'name' => 'Megacyte'],
        ['typeId' => 11399, 'name' => 'Morphite'],
        ['typeId' => 16272, 'name' => 'Heavy Water'],
        ['typeId' => 16273, 'name' => 'Liquid Ozone'],
        ['typeId' => 16274, 'name' => 'Helium Isotopes'],
        ['typeId' => 16275, 'name' => 'Strontium Clathrates'],
        ['typeId' => 17887, 'name' => 'Oxygen Isotopes'],
        ['typeId' => 17888, 'name' => 'Nitrogen Isotopes'],
        ['typeId' => 17889, 'name' => 'Hydrogen Isotopes'],
    ];
}

function read_config(): array {
    $path = __DIR__ . '/config.php';
    if (!is_file($path)) {
        return [];
    }
    $config = require $path;
    return is_array($config) ? $config : [];
}

function sso_config(array $config): array {
    $sso = $config['sso'] ?? [];
    return is_array($sso) ? $sso : [];
}

function sso_scopes(array $config): array {
    $scopes = sso_config($config)['scopes'] ?? ['esi-industry.read_character_mining.v1'];
    if (is_string($scopes)) {
        $scopes = preg_split('/\s+/', trim($scopes)) ?: [];
    }
    $scopes = array_values(array_filter(array_map('trim', is_array($scopes) ? $scopes : [])));
    return array_values(array_unique($scopes));
}

function pdo_or_null(array $config): ?PDO {
    if (!extension_loaded('pdo_mysql') || empty($config['db']) || !is_array($config['db'])) {
        return null;
    }
    $db = $config['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'] ?? 'localhost',
        (int)($db['port'] ?? 3306),
        $db['name'] ?? '',
        $db['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS eve_mining_state (
          state_key VARCHAR(64) NOT NULL PRIMARY KEY,
          state_json LONGTEXT NOT NULL,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS eve_market_cache (
          cache_key VARCHAR(191) NOT NULL PRIMARY KEY,
          source VARCHAR(64) NOT NULL,
          payload_json LONGTEXT NOT NULL,
          fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS eve_market_order_snapshots (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          region_id INT NOT NULL,
          station_id BIGINT UNSIGNED NULL,
          type_id INT NOT NULL,
          item_name VARCHAR(128) NOT NULL,
          region_best_buy DECIMAL(20,8) NULL,
          region_best_sell DECIMAL(20,8) NULL,
          station_best_buy DECIMAL(20,8) NULL,
          station_best_sell DECIMAL(20,8) NULL,
          region_buy_volume BIGINT UNSIGNED NOT NULL DEFAULT 0,
          region_sell_volume BIGINT UNSIGNED NOT NULL DEFAULT 0,
          station_buy_volume BIGINT UNSIGNED NOT NULL DEFAULT 0,
          station_sell_volume BIGINT UNSIGNED NOT NULL DEFAULT 0,
          region_buy_order_count INT UNSIGNED NOT NULL DEFAULT 0,
          region_sell_order_count INT UNSIGNED NOT NULL DEFAULT 0,
          station_buy_order_count INT UNSIGNED NOT NULL DEFAULT 0,
          station_sell_order_count INT UNSIGNED NOT NULL DEFAULT 0,
          source VARCHAR(64) NOT NULL,
          fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY idx_snapshot_lookup (region_id, station_id, type_id, fetched_at),
          KEY idx_snapshot_fetched_at (fetched_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS eve_sso_states (
          state_key VARCHAR(128) NOT NULL PRIMARY KEY,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS eve_sso_characters (
          character_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
          character_name VARCHAR(128) NOT NULL,
          owner_hash VARCHAR(191) NULL,
          scopes_json TEXT NOT NULL,
          access_token TEXT NOT NULL,
          refresh_token TEXT NULL,
          token_expires_at DATETIME NULL,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    return $pdo;
}

function state_path(): string {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        respond(500, ['ok' => false, 'error' => 'Could not create api/data directory']);
    }
    return $dir . '/eve-mining-state.json';
}

function load_file_state(): array {
    $path = state_path();
    if (!is_file($path)) {
        return default_state();
    }
    $decoded = json_decode(file_get_contents($path) ?: '', true);
    return is_array($decoded) ? array_merge(default_state(), $decoded) : default_state();
}

function save_file_state(array $state): void {
    $path = state_path();
    $state['updatedAt'] = gmdate('c');
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        respond(500, ['ok' => false, 'error' => 'Could not encode state']);
    }
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        respond(500, ['ok' => false, 'error' => 'Could not write state']);
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        respond(500, ['ok' => false, 'error' => 'Could not replace state']);
    }
}

function load_mysql_state(PDO $pdo): array {
    $keys = ['entries', 'marketPrices', 'marketSellPrices', 'marketSettings', 'salePlans', 'excludedResources'];
    $state = default_state();
    $stmt = $pdo->query('SELECT state_key, state_json, updated_at FROM eve_mining_state');
    foreach ($stmt->fetchAll() as $row) {
        if (!in_array($row['state_key'], $keys, true)) {
            continue;
        }
        $decoded = json_decode((string)$row['state_json'], true);
        if ($decoded !== null) {
            $state[$row['state_key']] = $decoded;
        }
        if ($state['updatedAt'] === null || $row['updated_at'] > $state['updatedAt']) {
            $state['updatedAt'] = $row['updated_at'];
        }
    }
    return $state;
}

function save_mysql_state(PDO $pdo, array $state): void {
    $payload = [
        'entries' => $state['entries'] ?? [],
        'marketPrices' => $state['marketPrices'] ?? new stdClass(),
        'marketSellPrices' => $state['marketSellPrices'] ?? new stdClass(),
        'marketSettings' => $state['marketSettings'] ?? new stdClass(),
        'salePlans' => $state['salePlans'] ?? new stdClass(),
        'excludedResources' => $state['excludedResources'] ?? [],
    ];
    $stmt = $pdo->prepare(
        'INSERT INTO eve_mining_state (state_key, state_json)
         VALUES (:state_key, :state_json)
         ON DUPLICATE KEY UPDATE state_json = VALUES(state_json)'
    );
    $pdo->beginTransaction();
    try {
        foreach ($payload as $key => $value) {
            $stmt->execute([
                ':state_key' => $key,
                ':state_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function market_cache_key(string $source, array $parts): string {
    ksort($parts);
    return $source . ':' . sha1(json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function save_market_cache(?PDO $pdo, string $cacheKey, string $source, array $payload): void {
    if (!$pdo instanceof PDO) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO eve_market_cache (cache_key, source, payload_json)
         VALUES (:cache_key, :source, :payload_json)
         ON DUPLICATE KEY UPDATE source = VALUES(source), payload_json = VALUES(payload_json), fetched_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        ':cache_key' => $cacheKey,
        ':source' => $source,
        ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function load_market_cache(?PDO $pdo, string $cacheKey, string $reason = ''): ?array {
    if (!$pdo instanceof PDO) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT payload_json, fetched_at FROM eve_market_cache WHERE cache_key = :cache_key LIMIT 1');
    $stmt->execute([':cache_key' => $cacheKey]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $payload = json_decode((string)$row['payload_json'], true);
    if (!is_array($payload)) {
        return null;
    }
    $payload['cached'] = true;
    $payload['cacheFetchedAt'] = $row['fetched_at'];
    if ($reason !== '') {
        $payload['cacheReason'] = $reason;
    }
    return $payload;
}

function fetch_json(string $url): array {
    if (!extension_loaded('curl')) {
        respond(500, ['ok' => false, 'error' => 'PHP curl extension is not enabled']);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'EVE Mining Journal/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $status < 200 || $status >= 300) {
        throw new RuntimeException($err !== '' ? $err : 'HTTP ' . $status);
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON response');
    }
    return $decoded;
}

function post_form_json(string $url, array $fields, ?string $basicUser = null, ?string $basicPass = null): array {
    if (!extension_loaded('curl')) {
        respond(500, ['ok' => false, 'error' => 'PHP curl extension is not enabled']);
    }
    $headers = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];
    if ($basicUser !== null && $basicPass !== null) {
        $headers[] = 'Authorization: Basic ' . base64_encode($basicUser . ':' . $basicPass);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_USERAGENT => 'Orelytics/1.0',
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $status < 200 || $status >= 300) {
        throw new RuntimeException($err !== '' ? $err : 'HTTP ' . $status . ': ' . (string)$raw);
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON response');
    }
    return $decoded;
}

function base64url_decode_str(string $value): string {
    $value = strtr($value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding) {
        $value .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode($value, true);
    if ($decoded === false) {
        throw new RuntimeException('Invalid JWT payload encoding');
    }
    return $decoded;
}

function decode_jwt_payload(string $jwt): array {
    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        throw new RuntimeException('Invalid JWT');
    }
    $payload = json_decode(base64url_decode_str($parts[1]), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid JWT payload');
    }
    return $payload;
}

function save_sso_state(PDO $pdo, string $state): void {
    $pdo->exec("DELETE FROM eve_sso_states WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)");
    $stmt = $pdo->prepare('INSERT INTO eve_sso_states (state_key) VALUES (:state_key)');
    $stmt->execute([':state_key' => $state]);
}

function consume_sso_state(PDO $pdo, string $state): bool {
    $pdo->exec("DELETE FROM eve_sso_states WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)");
    $stmt = $pdo->prepare('DELETE FROM eve_sso_states WHERE state_key = :state_key');
    $stmt->execute([':state_key' => $state]);
    return $stmt->rowCount() > 0;
}

function save_sso_character(PDO $pdo, array $token, array $claims): array {
    $sub = (string)($claims['sub'] ?? '');
    if (!preg_match('/^CHARACTER:EVE:(\d+)$/', $sub, $match)) {
        throw new RuntimeException('JWT did not contain an EVE character subject');
    }
    $characterId = (int)$match[1];
    $characterName = (string)($claims['name'] ?? ('Character ' . $characterId));
    $scopes = $claims['scp'] ?? [];
    if (is_string($scopes)) {
        $scopes = preg_split('/\s+/', trim($scopes)) ?: [];
    }
    $expiresAt = isset($claims['exp']) ? gmdate('Y-m-d H:i:s', (int)$claims['exp']) : null;
    $stmt = $pdo->prepare(
        'INSERT INTO eve_sso_characters (
          character_id, character_name, owner_hash, scopes_json, access_token, refresh_token, token_expires_at
        ) VALUES (
          :character_id, :character_name, :owner_hash, :scopes_json, :access_token, :refresh_token, :token_expires_at
        ) ON DUPLICATE KEY UPDATE
          character_name = VALUES(character_name),
          owner_hash = VALUES(owner_hash),
          scopes_json = VALUES(scopes_json),
          access_token = VALUES(access_token),
          refresh_token = COALESCE(VALUES(refresh_token), refresh_token),
          token_expires_at = VALUES(token_expires_at)'
    );
    $stmt->execute([
        ':character_id' => $characterId,
        ':character_name' => $characterName,
        ':owner_hash' => $claims['owner'] ?? null,
        ':scopes_json' => json_encode(array_values($scopes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':access_token' => $token['access_token'],
        ':refresh_token' => $token['refresh_token'] ?? null,
        ':token_expires_at' => $expiresAt,
    ]);
    return [
        'characterId' => $characterId,
        'characterName' => $characterName,
        'scopes' => array_values($scopes),
        'expiresAt' => $expiresAt,
    ];
}

function load_sso_characters(PDO $pdo): array {
    $rows = $pdo->query('SELECT character_id, character_name, scopes_json, token_expires_at, updated_at FROM eve_sso_characters ORDER BY character_name')->fetchAll();
    return array_map(static function (array $row): array {
        $scopes = json_decode((string)$row['scopes_json'], true);
        return [
            'characterId' => (int)$row['character_id'],
            'characterName' => (string)$row['character_name'],
            'scopes' => is_array($scopes) ? $scopes : [],
            'expiresAt' => $row['token_expires_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }, $rows);
}

function fetch_json_with_headers(string $url): array {
    if (!extension_loaded('curl')) {
        respond(500, ['ok' => false, 'error' => 'PHP curl extension is not enabled']);
    }
    $headers = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'EVE Mining Journal/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$headers): int {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        },
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $status < 200 || $status >= 300) {
        throw new RuntimeException($err !== '' ? $err : 'HTTP ' . $status);
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON response');
    }
    return ['data' => $decoded, 'headers' => $headers, 'status' => $status];
}

function fetch_text(string $url): string {
    if (!extension_loaded('curl')) {
        respond(500, ['ok' => false, 'error' => 'PHP curl extension is not enabled']);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: de-DE,de;q=0.9,en;q=0.8',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Referer: https://www.adam4eve.eu/',
        ],
        CURLOPT_ENCODING => '',
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $status < 200 || $status >= 300) {
        throw new RuntimeException($err !== '' ? $err : 'HTTP ' . $status);
    }
    return (string)$raw;
}

function numeric_price($value): ?float {
    if (!is_numeric($value)) {
        return null;
    }
    $price = (float)$value;
    return $price > 0 ? $price : null;
}

function fetch_esi_market_orders(int $regionId, int $typeId, string $orderType): array {
    $orders = [];
    $page = 1;
    $pages = 1;
    do {
        $url = sprintf(
            'https://esi.evetech.net/latest/markets/%d/orders/?datasource=tranquility&order_type=%s&type_id=%d&page=%d',
            $regionId,
            rawurlencode($orderType),
            $typeId,
            $page
        );
        $response = fetch_json_with_headers($url);
        $orders = array_merge($orders, $response['data']);
        $pagesHeader = $response['headers']['x-pages'] ?? null;
        $pages = $pagesHeader !== null ? max(1, min(50, (int)$pagesHeader)) : 1;
        $page++;
    } while ($page <= $pages);
    return $orders;
}

function summarize_esi_market_orders(array $buyOrders, array $sellOrders, ?int $stationId = null): array {
    $buyOrders = array_values(array_filter($buyOrders, static fn($order) => !empty($order['is_buy_order']) && numeric_price($order['price'] ?? null) !== null));
    $sellOrders = array_values(array_filter($sellOrders, static fn($order) => empty($order['is_buy_order']) && numeric_price($order['price'] ?? null) !== null));
    if ($stationId !== null && $stationId > 0) {
        $buyOrders = array_values(array_filter($buyOrders, static fn($order) => (int)($order['location_id'] ?? 0) === $stationId));
        $sellOrders = array_values(array_filter($sellOrders, static fn($order) => (int)($order['location_id'] ?? 0) === $stationId));
    }

    usort($buyOrders, static fn($a, $b) => (float)$b['price'] <=> (float)$a['price']);
    usort($sellOrders, static fn($a, $b) => (float)$a['price'] <=> (float)$b['price']);

    $buyVolume = array_sum(array_map(static fn($order) => (int)($order['volume_remain'] ?? 0), $buyOrders));
    $sellVolume = array_sum(array_map(static fn($order) => (int)($order['volume_remain'] ?? 0), $sellOrders));

    return [
        'buy' => isset($buyOrders[0]) ? (float)$buyOrders[0]['price'] : null,
        'sell' => isset($sellOrders[0]) ? (float)$sellOrders[0]['price'] : null,
        'buyVolume' => $buyVolume,
        'sellVolume' => $sellVolume,
        'buyOrderCount' => count($buyOrders),
        'sellOrderCount' => count($sellOrders),
    ];
}

function save_market_order_snapshot(PDO $pdo, int $regionId, ?int $stationId, array $material, array $regionSummary, array $stationSummary): void {
    $stmt = $pdo->prepare(
        'INSERT INTO eve_market_order_snapshots (
          region_id, station_id, type_id, item_name,
          region_best_buy, region_best_sell, station_best_buy, station_best_sell,
          region_buy_volume, region_sell_volume, station_buy_volume, station_sell_volume,
          region_buy_order_count, region_sell_order_count, station_buy_order_count, station_sell_order_count,
          source
        ) VALUES (
          :region_id, :station_id, :type_id, :item_name,
          :region_best_buy, :region_best_sell, :station_best_buy, :station_best_sell,
          :region_buy_volume, :region_sell_volume, :station_buy_volume, :station_sell_volume,
          :region_buy_order_count, :region_sell_order_count, :station_buy_order_count, :station_sell_order_count,
          :source
        )'
    );
    $stmt->execute([
        ':region_id' => $regionId,
        ':station_id' => $stationId,
        ':type_id' => (int)$material['typeId'],
        ':item_name' => (string)$material['name'],
        ':region_best_buy' => $regionSummary['buy'],
        ':region_best_sell' => $regionSummary['sell'],
        ':station_best_buy' => $stationSummary['buy'],
        ':station_best_sell' => $stationSummary['sell'],
        ':region_buy_volume' => (int)($regionSummary['buyVolume'] ?? 0),
        ':region_sell_volume' => (int)($regionSummary['sellVolume'] ?? 0),
        ':station_buy_volume' => (int)($stationSummary['buyVolume'] ?? 0),
        ':station_sell_volume' => (int)($stationSummary['sellVolume'] ?? 0),
        ':region_buy_order_count' => (int)($regionSummary['buyOrderCount'] ?? 0),
        ':region_sell_order_count' => (int)($regionSummary['sellOrderCount'] ?? 0),
        ':station_buy_order_count' => (int)($stationSummary['buyOrderCount'] ?? 0),
        ':station_sell_order_count' => (int)($stationSummary['sellOrderCount'] ?? 0),
        ':source' => 'ESI market orders',
    ]);
}

function refresh_market_order_snapshots(PDO $pdo, int $regionId, int $stationId, array $materials): array {
    $saved = 0;
    $errors = [];
    $items = [];
    foreach ($materials as $material) {
        $typeId = (int)($material['typeId'] ?? $material['oreId'] ?? 0);
        $name = trim((string)($material['name'] ?? $material['ore'] ?? ''));
        if ($typeId <= 0 || $name === '') {
            continue;
        }
        try {
            $buyOrders = fetch_esi_market_orders($regionId, $typeId, 'buy');
            $sellOrders = fetch_esi_market_orders($regionId, $typeId, 'sell');
            $regionSummary = summarize_esi_market_orders($buyOrders, $sellOrders);
            $stationSummary = summarize_esi_market_orders($buyOrders, $sellOrders, $stationId);
            $normalized = ['typeId' => $typeId, 'name' => $name];
            save_market_order_snapshot($pdo, $regionId, $stationId, $normalized, $regionSummary, $stationSummary);
            $saved++;
            $items[] = [
                'typeId' => $typeId,
                'name' => $name,
                'regionBestBuy' => $regionSummary['buy'],
                'regionBestSell' => $regionSummary['sell'],
                'stationBestBuy' => $stationSummary['buy'],
                'stationBestSell' => $stationSummary['sell'],
                'stationSellOrderCount' => $stationSummary['sellOrderCount'],
            ];
        } catch (Throwable $e) {
            $errors[] = ['typeId' => $typeId, 'name' => $name, 'error' => $e->getMessage()];
        }
    }
    return [
        'regionId' => $regionId,
        'stationId' => $stationId,
        'saved' => $saved,
        'errors' => $errors,
        'items' => $items,
        'fetchedAt' => gmdate('c'),
    ];
}

function snapshot_trend(array $rows): array {
    $values = array_values(array_filter($rows, static fn($row) => numeric_price($row['station_best_sell'] ?? null) !== null));
    $count = count($values);
    if ($count < 4) {
        return [
            'direction' => 'unknown',
            'label' => 'zu wenig Snapshots',
            'percent' => null,
            'samples' => $count,
            'source' => 'Eigene Snapshots',
        ];
    }
    usort($values, static fn($a, $b) => strcmp((string)$a['fetched_at'], (string)$b['fetched_at']));
    $windowSize = min(8, max(2, (int)floor($count / 3)));
    $olderValues = array_slice($values, 0, $windowSize);
    $recentValues = array_slice($values, -$windowSize);
    $avg = static function (array $sample): float {
        $sum = 0.0;
        $n = 0;
        foreach ($sample as $row) {
            $price = numeric_price($row['station_best_sell'] ?? null);
            if ($price !== null) {
                $sum += $price;
                $n++;
            }
        }
        return $n > 0 ? $sum / $n : 0.0;
    };
    $olderPrice = $avg($olderValues);
    $recentPrice = $avg($recentValues);
    if ($olderPrice <= 0 || $recentPrice <= 0) {
        return [
            'direction' => 'unknown',
            'label' => 'zu wenig Snapshots',
            'percent' => null,
            'samples' => $count,
            'source' => 'Eigene Snapshots',
        ];
    }
    $percent = (($recentPrice - $olderPrice) / $olderPrice) * 100;
    $abs = abs($percent);
    if ($abs < 0.8) {
        $direction = 'flat';
        $label = 'seitwärts';
    } elseif ($percent > 0) {
        $direction = 'up';
        $label = $abs >= 4 ? 'stark steigend' : 'steigend';
    } else {
        $direction = 'down';
        $label = $abs >= 4 ? 'stark fallend' : 'fallend';
    }
    return [
        'direction' => $direction,
        'label' => $label,
        'percent' => $percent,
        'samples' => $count,
        'compareSamples' => $windowSize,
        'recentPrice' => $recentPrice,
        'olderPrice' => $olderPrice,
        'fromDate' => $values[0]['fetched_at'] ?? null,
        'toDate' => $values[$count - 1]['fetched_at'] ?? null,
        'source' => 'Eigene Snapshots',
    ];
}

function load_market_order_snapshot_summary(PDO $pdo, int $regionId, int $stationId, array $materials, int $days): array {
    $typeIds = [];
    $names = [];
    foreach ($materials as $material) {
        $typeId = (int)($material['typeId'] ?? $material['oreId'] ?? 0);
        $name = trim((string)($material['name'] ?? $material['ore'] ?? ''));
        if ($typeId > 0) {
            $typeIds[] = $typeId;
            if ($name !== '') {
                $names[(string)$typeId] = $name;
            }
        }
    }
    $typeIds = array_values(array_unique($typeIds));
    if (!$typeIds) {
        return ['regionId' => $regionId, 'stationId' => $stationId, 'items' => [], 'byTypeId' => [], 'fetchedAt' => gmdate('c')];
    }
    $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
    $params = array_merge([$regionId, $stationId], $typeIds);
    $latestSql = "
        SELECT s.*
        FROM eve_market_order_snapshots s
        JOIN (
          SELECT type_id, MAX(fetched_at) AS fetched_at
          FROM eve_market_order_snapshots
          WHERE region_id = ? AND station_id = ? AND type_id IN ($placeholders)
          GROUP BY type_id
        ) latest ON latest.type_id = s.type_id AND latest.fetched_at = s.fetched_at
        WHERE s.region_id = ? AND s.station_id = ? AND s.type_id IN ($placeholders)
        ORDER BY s.type_id ASC, s.id DESC
    ";
    $latestStmt = $pdo->prepare($latestSql);
    $latestStmt->execute(array_merge($params, $params));
    $latestRows = $latestStmt->fetchAll();

    $days = max(1, min(180, $days));
    $historySql = "
        SELECT type_id, station_best_sell, fetched_at
        FROM eve_market_order_snapshots
        WHERE region_id = ? AND station_id = ? AND type_id IN ($placeholders)
          AND fetched_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL $days DAY)
        ORDER BY type_id ASC, fetched_at ASC
    ";
    $historyStmt = $pdo->prepare($historySql);
    $historyStmt->execute($params);
    $historyByType = [];
    foreach ($historyStmt->fetchAll() as $row) {
        $historyByType[(string)$row['type_id']][] = $row;
    }

    $seen = [];
    $items = [];
    $byTypeId = [];
    foreach ($latestRows as $row) {
        $typeKey = (string)$row['type_id'];
        if (isset($seen[$typeKey])) {
            continue;
        }
        $seen[$typeKey] = true;
        $item = [
            'typeId' => (int)$row['type_id'],
            'name' => $names[$typeKey] ?? (string)$row['item_name'],
            'regionBestBuy' => numeric_price($row['region_best_buy'] ?? null),
            'regionBestSell' => numeric_price($row['region_best_sell'] ?? null),
            'stationBestBuy' => numeric_price($row['station_best_buy'] ?? null),
            'stationBestSell' => numeric_price($row['station_best_sell'] ?? null),
            'regionBuyVolume' => (int)($row['region_buy_volume'] ?? 0),
            'regionSellVolume' => (int)($row['region_sell_volume'] ?? 0),
            'stationBuyVolume' => (int)($row['station_buy_volume'] ?? 0),
            'stationSellVolume' => (int)($row['station_sell_volume'] ?? 0),
            'regionBuyOrderCount' => (int)($row['region_buy_order_count'] ?? 0),
            'regionSellOrderCount' => (int)($row['region_sell_order_count'] ?? 0),
            'stationBuyOrderCount' => (int)($row['station_buy_order_count'] ?? 0),
            'stationSellOrderCount' => (int)($row['station_sell_order_count'] ?? 0),
            'snapshotTrend' => snapshot_trend($historyByType[$typeKey] ?? []),
            'fetchedAt' => $row['fetched_at'],
        ];
        $items[] = $item;
        $byTypeId[$typeKey] = $item;
    }
    return [
        'regionId' => $regionId,
        'stationId' => $stationId,
        'days' => $days,
        'items' => $items,
        'byTypeId' => $byTypeId,
        'fetchedAt' => gmdate('c'),
    ];
}

function adam_number(string $value): float {
    $raw = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $raw = trim(preg_replace('/[^\d,.\-]/', '', $raw) ?? '');
    if ($raw === '' || $raw === '-') {
        return 0.0;
    }
    if (strpos($raw, ',') !== false) {
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
    } elseif (substr_count($raw, '.') > 1 || preg_match('/^\d{1,3}(\.\d{3})+$/', $raw)) {
        $raw = str_replace('.', '', $raw);
    }
    return (float)$raw;
}

function adam_text(string $value): string {
    return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function median(array $values): ?float {
    $values = array_values(array_filter($values, static fn($v) => is_numeric($v)));
    if (!$values) {
        return null;
    }
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $mid = intdiv($count, 2);
    return $count % 2 ? (float)$values[$mid] : ((float)$values[$mid - 1] + (float)$values[$mid]) / 2;
}

function parse_adam_station_history(string $html): array {
    preg_match_all('/<tr\s+class="highlight"\s*>(.*?)<\/tr>/is', $html, $matches);
    $rows = [];
    foreach ($matches[1] as $rowHtml) {
        preg_match_all('/<td\b[^>]*>(.*?)<\/td>/is', $rowHtml, $cells);
        if (count($cells[1]) < 13) {
            continue;
        }
        $date = adam_text($cells[1][0]);
        $sellQuantity = adam_number($cells[1][8]);
        $sellValue = adam_number($cells[1][9]);
        $sellAvg = adam_number($cells[1][11]);
        $unit = $sellQuantity > 0 && $sellValue > 0 ? $sellValue / $sellQuantity : $sellAvg;
        if ($date === '' || $sellQuantity <= 0 || $unit <= 0) {
            continue;
        }
        $rows[] = [
            'date' => $date,
            'trades' => (int)adam_number($cells[1][7]),
            'quantity' => (int)round($sellQuantity),
            'value' => $sellValue > 0 ? $sellValue : $unit * $sellQuantity,
            'avg' => $sellAvg,
            'unit' => $unit,
            'high' => adam_number($cells[1][10]),
            'low' => adam_number($cells[1][12]),
        ];
    }
    usort($rows, static fn($a, $b) => strcmp((string)$b['date'], (string)$a['date']));
    return $rows;
}

function adam_station_summary_from_rows(array $recent, int $requestedDays): array {
    $prices = array_map(static fn($row) => (float)$row['unit'], $recent);
    $median = median($prices);
    $filtered = $recent;
    if ($median !== null && count($recent) >= 5) {
        $lower = $median * 0.75;
        $upper = $median * 1.25;
        $candidate = array_values(array_filter($recent, static function ($row) use ($lower, $upper) {
            $unit = (float)$row['unit'];
            return $unit >= $lower && $unit <= $upper;
        }));
        if (count($candidate) >= max(3, (int)floor(count($recent) * 0.6))) {
            $filtered = $candidate;
        }
    }

    $quantity = 0;
    $value = 0.0;
    $trades = 0;
    $dates = [];
    foreach ($filtered as $row) {
        $quantity += (int)$row['quantity'];
        $value += (float)$row['value'];
        $trades += (int)$row['trades'];
        $dates[] = $row['date'];
    }
    return [
        'days' => count($filtered),
        'requestedDays' => $requestedDays,
        'price' => $quantity > 0 ? $value / $quantity : null,
        'quantity' => $quantity,
        'value' => $value,
        'trades' => $trades,
        'median' => $median,
        'dates' => $dates,
        'lastDate' => $dates[0] ?? null,
    ];
}

function adam_station_summary(array $rows, int $days): array {
    return adam_station_summary_from_rows(array_slice($rows, 0, $days), $days);
}

function adam_station_trend(array $rows, int $days = 120): array {
    $window = array_slice($rows, 0, $days);
    $count = count($window);
    if ($count < 10) {
        return [
            'direction' => 'unknown',
            'label' => 'zu wenig Daten',
            'percent' => null,
            'days' => $count,
            'compareDays' => 0,
            'source' => 'Station',
        ];
    }

    $compareDays = min(20, max(5, (int)floor($count / 4)));
    $recent = adam_station_summary_from_rows(array_slice($window, 0, $compareDays), $compareDays);
    $older = adam_station_summary_from_rows(array_slice($window, -$compareDays), $compareDays);
    $recentPrice = (float)($recent['price'] ?? 0);
    $olderPrice = (float)($older['price'] ?? 0);
    if ($recentPrice <= 0 || $olderPrice <= 0) {
        return [
            'direction' => 'unknown',
            'label' => 'zu wenig Daten',
            'percent' => null,
            'days' => $count,
            'compareDays' => $compareDays,
            'source' => 'Station',
        ];
    }

    $percent = (($recentPrice - $olderPrice) / $olderPrice) * 100;
    $abs = abs($percent);
    if ($abs < 1.5) {
        $direction = 'flat';
        $label = 'seitwärts';
    } elseif ($percent > 0) {
        $direction = 'up';
        $label = $abs >= 8 ? 'stark steigend' : 'steigend';
    } else {
        $direction = 'down';
        $label = $abs >= 8 ? 'stark fallend' : 'fallend';
    }

    $oldest = market_history_average([$window[$count - 1]]);
    $newest = market_history_average([$window[0]]);

    return [
        'direction' => $direction,
        'label' => $label,
        'percent' => $percent,
        'days' => $count,
        'compareDays' => $compareDays,
        'recentPrice' => $recentPrice,
        'olderPrice' => $olderPrice,
        'fromDate' => $window[$count - 1]['date'] ?? null,
        'toDate' => $window[0]['date'] ?? null,
        'source' => 'Station',
    ];
}

function market_history_average(array $rows): array {
    $sum = 0.0;
    $volume = 0;
    $dates = [];
    foreach ($rows as $row) {
        $sum += (float)$row['average'];
        $volume += (int)($row['volume'] ?? 0);
        $timestamp = ((float)($row['date'] ?? 0)) / 1000;
        if ($timestamp > 0) {
            $dates[] = gmdate('Y-m-d', (int)$timestamp);
        }
    }
    return [
        'price' => count($rows) > 0 ? $sum / count($rows) : null,
        'volume' => $volume,
        'dates' => $dates,
    ];
}

function market_history_trend(array $rows, int $days = 120): array {
    $window = array_slice($rows, 0, $days);
    $count = count($window);
    if ($count < 10) {
        return [
            'direction' => 'unknown',
            'label' => 'zu wenig Daten',
            'percent' => null,
            'days' => $count,
            'compareDays' => 0,
            'source' => 'Region',
        ];
    }

    $compareDays = min(20, max(5, (int)floor($count / 4)));
    $recent = market_history_average(array_slice($window, 0, $compareDays));
    $older = market_history_average(array_slice($window, -$compareDays));
    $recentPrice = (float)($recent['price'] ?? 0);
    $olderPrice = (float)($older['price'] ?? 0);
    if ($recentPrice <= 0 || $olderPrice <= 0) {
        return [
            'direction' => 'unknown',
            'label' => 'zu wenig Daten',
            'percent' => null,
            'days' => $count,
            'compareDays' => $compareDays,
            'source' => 'Region',
        ];
    }

    $percent = (($recentPrice - $olderPrice) / $olderPrice) * 100;
    $abs = abs($percent);
    if ($abs < 1.5) {
        $direction = 'flat';
        $label = 'seitwärts';
    } elseif ($percent > 0) {
        $direction = 'up';
        $label = $abs >= 8 ? 'stark steigend' : 'steigend';
    } else {
        $direction = 'down';
        $label = $abs >= 8 ? 'stark fallend' : 'fallend';
    }

    return [
        'direction' => $direction,
        'label' => $label,
        'percent' => $percent,
        'days' => $count,
        'compareDays' => $compareDays,
        'recentPrice' => $recentPrice,
        'olderPrice' => $olderPrice,
        'fromDate' => $oldest['dates'][0] ?? null,
        'toDate' => $newest['dates'][0] ?? null,
        'source' => 'Region',
    ];
}

$config = read_config();
$action = $_GET['action'] ?? 'load';
$expectedToken = (string)($config['api_token'] ?? '');
$providedToken = request_header('X-Api-Token');
$cronToken = (string)($config['cron_token'] ?? '');
$providedCronToken = (string)($_GET['token'] ?? request_header('X-Cron-Token'));
$cronAuthorized = $action === 'refresh-price-snapshots' && $cronToken !== '' && hash_equals($cronToken, $providedCronToken);
$ssoPublicActions = ['sso-start', 'sso-callback'];
if ($expectedToken !== '' && !hash_equals($expectedToken, $providedToken) && !$cronAuthorized && !in_array($action, $ssoPublicActions, true)) {
    respond(401, ['ok' => false, 'error' => 'Unauthorized']);
}

$pdo = null;
$dbError = null;
try {
    $pdo = pdo_or_null($config);
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$storage = $pdo instanceof PDO ? 'mysql' : 'file';

if ($action === 'health') {
    respond(200, [
        'ok' => true,
        'php' => PHP_VERSION,
        'storage' => $storage,
        'mysqlConfigured' => !empty($config['db']),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'curl' => extension_loaded('curl'),
        'dbError' => $dbError,
    ]);
}

if ($action === 'sso-status') {
    $sso = sso_config($config);
    respond(200, [
        'ok' => true,
        'configured' => !empty($sso['client_id']) && !empty($sso['client_secret']) && !empty($sso['callback_url']),
        'scopes' => sso_scopes($config),
        'characters' => $pdo instanceof PDO ? load_sso_characters($pdo) : [],
        'storage' => $storage,
    ]);
}

if ($action === 'sso-start') {
    if (!$pdo instanceof PDO) {
        respond_html(500, '<h1>Orelytics SSO</h1><p>MySQL storage is required for EVE SSO.</p>');
    }
    $sso = sso_config($config);
    $clientId = trim((string)($sso['client_id'] ?? ''));
    $callbackUrl = trim((string)($sso['callback_url'] ?? ''));
    if ($clientId === '' || $callbackUrl === '') {
        respond_html(500, '<h1>Orelytics SSO</h1><p>EVE SSO is not configured.</p>');
    }
    $state = bin2hex(random_bytes(24));
    save_sso_state($pdo, $state);
    $params = [
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => $callbackUrl,
        'scope' => implode(' ', sso_scopes($config)),
        'state' => $state,
    ];
    header('Location: https://login.eveonline.com/v2/oauth/authorize?' . http_build_query($params));
    exit;
}

if ($action === 'sso-callback') {
    if (!$pdo instanceof PDO) {
        respond_html(500, '<h1>Orelytics SSO</h1><p>MySQL storage is required for EVE SSO.</p>');
    }
    $sso = sso_config($config);
    $clientId = trim((string)($sso['client_id'] ?? ''));
    $clientSecret = trim((string)($sso['client_secret'] ?? ''));
    $frontendUrl = trim((string)($sso['frontend_url'] ?? '../'));
    $state = (string)($_GET['state'] ?? '');
    $code = (string)($_GET['code'] ?? '');
    if ($clientId === '' || $clientSecret === '') {
        respond_html(500, '<h1>Orelytics SSO</h1><p>EVE SSO is not configured.</p>');
    }
    if ($state === '' || !consume_sso_state($pdo, $state)) {
        respond_html(400, '<h1>Orelytics SSO</h1><p>Invalid or expired SSO state.</p>');
    }
    if ($code === '') {
        respond_html(400, '<h1>Orelytics SSO</h1><p>Missing authorization code.</p>');
    }
    try {
        $token = post_form_json(
            'https://login.eveonline.com/v2/oauth/token',
            ['grant_type' => 'authorization_code', 'code' => $code],
            $clientId,
            $clientSecret
        );
        $claims = decode_jwt_payload((string)($token['access_token'] ?? ''));
        $aud = $claims['aud'] ?? [];
        $audiences = is_array($aud) ? $aud : [$aud];
        if (!in_array($clientId, $audiences, true) || !in_array('EVE Online', $audiences, true)) {
            throw new RuntimeException('Unexpected token audience');
        }
        if (isset($claims['exp']) && (int)$claims['exp'] < time()) {
            throw new RuntimeException('Token is already expired');
        }
        $character = save_sso_character($pdo, $token, $claims);
        $name = htmlspecialchars($character['characterName'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $target = htmlspecialchars($frontendUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        respond_html(200, '<!doctype html><meta charset="utf-8"><title>Orelytics SSO</title><meta http-equiv="refresh" content="2;url=' . $target . '"><body style="font-family:system-ui;background:#0b1016;color:#e7edf4;padding:32px"><h1>EVE verbunden</h1><p>' . $name . ' wurde mit Orelytics verbunden.</p><p><a style="color:#8bd3ff" href="' . $target . '">Zurück zu Orelytics</a></p></body>');
    } catch (Throwable $e) {
        respond_html(500, '<h1>Orelytics SSO</h1><p>SSO callback failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>');
    }
}

if ($action === 'refresh-price-snapshots') {
    if (!$pdo instanceof PDO) {
        respond(500, ['ok' => false, 'error' => 'MySQL storage is required for price snapshots', 'dbError' => $dbError]);
    }
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
        respond(405, ['ok' => false, 'error' => 'GET or POST required']);
    }

    $body = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($body)) {
            respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
        }
    }
    $regionId = (int)($body['regionId'] ?? $_GET['regionId'] ?? 10000043);
    $stationId = (int)($body['stationId'] ?? $_GET['stationId'] ?? 60008494);
    $materials = $body['items'] ?? $body['materials'] ?? default_sales_materials();
    if ($regionId <= 0 || $stationId <= 0 || !is_array($materials)) {
        respond(400, ['ok' => false, 'error' => 'Invalid regionId, stationId or materials']);
    }

    $data = refresh_market_order_snapshots($pdo, $regionId, $stationId, $materials);
    respond(200, ['ok' => true, 'data' => $data]);
}

if ($action === 'price-snapshots') {
    if (!$pdo instanceof PDO) {
        respond(500, ['ok' => false, 'error' => 'MySQL storage is required for price snapshots', 'dbError' => $dbError]);
    }
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
        respond(405, ['ok' => false, 'error' => 'GET or POST required']);
    }

    $body = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($body)) {
            respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
        }
    }
    $regionId = (int)($body['regionId'] ?? $_GET['regionId'] ?? 10000043);
    $stationId = (int)($body['stationId'] ?? $_GET['stationId'] ?? 60008494);
    $days = (int)($body['days'] ?? $_GET['days'] ?? 14);
    $materials = $body['items'] ?? $body['materials'] ?? default_sales_materials();
    if ($regionId <= 0 || $stationId <= 0 || !is_array($materials)) {
        respond(400, ['ok' => false, 'error' => 'Invalid regionId, stationId or materials']);
    }

    $data = load_market_order_snapshot_summary($pdo, $regionId, $stationId, $materials, $days);
    respond(200, ['ok' => true, 'data' => $data]);
}

if ($action === 'market-prices') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'error' => 'POST required']);
    }
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) {
        respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
    }
    $regionId = (int)($body['regionId'] ?? 10000043);
    $items = $body['items'] ?? [];
    if ($regionId <= 0 || !is_array($items)) {
        respond(400, ['ok' => false, 'error' => 'Invalid region or items']);
    }
    $cacheItems = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $typeId = (int)($item['oreId'] ?? $item['typeId'] ?? 0);
        $ore = trim((string)($item['ore'] ?? $item['name'] ?? ''));
        if ($typeId > 0) {
            $cacheItems[] = $typeId . ':' . $ore;
        }
    }
    sort($cacheItems, SORT_STRING);
    $cacheKey = market_cache_key('market-prices', ['regionId' => $regionId, 'items' => implode('|', $cacheItems)]);

    $buyPrices = [];
    $sellPrices = [];
    $byTypeId = [];
    $errors = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $ore = trim((string)($item['ore'] ?? $item['name'] ?? ''));
        $typeId = (int)($item['oreId'] ?? $item['typeId'] ?? 0);
        if ($ore === '' || $typeId <= 0) {
            continue;
        }

        $buyUrl = sprintf('https://esi.evetech.net/latest/markets/%d/orders/?datasource=tranquility&order_type=buy&type_id=%d', $regionId, $typeId);
        $sellUrl = sprintf('https://esi.evetech.net/latest/markets/%d/orders/?datasource=tranquility&order_type=sell&type_id=%d', $regionId, $typeId);
        try {
            $buyOrders = fetch_esi_market_orders($regionId, $typeId, 'buy');
            $sellOrders = fetch_esi_market_orders($regionId, $typeId, 'sell');
            $summary = summarize_esi_market_orders($buyOrders, $sellOrders);
            $buy = numeric_price($summary['buy'] ?? null);
            $sell = numeric_price($summary['sell'] ?? null);

            if ($buy !== null) {
                $buyPrices[$ore] = $buy;
            }
            if ($sell !== null) {
                $sellPrices[$ore] = $sell;
            }
            if ($buy === null && $sell === null) {
                $errors[] = ['ore' => $ore, 'typeId' => $typeId, 'error' => 'No buy or sell price returned'];
            }

            $byTypeId[(string)$typeId] = [
                'ore' => $ore,
                'buy' => $buy,
                'sell' => $sell,
                'buyVolume' => $summary['buyVolume'] ?? null,
                'sellVolume' => $summary['sellVolume'] ?? null,
                'buyOrderCount' => $summary['buyOrderCount'] ?? null,
                'sellOrderCount' => $summary['sellOrderCount'] ?? null,
                'source' => 'ESI market orders',
                'buySource' => $buyUrl,
                'sellSource' => $sellUrl,
            ];
        } catch (Throwable $e) {
            $errors[] = ['ore' => $ore, 'typeId' => $typeId, 'error' => $e->getMessage()];
        }
    }

    $data = [
        'regionId' => $regionId,
        'basis' => 'ESI market orders highest buy / lowest sell',
        'buyPrices' => $buyPrices,
        'sellPrices' => $sellPrices,
        'byTypeId' => $byTypeId,
        'errors' => $errors,
        'fetchedAt' => gmdate('c'),
    ];
    if ($buyPrices || $sellPrices) {
        save_market_cache($pdo, $cacheKey, 'market-prices', $data);
    } else {
        $cached = load_market_cache($pdo, $cacheKey, 'ESI market orders nicht erreichbar');
        if ($cached !== null) {
            respond(200, ['ok' => true, 'data' => $cached]);
        }
    }

    respond(200, ['ok' => true, 'data' => $data]);
}

if ($action === 'sales-material-history') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'error' => 'POST required']);
    }
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) {
        respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
    }
    $regionId = (int)($body['regionId'] ?? 10000043);
    $typeId = (int)($body['typeId'] ?? 37);
    $days = max(1, min(120, (int)($body['days'] ?? 10)));
    if ($regionId <= 0 || $typeId <= 0) {
        respond(400, ['ok' => false, 'error' => 'Invalid region or typeId']);
    }
    $cacheKey = market_cache_key('sales-material-history', ['regionId' => $regionId, 'typeId' => $typeId, 'days' => $days]);

    try {
        $url = sprintf('https://evetycoon.com/api/v1/market/history/%d/%d', $regionId, $typeId);
        $history = fetch_json($url);
        usort($history, static function ($a, $b) {
            return (float)($b['date'] ?? 0) <=> (float)($a['date'] ?? 0);
        });
        $recent = array_slice(array_values(array_filter($history, static function ($row) {
            return isset($row['average']) && is_numeric($row['average']) && (float)$row['average'] > 0;
        })), 0, $days);
        if (!$recent) {
            throw new RuntimeException('No history data found');
        }
        $summary = market_history_average($recent);
        $trend120 = market_history_trend(array_values(array_filter($history, static function ($row) {
            return isset($row['average']) && is_numeric($row['average']) && (float)$row['average'] > 0;
        })), min(120, $days));
        $data = [
            'regionId' => $regionId,
            'typeId' => $typeId,
            'days' => count($recent),
            'avgSell' => $summary['price'],
            'volume' => $summary['volume'],
            'dates' => $summary['dates'],
            'trend120d' => $trend120,
            'basis' => 'EVE Tycoon market history average over newest days',
            'fetchedAt' => gmdate('Y-m-d H:i'),
        ];
        save_market_cache($pdo, $cacheKey, 'sales-material-history', $data);
        respond(200, ['ok' => true, 'data' => $data]);
    } catch (Throwable $e) {
        $cached = load_market_cache($pdo, $cacheKey, 'EVE Tycoon market history nicht erreichbar: ' . $e->getMessage());
        if ($cached !== null) {
            respond(200, ['ok' => true, 'data' => $cached]);
        }
        respond(500, ['ok' => false, 'error' => 'Could not load market history: ' . $e->getMessage()]);
    }
}

if ($action === 'sales-station-history') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'error' => 'POST required']);
    }
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) {
        respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
    }
    $typeId = (int)($body['typeId'] ?? 34);
    $stationId = (int)($body['stationId'] ?? 60008494);
    $days = max(1, min(120, (int)($body['days'] ?? 20)));
    if ($typeId <= 0 || $stationId <= 0) {
        respond(400, ['ok' => false, 'error' => 'Invalid stationId or typeId']);
    }
    $cacheKey = market_cache_key('sales-station-history', ['stationId' => $stationId, 'typeId' => $typeId, 'days' => $days]);

    try {
        $url = sprintf('https://www.adam4eve.eu/hub_type_history.php?typeID=%d&mode=min&stationID=%d', $typeId, $stationId);
        $html = fetch_text($url);
        if (stripos($html, 'Bought from sell order') === false) {
            throw new RuntimeException('Adam4EVE response did not contain station trade table');
        }
        $rows = parse_adam_station_history($html);
        if (!$rows) {
            throw new RuntimeException('No station history data found');
        }
        $summary5 = adam_station_summary($rows, min(5, $days));
        $summary20 = adam_station_summary($rows, min(20, $days));
        $trend120 = adam_station_trend($rows, min(120, $days));
        $data = [
            'stationId' => $stationId,
            'typeId' => $typeId,
            'days' => $days,
            'stationVwap5d' => $summary5['price'],
            'stationVwap20d' => $summary20['price'],
            'stationVolume5d' => $summary5['quantity'],
            'stationVolume20d' => $summary20['quantity'],
            'stationTrades20d' => $summary20['trades'],
            'stationTrend120d' => $trend120,
            'lastDate' => $summary20['lastDate'],
            'dates' => $summary20['dates'],
            'basis' => 'Adam4EVE station Bought from sell order VWAP, outlier-trimmed around median',
            'source' => $url,
            'fetchedAt' => gmdate('Y-m-d H:i'),
        ];
        save_market_cache($pdo, $cacheKey, 'sales-station-history', $data);
        respond(200, ['ok' => true, 'data' => $data]);
    } catch (Throwable $e) {
        $cached = load_market_cache($pdo, $cacheKey, 'Adam4EVE station history nicht erreichbar: ' . $e->getMessage());
        if ($cached !== null) {
            respond(200, ['ok' => true, 'data' => $cached]);
        }
        respond(502, [
            'ok' => false,
            'error' => 'Could not load Adam4EVE station history: ' . $e->getMessage(),
        ]);
    }
}

if ($action === 'load') {
    if ($pdo instanceof PDO) {
        respond(200, ['ok' => true, 'storage' => 'mysql', 'data' => load_mysql_state($pdo)]);
    }
    respond(200, ['ok' => true, 'storage' => 'file', 'data' => load_file_state()]);
}

if ($action === 'save') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'error' => 'POST required']);
    }
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) {
        respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
    }
    $state = [
        'entries' => $body['entries'] ?? [],
        'marketPrices' => $body['marketPrices'] ?? new stdClass(),
        'marketSellPrices' => $body['marketSellPrices'] ?? new stdClass(),
        'marketSettings' => $body['marketSettings'] ?? new stdClass(),
        'salePlans' => $body['salePlans'] ?? new stdClass(),
        'excludedResources' => $body['excludedResources'] ?? [],
    ];
    try {
        if ($pdo instanceof PDO) {
            save_mysql_state($pdo, $state);
            respond(200, ['ok' => true, 'storage' => 'mysql']);
        }
        save_file_state($state);
        respond(200, ['ok' => true, 'storage' => 'file']);
    } catch (Throwable $e) {
        respond(500, ['ok' => false, 'error' => 'Save failed']);
    }
}

respond(404, ['ok' => false, 'error' => 'Unknown action']);
