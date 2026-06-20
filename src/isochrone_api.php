<?php
header('Content-Type: application/json; charset=utf-8');

define('ORS_ENDPOINT',    'https://api.openrouteservice.org/v2/isochrones/driving-car');
define('NOMINATIM_BASE',  'https://nominatim.openstreetmap.org/search');
define('NOMINATIM_UA',    'maizoek/1.0 (bout_largo_0g@icloud.com)');
define('ISO_CACHE_TTL',   24 * 3600);

require_once __DIR__ . '/lib/SqliteLogger.php';
require_once __DIR__ . '/lib/NullLogger.php';

try {
    $logger = new SqliteLogger(__DIR__ . '/../cache.sqlite');
} catch (\Throwable $e) {
    error_log('[isochrone_api] SqliteLogger init failed: ' . $e->getMessage());
    $logger = new NullLogger();
}

// ── Input ─────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!$body || empty($body['address1']) || empty($body['address2']) || empty($body['max_minutes1']) || empty($body['max_minutes2'])) {
    http_response_code(400);
    echo json_encode(['error' => 'missing_fields']);
    exit;
}

$address1     = trim($body['address1']);
$address2     = trim($body['address2']);
$max_minutes1 = max(1, min(180, (int)$body['max_minutes1']));
$max_minutes2 = max(1, min(180, (int)$body['max_minutes2']));
$departure_h  = max(0, min(23, (int)substr($body['departure_time'] ?? '08:00', 0, 2)));

// ── Infra ─────────────────────────────────────────────────────
$infra   = file_exists(__DIR__ . '/config/infra.json')
    ? json_decode(file_get_contents(__DIR__ . '/config/infra.json'), true)
    : [];
$ors_key = $infra['ors_api_key'] ?? '';
if (!$ors_key) {
    http_response_code(500);
    echo json_encode(['error' => 'ors_key_missing']);
    exit;
}

// ── SQLite cache ──────────────────────────────────────────────
function open_iso_cache(): PDO {
    $db = new PDO('sqlite:' . __DIR__ . '/../cache.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE IF NOT EXISTS isochrone_cache (
        cache_key  TEXT    NOT NULL PRIMARY KEY,
        geojson    TEXT    NOT NULL,
        cached_at  INTEGER NOT NULL
    )');
    return $db;
}

function iso_cache_get(PDO $db, string $key): ?string {
    $stmt = $db->prepare('SELECT geojson, cached_at FROM isochrone_cache WHERE cache_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (time() - (int)$row['cached_at']) > ISO_CACHE_TTL) return null;
    return $row['geojson'];
}

function iso_cache_set(PDO $db, string $key, string $geojson): void {
    $stmt = $db->prepare(
        'INSERT OR REPLACE INTO isochrone_cache (cache_key, geojson, cached_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([$key, $geojson, time()]);
}

// ── Nominatim geocoding ───────────────────────────────────────
function geocode(string $address, $logger): ?array {
    $url = NOMINATIM_BASE . '?' . http_build_query([
        'q'            => $address,
        'format'       => 'json',
        'limit'        => 1,
        'countrycodes' => 'be',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['User-Agent: ' . NOMINATIM_UA],
    ]);
    $resp     = curl_exec($ch);
    $curl_err = curl_error($ch);
    if (!$resp) {
        $logger->error('Nominatim cURL failure', ['ctx' => 'isochrone.geocode', 'address' => $address, 'curl_error' => $curl_err]);
        return null;
    }
    $data = json_decode($resp, true);
    if (empty($data[0])) {
        $logger->warning('Nominatim: no result', ['ctx' => 'isochrone.geocode', 'address' => $address]);
        return null;
    }
    return [(float)$data[0]['lon'], (float)$data[0]['lat']]; // [lng, lat] pour ORS
}

// ── ORS isochrone ─────────────────────────────────────────────
function fetch_isochrone(array $lnglat, int $seconds, string $ors_key, $logger): ?string {
    $ch = curl_init(ORS_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'locations'  => [$lnglat],
            'range'      => [$seconds],
            'range_type' => 'time',
        ]),
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $ors_key,
            'Content-Type: application/json',
        ],
    ]);
    $resp      = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    if (!$resp) {
        $detail = ['reason' => 'curl_failure', 'curl_error' => $curl_err];
        $logger->error('ORS cURL failure', ['ctx' => 'isochrone.ors', 'curl_error' => $curl_err, 'lnglat' => $lnglat]);
        $GLOBALS['ors_last_error'] = $detail;
        return null;
    }
    $data = json_decode($resp, true);
    if (empty($data['features'][0]['geometry'])) {
        $detail = ['reason' => 'bad_response', 'http_code' => $http_code, 'body' => substr($resp, 0, 500)];
        $logger->error('ORS bad response', ['ctx' => 'isochrone.ors', 'http_code' => $http_code, 'body' => $detail['body'], 'lnglat' => $lnglat]);
        $GLOBALS['ors_last_error'] = $detail;
        return null;
    }
    return json_encode($data['features'][0]['geometry']);
}

function get_or_fetch(PDO $db, array $lnglat, int $effective_secs, string $ors_key, $logger): ?string {
    $key = number_format($lnglat[1], 4, '.', '') . ',' . number_format($lnglat[0], 4, '.', '')
         . ':' . $effective_secs;
    $cached = iso_cache_get($db, $key);
    if ($cached) return $cached;
    $geojson = fetch_isochrone($lnglat, $effective_secs, $ors_key, $logger);
    if ($geojson) iso_cache_set($db, $key, $geojson);
    return $geojson;
}

// ── Traffic factor ────────────────────────────────────────────
$traffic_factors = [
     0=>1.00,  1=>1.00,  2=>1.00,  3=>1.00,  4=>1.00,
     5=>1.05,  6=>1.15,  7=>1.35,  8=>1.40,  9=>1.25,
    10=>1.10, 11=>1.05, 12=>1.10, 13=>1.05, 14=>1.05,
    15=>1.15, 16=>1.30, 17=>1.40, 18=>1.35, 19=>1.20,
    20=>1.10, 21=>1.05, 22=>1.00, 23=>1.00,
];
$factor          = $traffic_factors[$departure_h] ?? 1.0;
$effective_secs1 = (int)min(3600, round(($max_minutes1 * 60) / $factor));
$effective_secs2 = (int)min(3600, round(($max_minutes2 * 60) / $factor));

// ── Pipeline ──────────────────────────────────────────────────
$logger->info('Isochrone request', ['ctx' => 'isochrone.request', 'address1' => $address1, 'address2' => $address2]);

$coords1 = geocode($address1, $logger);
if (!$coords1) {
    http_response_code(422);
    echo json_encode(['error' => 'address_not_found', 'address' => $address1]);
    exit;
}

usleep(1100000); // Nominatim : 1 req/s max

$coords2 = geocode($address2, $logger);
if (!$coords2) {
    http_response_code(422);
    echo json_encode(['error' => 'address_not_found', 'address' => $address2]);
    exit;
}

$db = open_iso_cache();

$GLOBALS['ors_last_error'] = null;

$poly1 = get_or_fetch($db, $coords1, $effective_secs1, $ors_key, $logger);
if (!$poly1) {
    $logger->error('get_or_fetch returned falsy for address1', ['ctx' => 'isochrone.ors', 'address' => $address1, 'secs' => $effective_secs1, 'ors_detail' => $GLOBALS['ors_last_error']]);
    http_response_code(502);
    echo json_encode(['error' => 'ors_error', 'detail' => $GLOBALS['ors_last_error']]);
    exit;
}

$poly2 = get_or_fetch($db, $coords2, $effective_secs2, $ors_key, $logger);
if (!$poly2) {
    $logger->error('get_or_fetch returned falsy for address2', ['ctx' => 'isochrone.ors', 'address' => $address2, 'secs' => $effective_secs2, 'ors_detail' => $GLOBALS['ors_last_error']]);
    http_response_code(502);
    echo json_encode(['error' => 'ors_error', 'detail' => $GLOBALS['ors_last_error']]);
    exit;
}

echo json_encode([
    'poly1'              => json_decode($poly1),
    'poly2'              => json_decode($poly2),
    'effective_minutes1' => (int)round($effective_secs1 / 60),
    'effective_minutes2' => (int)round($effective_secs2 / 60),
    'traffic_factor'     => $factor,
]);
