#!/usr/bin/env python3
import math

# Direction to degrees mapping
DIRECTIONS = {
    "North": 0,
    "NorthEast": 45,
    "East": 90,
    "SouthEast": 135,
    "South": 180,
    "SouthWest": 225,
    "West": 270,
    "NorthWest": 315,
}


def calculate_bearing(lat1, lng1, lat2, lng2):
    """
    Calculate the compass bearing from point 1 to point 2.

    Uses the forward azimuth formula to determine the initial direction
    you would need to travel from point 1 to reach point 2.

    Args:
        lat1, lng1: Latitude and longitude of the starting point (degrees)
        lat2, lng2: Latitude and longitude of the destination point (degrees)

    Returns:
        Bearing in degrees (0-360), where:
        - 0° = North, 90° = East, 180° = South, 270° = West
    """
    # Convert degrees to radians for trigonometric functions
    lat1, lng1, lat2, lng2 = map(math.radians, [lat1, lng1, lat2, lng2])

    # Longitude difference between the two points
    delta_lng = lng2 - lng1

    # X component: east-west displacement
    x = math.sin(delta_lng) * math.cos(lat2)
    # Y component: north-south displacement (accounts for Earth's curvature)
    y = math.cos(lat1) * math.sin(lat2) - math.sin(lat1) * math.cos(lat2) * math.cos(delta_lng)

    # atan2 gives angle in radians (-π to +π)
    bearing = math.atan2(x, y)
    # Convert radians to degrees
    bearing = math.degrees(bearing)
    # Normalize to 0-360 range
    bearing = (bearing + 360) % 360

    return bearing


def is_in_direction_range(bearing, dir_from, dir_to):
    """
    Check if a bearing falls within a compass direction range.

    The range is defined clockwise from dir_from to dir_to.
    For example, "West" to "North" covers 270° → 360° → 0° (the northwest quadrant).

    Args:
        bearing: The bearing to check (0-360 degrees)
        dir_from: Starting direction name (e.g., "West")
        dir_to: Ending direction name (e.g., "North")

    Returns:
        True if bearing is within the range, False otherwise
    """
    # Convert direction names to degrees using the DIRECTIONS lookup table
    from_deg = DIRECTIONS[dir_from]
    to_deg = DIRECTIONS[dir_to]

    if from_deg <= to_deg:
        # Simple case: range doesn't cross 0° (e.g., East to South = 90° to 180°)
        return from_deg <= bearing <= to_deg
    else:
        # Range crosses 0° (e.g., West to North = 270° to 0°)
        # Bearing is valid if it's >= from_deg OR <= to_deg
        return bearing >= from_deg or bearing <= to_deg
