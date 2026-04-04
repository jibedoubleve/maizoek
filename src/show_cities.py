#!/usr/bin/env python3
"""
Generate a complete HTML page with Immoweb search links and an interactive map.

Combines URL generation and map visualization into a single output file.
"""
import folium
import json
from pathlib import Path
from jinja2 import Environment, FileSystemLoader
from generate_urls import (
    load_config,
    fetch_postal_code,
    generate_combined_url,
    generate_trevi_combined_url,
    generate_immovlan_combined_url,
    generate_city_url,
    generate_trevi_city_url,
    generate_immovlan_city_url,
)

OUTPUT_FILE = "./docs/index.html"
CITIES_FILE = "cities.json"
TEMPLATE_DIR = Path(__file__).parent / "templates"
TEMPLATE_FILE = "search.html"
TRANSLATIONS_FILE = Path(__file__).parent / "translations.json"


def load_translations(language):
    """Load translations for the specified language."""
    with open(TRANSLATIONS_FILE, 'r', encoding='utf-8') as f:
        translations = json.load(f)
    return translations.get(language, translations.get("fr"))


def load_cities():
    """Load cities and center coordinates from cities.json."""
    with open(CITIES_FILE, 'r', encoding='utf-8') as f:
        data = json.load(f)
    return data.get("cities", []), data.get("center", {})


def main():
    """Generate complete HTML page with links and map."""
    # Load data
    config = load_config()
    cities, center = load_cities()
    username = config.get("geonames_username", "")
    regions = config.get("regions")
    radius = config.get("radius")
    address = config.get("address")
    language = config.get("language", "fr")
    translations = load_translations(language)
    immoweb = config.get("immoweb", {})

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

    # Generate combined URLs
    combined_url = generate_combined_url(unique_postal_codes, config)
    trevi_combined_url = generate_trevi_combined_url(city_postal_map, config)
    immovlan_combined_url = generate_immovlan_combined_url(city_postal_map, config)

    print(f"Generated URLs for {len(unique_postal_codes)} postal codes")
    print()

    # Build cities data for template
    cities_data = []
    for city in cities:
        city_name = city.get("toponymName", city.get("name", ""))
        postal = city_postal_map.get(city_name)
        cities_data.append({
            "name": city_name,
            "postal": postal or "?",
            "immoweb_url": generate_city_url(city_name, config),
            "trevi_url": generate_trevi_city_url(city_name, postal, config) if postal else None,
            "immovlan_url": generate_immovlan_city_url(city_name, postal, config) if postal else None,
        })

    # Create the map centered on the main city
    map = folium.Map(
        location=[center["lat"], center["lng"]],
        zoom_start=11
    )

    # Add markers on all the selected cities
    for city in cities:
        city_name = city.get("toponymName", city.get("name", ""))
        postal = city_postal_map.get(city_name, "")
        folium.Marker(
            location=[float(city["lat"]), float(city["lng"])],
            popup=f"{city_name} ({postal})",
            tooltip=f"{city_name} ({city.get('countryCode', '')})"
        ).add_to(map)

    # Get the map HTML
    map_html = map._repr_html_()

    # Render template
    env = Environment(loader=FileSystemLoader(TEMPLATE_DIR))
    template = env.get_template(TEMPLATE_FILE)
    complete_html = template.render(
        combined_url=combined_url,
        trevi_combined_url=trevi_combined_url,
        immovlan_combined_url=immovlan_combined_url,
        cities=cities_data,
        map_html=map_html,
        address=address,
        radius=radius,
        regions=regions,
        immoweb=immoweb,
        t=translations,
        language=language,
    )

    # Save the complete page
    with open(OUTPUT_FILE, 'w', encoding='utf-8') as f:
        f.write(complete_html)

    print(f"Page saved as {OUTPUT_FILE}")
    print()
    print("=== COMBINED SEARCH URL ===")
    print(combined_url)


if __name__ == "__main__":
    main()
