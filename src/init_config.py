#!/usr/bin/env python3
"""
init_config.py: Interactive configuration wizard for query_params.json

Checks if query_params.json exists. If not, guides the user through
creating one with their preferences.
"""

import json
import sys
from pathlib import Path

CONFIG_FILE = Path(__file__).parent.parent / "query_params.json"


def prompt_choice(question: str, options: list[str], default: str | None = None) -> str:
    """Prompt user to choose from a list of options."""
    print(f"\n{question}")
    for i, opt in enumerate(options, 1):
        marker = " (default)" if opt == default else ""
        print(f"  {i}. {opt}{marker}")

    while True:
        answer = input(f"Choice [1-{len(options)}]: ").strip()
        if not answer and default:
            return default
        try:
            idx = int(answer) - 1
            if 0 <= idx < len(options):
                return options[idx]
        except ValueError:
            pass
        print("Invalid choice, please try again.")


def prompt_multi_choice(question: str, options: list[str], defaults: list[str] | None = None) -> list[str]:
    """Prompt user to choose multiple options."""
    print(f"\n{question}")
    for i, opt in enumerate(options, 1):
        marker = " *" if defaults and opt in defaults else ""
        print(f"  {i}. {opt}{marker}")

    print("Enter numbers separated by commas (e.g., 1,2,3)")
    if defaults:
        print(f"Press Enter for defaults: {', '.join(defaults)}")

    while True:
        answer = input("Choices: ").strip()
        if not answer and defaults:
            return defaults
        try:
            indices = [int(x.strip()) - 1 for x in answer.split(",")]
            if all(0 <= idx < len(options) for idx in indices):
                return [options[idx] for idx in indices]
        except ValueError:
            pass
        print("Invalid input, please try again.")


def prompt_text(question: str, default: str | None = None) -> str:
    """Prompt user for text input."""
    default_hint = f" [{default}]" if default else ""
    answer = input(f"\n{question}{default_hint}: ").strip()
    return answer if answer else (default or "")


def prompt_number(question: str, default: int | None = None, allow_none: bool = False) -> int | None:
    """Prompt user for a number."""
    default_hint = f" [{default}]" if default is not None else (" [none]" if allow_none else "")

    while True:
        answer = input(f"\n{question}{default_hint}: ").strip()
        if not answer:
            if default is not None:
                return default
            if allow_none:
                return None
        if allow_none and answer.lower() == "none":
            return None
        try:
            return int(answer)
        except ValueError:
            print("Please enter a valid number.")


def create_config() -> dict:
    """Interactive wizard to create configuration."""
    print("\n" + "=" * 50)
    print("  Configuration Wizard - House Search")
    print("=" * 50)

    config = {}

    # Language
    config["language"] = prompt_choice(
        "Select language:",
        ["fr", "nl", "en"],
        default="fr"
    )

    # Location
    config["address"] = prompt_text("Reference city (e.g., Liège, Bruxelles)", default="Liège")
    config["radius"] = prompt_number("Search radius in km", default=30)

    # Direction filter
    directions = ["North", "NorthEast", "East", "SouthEast", "South", "SouthWest", "West", "NorthWest"]
    config["dir_from"] = prompt_choice("Direction FROM:", directions, default="SouthWest")
    config["dir_to"] = prompt_choice("Direction TO:", directions, default="North")

    # Population
    config["min_population"] = prompt_number("Minimum city population", default=5000)

    # Country & Regions
    config["country"] = prompt_choice("Country:", ["BE", "LU", "FR", "NL", "DE"], default="BE")

    if config["country"] == "BE":
        config["regions"] = prompt_multi_choice(
            "Regions to search:",
            ["WAL", "BRU", "VLG"],
            defaults=["WAL"]
        )
    else:
        config["regions"] = []

    # GeoNames feature codes (keep defaults)
    config["fcodes"] = ["PPL", "PPLA", "PPLA2", "PPLA3", "PPLA4"]

    # Immoweb settings
    print("\n" + "-" * 50)
    print("  Immoweb Search Criteria")
    print("-" * 50)

    immoweb = {}

    immoweb["transaction"] = prompt_choice(
        "Transaction type:",
        ["for-sale", "for-rent"],
        default="for-sale"
    )

    immoweb["property_type"] = prompt_choice(
        "Property type:",
        ["house", "apartment", "new-real-estate-project-houses", "villa"],
        default="house"
    )

    immoweb["min_price"] = prompt_number("Minimum price", default=200000)
    immoweb["max_price"] = prompt_number("Maximum price", default=500000)
    immoweb["min_bedrooms"] = prompt_number("Minimum bedrooms", default=3)
    immoweb["max_bedrooms"] = prompt_number("Maximum bedrooms (Enter for none)", allow_none=True)
    immoweb["min_land_surface"] = prompt_number("Minimum land surface m² (Enter for none)", allow_none=True)

    immoweb["epc_scores"] = prompt_multi_choice(
        "Acceptable EPC scores:",
        ["A++", "A+", "A", "B", "C", "D", "E", "F"],
        defaults=["A++", "A+", "A", "B", "C"]
    )

    config["immoweb"] = immoweb

    # GeoNames credentials
    print("\n" + "-" * 50)
    print("  GeoNames API")
    print("-" * 50)
    config["geonames_username"] = prompt_text(
        "GeoNames username (register at geonames.org)",
        default="demo"
    )

    return config


def save_config(config: dict) -> None:
    """Save configuration to JSON file."""
    with open(CONFIG_FILE, "w", encoding="utf-8") as f:
        json.dump(config, f, indent=2, ensure_ascii=False)
    print(f"\nConfiguration saved to {CONFIG_FILE}")


def main() -> int:
    """Check for config file, create if missing."""
    if CONFIG_FILE.exists():
        return 0

    print("No configuration file found.")
    print("Let's create one!")

    try:
        config = create_config()
        save_config(config)
        print("\nConfiguration complete! Re-run the script to start searching.")
        return 1  # Signal to exit after config creation
    except KeyboardInterrupt:
        print("\n\nConfiguration cancelled.")
        return 2


if __name__ == "__main__":
    sys.exit(main())
