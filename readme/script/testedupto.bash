#!/usr/bin/env bash

set -euo pipefail

API_URL="https://api.wordpress.org/core/version-check/1.7/"
PLUGIN_FILE="$(dirname "$0")/../../public/plugins/egas/egas.php"

if [[ ! -f "$PLUGIN_FILE" ]]; then
    echo "Error: plugin file not found: $PLUGIN_FILE" >&2
    exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
    echo "Error: jq is required but not installed." >&2
    exit 1
fi

JSON="$(curl -fsSL "$API_URL")"

if [[ -z "$JSON" ]]; then
    echo "Error: failed to fetch data from $API_URL" >&2
    exit 1
fi

# Get the highest full version number across all offers (sorted with version sort)
LATEST_FULL_VERSION="$(echo "$JSON" | jq -r '.offers[].current' | sort -V | tail -n 1)"

if [[ -z "$LATEST_FULL_VERSION" || "$LATEST_FULL_VERSION" == "null" ]]; then
    echo "Error: could not determine latest WordPress version from API response." >&2
    exit 1
fi

# Keep only major.minor (e.g. 7.0.1 -> 7.0, 6.9.4 -> 6.9)
LATEST_VERSION="$(echo "$LATEST_FULL_VERSION" | cut -d. -f1,2)"

echo "Latest WordPress version (full): $LATEST_FULL_VERSION"
echo "Latest WordPress version (major.minor): $LATEST_VERSION"

# Replace "Tested up to: X.Y[.Z]" with the latest major.minor version, whatever it currently is
if grep -qE '^[[:space:]]*\*?[[:space:]]*Tested up to:[[:space:]]*[0-9]+(\.[0-9]+)*' "$PLUGIN_FILE"; then
    sed -i -E "s/(Tested up to:[[:space:]]*)[0-9]+(\.[0-9]+)*/\1${LATEST_VERSION}/" "$PLUGIN_FILE"
    echo "Updated 'Tested up to' in $PLUGIN_FILE to $LATEST_VERSION"
else
    echo "Error: 'Tested up to:' line not found in $PLUGIN_FILE" >&2
    exit 1
fi
