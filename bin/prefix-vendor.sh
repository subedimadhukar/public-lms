#!/usr/bin/env bash
#
# Builds vendor-prefixed/: mPDF and its dependencies copied under the
# PublicLMS\Vendor namespace with Strauss, so they never clash with another
# plugin's copy (ADR-013, ADR-044, ADR-052), then patched and trimmed.
# Run it after changing a version in tools/prefix/composer.json, and before
# building a release zip (composer prefix runs it).
#
# Needs PHP 8 and Composer 2 on the machine that runs it. The plugin itself
# keeps its PHP 7.4 Composer platform; Strauss lives in tools/strauss.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

composer install --working-dir="$ROOT/tools/strauss" --no-interaction --quiet
composer install --working-dir="$ROOT/tools/prefix" --no-interaction --quiet --no-plugins --no-scripts

(
	cd "$ROOT/tools/prefix"
	rm -rf vendor-prefixed
	# mPDF's main class is 27,000 lines; parsing it needs more than PHP's default 128 MB.
	php -d memory_limit=1G ../strauss/vendor/bin/strauss
)

rm -rf "$ROOT/vendor-prefixed"
mv "$ROOT/tools/prefix/vendor-prefixed" "$ROOT/vendor-prefixed"

MPDF="$ROOT/vendor-prefixed/mpdf/mpdf"

# Teach mPDF's OpenType reader and shaper the structures current Noto fonts
# use (GSUB 5.3, mark filtering sets, multiple-glyph Arabic forms). Each
# patch must apply exactly once, or the build stops.
php "$ROOT/bin/patch-mpdf.php" "$MPDF/src"

# Trim what certificates never use. Fonts: keep DejaVu Sans and Serif,
# regular and bold, for Latin, Greek and Cyrillic; other scripts use the
# Noto fonts in assets/fonts/noto/ or the site's own fonts. Collations only
# sort an index; mPDF's own tmp/ is replaced by a folder in the temp dir.
find "$MPDF/ttfonts" -type f ! -name 'DejaVuSans.ttf' ! -name 'DejaVuSans-Bold.ttf' ! -name 'DejaVuSerif.ttf' ! -name 'DejaVuSerif-Bold.ttf' ! -name 'DejaVuinfo.txt' -delete
rm -rf "$MPDF/data/collations" "$MPDF/tmp"

echo "vendor-prefixed/ rebuilt: $(du -sh "$ROOT/vendor-prefixed" | cut -f1)"
