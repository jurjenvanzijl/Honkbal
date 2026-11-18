#!/usr/bin/env bash
#
# Maakt een zip om Honkbal Live te installeren op hosting zonder SSH (bijv. Hostinger),
# alleen met Bestandsbeheer.
#
# Gebruik:  scripts/maak-hostinger-pakket.sh https://jouwdomein.nl [uitvoermap]
#
# Uitvoer (in de uitvoermap):
#   honkbal-live-hostinger.zip   app (code, vendor, .env ZONDER geheimen, lege database) + public_html
#   uitpakken.php                pakt de zip op de server uit en maakt daar pas APP_KEY en SETUP_TOKEN aan
#
# De zip bevat bewust geen geheimen: als hij even openbaar in public_html staat, is dat geen lek.
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
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --quiet
# Soms installeert Composer vanuit git; die geschiedenis hoort niet in het pakket.
find vendor -type d -name .git -prune -exec rm -rf {} +

echo "→ Ontwikkelbestanden verwijderen"
rm -rf tests .github phpunit.xml .editorconfig .styleci.yml scripts CLAUDE.md

echo "→ .env voor productie maken (geheimen worden op de server aangemaakt door uitpakken.php)"
cp .env.example .env
set_env() { # sleutel waarde
    if grep -q "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi
}
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "$URL"
set_env APP_KEY ""
set_env SETUP_TOKEN ""
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
Honkbal Live – installatie op Hostinger (ook vanaf je telefoon)
================================================================

1. Maak een backup van de huidige site (hPanel → Backups).
2. Zet in hPanel de PHP-versie op 8.3 of hoger.
3. Upload honkbal-live-hostinger.zip en uitpakken.php naar public_html (NIET zelf uitpakken).
4. Open $URL/uitpakken.php en volg de stappen.
5. Zet in hPanel "HTTPS forceren" aan en maak via de getoonde link je account aan.
TXT

mkdir -p "$OUT_DIR"
ZIP="$OUT_DIR/honkbal-live-hostinger.zip"
rm -f "$ZIP"
(cd "$WORK/pakket" && zip -qr "$ZIP" honkbal public_html INSTALLATIE.txt)
cp "$ROOT/scripts/hostinger/uitpakken.php" "$OUT_DIR/uitpakken.php"
cp "$WORK/pakket/INSTALLATIE.txt" "$OUT_DIR/INSTALLATIE.txt"
echo "✓ Klaar: $ZIP"
echo "         $OUT_DIR/uitpakken.php"
