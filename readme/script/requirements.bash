#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

README_FILE="$SCRIPT_DIR/../../public/plugins/egas/readme.txt"
PLUGIN_FILE="$SCRIPT_DIR/../../public/plugins/egas/egas-data-sync-for-sage.php"
TMP_FILE="$(mktemp)"

if [[ ! -f "$PLUGIN_FILE" ]]; then
    echo "Erreur : fichier plugin introuvable : $PLUGIN_FILE"
    exit 1
fi

if [[ ! -f "$README_FILE" ]]; then
    echo "Erreur : fichier readme introuvable : $README_FILE"
    exit 1
fi


extract_value() {
    local key="$1"

    grep -E "^\s*\*\s+${key}:" "$PLUGIN_FILE" \
        | sed -E "s/^\s*\*\s+${key}:\s*//" \
        | head -n1
}


PHP_VERSION="$(extract_value "Requires PHP")"
WP_VERSION="$(extract_value "Requires at least")"
REQUIRED_PLUGINS="$(extract_value "Requires Plugins")"


REQUIREMENTS=()


if [[ -n "$PHP_VERSION" ]]; then
    REQUIREMENTS+=("* PHP ${PHP_VERSION} or greater is required")
fi


if [[ -n "$WP_VERSION" ]]; then
    REQUIREMENTS+=("* WordPress ${WP_VERSION} or greater")
fi


if [[ -n "$REQUIRED_PLUGINS" ]]; then

    IFS=',' read -ra PLUGINS <<< "$REQUIRED_PLUGINS"

    for plugin in "${PLUGINS[@]}"; do

        plugin="$(echo "$plugin" | xargs)"

        if [[ "$plugin" == "woocommerce" ]]; then
            REQUIREMENTS+=("* WooCommerce plugin is required")
        else
            REQUIREMENTS+=("* ${plugin} plugin is required")
        fi

    done
fi


if [[ ${#REQUIREMENTS[@]} -eq 0 ]]; then
    echo "Erreur : aucune requirement trouvée dans $PLUGIN_FILE"
    exit 1
fi


REQUIREMENTS_TEXT=$(printf "%s\n" "${REQUIREMENTS[@]}")


awk -v requirements="$REQUIREMENTS_TEXT" '
{
    if ($0 == "%requirements%") {
        print requirements
    } else {
        print
    }
}
' "$README_FILE" > "$TMP_FILE"


mv "$TMP_FILE" "$README_FILE"


echo "Requirements générés avec succès."
echo ""
echo "$REQUIREMENTS_TEXT"
