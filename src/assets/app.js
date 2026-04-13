'use strict';

// ── Mappings ──────────────────────────────────────────────────
// DIRECTIONS is injected server-side in index.php from constants.php
const TREVI_TRANSACTION = { 'for-sale': 0, 'for-rent': 1 };
const TREVI_PATH        = { house: 'maisons', apartment: 'appartements' };
const TREVI_CAT         = { house: 1, apartment: 2 };
const IMMOVLAN_TRANSACTION = { 'for-sale': 'a-vendre,en-vente-publique', 'for-rent': 'a-louer' };
const IMMOVLAN_TYPE        = { house: 'maison', apartment: 'appartement' };
const IMMOVLAN_SUBTYPES    = {
    HOUSE: ['maison'], VILLA: ['villa'], MANSION: ['maison-de-maitre'],
    MANOR_HOUSE: ['maison-de-maitre', 'fermette'], CHALET: ['chalet'],
    CASTLE: ['chateau'], BUNGALOW: ['bungalow'],
};

// ── State ─────────────────────────────────────────────────────
let searchResults = null;
let leafletMap    = null;
let markersLayer  = null;

// ── Helpers ───────────────────────────────────────────────────
function enc(v) {
    return encodeURIComponent(String(v)).replace(/%2C/g, ',').replace(/%2B/g, '+');
}
function qs(pairs) {
    return pairs
        .filter(([, v]) => v != null && v !== '')
        .map(([k, v]) => `${encodeURIComponent(k)}=${enc(v)}`)
        .join('&');
}
function val(id)    { return document.getElementById(id)?.value ?? ''; }
function checks(n)  { return [...document.querySelectorAll(`input[name="${n}"]:checked`)].map(e => e.value); }
function intVal(id) { return parseInt(val(id)) || null; }

// ── Form state ────────────────────────────────────────────────
function getSearchParams() {
    return {
        address:           val('f-address'),
        radius:            parseInt(val('f-radius')) || 30,
        country:           val('f-country'),
        regions:           checks('region'),
        dir_from:          val('f-dir-from'),
        dir_to:            val('f-dir-to'),
        min_population:    parseInt(val('f-min-population')) || 5000,
        ignore_population: document.getElementById('f-ignore-population')?.checked ?? false,
    };
}

function getFilterState() {
    return {
        propertyType: val('f-property-type') || 'house',
        transaction:  val('f-transaction')   || 'for-sale',
        minPrice:     intVal('f-min-price'),
        maxPrice:     intVal('f-max-price'),
        minBedrooms:  intVal('f-min-bedrooms'),
        maxBedrooms:  intVal('f-max-bedrooms'),
        subtypes:     checks('subtype'),
        epcScores:    checks('epc'),
    };
}

// ── URL builders ──────────────────────────────────────────────
function buildImmowebCombined(s, postalCodes) {
    const base  = `https://www.immoweb.be/en/search/${s.propertyType}/${s.transaction}`;
    const pairs = [['countries', 'BE'], ['postalCodes', postalCodes.join(',')]];
    if (s.minPrice)        pairs.push(['minPrice', s.minPrice]);
    if (s.maxPrice)        pairs.push(['maxPrice', s.maxPrice]);
    if (s.minBedrooms)     pairs.push(['minBedroomCount', s.minBedrooms]);
    if (s.maxBedrooms)     pairs.push(['maxBedroomCount', s.maxBedrooms]);
    if (s.subtypes.length) pairs.push(['propertySubtypes', s.subtypes.join(',')]);
    if (s.epcScores.length) pairs.push(['epcScores', s.epcScores.join(',')]);
    return `${base}?${qs(pairs)}`;
}

function buildTreviCombined(s, cities) {
    const path  = TREVI_PATH[s.propertyType] || 'maisons';
    const parts = [
        `purpose=${TREVI_TRANSACTION[s.transaction] ?? 0}`,
        `estatecategory=${TREVI_CAT[s.propertyType] ?? 1}`,
    ];
    cities.forEach(c => {
        if (c.postal) parts.push(`zips%5B%5D=${encodeURIComponent(c.postal + '_' + c.name.toUpperCase())}`);
    });
    if (s.minPrice) parts.push(`minprice=${s.minPrice}`);
    if (s.maxPrice) parts.push(`maxprice=${s.maxPrice}`);
    return `https://www.trevi.be/fr/acheter-bien-immobilier/${path}?${parts.join('&')}`;
}

function immovlanSubtypes(subtypes) {
    return [...new Set(subtypes.flatMap(st => IMMOVLAN_SUBTYPES[st] || []))];
}

function buildImmovlanCombined(s, cities) {
    const ivSt  = immovlanSubtypes(s.subtypes);
    const towns = cities.filter(c => c.postal).map(c => `${c.postal}-${c.name.toLowerCase().replace(/ /g, '-')}`);
    const pairs = [
        ['transactiontypes', IMMOVLAN_TRANSACTION[s.transaction] || 'a-vendre,en-vente-publique'],
        ['propertytypes',    IMMOVLAN_TYPE[s.propertyType] || 'maison'],
    ];
    if (ivSt.length)   pairs.push(['propertysubtypes', ivSt.join(',')]);
    if (towns.length)  pairs.push(['towns', towns.join(',')]);
    if (s.minPrice)    pairs.push(['minprice', s.minPrice]);
    if (s.maxPrice)    pairs.push(['maxprice', s.maxPrice]);
    if (s.minBedrooms) pairs.push(['minbedrooms', s.minBedrooms]);
    if (s.maxBedrooms) pairs.push(['maxbedrooms', s.maxBedrooms]);
    return `https://immovlan.be/fr/immobilier?${qs(pairs)}`;
}

function buildImmowebCity(name, postal, s) {
    const base  = `https://www.immoweb.be/en/search/${s.propertyType}/${s.transaction}`;
    const pairs = [['countries', 'BE']];
    if (postal) pairs.push(['postalCodes', postal]);
    if (s.minPrice)        pairs.push(['minPrice', s.minPrice]);
    if (s.maxPrice)        pairs.push(['maxPrice', s.maxPrice]);
    if (s.minBedrooms)     pairs.push(['minBedroomCount', s.minBedrooms]);
    if (s.maxBedrooms)     pairs.push(['maxBedroomCount', s.maxBedrooms]);
    if (s.subtypes.length) pairs.push(['propertySubtypes', s.subtypes.join(',')]);
    if (s.epcScores.length) pairs.push(['epcScores', s.epcScores.join(',')]);
    return `${base}?${qs(pairs)}`;
}

function buildTreviCity(name, postal, s) {
    const path  = TREVI_PATH[s.propertyType] || 'maisons';
    const parts = [
        `purpose=${TREVI_TRANSACTION[s.transaction] ?? 0}`,
        `estatecategory=${TREVI_CAT[s.propertyType] ?? 1}`,
        `zips%5B%5D=${encodeURIComponent(postal + '_' + name.toUpperCase())}`,
    ];
    if (s.minPrice) parts.push(`minprice=${s.minPrice}`);
    if (s.maxPrice) parts.push(`maxprice=${s.maxPrice}`);
    return `https://www.trevi.be/fr/acheter-bien-immobilier/${path}?${parts.join('&')}`;
}

function buildImmovlanCity(name, postal, s) {
    const ivSt  = immovlanSubtypes(s.subtypes);
    const pairs = [
        ['transactiontypes', IMMOVLAN_TRANSACTION[s.transaction] || 'a-vendre,en-vente-publique'],
        ['propertytypes',    IMMOVLAN_TYPE[s.propertyType] || 'maison'],
    ];
    if (ivSt.length) pairs.push(['propertysubtypes', ivSt.join(',')]);
    pairs.push(['towns', `${postal}-${name.toLowerCase().replace(/ /g, '-')}`]);
    if (s.minPrice)    pairs.push(['minprice', s.minPrice]);
    if (s.maxPrice)    pairs.push(['maxprice', s.maxPrice]);
    if (s.minBedrooms) pairs.push(['minbedrooms', s.minBedrooms]);
    if (s.maxBedrooms) pairs.push(['maxbedrooms', s.maxBedrooms]);
    return `https://immovlan.be/fr/immobilier?${qs(pairs)}`;
}

// ── Map ───────────────────────────────────────────────────────
function initMap(center) {
    if (leafletMap) { leafletMap.remove(); leafletMap = null; }
    leafletMap = L.map('map').setView([center.lat, center.lng], 9);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(leafletMap);
    markersLayer = L.layerGroup().addTo(leafletMap);
}

function updateMap(data) {
    if (!leafletMap) initMap(data.center);
    markersLayer.clearLayers();
    const bounds = [];
    data.cities.forEach(city => {
        L.marker([city.lat, city.lng])
            .bindTooltip(`${city.name}${city.postal ? ' (' + city.postal + ')' : ''}`)
            .addTo(markersLayer);
        bounds.push([city.lat, city.lng]);
    });
    if (bounds.length) leafletMap.fitBounds(bounds, { padding: [30, 30], maxZoom: 11 });
}

// ── Compass ───────────────────────────────────────────────────
function drawCompass() {
    const canvas = document.getElementById('compass');
    if (!canvas) return;
    const ctx     = canvas.getContext('2d');
    const cx = 50, cy = 50, r = 36, labelR = 46;
    const LABELS  = { North:'N', NorthEast:'NE', East:'E', SouthEast:'SE', South:'S', SouthWest:'SW', West:'W', NorthWest:'NW' };

    function toRad(b) { return (b - 90) * Math.PI / 180; }

    const dirFrom = val('f-dir-from') || 'North';
    const dirTo   = val('f-dir-to')   || 'North';
    const fromDeg = DIRECTIONS[dirFrom] ?? 0;
    const toDeg   = DIRECTIONS[dirTo]   ?? 0;
    let spanDeg   = (toDeg - fromDeg + 360) % 360;
    if (spanDeg === 0) spanDeg = 360;

    ctx.clearRect(0, 0, 100, 100);

    // Background
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, 2 * Math.PI);
    ctx.fillStyle = '#f0f2f5';
    ctx.fill();

    // Sector
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.arc(cx, cy, r, toRad(fromDeg), toRad(fromDeg + spanDeg));
    ctx.closePath();
    ctx.fillStyle   = 'rgba(0,63,127,0.18)';
    ctx.fill();
    ctx.strokeStyle = '#003f7f';
    ctx.lineWidth   = 1.5;
    ctx.stroke();

    // Border
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, 2 * Math.PI);
    ctx.strokeStyle = '#e4e7eb';
    ctx.lineWidth   = 1;
    ctx.stroke();

    // Labels
    ctx.font          = 'bold 9px sans-serif';
    ctx.textAlign     = 'center';
    ctx.textBaseline  = 'middle';
    for (const [name, deg] of Object.entries(DIRECTIONS)) {
        const rad  = toRad(deg);
        const isEdge = name === dirFrom || name === dirTo;
        ctx.fillStyle = isEdge ? '#003f7f' : '#6b7280';
        ctx.fillText(LABELS[name], cx + labelR * Math.cos(rad), cy + labelR * Math.sin(rad));
    }

    // Center dot
    ctx.beginPath();
    ctx.arc(cx, cy, 3, 0, 2 * Math.PI);
    ctx.fillStyle = '#003f7f';
    ctx.fill();
}

// ── Cities list ───────────────────────────────────────────────
function renderCitiesList(cities, s) {
    const list  = document.getElementById('cities-list');
    const count = document.getElementById('city-count');
    if (!list) return;
    if (count) count.textContent = cities.length;
    list.innerHTML = '';

    cities.forEach(city => {
        const li = document.createElement('li');
        li.className       = 'city-item';
        li.dataset.name    = city.name;
        li.dataset.postal  = city.postal || '?';

        const nameSpan = document.createElement('span');
        nameSpan.className   = 'city-name';
        nameSpan.textContent = city.name;

        const linksDiv = document.createElement('div');
        linksDiv.className = 'city-links';
        if (city.postal) {
            const defs = [
                { url: buildImmowebCity(city.name, city.postal, s), css: 'city-link-immoweb', label: 'Immoweb' },
                ...(s.transaction !== 'for-rent' ? [{ url: buildTreviCity(city.name, city.postal, s), css: 'city-link-trevi', label: 'Trevi' }] : []),
                { url: buildImmovlanCity(city.name, city.postal, s), css: 'city-link-immovlan', label: 'Immovlan' },
            ];
            defs.forEach(({ url, css, label }) => {
                const a = document.createElement('a');
                a.href        = url;
                a.target      = '_blank';
                a.className   = `city-link ${css}`;
                a.textContent = label;
                linksDiv.appendChild(a);
            });
        }

        li.appendChild(nameSpan);
        li.appendChild(linksDiv);
        list.appendChild(li);
    });
}

// ── Update combined URLs + city links ─────────────────────────
function updateUrls() {
    if (!searchResults) return;
    const s = getFilterState();

    const showTrevi = s.transaction !== 'for-rent';

    document.getElementById('btn-immoweb').href  = buildImmowebCombined(s, searchResults.postalCodes);
    document.getElementById('btn-trevi').hidden  = !showTrevi;
    if (showTrevi) document.getElementById('btn-trevi').href = buildTreviCombined(s, searchResults.cities);
    document.getElementById('btn-immovlan').href = buildImmovlanCombined(s, searchResults.cities);

    document.querySelectorAll('.city-item[data-name]').forEach(item => {
        const name   = item.dataset.name;
        const postal = item.dataset.postal;
        if (!postal || postal === '?') return;
        const immoweb  = item.querySelector('.city-link-immoweb');
        const trevi    = item.querySelector('.city-link-trevi');
        const immovlan = item.querySelector('.city-link-immovlan');
        if (immoweb)  immoweb.href  = buildImmowebCity(name, postal, s);
        if (trevi)  { trevi.hidden  = !showTrevi; if (showTrevi) trevi.href = buildTreviCity(name, postal, s); }
        if (immovlan) immovlan.href = buildImmovlanCity(name, postal, s);
    });
}

// ── Tabs ──────────────────────────────────────────────────────
function switchTab(tabId) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tabId));
    document.querySelectorAll('.tab-panel').forEach(p => { p.hidden = p.id !== 'tab-' + tabId; });
    if (tabId === 'results' && leafletMap) setTimeout(() => leafletMap.invalidateSize(), 50);
}

// ── Cookie ────────────────────────────────────────────────────
function saveConfigCookie() {
    const sp     = getSearchParams();
    const fs     = getFilterState();
    const config = {
        address:        sp.address,
        radius:         sp.radius,
        country:        sp.country || 'BE',
        regions:        sp.regions,
        dir_from:       sp.dir_from,
        dir_to:         sp.dir_to,
        min_population:    sp.min_population,
        ignore_population: sp.ignore_population,
        immoweb: {
            transaction:       fs.transaction,
            property_type:     fs.propertyType,
            property_subtypes: fs.subtypes,
            min_price:         fs.minPrice,
            max_price:         fs.maxPrice,
            min_bedrooms:      fs.minBedrooms,
            max_bedrooms:      fs.maxBedrooms,
            epc_scores:        fs.epcScores,
        },
    };
    const expires = new Date();
    expires.setFullYear(expires.getFullYear() + 1);
    document.cookie = `user_config=${encodeURIComponent(JSON.stringify(config))};expires=${expires.toUTCString()};path=/;SameSite=Lax`;
}

// ── Reset ─────────────────────────────────────────────────────
function resetFilters() {
    const cfg = window.DEFAULT_CONFIG?.immoweb || {};
    document.getElementById('f-min-price').value    = cfg.min_price    || '';
    document.getElementById('f-max-price').value    = cfg.max_price    || '';
    document.getElementById('f-min-bedrooms').value = cfg.min_bedrooms || '';
    document.getElementById('f-max-bedrooms').value = cfg.max_bedrooms || '';
    document.querySelectorAll('input[name="subtype"]').forEach(cb => {
        cb.checked = !cfg.property_subtypes?.length || cfg.property_subtypes.includes(cb.value);
    });
    document.querySelectorAll('input[name="epc"]').forEach(cb => {
        cb.checked = cfg.epc_scores?.includes(cb.value) ?? false;
    });
    updateUrls();
}

// ── Search ────────────────────────────────────────────────────
async function executeSearch() {
    const btn     = document.getElementById('btn-search');
    const status  = document.getElementById('search-status');
    const spinner = document.getElementById('search-spinner');
    const label   = document.getElementById('search-label');

    const progress = document.getElementById('search-progress');

    btn.disabled     = true;
    if (spinner)  spinner.hidden = false;
    if (label)    label.textContent = TRANSLATIONS.generating ?? 'Préparation en cours…';
    if (status)   { status.textContent = ''; status.hidden = true; }
    if (progress) { progress.textContent = TRANSLATIONS.search_progress ?? 'Recherche des communes dans votre zone…'; progress.hidden = false; }

    try {
        const res  = await fetch('search.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(getSearchParams()),
        });
        const data = await res.json();

        if (!res.ok || data.error) throw new Error(data.error || 'Erreur serveur');

        searchResults = data;
        saveConfigCookie();
        const s = getFilterState();

        updateMap(data);
        renderCitiesList(data.cities, s);
        updateUrls();

        const mapTitle = document.getElementById('map-title');
        if (mapTitle) mapTitle.textContent = `${data.cities.length} ${TRANSLATIONS.map_cities_label ?? 'communes dans votre zone'}`;

        const toggleBtn   = document.getElementById('btn-toggle-cities');
        const toggleLabel = document.getElementById('cities-toggle-label');
        if (toggleBtn && toggleLabel) {
            const tpl = TRANSLATIONS.view_cities ?? 'Voir les %d communes incluses';
            toggleLabel.textContent = tpl.replace('%d', data.cities.length);
            toggleBtn.hidden = false;
        }

        document.getElementById('tab-btn-results').hidden = false;
        switchTab('results');

    } catch (err) {
        if (status) { status.textContent = '⚠ ' + err.message; status.hidden = false; }
    } finally {
        btn.disabled = false;
        if (spinner)  spinner.hidden = true;
        if (label)    label.textContent = TRANSLATIONS.generate_links ?? 'Voir les annonces';
        if (progress) progress.hidden = true;
    }
}

// ── Init ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('f-address')?.focus();
    document.getElementById('btn-search').addEventListener('click', executeSearch);
    document.getElementById('f-reset')?.addEventListener('click', resetFilters);

    document.getElementById('btn-toggle-cities')?.addEventListener('click', () => {
        const panel   = document.getElementById('cities-panel');
        const chevron = document.querySelector('.cities-toggle-chevron');
        const open    = !panel.hidden;
        panel.hidden  = open;
        if (chevron) chevron.style.transform = open ? '' : 'rotate(90deg)';
    });

    document.getElementById('cities-filter')?.addEventListener('input', e => {
        const q = e.target.value.toLowerCase();
        document.querySelectorAll('#cities-list .city-item').forEach(li => {
            li.hidden = !li.dataset.name?.toLowerCase().includes(q);
        });
    });

    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => switchTab(btn.dataset.tab));
    });

    // Filter inputs → update URLs
    document.querySelectorAll('#filter-section input, #filter-section select').forEach(el => {
        el.addEventListener('change', updateUrls);
        el.addEventListener('input',  updateUrls);
    });

    // Direction dropdowns → redraw compass
    document.getElementById('f-dir-from')?.addEventListener('change', drawCompass);
    document.getElementById('f-dir-to')?.addEventListener('change',   drawCompass);

    // Ignore population checkbox → toggle population field
    document.getElementById('f-ignore-population')?.addEventListener('change', function () {
        document.getElementById('f-population-row').hidden = this.checked;
    });

    drawCompass();
});
