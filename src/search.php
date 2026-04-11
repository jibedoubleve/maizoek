<?php
header('Content-Type: application/json; charset=utf-8');

define('GEONAMES_BASE', 'https://secure.geonames.org');

const DIRECTIONS = [
    'North' => 0, 'NorthEast' => 45, 'East' => 90, 'SouthEast' => 135,
    'South' => 180, 'SouthWest' => 225, 'West' => 270, 'NorthWest' => 315,
];

// ── HTTP helper ───────────────────────────────────────────────
function geonames_get(string $endpoint, array $params): ?array {
    $url = GEONAMES_BASE . '/' . $endpoint . '?' . http_build_query($params);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    return $response ? json_decode($response, true) : null;
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

function fetch_postal_code(float $lat, float $lng, string $username): ?string {
    $data = geonames_get('findNearbyPostalCodesJSON', [
        'lat' => $lat, 'lng' => $lng, 'maxRows' => 1, 'username' => $username,
    ]);
    return $data['postalCodes'][0]['postalCode'] ?? null;
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
$country  = $input['country']             ?? 'BE';
$regions  = $input['regions']             ?? [];
$min_pop  = (int) ($input['min_population'] ?? 5000);
$dir_from = $input['dir_from']            ?? 'North';
$dir_to   = $input['dir_to']              ?? 'North';

if (!$address) {
    http_response_code(400);
    echo json_encode(['error' => 'Adresse manquante']);
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

// Fetch postal codes
$result_cities = [];
$all_postal    = [];

foreach ($cities as $city) {
    $name   = $city['toponymName'] ?? $city['name'] ?? '';
    $postal = fetch_postal_code((float) $city['lat'], (float) $city['lng'], $username);
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
