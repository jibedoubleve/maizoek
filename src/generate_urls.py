#!/usr/bin/env python3
"""
Generate Immoweb search URLs for cities.

Reads filtered cities from cities.json, fetches postal codes from GeoNames API,
and generates both a single combined URL and individual city URLs.

Usage: python3 generate_urls.py
Output: immoweb_urls.txt
"""
import json
import urllib.request
import urllib.parse
import subprocess

# Input/output files
CONFIG_FILE = "query_params.json"
CITIES_FILE = "cities.json"
OUTPUT_FILE = "immoweb_urls.md"

# URLs
IMMOWEB_BASE_URL = "https://www.immoweb.be/en/search"
GEONAMES_API_URL = "http://api.geonames.org/findNearbyPostalCodesJSON"


def load_config():
    """
    Load configuration from query_params.json.

    Returns:
        Configuration dictionary
    """
    with open(CONFIG_FILE, 'r') as f:
        return json.load(f)


def load_cities():
    """
    Load filtered cities from cities.json.

    Returns:
        List of city dictionaries with lat, lng, toponymName, etc.
    """
    with open(CITIES_FILE, 'r') as f:
        data = json.load(f)

    return data.get("cities", [])


def fetch_postal_code(lat, lng, username):
    """
    Fetch postal code from GeoNames API for given coordinates.

    Args:
        lat: Latitude
        lng: Longitude
        username: GeoNames API username

    Returns:
        Postal code string or None if not found
    """
    params = urllib.parse.urlencode({
        'lat': lat,
        'lng': lng,
        'maxRows': 1,
        'username': username
    })

    url = f"{GEONAMES_API_URL}?{params}"

    try:
        with urllib.request.urlopen(url) as response:
            data = json.loads(response.read().decode())
            postal_codes = data.get("postalCodes", [])
            if postal_codes:
                return postal_codes[0].get("postalCode")
    except Exception as e:
        print(f"  Warning: Could not fetch postal code: {e}")

    return None


def build_query_params(config, postal_codes):
    """
    Build query parameters for Immoweb URL.

    Args:
        config: Configuration dictionary
        postal_codes: List of postal codes

    Returns:
        URL-encoded query string
    """
    immoweb = config.get("immoweb", {})
    country = config.get("country", "BE")

    # Format postal codes as BE-4000,BE-4020,...
    formatted_codes = ",".join([f"{country}-{code}" for code in postal_codes])

    # Build params dict, only including non-null values
    params = {
        "countries": country,
        "postalCodes": formatted_codes,
    }

    # Map config keys to Immoweb URL parameter names
    param_mapping = {
        "min_price": "minPrice",
        "max_price": "maxPrice",
        "min_bedrooms": "minBedroomCount",
        "max_bedrooms": "maxBedroomCount",
        "min_land_surface": "minLandSurface",
    }

    for config_key, url_param in param_mapping.items():
        value = immoweb.get(config_key)
        if value is not None:
            params[url_param] = value

    # Handle epc_scores (array -> comma-separated string)
    epc_scores = immoweb.get("epc_scores")
    if epc_scores:
        params["epcScores"] = ",".join(epc_scores)

    return urllib.parse.urlencode(params)


def generate_combined_url(postal_codes, config):
    """
    Generate a single Immoweb URL with all postal codes.

    Args:
        postal_codes: List of postal codes (e.g., ["4000", "4020"])
        config: Configuration dictionary

    Returns:
        Combined Immoweb search URL
    """
    immoweb = config.get("immoweb", {})
    property_type = immoweb.get("property_type", "house")
    transaction = immoweb.get("transaction", "for-sale")

    query_string = build_query_params(config, postal_codes)

    url = f"{IMMOWEB_BASE_URL}/{property_type}/{transaction}?{query_string}"

    return url


def generate_city_url(city_name, config):
    """
    Generate an Immoweb search URL for a single city.

    Args:
        city_name: Name of the city
        config: Configuration dictionary

    Returns:
        Immoweb search URL for the city
    """
    immoweb = config.get("immoweb", {})
    property_type = immoweb.get("property_type", "house")
    transaction = immoweb.get("transaction", "for-sale")

    # Normalize city name for URL
    normalized = city_name.lower().replace(" ", "-").replace("'", "-")

    return f"{IMMOWEB_BASE_URL}/{property_type}/{transaction}/{normalized}"


def main():
    """
    Main function: fetch postal codes and generate Immoweb URLs.
    """
    # Load data
    config = load_config()
    cities = load_cities()
    username = config.get("geonames_username", "")

    if not username:
        print("Error: geonames_username not found in config")
        return

    print(f"Processing {len(cities)} cities...")
    print()

    # Fetch postal codes for each city
    postal_codes = []
    city_postal_map = {}

    for city in cities:
        city_name = city.get("toponymName", city.get("name", ""))
        lat = city.get("lat")
        lng = city.get("lng")

        print(f"  {city_name}: fetching postal code...", end=" ")
        postal_code = fetch_postal_code(lat, lng, username)

        if postal_code:
            print(f"{postal_code}")
            postal_codes.append(postal_code)
            city_postal_map[city_name] = postal_code
        else:
            print("not found")

    print()

    # Remove duplicates while preserving order
    unique_postal_codes = list(dict.fromkeys(postal_codes))

    # Generate combined URL
    combined_url = generate_combined_url(unique_postal_codes, config)

    # Write output
    with open(OUTPUT_FILE, 'w') as f:
        f.write("# Immoweb Search\n\n")
        f.write("I you want to edit the criteria of the search,\nYou can edit the file `query_params.json`\n\n")
        f.write("Documentation can be found at README.md\n\n")
        f.write(f"> [Search on Immoweb]({combined_url})")
        f.write("\n\n")
        f.write("# Individual cities\n\n")
        for city in cities:
            city_name = city.get("toponymName", city.get("name", ""))
            postal = city_postal_map.get(city_name, "?")
            city_url = generate_city_url(city_name, config)
            f.write(f"* [{city_name} - {postal}]({city_url})\n\n")

    # Print results
    print(f"Generated URLs for {len(unique_postal_codes)} postal codes")
    print(f"Output saved to {OUTPUT_FILE}")
    print()
    print("=== COMBINED SEARCH URL ===")
    print(combined_url)

if __name__ == "__main__":
    main()
