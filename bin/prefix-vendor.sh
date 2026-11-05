#!/usr/bin/env bash
#
# Builds vendor-prefixed/: mPDF and its dependencies copied under the
# PublicLMS\Vendor namespace with Strauss, so they never clash with another
# plugin's copy (ADR-013, ADR-044, ADR-052). `composer prefix` runs it; CI
# runs it before tests and the release zip (ADR-053).
#
# 1. A fresh mPDF from Composer (its cache, after the first run), pinned at
#    exactly 8.3.1 in tools/prefix/composer.json.
# 2. tools/prefix/mpdf-8.3.1-publiclms.patch, applied with `git apply`: any
#    hunk that no longer applies stops the build.
# 3. Strauss prefixes the patched copy.
# 4. mPDF's fonts are trimmed to DejaVu Sans and Serif, regular and bold.
#
# Needs PHP 8 and Composer 2 on the machine that runs it. The plugin itself
# keeps its PHP 7.4 Composer platform; Strauss lives in tools/strauss.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PREFIX="$ROOT/tools/prefix"
MPDF_VERSION='v8.3.1'
PATCH="$PREFIX/mpdf-8.3.1-publiclms.patch"

composer install --working-dir="$ROOT/tools/strauss" --no-interaction --quiet

# Always patch clean code: reinstall the packages rather than reuse a patched copy.
rm -rf "$PREFIX/packages"
composer install --working-dir="$PREFIX" --no-interaction --quiet --no-plugins --no-scripts

installed="$(php -r '$j = json_decode( file_get_contents( $argv[1] ), true ); foreach ( $j["packages"] ?? $j as $p ) { if ( "mpdf/mpdf" === $p["name"] ) { echo $p["version"]; } }' "$PREFIX/packages/composer/installed.json")"
if [ "$installed" != "$MPDF_VERSION" ]; then
	echo "mPDF is $installed, but the patch is written for $MPDF_VERSION. Update and re-test the patch (ADR-052)." >&2
	exit 1
fi

(
	cd "$ROOT"
	if ! git apply --check --directory=tools/prefix/packages/mpdf/mpdf "$PATCH"; then
		echo "The mPDF patch no longer applies to mPDF $MPDF_VERSION. Nothing was built (ADR-052)." >&2
		exit 1
	fi
	git apply --directory=tools/prefix/packages/mpdf/mpdf "$PATCH"
	echo "Applied $(basename "$PATCH")."
)

(
	cd "$PREFIX"
	rm -rf vendor-prefixed
	# mPDF's main class is 27,000 lines; parsing it needs more than PHP's default 128 MB.
	php -d memory_limit=1G ../strauss/vendor/bin/strauss
)

rm -rf "$ROOT/vendor-prefixed"
mv "$PREFIX/vendor-prefixed" "$ROOT/vendor-prefixed"

# Trim what certificates never use. Fonts: keep DejaVu Sans and Serif,
# regular and bold, for Latin, Greek and Cyrillic; other scripts use the
# Noto fonts in assets/fonts/noto/ or the site's own fonts. Collations only
# sort an index; mPDF's own tmp/ is replaced by a folder in the temp dir.
MPDF="$ROOT/vendor-prefixed/mpdf/mpdf"
find "$MPDF/ttfonts" -type f ! -name 'DejaVuSans.ttf' ! -name 'DejaVuSans-Bold.ttf' ! -name 'DejaVuSerif.ttf' ! -name 'DejaVuSerif-Bold.ttf' ! -name 'DejaVuinfo.txt' -delete
rm -rf "$MPDF/data/collations" "$MPDF/tmp"

echo "vendor-prefixed/ rebuilt with the mPDF $MPDF_VERSION patch: $(du -sh "$ROOT/vendor-prefixed" | cut -f1)"
