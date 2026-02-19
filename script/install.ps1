#
# install.ps1: Setup script (Windows)
#
# Creates a Python virtual environment and installs dependencies.
#

$ErrorActionPreference = "Stop"

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location "$ScriptDir/.."

Write-Host "Creating virtual environment..."
python -m venv .venv

Write-Host "Activating virtual environment..."
.\.venv\Scripts\Activate.ps1

Write-Host "Installing dependencies..."
pip install -r requirements.txt

Write-Host "Done!"
