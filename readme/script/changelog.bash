#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

PLUGIN_FILE="$SCRIPT_DIR/../../public/plugins/egas/egas.php"
README_FILE="$SCRIPT_DIR/../../public/plugins/egas/readme.txt"
CHANGELOG_FILE="$SCRIPT_DIR/../changelog.json"

TMP_FILE="$(mktemp)"


if [[ ! -f "$PLUGIN_FILE" ]]; then
    echo "Erreur : fichier plugin introuvable : $PLUGIN_FILE"
    exit 1
fi

if [[ ! -f "$README_FILE" ]]; then
    echo "Erreur : fichier readme introuvable : $README_FILE"
    exit 1
fi

if [[ ! -f "$CHANGELOG_FILE" ]]; then
    echo "Erreur : fichier changelog introuvable : $CHANGELOG_FILE"
    exit 1
fi


PLUGIN_VERSION=$(grep -E "^\s*\*\s+Version:" "$PLUGIN_FILE" \
    | sed -E 's/^\s*\*\s+Version:\s*//' \
    | head -n1)


if [[ -z "$PLUGIN_VERSION" ]]; then
    echo "Erreur : version plugin introuvable dans $PLUGIN_FILE"
    exit 1
fi


CHANGELOG_VERSION=$(python3 <<PYTHON
import json

with open("${CHANGELOG_FILE}", "r", encoding="utf-8") as f:
    data = json.load(f)

print(data["changelog"][0]["version"])
PYTHON
)


if [[ "$PLUGIN_VERSION" != "$CHANGELOG_VERSION" ]]; then
    echo "Erreur : version plugin et changelog différentes"
    echo "Plugin : ${PLUGIN_VERSION}"
    echo "Changelog : ${CHANGELOG_VERSION}"
    exit 1
fi


CHANGELOG_CONTENT=$(python3 <<PYTHON
import json

with open("${CHANGELOG_FILE}", "r", encoding="utf-8") as f:
    data = json.load(f)

output = []

for release in data["changelog"]:

    version = release["version"]

    output.append(f"= {version} =")
    output.append("")

    for change_group in release.get("changes", []):

        for change_type, changes in change_group.items():

            label = change_type.capitalize()

            if change_type == "added":
                label = "Added"
            elif change_type == "fixed":
                label = "Fix"
            elif change_type == "changed":
                label = "Changed"
            elif change_type == "removed":
                label = "Removed"

            for change in changes:
                output.append(f"* {label} - {change}")

    output.append("")


print("\n".join(output))

PYTHON
)


if [[ -z "$CHANGELOG_CONTENT" ]]; then
    echo "Erreur : changelog vide"
    exit 1
fi


awk -v changelog="$CHANGELOG_CONTENT" '
{
    if ($0 == "%changelog%") {
        print changelog
    } else {
        print
    }
}
' "$README_FILE" > "$TMP_FILE"


mv "$TMP_FILE" "$README_FILE"


echo "Changelog généré avec succès."
echo "Version : ${PLUGIN_VERSION}"
