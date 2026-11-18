#!/usr/bin/env bash
#
# Maakt een zip om Honkbal Live te installeren op hosting zonder SSH (bijv. Hostinger),
# alleen met Bestandsbeheer.
#
# Gebruik:  scripts/maak-hostinger-pakket.sh https://jouwdomein.nl [uitvoermap]
#
# De zip bevat:
#   honkbal/       de app (code, vendor, .env, lege database)  -> naast public_html zetten
#   public_html/   de openbare bestanden                       -> inhoud in public_html zetten
#   INSTALLATIE.txt
#
# LET OP: de zip bevat een .env met een geheime APP_KEY en SETUP_TOKEN. Niet delen of in git zetten.
set -euo pipefail

URL="${1:?Geef de URL op, bijv. https://13base.com}"
OUT_DIR="${2:-$(pwd)/build}"
URL="${URL%/}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "→ Code exporteren (laatste commit)"
mkdir -p "$WORK/pakket/honkbal"
git -C "$ROOT" archive HEAD | tar -x -C "$WORK/pakket/honkbal"
cd "$WORK/pakket/honkbal"

echo "→ Composer-pakketten installeren (zonder dev)"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet

echo "→ Ontwikkelbestanden verwijderen"
rm -rf tests .github phpunit.xml .editorconfig .styleci.yml scripts CLAUDE.md

echo "→ .env voor productie maken"
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
SETUP_TOKEN="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32)"
cp .env.example .env
set_env() { # sleutel waarde
    if grep -q "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi
}
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "$URL"
set_env APP_KEY "$APP_KEY"
set_env SETUP_TOKEN "$SETUP_TOKEN"
set_env LOG_LEVEL warning
set_env SESSION_DRIVER file
set_env SESSION_LIFETIME 1440
set_env SESSION_SECURE_COOKIE true
set_env CACHE_STORE file
set_env QUEUE_CONNECTION sync

echo "→ Lege database aanmaken"
touch database/database.sqlite
php artisan migrate --force --no-interaction --quiet
php artisan optimize:clear --quiet

echo "→ public_html samenstellen"
mv public "$WORK/pakket/public_html"
INDEX="$WORK/pakket/public_html/index.php"
sed -i "s#__DIR__.'/../#__DIR__.'/../honkbal/#g" "$INDEX"
sed -i "s#^\$app->handleRequest#\$app->usePublicPath(__DIR__);\n\n\$app->handleRequest#" "$INDEX"
grep -q "usePublicPath" "$INDEX" || { echo "index.php aanpassen mislukt"; exit 1; }

cat > "$WORK/pakket/INSTALLATIE.txt" <<TXT
Honkbal Live – installatie op Hostinger (zonder SSH)
=====================================================

1. Maak een backup van de huidige site (hPanel → Bestanden → Backups).
2. Zet in hPanel de PHP-versie op 8.3 of hoger (Geavanceerd → PHP-configuratie).
3. Open Bestandsbeheer en ga naar de map van het domein (domains/<domein>/).
   - Maak een map "oude-site" en verplaats ALLES uit public_html daarheen.
4. Upload deze zip naar domains/<domein>/ en pak hem daar uit.
   - De map "honkbal" komt naast public_html te staan.
   - Verplaats de INHOUD van de uitgepakte map "public_html" naar de bestaande public_html
     (let op het verborgen bestand .htaccess).
5. Zet in hPanel "HTTPS forceren" aan (Beveiliging → SSL).
6. Open deze link en maak je account aan (bewaar hem; ook nodig na updates):

   $URL/installatie?token=$SETUP_TOKEN

7. Klaar. Inloggen kan daarna via $URL/inloggen
   Op je telefoon: open $URL in Chrome → ⋮ → "Toevoegen aan startscherm".

Geheim: bewaar deze zip en dit bestand veilig; deel ze niet.
TXT

mkdir -p "$OUT_DIR"
ZIP="$OUT_DIR/honkbal-live-hostinger.zip"
rm -f "$ZIP"
(cd "$WORK/pakket" && zip -qr "$ZIP" honkbal public_html INSTALLATIE.txt)
echo "✓ Klaar: $ZIP"
echo "  Installatielink: $URL/installatie?token=$SETUP_TOKEN"
