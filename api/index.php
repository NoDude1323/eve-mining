<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

function respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

function read_config(): array {
    $path = __DIR__ . '/config.php';
    if (!is_file($path)) {
        return [];
    }
    $config = require $path;
    return is_array($config) ? $config : [];
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

function numeric_price($value): ?float {
    if (!is_numeric($value)) {
        return null;
    }
    $price = (float)$value;
    return $price > 0 ? $price : null;
}

$config = read_config();
$expectedToken = (string)($config['api_token'] ?? '');
if ($expectedToken !== '' && !hash_equals($expectedToken, request_header('X-Api-Token'))) {
    respond(401, ['ok' => false, 'error' => 'Unauthorized']);
}

$pdo = null;
$dbError = null;
try {
    $pdo = pdo_or_null($config);
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$action = $_GET['action'] ?? 'load';
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

        $url = sprintf('https://evetycoon.com/api/v1/market/stats/%d/%d', $regionId, $typeId);
        try {
            $stats = fetch_json($url);
            $buy = numeric_price($stats['buyAvgFivePercent'] ?? null) ?? numeric_price($stats['maxBuy'] ?? null);
            $sell = numeric_price($stats['sellAvgFivePercent'] ?? null) ?? numeric_price($stats['minSell'] ?? null);

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
                'buyVolume' => $stats['buyVolume'] ?? null,
                'sellVolume' => $stats['sellVolume'] ?? null,
                'source' => $url,
            ];
        } catch (Throwable $e) {
            $errors[] = ['ore' => $ore, 'typeId' => $typeId, 'error' => $e->getMessage()];
        }
    }

    respond(200, [
        'ok' => true,
        'data' => [
            'regionId' => $regionId,
            'basis' => 'EVE Tycoon market stats buyAvgFivePercent/sellAvgFivePercent',
            'buyPrices' => $buyPrices,
            'sellPrices' => $sellPrices,
            'byTypeId' => $byTypeId,
            'errors' => $errors,
            'fetchedAt' => gmdate('c'),
        ],
    ]);
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
    $days = max(1, min(30, (int)($body['days'] ?? 10)));
    if ($regionId <= 0 || $typeId <= 0) {
        respond(400, ['ok' => false, 'error' => 'Invalid region or typeId']);
    }

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
            respond(404, ['ok' => false, 'error' => 'No history data found']);
        }
        $sum = 0.0;
        $volume = 0;
        $dates = [];
        foreach ($recent as $row) {
            $sum += (float)$row['average'];
            $volume += (int)($row['volume'] ?? 0);
            $timestamp = ((float)($row['date'] ?? 0)) / 1000;
            if ($timestamp > 0) {
                $dates[] = gmdate('Y-m-d', (int)$timestamp);
            }
        }
        respond(200, [
            'ok' => true,
            'data' => [
                'regionId' => $regionId,
                'typeId' => $typeId,
                'days' => count($recent),
                'avgSell' => $sum / count($recent),
                'volume' => $volume,
                'dates' => $dates,
                'basis' => 'EVE Tycoon market history average over newest days',
                'fetchedAt' => gmdate('Y-m-d H:i'),
            ],
        ]);
    } catch (Throwable $e) {
        respond(500, ['ok' => false, 'error' => 'Could not load market history']);
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
