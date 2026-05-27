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
    $days = max(1, min(120, (int)($body['days'] ?? 10)));
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
        $summary = market_history_average($recent);
        $trend120 = market_history_trend(array_values(array_filter($history, static function ($row) {
            return isset($row['average']) && is_numeric($row['average']) && (float)$row['average'] > 0;
        })), min(120, $days));
        respond(200, [
            'ok' => true,
            'data' => [
                'regionId' => $regionId,
                'typeId' => $typeId,
                'days' => count($recent),
                'avgSell' => $summary['price'],
                'volume' => $summary['volume'],
                'dates' => $summary['dates'],
                'trend120d' => $trend120,
                'basis' => 'EVE Tycoon market history average over newest days',
                'fetchedAt' => gmdate('Y-m-d H:i'),
            ],
        ]);
    } catch (Throwable $e) {
        respond(500, ['ok' => false, 'error' => 'Could not load market history']);
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

    try {
        $url = sprintf('https://www.adam4eve.eu/hub_type_history.php?typeID=%d&mode=min&stationID=%d', $typeId, $stationId);
        $html = fetch_text($url);
        if (stripos($html, 'Bought from sell order') === false) {
            throw new RuntimeException('Adam4EVE response did not contain station trade table');
        }
        $rows = parse_adam_station_history($html);
        if (!$rows) {
            respond(404, ['ok' => false, 'error' => 'No station history data found']);
        }
        $summary5 = adam_station_summary($rows, min(5, $days));
        $summary20 = adam_station_summary($rows, min(20, $days));
        $trend120 = adam_station_trend($rows, min(120, $days));
        respond(200, [
            'ok' => true,
            'data' => [
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
            ],
        ]);
    } catch (Throwable $e) {
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
