#!/usr/bin/env python3
"""
Generate Immoweb and Trevi search URLs for cities.
"""
import json
import urllib.request
import urllib.parse

# Input files
CONFIG_FILE = "query_params.json"

# URLs
IMMOWEB_BASE_URL = "https://www.immoweb.be/en/search"
TREVI_BASE_URL = "https://www.trevi.be/fr/acheter-bien-immobilier"
IMMOVLAN_BASE_URL = "https://immovlan.be/fr/immobilier"
GEONAMES_API_URL = "http://api.geonames.org/findNearbyPostalCodesJSON"

# Trevi mappings derived from shared immoweb config values
TREVI_TRANSACTION_MAP = {
    "for-sale": 0,
    "for-rent": 1,
}
TREVI_PROPERTY_PATH_MAP = {
    "house": "maisons",
    "apartment": "appartements",
}
TREVI_PROPERTY_CATEGORY_MAP = {
    "house": 1,
    "apartment": 2,
}

# Immovlan mappings
IMMOVLAN_TRANSACTION_MAP = {
    "for-sale": "a-vendre,en-vente-publique",
    "for-rent": "a-louer",
}
IMMOVLAN_PROPERTY_TYPE_MAP = {
    "house": "maison",
    "apartment": "appartement",
}
IMMOVLAN_SUBTYPE_MAP = {
    "HOUSE": ["maison"],
    "VILLA": ["villa"],
    "MANSION": ["maison-de-maitre"],
    "MANOR_HOUSE": ["maison-de-maitre", "fermette"],
    "CHALET": ["chalet"],
    "CASTLE": ["chateau"],
    "BUNGALOW": ["bungalow"],
    # FARMHOUSE, EXCEPTIONAL_PROPERTY, TOWN_HOUSE, COUNTRY_COTTAGE, PAVILION: no equivalent
}


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

    formatted_codes = ",".join(postal_codes)

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

    # Handle property_subtypes (e.g. MANSION, VILLA, CHALET -> propertySubtypes=MANSION,VILLA,CHALET)
    property_subtypes = immoweb.get("property_subtypes")
    if property_subtypes:
        params["propertySubtypes"] = ",".join(property_subtypes)

    # Use quote_via to keep commas and + unencoded (Immoweb expects literal commas and + in EPC scores)
    return urllib.parse.urlencode(params, quote_via=lambda s, safe, enc, err: urllib.parse.quote(str(s), safe=",+"))


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


def _trevi_base_params(config):
    """
    Build the common Trevi query parameters from shared immoweb config.

    Returns:
        (params_list, path_segment) tuple
    """
    immoweb = config.get("immoweb", {})
    property_type = immoweb.get("property_type", "house")

    params = [
        ("purpose", TREVI_TRANSACTION_MAP.get(immoweb.get("transaction", "for-sale"), 0)),
        ("estatecategory", TREVI_PROPERTY_CATEGORY_MAP.get(property_type, 1)),
    ]

    min_price = immoweb.get("min_price")
    if min_price is not None:
        params.append(("minprice", min_price))

    max_price = immoweb.get("max_price")
    if max_price is not None:
        params.append(("maxprice", max_price))

    path_segment = TREVI_PROPERTY_PATH_MAP.get(property_type, "maisons")
    return params, path_segment


def generate_trevi_combined_url(city_postal_map, config):
    """
    Generate a single Trevi URL covering all cities using zips[] parameters.
    City names are kept with their original accents (e.g. 4000_Liège).

    Args:
        city_postal_map: Dict mapping city name -> postal code
        config: Configuration dictionary

    Returns:
        Combined Trevi search URL
    """
    params, path_segment = _trevi_base_params(config)

    for city_name, postal_code in city_postal_map.items():
        params.append(("zips[]", f"{postal_code}_{city_name}"))

    query_string = urllib.parse.urlencode(params, quote_via=urllib.parse.quote)
    return f"{TREVI_BASE_URL}/{path_segment}?{query_string}"


def generate_immovlan_combined_url(city_postal_map, config):
    """
    Generate a single Immovlan URL covering all cities using towns parameter.
    Format: towns={postalcode}-{cityname},{postalcode}-{cityname},...

    Args:
        city_postal_map: Dict mapping city name -> postal code
        config: Configuration dictionary

    Returns:
        Combined Immovlan search URL
    """
    immoweb = config.get("immoweb", {})
    transaction = immoweb.get("transaction", "for-sale")
    property_type = immoweb.get("property_type", "house")

    params = [
        ("transactiontypes", IMMOVLAN_TRANSACTION_MAP.get(transaction, "a-vendre,en-vente-publique")),
        ("propertytypes", IMMOVLAN_PROPERTY_TYPE_MAP.get(property_type, "maison")),
    ]

    # Map subtypes
    immovlan_subtypes = []
    for subtype in immoweb.get("property_subtypes", []):
        immovlan_subtypes.extend(IMMOVLAN_SUBTYPE_MAP.get(subtype, []))
    # Deduplicate while preserving order
    seen = set()
    immovlan_subtypes = [s for s in immovlan_subtypes if not (s in seen or seen.add(s))]
    if immovlan_subtypes:
        params.append(("propertysubtypes", ",".join(immovlan_subtypes)))

    # Towns: {postalcode}-{cityname-lowercase-hyphenated}
    towns = [
        f"{postal}-{name.lower().replace(' ', '-')}"
        for name, postal in city_postal_map.items()
    ]
    if towns:
        params.append(("towns", ",".join(towns)))

    min_price = immoweb.get("min_price")
    if min_price is not None:
        params.append(("minprice", min_price))

    max_price = immoweb.get("max_price")
    if max_price is not None:
        params.append(("maxprice", max_price))

    min_bedrooms = immoweb.get("min_bedrooms")
    if min_bedrooms is not None:
        params.append(("minbedrooms", min_bedrooms))

    max_bedrooms = immoweb.get("max_bedrooms")
    if max_bedrooms is not None:
        params.append(("maxbedrooms", max_bedrooms))

    query_string = urllib.parse.urlencode(params, quote_via=urllib.parse.quote)
    return f"{IMMOVLAN_BASE_URL}?{query_string}"


def generate_immovlan_city_url(city_name, postal_code, config):
    """
    Generate an Immovlan search URL for a single city.

    Args:
        city_name: City name
        postal_code: Postal code for the city
        config: Configuration dictionary

    Returns:
        Immovlan search URL for the city
    """
    immoweb = config.get("immoweb", {})
    transaction = immoweb.get("transaction", "for-sale")
    property_type = immoweb.get("property_type", "house")

    params = [
        ("transactiontypes", IMMOVLAN_TRANSACTION_MAP.get(transaction, "a-vendre,en-vente-publique")),
        ("propertytypes", IMMOVLAN_PROPERTY_TYPE_MAP.get(property_type, "maison")),
    ]

    immovlan_subtypes = []
    for subtype in immoweb.get("property_subtypes", []):
        immovlan_subtypes.extend(IMMOVLAN_SUBTYPE_MAP.get(subtype, []))
    seen = set()
    immovlan_subtypes = [s for s in immovlan_subtypes if not (s in seen or seen.add(s))]
    if immovlan_subtypes:
        params.append(("propertysubtypes", ",".join(immovlan_subtypes)))

    if postal_code:
        town = f"{postal_code}-{city_name.lower().replace(' ', '-')}"
        params.append(("towns", town))

    min_price = immoweb.get("min_price")
    if min_price is not None:
        params.append(("minprice", min_price))

    max_price = immoweb.get("max_price")
    if max_price is not None:
        params.append(("maxprice", max_price))

    min_bedrooms = immoweb.get("min_bedrooms")
    if min_bedrooms is not None:
        params.append(("minbedrooms", min_bedrooms))

    max_bedrooms = immoweb.get("max_bedrooms")
    if max_bedrooms is not None:
        params.append(("maxbedrooms", max_bedrooms))

    query_string = urllib.parse.urlencode(params, quote_via=urllib.parse.quote)
    return f"{IMMOVLAN_BASE_URL}?{query_string}"


def generate_trevi_city_url(city_name, postal_code, config):
    """
    Generate a Trevi search URL for a single city.

    Args:
        city_name: City name with original accents (e.g. "Liège")
        postal_code: Postal code for the city (e.g. "4000")
        config: Configuration dictionary

    Returns:
        Trevi search URL for the city
    """
    params, path_segment = _trevi_base_params(config)
    params.append(("zips[]", f"{postal_code}_{city_name}"))

    query_string = urllib.parse.urlencode(params, quote_via=urllib.parse.quote)
    return f"{TREVI_BASE_URL}/{path_segment}?{query_string}"


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
