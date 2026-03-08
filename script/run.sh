#!/bin/bash
###
### run.sh: Main entry point
###
### Orchestrates the house search workflow:
### 1. Fetches cities from GeoNames API
### 2. Generates HTML page with Immoweb search URLs and map
###

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR/.."

source .venv/bin/activate

echo "=== House Search ==="
echo ""

# Step 0: Check/create configuration
python3 src/init_config.py
if [ $? -ne 0 ]; then
    exit 0
fi

# Step 1: Fetch cities
echo "Step 1: Fetching cities..."
python3 src/fetch_cities.py
echo ""

# Step 2: Generate HTML page with search URLs and map
echo "Step 2: Generating search page..."
python3 src/show_cities.py
echo ""

# Open results in browser
echo "Opening results in browser..."
open ./docs/index.html

echo "=== Done ==="
