'use strict';

const TRAFFIC_FACTORS = {
     0: 1.00,  1: 1.00,  2: 1.00,  3: 1.00,  4: 1.00,
     5: 1.05,  6: 1.15,  7: 1.35,  8: 1.40,  9: 1.25,
    10: 1.10, 11: 1.05, 12: 1.10, 13: 1.05, 14: 1.05,
    15: 1.15, 16: 1.30, 17: 1.40, 18: 1.35, 19: 1.20,
    20: 1.10, 21: 1.05, 22: 1.00, 23: 1.00,
};

let czMap    = null;
let czLayers = [];

function tr(key, value) {
    const str = (TRANSLATIONS && TRANSLATIONS[key]) ? TRANSLATIONS[key] : key;
    return value !== undefined ? str.replace('%d', value).replace('%s', value) : str;
}

function getIsochroneState() {
    return {
        address1:       document.getElementById('cz-address1').value.trim(),
        address2:       document.getElementById('cz-address2').value.trim(),
        max_minutes1:   parseInt(document.getElementById('cz-max-time-1').value) || 30,
        max_minutes2:   parseInt(document.getElementById('cz-max-time-2').value) || 30,
        departure_time: document.getElementById('cz-departure').value || '08:00',
    };
}

function applyTrafficFactor(minutes, hour) {
    const factor = TRAFFIC_FACTORS[hour] ?? 1.0;
    return Math.round(minutes / factor);
}

function updateAdjustedTime() {
    const state = getIsochroneState();
    const hour  = parseInt(state.departure_time.split(':')[0]);
    const el1   = document.getElementById('cz-adjusted-time-1');
    const el2   = document.getElementById('cz-adjusted-time-2');
    if (el1) el1.textContent = tr('cz_adjusted_time', applyTrafficFactor(state.max_minutes1, hour));
    if (el2) el2.textContent = tr('cz_adjusted_time', applyTrafficFactor(state.max_minutes2, hour));
}

async function fetchIsochrones(state) {
    const resp = await fetch('isochrone_api.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(state),
    });
    const data = await resp.json();
    if (!resp.ok) {
        if (data.error === 'address_not_found') throw new Error(tr('cz_error_address', data.address));
        throw new Error(tr('cz_error_ors'));
    }
    return data;
}

function renderIsochrones(poly1, poly2) {
    czLayers.forEach(l => czMap.removeLayer(l));
    czLayers = [];

    const l1 = L.geoJSON(poly1, {
        style: { color: '#003f7f', fillColor: '#003f7f', fillOpacity: 0.15, weight: 2 },
    }).addTo(czMap);
    const l2 = L.geoJSON(poly2, {
        style: { color: '#c0392b', fillColor: '#c0392b', fillOpacity: 0.15, weight: 2 },
    }).addTo(czMap);
    czLayers = [l1, l2];

    const intersection = turf.intersect(turf.feature(poly1), turf.feature(poly2));
    const errEl = document.getElementById('cz-error');

    if (intersection) {
        const li = L.geoJSON(intersection, {
            style: { color: '#2E7D32', fillColor: '#2E7D32', fillOpacity: 0.45, weight: 2 },
        }).addTo(czMap);
        czLayers.push(li);
        errEl.hidden = true;
        czMap.fitBounds(li.getBounds().pad(0.1));
    } else {
        errEl.textContent = tr('cz_no_intersection');
        errEl.hidden = false;
        czMap.fitBounds(l1.getBounds().extend(l2.getBounds()).pad(0.1));
    }
}

async function onCalculate() {
    const state = getIsochroneState();
    if (!state.address1 || !state.address2) return;

    const btn   = document.getElementById('cz-btn');
    const errEl = document.getElementById('cz-error');
    errEl.hidden    = true;
    btn.disabled    = true;
    btn.textContent = tr('cz_calculating');

    try {
        const data = await fetchIsochrones(state);
        renderIsochrones(data.poly1, data.poly2);
    } catch (e) {
        errEl.textContent = e.message;
        errEl.hidden      = false;
    } finally {
        btn.disabled    = false;
        btn.textContent = tr('cz_calculate');
    }
}

// ── Cookie ────────────────────────────────────────────────────
function saveCzCookie() {
    const expires = new Date();
    expires.setFullYear(expires.getFullYear() + 1);
    document.cookie = `cz_config=${encodeURIComponent(JSON.stringify(getIsochroneState()))};expires=${expires.toUTCString()};path=/;SameSite=Lax`;
}

function loadCzCookie() {
    const match = document.cookie.match(/(?:^|;\s*)cz_config=([^;]*)/);
    if (!match) return;
    try {
        const cfg = JSON.parse(decodeURIComponent(match[1]));
        if (cfg.address1)      document.getElementById('cz-address1').value    = cfg.address1;
        if (cfg.address2)      document.getElementById('cz-address2').value    = cfg.address2;
        if (cfg.max_minutes1)  document.getElementById('cz-max-time-1').value  = cfg.max_minutes1;
        if (cfg.max_minutes2)  document.getElementById('cz-max-time-2').value  = cfg.max_minutes2;
        if (cfg.departure_time) document.getElementById('cz-departure').value  = cfg.departure_time;
    } catch (e) { /* cookie corrompu, ignoré */ }
}

document.addEventListener('DOMContentLoaded', () => {
    czMap = L.map('cz-map').setView([50.5, 4.5], 8);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(czMap);

    loadCzCookie();

    const fields = ['cz-address1', 'cz-address2', 'cz-max-time-1', 'cz-max-time-2', 'cz-departure'];
    fields.forEach(id => {
        const el = document.getElementById(id);
        el.addEventListener('input',  saveCzCookie);
        el.addEventListener('change', saveCzCookie);
    });

    document.getElementById('cz-max-time-1').addEventListener('input', updateAdjustedTime);
    document.getElementById('cz-max-time-2').addEventListener('input', updateAdjustedTime);
    document.getElementById('cz-departure').addEventListener('change', updateAdjustedTime);
    document.getElementById('cz-btn').addEventListener('click', onCalculate);

    updateAdjustedTime();
});
