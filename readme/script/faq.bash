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

echo "FAQ source : $URL"
echo "FAQ selector : $SELECTOR"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

README_FILE="$SCRIPT_DIR/../../public/plugins/egas/readme.txt"
TMP_FILE="$(mktemp)"

trap 'rm -f "$TMP_FILE"' EXIT

if [[ ! -f "$README_FILE" ]]; then
    echo "Erreur : fichier introuvable : $README_FILE"
    exit 1
fi


FAQ_CONTENT=$(python3 - "$URL" "$SELECTOR" <<'PYTHON'
import sys
import requests
from bs4 import BeautifulSoup
from deep_translator import GoogleTranslator


url = sys.argv[1]
selector = sys.argv[2]


try:
    response = requests.get(
        url,
        timeout=20,
        headers={
            "User-Agent": "Mozilla/5.0"
        }
    )

    response.raise_for_status()

except Exception as e:
    print(f"Erreur HTTP : {e}", file=sys.stderr)
    sys.exit(1)


soup = BeautifulSoup(response.text, "html.parser")

container = soup.select_one(selector)

if not container:
    print(f"Erreur : sélecteur introuvable : {selector}", file=sys.stderr)
    sys.exit(1)


faq_items = container.select(".gb-accordion__item")

if not faq_items:
    print("Erreur : aucun élément FAQ trouvé", file=sys.stderr)
    sys.exit(1)


translator = GoogleTranslator(
    source="auto",
    target="en"
)


output = []


for item in faq_items:

    question = item.select_one(".gb-accordion__toggle h3")
    answer = item.select_one(".gb-accordion__content p")

    if not question or not answer:
        continue


    q = question.get_text(" ", strip=True)
    a = answer.get_text(" ", strip=True)


    if not q or not a:
        continue


    try:
        q_en = translator.translate(q)
        a_en = translator.translate(a)

    except Exception:
        # Si la traduction échoue, on garde le texte original
        q_en = q
        a_en = a


    output.append(
        f"= {q_en} =\n\n{a_en}\n"
    )


if not output:
    print("Erreur : aucune FAQ valide extraite", file=sys.stderr)
    sys.exit(1)


print("\n".join(output))

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
