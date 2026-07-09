#!/usr/bin/env bash

set -euo pipefail

if [[ $# -lt 2 ]]; then
    echo "Usage:"
    echo "$0 <url> <css-selector>"
    echo ""
    echo "Exemple:"
    echo "$0 https://egas-solutions.com/ '#faq-readme'"
    exit 1
fi

URL="$1"
SELECTOR="$2"

README_FILE="$(dirname "$0")/../../public/plugins/egas/readme.txt"
TMP_FILE="$(mktemp)"

if [[ ! -f "$README_FILE" ]]; then
    echo "Erreur : fichier introuvable : $README_FILE"
    exit 1
fi


FAQ_CONTENT=$(python3 <<PYTHON
import sys
import requests
from bs4 import BeautifulSoup

url = "${URL}"
selector = "${SELECTOR}"

response = requests.get(
    url,
    timeout=20,
    headers={
        "User-Agent": "Mozilla/5.0"
    }
)

response.raise_for_status()

soup = BeautifulSoup(response.text, "html.parser")

container = soup.select_one(selector)

if not container:
    print(f"Erreur : sélecteur introuvable {selector}", file=sys.stderr)
    sys.exit(1)


faq_items = container.select(".gb-accordion__item")

if not faq_items:
    print("Erreur : aucun élément FAQ trouvé", file=sys.stderr)
    sys.exit(1)


output = []

for item in faq_items:

    question = item.select_one(".gb-accordion__toggle h3")

    answer = item.select_one(".gb-accordion__content p")

    if not question or not answer:
        continue

    q = question.get_text(" ", strip=True)

    # conserve le gras Markdown simple
    a = answer.get_text(" ", strip=True)

    output.append(
        f"= {q} =\\n\\n{a}\\n"
    )


print("\\n".join(output))

PYTHON
)


if [[ -z "$FAQ_CONTENT" ]]; then
    echo "Erreur : aucune FAQ extraite"
    exit 1
fi


awk -v faq="$FAQ_CONTENT" '
{
    if ($0 == "%faq%") {
        printf "%s\n", faq
    } else {
        print
    }
}
' "$README_FILE" > "$TMP_FILE"


mv "$TMP_FILE" "$README_FILE"


echo "FAQ générée avec succès."
echo "Source : $URL"
echo "Sélecteur : $SELECTOR"
