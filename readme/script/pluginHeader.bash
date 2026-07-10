#!/usr/bin/env bash

set -euo pipefail

PLUGIN_FILE="$(dirname "$0")/../../public/plugins/egas/egas.php"
README_FILE="$(dirname "$0")/../../public/plugins/egas/readme.txt"
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

    return 0
}

PLUGIN_NAME="$(extract_value "Plugin Name")"
PLUGIN_URI="$(extract_value "Plugin URI")"
DESCRIPTION="$(extract_value "Description")"
VERSION="$(extract_value "Version")"
AUTHOR="$(extract_value "Author")"
AUTHOR_URI="$(extract_value "Author URI")"
REQUIRES_WP="$(extract_value "Requires at least")"
TESTED_UP_TO="$(extract_value "Tested up to")"
REQUIRES_PHP="$(extract_value "Requires PHP")"
LICENSE="$(extract_value "License")"
LICENSE_URI="$(extract_value "License URI")"
TEXT_DOMAIN="$(extract_value "Text Domain")"

PLUGIN_HEADER=$(cat <<EOF
=== ${PLUGIN_NAME} ===

Contributors: ${AUTHOR}
Tags: sage, woocommerce, synchronization, erp
Requires at least: ${REQUIRES_WP}
Tested up to: ${TESTED_UP_TO}
Requires PHP: ${REQUIRES_PHP}
Stable tag: ${VERSION}
License: ${LICENSE}
License URI: ${LICENSE_URI}

EOF
)

# Remplacement uniquement du token %pluginHeader%
awk -v header="$PLUGIN_HEADER" '
{
    if ($0 == "%pluginHeader%") {
        printf "%s\n", header
    } else {
        print
    }
}
' "$README_FILE" > "$TMP_FILE"

mv "$TMP_FILE" "$README_FILE"

echo "README généré avec succès."
echo "Version détectée : ${VERSION}"
