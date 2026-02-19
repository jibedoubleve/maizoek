#
# run.ps1: Main entry point (Windows)
#
# Orchestrates the house search workflow:
# 1. Fetches cities from GeoNames API
# 2. Generates HTML page with Immoweb search URLs and map
#

$ErrorActionPreference = "Stop"

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location "$ScriptDir/.."

# Activate venv (cross-platform)
if ($IsWindows) {
    .\.venv\Scripts\Activate.ps1
} else {
    . ./.venv/bin/Activate.ps1
}

Write-Host "=== House Search ===" -ForegroundColor Cyan
Write-Host ""

# Step 0: Check/create configuration
python src/init_config.py
if ($LASTEXITCODE -ne 0) {
    exit 0
}

# Step 1: Fetch cities
Write-Host "Step 1: Fetching cities..."
python src/fetch_cities.py
Write-Host ""

# Step 2: Generate HTML page with search URLs and map
Write-Host "Step 2: Generating search page..."
python src/show_cities.py
Write-Host ""

# Open results in browser
Write-Host "Opening results in browser..."
Start-Process (Join-Path (Get-Location).Path "index.html")

Write-Host "=== Done ===" -ForegroundColor Green
