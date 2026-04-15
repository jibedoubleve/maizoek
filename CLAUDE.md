# CLAUDE.md — Maizoek

## What this app does
Maizoek generates ready-to-use search URLs for Belgian real estate sites (Immoweb, Trevi, ImmoVlan). The user defines a geographic zone (center city + radius + compass arc + region + population threshold); the app finds all matching towns via GeoNames API and builds pre-filled search URLs for each provider.

**No framework. No build step. Vanilla PHP + vanilla JS.**

---

## Stack
- **Backend**: PHP 8.5+, no framework, served directly
- **Frontend**: Vanilla JS (ES6+), Leaflet.js for map
- **Storage**: SQLite (`cache.sqlite` at project root) — postal code cache (30-day TTL) + logs
- **External API**: GeoNames (city lookup + postal codes via cURL multi)

---

## File structure

```
src/
├── index.php              # UI template + PHP config loader; injects DEFAULT_CONFIG into JS
├── search.php             # AJAX endpoint (POST JSON → returns cities + postal codes)
├── assets/
│   ├── app.js             # All client-side logic: filter state, URL builders, map, events
│   └── app.css            # All styles (CSS variables, no preprocessor)
├── config/
│   ├── query_params.json  # Default filter values (loaded by index.php + search.php)
│   ├── translations.json  # UI strings: fr / en / nl
│   ├── trevi_localities.json  # Trevi locality ID mappings keyed by postal code
│   └── infra.json         # GeoNames credentials — NOT committed, gitignored
└── lib/
    ├── constants.php      # DIRECTIONS array (compass bearings)
    ├── SqliteLogger.php   # PSR-style logger writing to cache.sqlite
    └── NullLogger.php
```

---

## JS architecture (app.js)

### Data flow
```
form input change
  → getFilterState()          // reads all filter inputs → plain object
  → build*Combined(s, ...)    // builds provider URLs
  → updateUrls()              // sets href on result buttons + city links
```

### Filter state object (getFilterState)
```js
{
  propertyType: 'house' | 'apartment',
  transaction:  'for-sale' | 'for-rent',
  minPrice, maxPrice,          // number | null
  minBedrooms, maxBedrooms,    // number | null
  subtypes: string[],          // Immoweb subtype keys e.g. ['HOUSE','VILLA']
  epcMin: number,              // 0–8 index into EPC_SCALE
  epcMax: number,              // 0–8 index into EPC_SCALE
  includeUnderOption: boolean, // Immoweb only
}
```

### EPC system
`EPC_SCALE = ['A++','A+','A','B','C','D','E','F','G']` (indices 0–8)

Wallonia groups used by ImmoVlan:
- `excellent`: [0,1] → A++, A+
- `good`:      [2,3] → A, B
- `poor`:      [4,5,6] → C, D, E
- `bad`:       [7,8] → F, G

Helpers:
- `epcRangeToImmoweb(min, max)` → array of score strings (caps at F=7; returns `[]` if all selected)
- `epcRangeToImmovlanGroups(min, max)` → array of group name strings (returns `[]` if 0–8)

### URL builders
| Function | Provider | URL base |
|---|---|---|
| `buildImmowebCombined(s, postalCodes)` | Immoweb | `immoweb.be/en/search/{type}/{transaction}` |
| `buildTreviCombined(s, cities)` | Trevi | `trevi.be/fr/acheter.../...` |
| `buildImmovlanCombined(s, cities)` | ImmoVlan | `immovlan.be/fr/immobilier` |
| `buildImmowebCity / buildTreviCity / buildImmovlanCity` | per city | same patterns |

### Provider URL param names
| Filter | Immoweb | Trevi | ImmoVlan |
|---|---|---|---|
| Transaction | path segment | `purpose=0/1` | `transactiontypes=a-vendre,...` |
| Property type | path segment | `estatecategory=1/2` | `propertytypes=maison/appartement` |
| Subtypes | `propertySubtypes` | — | `propertysubtypes` (mapped via `IMMOVLAN_SUBTYPES`) |
| Min/max price | `minPrice/maxPrice` | `minprice/maxprice` | `minprice/maxprice` |
| Min/max bedrooms | `minBedroomCount/maxBedroomCount` | — | `minbedrooms/maxbedrooms` |
| EPC | `epcScores=A++,A+,...` | — | `epcratings=excellent,good,...` |
| Under option | `isUnderOption=false` | — | — |
| Cities | `postalCodes=1000,4000,...` | `zips[]=ID` (Trevi locality IDs) | `towns=4000-liege,1000-bruxelles` |

Trevi locality IDs are looked up via `TREVI_LOCALITIES[postalCode]` (injected from `trevi_localities.json`).

### Key constants in app.js
```js
IMMOVLAN_TRANSACTION = { 'for-sale': 'a-vendre,en-vente-publique', 'for-rent': 'a-louer' }
IMMOVLAN_TYPE        = { house: 'maison', apartment: 'appartement' }
IMMOVLAN_SUBTYPES    = { HOUSE: ['maison'], VILLA: ['villa'], MANSION: ['maison-de-maitre'], ... }
EPC_SCALE            = ['A++','A+','A','B','C','D','E','F','G']
EPC_COLORS           = ['#1565C0','#2E7D32','#43A047','#66BB6A','#AFB42B','#F9A825','#EF6C00','#D84315','#B71C1C']
IMMOVLAN_EPC_GROUPS  = { excellent:[0,1], good:[2,3], poor:[4,5,6], bad:[7,8] }
```

### Config persistence (cookie)
`saveConfigCookie()` serialises `getFilterState()` into `user_config` cookie as JSON matching `query_params.json` shape. PHP merges it on load via `array_merge($config, $cookie_config)`. Backward compat: old cookies may have `epc_scores[]` instead of `epc_min/epc_max` — handled in both PHP and `resetFilters()`.

### PHP → JS data injection (index.php head)
```php
const DEFAULT_CONFIG = <?= json_encode($config) ?>;   // full query_params.json + cookie
const DIRECTIONS     = <?= json_encode(DIRECTIONS) ?>;
const TRANSLATIONS   = <?= json_encode($t) ?>;
const TREVI_LOCALITIES = <?= json_encode(...) ?>;
```

---

## config/query_params.json shape
```json
{
  "language": "fr",
  "address": "Liège",
  "radius": 30,
  "dir_from": "SouthWest", "dir_to": "North",
  "min_population": 2000,
  "ignore_population": false,
  "country": "BE",
  "regions": ["WAL"],
  "geonames_username": "...",
  "immoweb": {
    "transaction": "for-sale",
    "property_type": "house",
    "property_subtypes": ["HOUSE","VILLA"],
    "min_price": 400000, "max_price": 800000,
    "min_bedrooms": 4, "max_bedrooms": null,
    "epc_min": 0, "epc_max": 4,
    "include_under_option": false
  }
}
```
`infra.json` (not committed): `{ "geonames_username": "...", "goatcounter_url": "..." }`

---

## Filter availability by provider
| Filter | Immoweb | Trevi | ImmoVlan |
|---|:---:|:---:|:---:|
| Transaction | ✓ | ✓ | ✓ |
| Property type | ✓ | ✓ | ✓ |
| Price | ✓ | ✓ | ✓ |
| Bedrooms | ✓ | — | ✓ |
| Subtypes | ✓ | — | ✓ (mapped) |
| EPC/PEB | ✓ (individual scores) | — | ✓ (Wallonia groups) |
| Include under option | ✓ | — | — |

---

## search.php pipeline
1. Resolve center coordinates via GeoNames `searchJSON`
2. Find nearby places via `findNearbyPlaceNameJSON` (max 500, radius in km)
3. Filter: fcode blacklist (`PPLH/PPLQ/PPLW`), population, Belgian region (`adminCode1`), compass bearing
4. Fetch postal codes: SQLite cache-first, parallel cURL for misses (`findNearbyPostalCodesJSON`)
5. Return `{ center, cities[], postalCodes[] }`

Cache key: lat/lng rounded to 4 decimals. TTL: 30 days.

---

## CSS conventions
CSS variables in `:root`: `--color-immoweb` (#003f7f), `--color-trevi` (#c0392b), `--color-immovlan` (#e07b00). No preprocessor. All styles in `app.css`.

Provider badges: `.provider-badge[data-tooltip="..."]` — tooltip on hover via `::after`.

---

## Commit format
```
(#issue) short description
```

---

## What NOT to do
- Do not introduce a framework, bundler, or build step
- Do not deep-merge `immoweb` cookie config — PHP uses `array_merge` (shallow)
- Do not add `noindex=1` to ImmoVlan URLs (it's a UI-only param from manual browsing)
- `infra.json` must never be committed
