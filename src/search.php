<?php
header('Content-Type: application/json; charset=utf-8');

define('GEONAMES_BASE', 'https://secure.geonames.org');
define('CACHE_TTL',     30 * 24 * 3600); // 30 days

const DIRECTIONS = [
    'North' => 0, 'NorthEast' => 45, 'East' => 90, 'SouthEast' => 135,
    'South' => 180, 'SouthWest' => 225, 'West' => 270, 'NorthWest' => 315,
];

// ── SQLite cache ──────────────────────────────────────────────
function open_cache(): PDO {
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

/** @return string|null|false  false = cache miss; null = cached (no postal found); string = postal code */
function cache_get(PDO $db, float $lat, float $lng): string|null|false {
    $stmt = $db->prepare(
        'SELECT postal, cached_at FROM location_cache WHERE lat_key = ? AND lng_key = ?'
    );
    $stmt->execute([number_format($lat, 4, '.', ''), number_format($lng, 4, '.', '')]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (time() - (int) $row['cached_at']) > CACHE_TTL) return false;
    return $row['postal']; // null or string
}

function cache_set(PDO $db, float $lat, float $lng, ?string $postal): void {
    $stmt = $db->prepare(
        'INSERT OR REPLACE INTO location_cache (lat_key, lng_key, postal, cached_at) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([number_format($lat, 4, '.', ''), number_format($lng, 4, '.', ''), $postal, time()]);
}

// ── HTTP helpers ──────────────────────────────────────────────
function geonames_get(string $endpoint, array $params): ?array {
    $url = GEONAMES_BASE . '/' . $endpoint . '?' . http_build_query($params);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response ? json_decode($response, true) : null;
}

function geonames_get_multi(string $endpoint, array $requests, array $common_params): array {
    $mh      = curl_multi_init();
    $handles = [];

    foreach ($requests as $key => $params) {
        $url = GEONAMES_BASE . '/' . $endpoint . '?' . http_build_query(array_merge($common_params, $params));
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    $results = [];
    foreach ($handles as $key => $ch) {
        $body          = curl_multi_getcontent($ch);
        $results[$key] = $body ? json_decode($body, true) : null;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
    return $results;
}

// ── GeoNames helpers ──────────────────────────────────────────
function get_coordinates(string $address, string $country, string $username): ?array {
    $data = geonames_get('searchJSON', [
        'q' => $address, 'maxRows' => 1, 'country' => $country, 'username' => $username,
    ]);
    if (empty($data['geonames'])) return null;
    return [(float) $data['geonames'][0]['lat'], (float) $data['geonames'][0]['lng']];
}

function get_cities_filter(int $min_population): string {
    if ($min_population >= 15000) return 'cities15000';
    if ($min_population >= 5000)  return 'cities5000';
    return 'cities1000';
}

function find_nearby_cities(float $lat, float $lng, int $radius, int $min_population, string $username): array {
    $data = geonames_get('findNearbyPlaceNameJSON', [
        'lat' => $lat, 'lng' => $lng, 'radius' => $radius,
        'cities' => get_cities_filter($min_population), 'maxRows' => 500, 'username' => $username,
    ]);
    return $data['geonames'] ?? [];
}


// ── Direction filter ──────────────────────────────────────────
function calculate_bearing(float $lat1, float $lng1, float $lat2, float $lng2): float {
    [$lat1, $lng1, $lat2, $lng2] = array_map('deg2rad', [$lat1, $lng1, $lat2, $lng2]);
    $dLng = $lng2 - $lng1;
    $x = sin($dLng) * cos($lat2);
    $y = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLng);
    return fmod(rad2deg(atan2($x, $y)) + 360, 360);
}

function is_in_range(float $bearing, string $dir_from, string $dir_to): bool {
    $from = DIRECTIONS[$dir_from] ?? 0;
    $to   = DIRECTIONS[$dir_to]   ?? 0;
    if ($from <= $to) return $bearing >= $from && $bearing <= $to;
    return $bearing >= $from || $bearing <= $to;
}

// ── Load server config ────────────────────────────────────────
$infra_file = __DIR__ . '/infra.json';
if (!file_exists($infra_file)) {
    http_response_code(500);
    echo json_encode(['error' => 'infra.json introuvable']);
    exit;
}
$infra    = json_decode(file_get_contents($infra_file), true);
$username = $infra['geonames_username'] ?? '';

if (!$username) {
    http_response_code(500);
    echo json_encode(['error' => 'geonames_username manquant dans infra.json']);
    exit;
}

$query_file = __DIR__ . '/query_params.json';
if (!file_exists($query_file)) {
    http_response_code(500);
    echo json_encode(['error' => 'query_params.json introuvable']);
    exit;
}
$server_config  = json_decode(file_get_contents($query_file), true);
$default_fcodes = $server_config['fcodes'] ?? ['PPL', 'PPLA', 'PPLA2', 'PPLA3', 'PPLA4'];

// ── Read POST input ───────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Corps de requête invalide']);
    exit;
}

$address  = trim($input['address']        ?? '');
$radius   = (int) ($input['radius']       ?? 30);
$regions  = $input['regions']             ?? [];
$min_pop  = (int) ($input['min_population'] ?? 5000);
$dir_from = $input['dir_from']            ?? 'North';
$dir_to   = $input['dir_to']              ?? 'North';

$allowed_countries = ['BE', 'FR', 'NL', 'LU'];
$country = in_array($input['country'] ?? '', $allowed_countries, true)
    ? $input['country']
    : 'BE';

if (!$address) {
    http_response_code(400);
    echo json_encode(['error' => 'Adresse manquante']);
    exit;
}

if (!array_key_exists($dir_from, DIRECTIONS) || !array_key_exists($dir_to, DIRECTIONS)) {
    http_response_code(400);
    echo json_encode(['error' => 'Direction invalide']);
    exit;
}

// ── Pipeline ──────────────────────────────────────────────────
$coords = get_coordinates($address, $country, $username);
if (!$coords) {
    http_response_code(404);
    echo json_encode(['error' => "Ville introuvable : $address"]);
    exit;
}
[$center_lat, $center_lng] = $coords;

$cities = find_nearby_cities($center_lat, $center_lng, $radius, $min_pop, $username);

// Filter: fcode + population
$cities = array_values(array_filter($cities, fn($c) =>
    in_array($c['fcode'] ?? '', $default_fcodes) && ($c['population'] ?? 0) >= $min_pop
));

// Filter: regions
if ($regions) {
    $cities = array_values(array_filter($cities, fn($c) =>
        in_array($c['adminCode1'] ?? '', $regions)
    ));
}

// Filter: compass direction
$cities = array_values(array_filter($cities, function ($c) use ($center_lat, $center_lng, $dir_from, $dir_to) {
    $b = calculate_bearing($center_lat, $center_lng, (float) $c['lat'], (float) $c['lng']);
    return is_in_range($b, $dir_from, $dir_to);
}));

// Sort by name
usort($cities, fn($a, $b) => strcmp(
    $a['toponymName'] ?? $a['name'] ?? '',
    $b['toponymName'] ?? $b['name'] ?? ''
));

// Fetch postal codes — cache-first, parallel API for misses
$db       = open_cache();
$cached   = [];
$to_fetch = [];

foreach ($cities as $key => $city) {
    $hit = cache_get($db, (float) $city['lat'], (float) $city['lng']);
    if ($hit !== false) {
        $cached[$key] = $hit;
    } else {
        $to_fetch[$key] = ['lat' => $city['lat'], 'lng' => $city['lng'], 'maxRows' => 1];
    }
}

$fetched = [];
if ($to_fetch) {
    $responses = geonames_get_multi('findNearbyPostalCodesJSON', $to_fetch, ['username' => $username]);
    foreach ($to_fetch as $key => $_) {
        $postal      = $responses[$key]['postalCodes'][0]['postalCode'] ?? null;
        $fetched[$key] = $postal;
        cache_set($db, (float) $cities[$key]['lat'], (float) $cities[$key]['lng'], $postal);
    }
}

$result_cities = [];
$all_postal    = [];

foreach ($cities as $key => $city) {
    $name   = $city['toponymName'] ?? $city['name'] ?? '';
    $postal = array_key_exists($key, $cached) ? $cached[$key] : ($fetched[$key] ?? null);
    $result_cities[] = [
        'name'   => $name,
        'lat'    => (float) $city['lat'],
        'lng'    => (float) $city['lng'],
        'postal' => $postal,
    ];
    if ($postal) $all_postal[] = $postal;
}

echo json_encode([
    'center'      => ['lat' => $center_lat, 'lng' => $center_lng],
    'cities'      => $result_cities,
    'postalCodes' => array_values(array_unique($all_postal)),
], JSON_UNESCAPED_UNICODE);
