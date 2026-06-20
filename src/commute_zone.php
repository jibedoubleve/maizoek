<?php
$infra = file_exists(__DIR__ . '/config/infra.json')
    ? json_decode(file_get_contents(__DIR__ . '/config/infra.json'), true)
    : [];

$all_t         = file_exists(__DIR__ . '/config/translations.json')
    ? json_decode(file_get_contents(__DIR__ . '/config/translations.json'), true)
    : [];
$allowed_langs = ['fr', 'en', 'nl'];
$lang          = in_array($_GET['lang'] ?? '', $allowed_langs)
    ? $_GET['lang']
    : ($infra['selected_language'] ?? 'fr');
$t             = $all_t[$lang] ?? $all_t['fr'] ?? [];

function h($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

$title = $t['cz_page_title'] ?? 'Zone de trajet';
require __DIR__ . '/lib/_head.php';
?>
    <script>const TRANSLATIONS = <?= json_encode($t, JSON_UNESCAPED_UNICODE) ?>;</script>
    <?php if (!empty($infra['goatcounter_url'])): ?>
    <script data-goatcounter="<?= h($infra['goatcounter_url']) ?>/count" async src="//gc.zgo.at/count.js"></script>
    <?php endif; ?>
</head>
<body>

<div class="action-bar">
    <div class="action-bar-brand">
        <span class="brand-title"><?= h($t['cz_page_title'] ?? 'Zone de trajet') ?></span>
        <span class="brand-subtitle">
            <a href="/" class="back-link"><?= h($t['cz_back_to_search'] ?? '← Retour') ?></a>
        </span>
    </div>
    <nav class="lang-switcher">
        <?php foreach ($allowed_langs as $l): ?>
        <a href="?lang=<?= h($l) ?>" class="lang-btn<?= $l === $lang ? ' active' : '' ?>"><?= strtoupper($l) ?></a>
        <?php endforeach; ?>
    </nav>
</div>

<div class="main">
    <div class="params-card">
        <p class="params-card-title"><?= h($t['cz_page_title'] ?? 'Zone de trajet') ?></p>
        <p class="search-hint"><?= h($t['cz_subtitle'] ?? '') ?></p>

        <div class="param-row">
            <span class="param-label"><?= h($t['cz_address1'] ?? 'Adresse 1') ?></span>
            <div class="param-value cz-address-row">
                <input class="form-input" type="text" id="cz-address1"
                    placeholder="<?= h($t['cz_address_placeholder'] ?? '') ?>">
                <input class="form-input form-input-sm" type="number" id="cz-max-time-1"
                    value="30" min="5" max="60">
                <span class="filter-unit">min</span>
            </div>
        </div>
        <div class="param-row">
            <span class="param-label"><?= h($t['cz_address2'] ?? 'Adresse 2') ?></span>
            <div class="param-value cz-address-row">
                <input class="form-input" type="text" id="cz-address2"
                    placeholder="<?= h($t['cz_address_placeholder'] ?? '') ?>">
                <input class="form-input form-input-sm" type="number" id="cz-max-time-2"
                    value="30" min="5" max="60">
                <span class="filter-unit">min</span>
            </div>
        </div>
        <div class="param-row">
            <span class="param-label"><?= h($t['cz_departure_time'] ?? 'Heure de départ') ?></span>
            <div class="param-value">
                <input class="form-input" type="time" id="cz-departure" value="08:00">
            </div>
        </div>

        <div class="cz-warning"><?= h($t['cz_traffic_warning'] ?? '') ?></div>
        <div class="cz-adjusted" id="cz-adjusted-time-1"></div>
        <div class="cz-adjusted" id="cz-adjusted-time-2"></div>

        <div class="params-generate">
            <button id="cz-btn" class="btn-search-primary">
                <?= h($t['cz_calculate'] ?? 'Calculer la zone') ?>
            </button>
        </div>

        <div id="cz-error" class="cz-error" hidden></div>
    </div>

    <div class="map-header" style="margin-top:20px">
        <div class="cz-legend">
            <span class="cz-legend-item cz-legend-blue"><?= h($t['cz_zone1_label'] ?? 'Zone adresse 1') ?></span>
            <span class="cz-legend-item cz-legend-red"><?= h($t['cz_zone2_label'] ?? 'Zone adresse 2') ?></span>
            <span class="cz-legend-item cz-legend-green"><?= h($t['cz_intersection_label'] ?? 'Zone commune') ?></span>
        </div>
    </div>
    <div id="cz-map"></div>
</div>

<footer class="footer">
    <a class="footer-repo" href="https://github.com/jibedoubleve/immo-zone-search" target="_blank" rel="noopener">
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
</footer>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/@turf/turf@6/turf.min.js"></script>
<script src="assets/commute_zone.js"></script>
</body>
</html>
