# House Travel

Tools to find cities and generate Immoweb search URLs for house hunting in Belgium.

> [!IMPORTANT]
> This project is "vibe coded" — I wrote very little code myself and mostly supervised Claude AI's output.

## Usage

```sh
./run.sh
```

This runs the full workflow and opens the search results in your browser.

## How It Works

```
query_params.json               ← Configuration
        │
        ▼
┌─────────────────────┐
│      run.sh         │         ← Entry point
└──────────┬──────────┘
           │
     ┌─────┴──────┐
     ▼            ▼
fetch_cities.py   generate_urls.py
     │                   │
     ▼            ┌──────┴──────┐
cities.json ─────►▼             ▼
             GeoNames API   Immoweb URL
             (postal codes)  builder
                               │
                               ▼
                        immoweb_urls.md
                               │
                               ▼
                           pandoc
                               │
                               ▼
                         search.html ← Opens in browser
```

| Script | Description |
|--------|-------------|
| `run.sh` | Main entry point, orchestrates the workflow |
| `src/fetch_cities.py` | Fetches cities from GeoNames API, filters by direction |
| `src/filter_cities.py` | Helper module for compass direction calculations |
| `src/generate_urls.py` | Generates Immoweb search URLs |

## Configuration

Edit `query_params.json` to configure the search:

### City Search Parameters

| Parameter | Description |
|-----------|-------------|
| `address` | Center location for the search |
| `radius` | Search radius in kilometers |
| `fcodes` | GeoNames feature codes to include (see below) |
| `min_population` | Minimum population threshold |
| `dir_from` / `dir_to` | Compass direction range (clockwise) |
| `country` | Country code filter (e.g., `BE` for Belgium) |
| `geonames_username` | GeoNames API username |

### Direction Options

`North`, `NorthEast`, `East`, `SouthEast`, `South`, `SouthWest`, `West`, `NorthWest`

### Immoweb Parameters

| Parameter | Description | Unit |
|-----------|-------------|------|
| `immoweb.transaction` | `for-sale` or `for-rent` | - |
| `immoweb.property_type` | `house`, `apartment`, etc. | - |
| `immoweb.min_price` | Minimum price (or `null` to disable) | € |
| `immoweb.max_price` | Maximum price (or `null` to disable) | € |
| `immoweb.min_bedrooms` | Minimum bedrooms (or `null` to disable) | count |
| `immoweb.max_bedrooms` | Maximum bedrooms (or `null` to disable) | count |
| `immoweb.min_land_surface` | Minimum land/garden surface (or `null` to disable) | m² |
| `immoweb.epc_scores` | EPC/PEB ratings to include (or `null` to disable) | array |

**EPC Scores:** `["A++", "A+", "A", "B", "C", "D", "E", "F", "G"]`

## Feature Codes

| Code | Description |
|------|-------------|
| `PPL` | Populated place (generic) |
| `PPLA` | Seat of 1st-order admin division |
| `PPLA2` | Seat of 2nd-order admin division |
| `PPLA3` | Seat of 3rd-order admin division |
| `PPLA4` | Seat of 4th-order admin division |

## Output Files

| File | Description |
|------|-------------|
| `cities.json` | Filtered cities with coordinates (intermediate) |
| `search.html` | Final output with clickable Immoweb URLs |

## Dependencies

- Python 3
- [pandoc](https://pandoc.org/) — Converts markdown to HTML
