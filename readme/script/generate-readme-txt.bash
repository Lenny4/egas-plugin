#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

rm -f "$SCRIPT_DIR/../../public/plugins/egas/readme.txt"
cp "$SCRIPT_DIR/../readme.txt" "$SCRIPT_DIR/../../public/plugins/egas/"

"$SCRIPT_DIR/changelog.bash"
"$SCRIPT_DIR/pluginHeader.bash"
"$SCRIPT_DIR/faq.bash" "https://egas-solutions.com/" "#faq-readme"
"$SCRIPT_DIR/requirements.bash"
"$SCRIPT_DIR/testedupto.bash"
