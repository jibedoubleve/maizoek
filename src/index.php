<?php
require_once __DIR__ . '/lib/constants.php';
// ── Infra config ──────────────────────────────────────────────
$infra = file_exists(__DIR__ . '/config/infra.json')
    ? json_decode(file_get_contents(__DIR__ . '/config/infra.json'), true)
    : [];

// ── Config & translations ─────────────────────────────────────
$config = file_exists(__DIR__ . '/config/query_params.json')
    ? json_decode(file_get_contents(__DIR__ . '/config/query_params.json'), true)
    : [];

if (!empty($_COOKIE['user_config'])) {
    $cookie_config = json_decode($_COOKIE['user_config'], true);
    if (is_array($cookie_config)) {
        $config = array_merge($config, $cookie_config);
    }
}

$all_t   = file_exists(__DIR__ . '/config/translations.json')
    ? json_decode(file_get_contents(__DIR__ . '/config/translations.json'), true)
    : [];
$allowed_langs = ['fr', 'en', 'nl'];
$lang = in_array($_GET['lang'] ?? '', $allowed_langs)
    ? $_GET['lang']
    : ($all_t['selected_language'] ?? 'fr');
$t       = $all_t[$lang] ?? $all_t['fr'] ?? [];
$immoweb = $config['immoweb'] ?? [];

$versionFile = __DIR__ . '/config/version.json';
$versionInfo = file_exists($versionFile)
    ? json_decode(file_get_contents($versionFile), true)
    : ['version' => 'dev', 'date' => ''];

$directions   = ['North','NorthEast','East','SouthEast','South','SouthWest','West','NorthWest'];
$all_subtypes = ['HOUSE','VILLA','MANSION','MANOR_HOUSE','CHALET','FARMHOUSE','EXCEPTIONAL_PROPERTY','TOWN_HOUSE','CASTLE','BUNGALOW','COUNTRY_COTTAGE','PAVILION'];
$epc_scale_order = ['A++' => 0, 'A+' => 1, 'A' => 2, 'B' => 3, 'C' => 4, 'D' => 5, 'E' => 6, 'F' => 7, 'G' => 8];
if (isset($immoweb['epc_min']) && isset($immoweb['epc_max'])) {
    $epc_min = (int)$immoweb['epc_min'];
    $epc_max = (int)$immoweb['epc_max'];
} elseif (!empty($immoweb['epc_scores'])) {
    $indices = array_values(array_filter(array_map(fn($s) => $epc_scale_order[$s] ?? null, $immoweb['epc_scores']), fn($v) => $v !== null));
    $epc_min = $indices ? min($indices) : 0;
    $epc_max = $indices ? max($indices) : 8;
} else {
    $epc_min = 0;
    $epc_max = 8;
}

function h($s)          { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function sel($a, $b)    { return $a === $b ? ' selected' : ''; }
function chk($cond)     { return $cond ? ' checked' : ''; }
function subtypeLabel(string $st, array $t): string {
    return $t[$st] ?? ucwords(strtolower(str_replace('_', ' ', $st)));
}
?>
<!DOCTYPE html>
<html lang="<?= h($lang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($t['title'] ?? 'Recherche Immobilière') ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="assets/app.css">
    <?php
    $client_config = $config;
    unset($client_config['fcodes']);
    ?>
    <script>const DEFAULT_CONFIG = <?= json_encode($client_config, JSON_UNESCAPED_UNICODE) ?>;
    const DIRECTIONS = <?= json_encode(DIRECTIONS) ?>;
    const TRANSLATIONS = <?= json_encode($t, JSON_UNESCAPED_UNICODE) ?>;
    const TREVI_LOCALITIES = <?= json_encode(json_decode(file_get_contents(__DIR__ . '/config/trevi_localities.json'), true), JSON_UNESCAPED_UNICODE) ?>;</script>
    <?php if (!empty($infra['goatcounter_url'])): ?>
    <script data-goatcounter="<?= h($infra['goatcounter_url']) ?>/count" async src="//gc.zgo.at/count.js"></script>
    <?php endif; ?>
</head>
<body>

<!-- ── Sticky action bar ─────────────────────────────────────── -->
<div class="action-bar">
    <div class="action-bar-brand">
        <span class="brand-title"><?= h($t['header_title'] ?? 'Recherche immobilière multi-zones') ?></span>
        <span class="brand-subtitle">Immoweb &middot; Trevi &middot; Immovlan</span>
    </div>
    <span id="search-status" hidden></span>
    <nav class="lang-switcher">
        <?php foreach ($allowed_langs as $l): ?>
        <a href="?lang=<?= h($l) ?>" class="lang-btn<?= $l === $lang ? ' active' : '' ?>"><?= strtoupper($l) ?></a>
        <?php endforeach; ?>
    </nav>
</div>

<!-- ── Main ──────────────────────────────────────────────────── -->
<div class="main">

<!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn active" data-tab="params"><?= h($t['search_params'] ?? 'Paramètres') ?></button>
        <button class="tab-btn" data-tab="results" id="tab-btn-results" hidden><?= h($t['results_title'] ?? 'Résultats') ?></button>
    </div>

    <!-- ── Tab: Paramètres ────────────────────────────────────── -->
    <div class="tab-panel" id="tab-params">
        <div class="params-grid">

            <!-- Zone de recherche -->
            <div class="params-card">
                <p class="params-card-title"><?= h($t['location_params'] ?? 'Zone de recherche') ?></p>
                <div class="section-compass">
                    <div>
                        <div class="param-row">
                            <span class="param-label"><?= h($t['center'] ?? 'Centre') ?></span>
                            <div class="param-value">
                                <input class="form-input" type="text" id="f-address"
                                    value="<?= h($config['address'] ?? '') ?>" placeholder="Ex: Liège">
                            </div>
                        </div>
                        <div class="param-row">
                            <span class="param-label"><?= h($t['radius'] ?? 'Rayon') ?></span>
                            <div class="param-value" style="display:flex;align-items:center;gap:6px;justify-content:flex-end">
                                <input class="form-input form-input-sm" type="number" id="f-radius"
                                    value="<?= h($config['radius'] ?? 30) ?>" min="1" max="200">
                                <span style="font-size:0.82em;color:var(--color-text-muted)">km</span>
                            </div>
                        </div>
                        <div class="param-row">
                            <span class="param-label"><?= h($t['regions'] ?? 'Régions') ?></span>
                            <div class="param-value" style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end">
                                <?php foreach (['WAL','BRU','VLG'] as $region): ?>
                                <label class="filter-chip">
                                    <input type="checkbox" name="region" value="<?= h($region) ?>"
                                        <?= chk(in_array($region, $config['regions'] ?? [])) ?>>
                                    <?= h($region) ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="param-row">
                            <span class="param-label"><?= h($t['dir_from'] ?? 'Direction de') ?></span>
                            <div class="param-value">
                                <select class="form-input" id="f-dir-from">
                                    <?php foreach ($directions as $d): ?>
                                    <option value="<?= h($d) ?>"<?= sel($d, $config['dir_from'] ?? 'North') ?>><?= h($d) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="param-row">
                            <span class="param-label"><?= h($t['dir_to'] ?? 'Direction à') ?></span>
                            <div class="param-value">
                                <select class="form-input" id="f-dir-to">
                                    <?php foreach ($directions as $d): ?>
                                    <option value="<?= h($d) ?>"<?= sel($d, $config['dir_to'] ?? 'North') ?>><?= h($d) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="param-row" id="f-population-row"<?= ($config['ignore_population'] ?? false) ? ' hidden' : '' ?>>
                            <span class="param-label"><?= h($t['min_population'] ?? 'Population min.') ?></span>
                            <div class="param-value">
                                <input class="form-input" type="number" id="f-min-population"
                                    value="<?= h($config['min_population'] ?? 5000) ?>" min="0" step="1000">
                            </div>
                        </div>
                        <div class="param-row">
                            <span class="param-label"><?= h($t['no_population_limit'] ?? 'Tous les lieux') ?></span>
                            <div class="param-value">
                                <input type="checkbox" id="f-ignore-population"
                                    <?= chk($config['ignore_population'] ?? false) ?>>
                            </div>
                        </div>
                    </div>
                    <canvas id="compass" width="100" height="100" style="flex-shrink:0"></canvas>
                </div>
            </div>

            <!-- Critères de recherche -->
            <div class="params-card" id="filter-section">
                <p class="params-card-title"><?= h($t['search_params'] ?? 'Critères') ?></p>

                <div class="param-row">
                    <span class="param-label">Transaction</span>
                    <div class="param-value">
                        <select class="form-input" id="f-transaction">
                            <option value="for-sale"<?= sel('for-sale', $immoweb['transaction'] ?? 'for-sale') ?>><?= h($t['for-sale'] ?? 'À vendre') ?></option>
                            <option value="for-rent"<?= sel('for-rent', $immoweb['transaction'] ?? '') ?>><?= h($t['for-rent'] ?? 'À louer') ?></option>
                        </select>
                    </div>
                </div>
                <div class="param-row">
                    <span class="param-label"><?= h($t['property_type'] ?? 'Type') ?></span>
                    <div class="param-value">
                        <select class="form-input" id="f-property-type">
                            <option value="house"<?= sel('house', $immoweb['property_type'] ?? 'house') ?>><?= h($t['house'] ?? 'Maison') ?></option>
                            <option value="apartment"<?= sel('apartment', $immoweb['property_type'] ?? '') ?>><?= h($t['apartment'] ?? 'Appartement') ?></option>
                        </select>
                    </div>
                </div>
                <div class="param-row">
                    <span class="param-label"><?= h($t['price'] ?? 'Prix') ?></span>
                    <div class="param-value">
                        <div class="filter-row" style="justify-content:flex-end">
                            <input class="form-input form-input-sm" type="number" id="f-min-price"
                                placeholder="<?= h($t['min'] ?? 'min') ?>" min="0" step="10000"
                                value="<?= h($immoweb['min_price'] ?? '') ?>">
                            <span class="filter-sep">→</span>
                            <input class="form-input form-input-sm" type="number" id="f-max-price"
                                placeholder="<?= h($t['max'] ?? 'max') ?>" min="0" step="10000"
                                value="<?= h($immoweb['max_price'] ?? '') ?>">
                            <span class="filter-unit">€</span>
                        </div>
                    </div>
                </div>
                <div class="param-row">
                    <span class="param-label"><?= h($t['bedrooms'] ?? 'Chambres') ?> <span class="provider-badge" data-tooltip="<?= h($t['badge_tooltip_immoweb_immovlan'] ?? 'Non disponible sur Trevi') ?>">Immoweb · ImmoVlan</span></span>
                    <div class="param-value">
                        <div class="filter-row" style="justify-content:flex-end">
                            <input class="form-input form-input-sm" type="number" id="f-min-bedrooms"
                                placeholder="<?= h($t['min'] ?? 'min') ?>" min="1"
                                value="<?= h($immoweb['min_bedrooms'] ?? '') ?>">
                            <span class="filter-sep">→</span>
                            <input class="form-input form-input-sm" type="number" id="f-max-bedrooms"
                                placeholder="<?= h($t['max'] ?? 'max') ?>" min="1"
                                value="<?= h($immoweb['max_bedrooms'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <span class="filter-label" style="margin-top:8px"><?= h($t['property_type'] ?? 'Sous-types') ?> <span class="provider-badge" data-tooltip="<?= h($t['badge_tooltip_immoweb_immovlan'] ?? 'Non disponible sur Trevi') ?>">Immoweb · ImmoVlan</span></span>
                <div class="filter-chips">
                    <?php foreach ($all_subtypes as $st): ?>
                    <label class="filter-chip">
                        <input type="checkbox" name="subtype" value="<?= h($st) ?>"
                            <?= chk(!($immoweb['property_subtypes'] ?? []) || in_array($st, $immoweb['property_subtypes'] ?? [])) ?>>
                        <?= h(subtypeLabel($st, $t)) ?>
                    </label>
                    <?php endforeach; ?>
                </div>

                <span class="filter-label"><?= h($t['epc'] ?? 'PEB') ?> <span class="provider-badge" data-tooltip="<?= h($t['badge_tooltip_epc'] ?? 'Groupes Wallonie sur ImmoVlan') ?>">Immoweb · ImmoVlan</span></span>
                <div class="epc-slider-wrap">
                    <div class="epc-track-container">
                        <div class="epc-track-bg"></div>
                        <div class="epc-inactive" id="epc-inactive-left"></div>
                        <div class="epc-inactive" id="epc-inactive-right"></div>
                        <input type="range" class="epc-range" id="f-epc-min"
                               min="0" max="8" value="<?= h($epc_min) ?>">
                        <input type="range" class="epc-range" id="f-epc-max"
                               min="0" max="8" value="<?= h($epc_max) ?>">
                    </div>
                    <div class="epc-scale-labels">
                        <span>A++</span><span>A+</span><span>A</span><span>B</span>
                        <span>C</span><span>D</span><span>E</span><span>F</span><span>G</span>
                    </div>
                    <div class="epc-groups">
                        <span class="epc-group" id="epc-group-excellent"><?= h($t['epc_excellent'] ?? 'Excellent') ?></span>
                        <span class="epc-group" id="epc-group-good"><?= h($t['epc_good'] ?? 'Bon') ?></span>
                        <span class="epc-group" id="epc-group-poor"><?= h($t['epc_poor'] ?? 'Moyen') ?></span>
                        <span class="epc-group" id="epc-group-bad"><?= h($t['epc_bad'] ?? 'Mauvais') ?></span>
                    </div>
                    <div class="epc-sel-label" id="epc-sel-label"></div>
                </div>

                <div class="param-row">
                    <span class="param-label"><?= h($t['include_under_option'] ?? 'Inclure biens sous option') ?> <span class="provider-badge" data-tooltip="<?= h($t['badge_tooltip_immoweb_only'] ?? 'Non disponible sur Trevi et ImmoVlan') ?>">Immoweb</span></span>
                    <div class="param-value">
                        <input type="checkbox" id="f-include-under-option"
                            <?= chk($immoweb['include_under_option'] ?? false) ?>>
                    </div>
                </div>

                <div class="filter-footer">
                    <button type="button" id="f-reset" class="filter-reset">
                        <?= h($t['reset_filters'] ?? 'Réinitialiser') ?>
                    </button>
                </div>
            </div>

        </div>

        <div class="params-generate">
            <p class="search-hint"><?= h($t['search_hint'] ?? 'Configurez votre zone et vos critères, puis lancez la recherche.') ?></p>
            <button id="btn-search" class="btn-search-primary">
                <span id="search-spinner" class="spinner" hidden></span>
                <span id="search-label"><?= h($t['generate_links'] ?? 'Voir les annonces') ?></span>
            </button>
            <p id="search-progress" class="search-progress" hidden></p>
        </div>
    </div>

    <!-- ── Tab: Résultats ────────────────────────────────────── -->
    <div class="tab-panel" id="tab-results" hidden>
        <div class="results-links">
            <a id="btn-immoweb" href="#" target="_blank" class="result-link result-link-immoweb">
                <span class="btn-logo">iw</span><?= h($t['search_button_immoweb'] ?? 'Rechercher sur Immoweb') ?>
            </a>
            <a id="btn-trevi" href="#" target="_blank" class="result-link result-link-trevi">
                <span class="btn-logo">tr</span><?= h($t['search_button_trevi'] ?? 'Rechercher sur Trevi') ?>
            </a>
            <a id="btn-immovlan" href="#" target="_blank" class="result-link result-link-immovlan">
                <span class="btn-logo">iv</span><?= h($t['search_button_immovlan'] ?? 'Rechercher sur Immovlan') ?>
            </a>
        </div>
        <div class="map-header">
            <span id="map-title" class="map-title"></span>
            <span class="map-legend">
                <span class="map-legend-dot"></span>
                <?= h($t['map_legend_label'] ?? 'Commune incluse dans la recherche') ?>
            </span>
        </div>
        <div id="map"></div>

        <button id="btn-toggle-cities" class="cities-toggle" hidden>
            <span id="cities-toggle-label"></span>
            <span class="cities-toggle-chevron">›</span>
        </button>
        <div id="cities-panel" class="cities-panel" hidden>
            <div class="cities-filter-wrap">
                <span class="cities-filter-icon">&#128269;</span>
                <input id="cities-filter" class="cities-filter-input" type="text"
                    placeholder="<?= h($t['filter_cities'] ?? 'Filtrer les communes…') ?>">
            </div>
            <ul id="cities-list" class="city-list">
                <li class="empty-state"><?= h($t['empty_cities'] ?? 'Lance une recherche pour voir les villes.') ?></li>
            </ul>
        </div>
    </div>

</div>

<footer class="footer">
    <a class="footer-repo" href="https://github.com/jibedoubleve/maizoek" target="_blank" rel="noopener">
        <svg height="16" viewBox="0 0 16 16" width="16" fill="currentColor" aria-hidden="true">
            <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38
            0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13
            -.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66
            .07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15
            -.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27
            .68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12
            .51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48
            0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/>
        </svg>
      View on Github
    </a>
    <span class="footer-meta">
        <?= h($versionInfo['version']) ?> &middot; <?= h($versionInfo['date']) ?>
    </span>
</footer>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
