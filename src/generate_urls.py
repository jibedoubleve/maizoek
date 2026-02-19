#!/usr/bin/env python3
"""
Generate Immoweb search URLs for cities.
"""
import json
import urllib.request
import urllib.parse

# Input files
CONFIG_FILE = "query_params.json"

# URLs
IMMOWEB_BASE_URL = "https://www.immoweb.be/en/search"
GEONAMES_API_URL = "http://api.geonames.org/findNearbyPostalCodesJSON"


def load_config():
    """
    Load configuration from query_params.json.

    Returns:
        Configuration dictionary
    """
    with open(CONFIG_FILE, 'r', encoding='utf-8') as f:
        return json.load(f)


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
