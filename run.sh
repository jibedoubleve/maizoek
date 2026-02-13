#!/bin/bash
###
### run.sh: Main entry point
###
### Orchestrates the house search workflow:
### 1. Fetches cities from GeoNames API
### 2. Generates Immoweb search URLs
###

set -e

echo "=== House Travel ==="
echo ""

# Step 1: Fetch cities
echo "Step 1: Fetching cities..."
python3 src/fetch_cities.py
echo ""

# Step 2: Generate Immoweb URLs
echo "Step 2: Generating Immoweb URLs..."
python3 src/generate_urls.py
echo ""

# Step 3: Convert the result into html and open it
echo "Step 3: Open results in the browser..."
pandoc immoweb_urls.md -s -o search.html
open search.html
rm immoweb_urls.md
 
echo ""

echo "=== Done ==="
echo "Open immoweb_urls.md to see the search URLs"
