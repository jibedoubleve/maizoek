# House Seeker

Tools to find cities and generate Immoweb search URLs for house hunting in Belgium.

> [!IMPORTANT]
> This project is "vibe coded" — I wrote very little code myself and mostly supervised Claude AI's output.

## Prerequisites

After cloning the repository, run the installation script to create a virtual environment and install dependencies.

### Linux / macOS

```bash
git clone <your-repo>
cd immoweb-search
./script/install.sh
```

### Windows (PowerShell)

```powershell
git clone <your-repo>
cd immoweb-search
.\script\install.ps1
```

> The install scripts create a `.venv` virtual environment and install all required packages from `requirements.txt`.

## Usage

### Linux / macOS

```sh
./script/run.sh
```

### Windows (PowerShell)

```powershell
.\script\run.ps1
```

This runs the full workflow and opens the search results in your browser.

## How It Works

```
┌─────────────────────┐
│   script/run.sh     │         ← Entry point
└──────────┬──────────┘
           │
           ▼
    init_config.py              ← Interactive wizard (first run only)
           │                       creates query_params.json if missing
           ▼
  query_params.json             ← Configuration
           │
           ▼
    fetch_cities.py             ← Fetches cities from GeoNames API
           │
           ▼
      cities.json               ← Filtered cities with coordinates
           │
           ▼
    show_cities.py              ← Generates HTML page with map
           │
           ▼
      index.html                ← Opens in browser
```

| Script | Description |
|--------|-------------|
| `script/install.sh` | Setup script for Linux/macOS (creates venv, installs deps) |
| `script/install.ps1` | Setup script for Windows (creates venv, installs deps) |
| `script/run.sh` | Main entry point for Linux/macOS |
| `script/run.ps1` | Main entry point for Windows (PowerShell) |
| `src/init_config.py` | Interactive wizard to create `query_params.json` on first run |
| `src/fetch_cities.py` | Fetches cities from GeoNames API, filters by direction and region |
| `src/filter_cities.py` | Filters cities by compass direction from center point |
| `src/generate_urls.py` | Generates Immoweb search URLs |
| `src/show_cities.py` | Generates HTML page with search links and interactive map |

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
| `regions` | List of region codes to include (e.g., `["WAL"]`) or `null` for all |
| `geonames_username` | GeoNames API username |
| `language` | Interface language (`fr`, `nl`, or `en`) |

### Direction Options

`North`, `NorthEast`, `East`, `SouthEast`, `South`, `SouthWest`, `West`, `NorthWest`

### Region Codes (Belgium)

| Code | Region |
|------|--------|
| `WAL` | Wallonia |
| `VLG` | Flanders |
| `BRU` | Brussels Capital |

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

**EPC Scores:** `["A++", "A+", "A", "B", "C", "D", "E", "F"]`

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
| `index.html` | Final output with clickable Immoweb URLs and interactive map |

## Dependencies

- Python 3
- Python packages: `folium`, `jinja2`
