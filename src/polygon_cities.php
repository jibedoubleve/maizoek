<?php
header('Content-Type: application/json; charset=utf-8');

define('PC_GEONAMES_BASE', 'https://secure.geonames.org');
define('PC_CACHE_TTL',     30 * 24 * 3600);
define('PC_EXCLUDED_FCODES', ['PPLH', 'PPLQ', 'PPLW']);

require_once __DIR__ . '/lib/SqliteLogger.php';

// ── SQLite cache ──────────────────────────────────────────────
function pc_open_cache(): PDO {
    $db = new PDO('sqlite:' . __DIR__ . '/../cache.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE IF NOT EXISTS location_cache (
        lat_key   TEXT NOT NULL,
        lng_key   TEXT NOT NULL,
        postal    TEXT,
        cached_at INTEGER NOT NULL,
        PRIMARY KEY (lat_key, lng_key)
    )');
    return $db;
}

function pc_cache_get(PDO $db, float $lat, float $lng): string|null|false {
    $stmt = $db->prepare(
        'SELECT postal, cached_at FROM location_cache WHERE lat_key = ? AND lng_key = ?'
    );
    $stmt->execute([number_format($lat, 4, '.', ''), number_format($lng, 4, '.', '')]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (time() - (int)$row['cached_at']) > PC_CACHE_TTL) return false;
    return $row['postal'];
}

function pc_cache_set(PDO $db, float $lat, float $lng, ?string $postal): void {
    $stmt = $db->prepare(
        'INSERT OR REPLACE INTO location_cache (lat_key, lng_key, postal, cached_at) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([number_format($lat, 4, '.', ''), number_format($lng, 4, '.', ''), $postal, time()]);
}

// ── HTTP helpers ──────────────────────────────────────────────
function pc_geonames_get(string $endpoint, array $params): ?array {
    $url = PC_GEONAMES_BASE . '/' . $endpoint . '?' . http_build_query($params);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $resp = curl_exec($ch);
    return $resp ? json_decode($resp, true) : null;
}

function pc_geonames_get_multi(string $endpoint, array $requests, array $common): array {
    $mh      = curl_multi_init();
    $handles = [];
    foreach ($requests as $key => $params) {
        $url = PC_GEONAMES_BASE . '/' . $endpoint . '?' . http_build_query(array_merge($common, $params));
        $ch  = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }
    $running = null;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);
    $results = [];
    foreach ($handles as $key => $ch) {
        $body = curl_multi_getcontent($ch);
        $results[$key] = $body ? json_decode($body, true) : null;
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);
    return $results;
}

// ── Bounding box helpers ──────────────────────────────────────
function extract_outer_ring(array $geometry): array {
    if ($geometry['type'] === 'Polygon') return $geometry['coordinates'][0];
    $all = [];
    foreach ($geometry['coordinates'] as $poly) array_push($all, ...$poly[0]);
    return $all;
}

function bbox_center_radius(array $coords): array {
    $lats  = array_column($coords, 1);
    $lngs  = array_column($coords, 0);
    $cLat  = (min($lats) + max($lats)) / 2;
    $cLng  = (min($lngs) + max($lngs)) / 2;
    $R     = 6371;
    $dLat  = deg2rad(max($lats) - $cLat);
    $dLng  = deg2rad(max($lngs) - $cLng);
    $a     = sin($dLat / 2) ** 2 + cos(deg2rad($cLat)) * cos(deg2rad(max($lats))) * sin($dLng / 2) ** 2;
    $r     = $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    return [$cLat, $cLng, (int)ceil($r) + 5]; // +5 km buffer
}

// ── Input ─────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!$body || empty($body['polygon'])) {
    http_response_code(400);
    echo json_encode(['error' => 'polygon manquant']);
    exit;
}

$polygon    = $body['polygon'];
$geo_type   = $polygon['type'] ?? '';
$min_pop    = (int)($body['min_population'] ?? 2000);
$ignore_pop = (bool)($body['ignore_population'] ?? false);
$regions    = is_array($body['regions'] ?? null) ? $body['regions'] : [];

if (!in_array($geo_type, ['Polygon', 'MultiPolygon'], true) || empty($polygon['coordinates'])) {
    http_response_code(400);
    echo json_encode(['error' => 'polygon invalide']);
    exit;
}

// ── Infra ─────────────────────────────────────────────────────
$infra    = file_exists(__DIR__ . '/config/infra.json')
    ? json_decode(file_get_contents(__DIR__ . '/config/infra.json'), true)
    : [];
$username = $infra['geonames_username'] ?? '';
$logger   = new SqliteLogger(__DIR__ . '/../cache.sqlite');

if (!$username) {
    $logger->error('geonames_username missing', ['ctx' => 'polygon.config']);
    http_response_code(500);
    echo json_encode(['error' => 'geonames_username manquant dans infra.json']);
    exit;
}

// ── Center + radius from bounding box ────────────────────────
$outer_coords             = extract_outer_ring($polygon);
[$centerLat, $centerLng, $radius] = bbox_center_radius($outer_coords);

$logger->info('Polygon cities request', [
    'ctx'    => 'polygon.request',
    'type'   => $geo_type,
    'center' => "$centerLat,$centerLng",
    'radius' => $radius,
]);

// ── GeoNames: nearby places ───────────────────────────────────
$data = pc_geonames_get('findNearbyPlaceNameJSON', [
    'lat' => $centerLat, 'lng' => $centerLng,
    'radius' => $radius, 'maxRows' => 500, 'username' => $username,
]);

if ($data === null) {
    $logger->error('GeoNames unreachable (cURL)', ['ctx' => 'polygon.geonames', 'center' => "$centerLat,$centerLng"]);
    http_response_code(502);
    echo json_encode(['error' => 'GeoNames inaccessible']);
    exit;
}
if (isset($data['status'])) {
    $logger->error('GeoNames API error', ['ctx' => 'polygon.geonames', 'status' => $data['status']]);
    http_response_code(502);
    echo json_encode(['error' => 'GeoNames erreur API', 'detail' => $data['status']]);
    exit;
}

$cities = $data['geonames'] ?? [];

// Filter: fcode blacklist
$cities = array_values(array_filter($cities, fn($c) => !in_array($c['fcode'] ?? '', PC_EXCLUDED_FCODES)));

// Filter: population
if (!$ignore_pop) {
    $cities = array_values(array_filter($cities, fn($c) => ($c['population'] ?? 0) >= $min_pop));
}

// Filter: regions
if ($regions) {
    $cities = array_values(array_filter($cities, fn($c) => in_array($c['adminCode1'] ?? '', $regions)));
}

usort($cities, fn($a, $b) => strcmp($a['toponymName'] ?? $a['name'] ?? '', $b['toponymName'] ?? $b['name'] ?? ''));

// ── Postal codes (cache-first, parallel cURL for misses) ──────
$db       = pc_open_cache();
$cached   = [];
$to_fetch = [];

foreach ($cities as $key => $city) {
    $hit = pc_cache_get($db, (float)$city['lat'], (float)$city['lng']);
    if ($hit !== false) {
        $cached[$key] = $hit;
    } else {
        $to_fetch[$key] = ['lat' => $city['lat'], 'lng' => $city['lng'], 'maxRows' => 1];
    }
}

$fetched = [];
if ($to_fetch) {
    $logger->debug('Fetching postal codes', ['ctx' => 'polygon.postal', 'count' => count($to_fetch)]);
    $responses = pc_geonames_get_multi('findNearbyPostalCodesJSON', $to_fetch, ['username' => $username]);
    foreach ($to_fetch as $key => $_) {
        if (isset($responses[$key]['status'])) {
            $logger->error('GeoNames postal API error', ['ctx' => 'polygon.postal', 'status' => $responses[$key]['status']]);
        }
        $postal        = $responses[$key]['postalCodes'][0]['postalCode'] ?? null;
        $fetched[$key] = $postal;
        pc_cache_set($db, (float)$cities[$key]['lat'], (float)$cities[$key]['lng'], $postal);
    }
}

$result_cities = [];
$all_postal    = [];

foreach ($cities as $key => $city) {
    $name   = $city['toponymName'] ?? $city['name'] ?? '';
    $postal = array_key_exists($key, $cached) ? $cached[$key] : ($fetched[$key] ?? null);
    $result_cities[] = ['name' => $name, 'lat' => (float)$city['lat'], 'lng' => (float)$city['lng'], 'postal' => $postal];
    if ($postal) $all_postal[] = $postal;
}

$logger->info('Polygon cities complete', ['ctx' => 'polygon.result', 'bbox_cities' => count($result_cities)]);

echo json_encode([
    'center'      => ['lat' => $centerLat, 'lng' => $centerLng],
    'cities'      => $result_cities,
    'postalCodes' => array_values(array_unique($all_postal)),
], JSON_UNESCAPED_UNICODE);
