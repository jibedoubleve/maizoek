#!/usr/bin/env python3
"""
Fetch cities from GeoNames API.

Queries the GeoNames API to find populated places within a specified radius
from a center address. Filters results by population threshold and compass
direction (e.g., West to North). Outputs matching cities to cities.json.

Usage: python3 fetch_cities.py
"""
import json
import urllib.request
import urllib.parse

from filter_cities import calculate_bearing, is_in_direction_range

# Files
CONFIG_FILE = "query_params.json"
OUTPUT_FILE = "cities.json"

# GeoNames API
GEONAMES_BASE_URL = "http://api.geonames.org"


def load_config():
    """Load configuration from query_params.json."""
    with open(CONFIG_FILE, 'r', encoding='utf-8') as f:
        return json.load(f)


def geonames_request(endpoint, params):
    """
    Make a request to the GeoNames API.

    Args:
        endpoint: API endpoint (e.g., "searchJSON", "findNearbyJSON")
        params: Dictionary of query parameters

    Returns:
        Parsed JSON response

    Raises:
        Exception: If the request fails
    """
    query_string = urllib.parse.urlencode(params)
    url = f"{GEONAMES_BASE_URL}/{endpoint}?{query_string}"

    with urllib.request.urlopen(url) as response:
        return json.loads(response.read().decode())


def get_coordinates(address, country, username):
    """
    Get coordinates for an address using GeoNames search.

    Args:
        address: Address or place name to search
        country: Country code (e.g., "BE")
        username: GeoNames API username

    Returns:
        Tuple of (latitude, longitude) as floats
    """
    data = geonames_request("searchJSON", {
        "q": address,
        "maxRows": 1,
        "country": country,
        "username": username,
    })

    if not data.get("geonames"):
        raise ValueError(f"Could not find coordinates for '{address}'")

    result = data["geonames"][0]
    return float(result["lat"]), float(result["lng"])


def get_cities_filter(min_population):
    """
    Map min_population to GeoNames cities filter.

    Args:
        min_population: Minimum population threshold

    Returns:
        GeoNames cities filter string (cities1000, cities5000, or cities15000)
    """
    if min_population >= 15000:
        return "cities15000"
    elif min_population >= 5000:
        return "cities5000"
    else:
        return "cities1000"


def find_nearby_cities(lat, lng, radius, min_population, username):
    """
    Find cities near a coordinate.

    Args:
        lat: Center latitude
        lng: Center longitude
        radius: Search radius in km
        min_population: Minimum population (used to select API filter)
        username: GeoNames API username

    Returns:
        List of city dictionaries from GeoNames
    """
    cities_filter = get_cities_filter(min_population)

    data = geonames_request("findNearbyPlaceNameJSON", {
        "lat": lat,
        "lng": lng,
        "radius": radius,
        "cities": cities_filter,
        "maxRows": 500,
        "username": username,
    })

    return data.get("geonames", [])


def filter_by_fcode_and_population(cities, fcodes, min_population):
    """
    Filter cities by feature code and minimum population.

    Args:
        cities: List of city dictionaries
        fcodes: List of allowed feature codes (e.g., ["PPL", "PPLA"])
        min_population: Minimum population threshold

    Returns:
        Filtered list of cities
    """
    return [
        city for city in cities
        if city.get("fcode") in fcodes and city.get("population", 0) >= min_population
    ]


def filter_by_regions(cities, regions):
    """
    Filter cities by administrative region (adminCode1).

    Args:
        cities: List of city dictionaries
        regions: List of region codes (e.g., ["WAL", "BRU"]) or None for no filtering

    Returns:
        Filtered list of cities (or original list if regions is None/empty)
    """
    if not regions:
        return cities

    return [
        city for city in cities
        if city.get("adminCode1") in regions
    ]


def filter_by_direction(cities, center_lat, center_lng, dir_from, dir_to):
    """
    Filter cities by compass direction from center.

    Args:
        cities: List of city dictionaries
        center_lat: Center latitude
        center_lng: Center longitude
        dir_from: Starting direction (e.g., "West")
        dir_to: Ending direction (e.g., "North")

    Returns:
        Filtered list of cities within the direction range
    """
    filtered = []
    for city in cities:
        city_lat = float(city["lat"])
        city_lng = float(city["lng"])
        bearing = calculate_bearing(center_lat, center_lng, city_lat, city_lng)

        if is_in_direction_range(bearing, dir_from, dir_to):
            filtered.append(city)

    return filtered


def main():
    """Main function: fetch and filter cities from GeoNames API."""
    # Load configuration
    config = load_config()

    address = config["address"]
    radius = config["radius"]
    fcodes = config["fcodes"]
    min_population = config["min_population"]
    dir_from = config["dir_from"]
    dir_to = config["dir_to"]
    country = config["country"]
    regions = config.get("regions")
    username = config["geonames_username"]

    # Get coordinates for the center address
    print(f"Looking up coordinates for '{address}'...")
    center_lat, center_lng = get_coordinates(address, country, username)
    print(f"{address} is at coordinate {center_lat},{center_lng}")
    print("=" * 40)

    # Find nearby cities
    print(f"Searching for cities within {radius}km (min pop: {min_population})...")
    cities = find_nearby_cities(center_lat, center_lng, radius, min_population, username)
    print(f"Found {len(cities)} places")

    # Filter by feature code and population
    cities = filter_by_fcode_and_population(cities, fcodes, min_population)
    print(f"After fcode/population filter: {len(cities)} cities")

    # Filter by region
    if regions:
        cities = filter_by_regions(cities, regions)
        print(f"After region filter ({', '.join(regions)}): {len(cities)} cities")

    # Filter by direction
    cities = filter_by_direction(cities, center_lat, center_lng, dir_from, dir_to)
    print(f"After direction filter ({dir_from} to {dir_to}): {len(cities)} cities")

    # Sort by name
    cities.sort(key=lambda c: c.get("toponymName", c.get("name", "")))

    # Build output data
    output = {
        "center": {"lat": center_lat, "lng": center_lng},
        "cities": cities,
    }

    # Write to file
    with open(OUTPUT_FILE, 'w', encoding='utf-8') as f:
        json.dump(output, f, indent=2, ensure_ascii=False)

    print()
    print(f"Results saved to {OUTPUT_FILE}")


if __name__ == "__main__":
    main()
