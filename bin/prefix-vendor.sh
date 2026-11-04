#!/usr/bin/env bash
#
# Rebuilds vendor-prefixed/: Dompdf and its dependencies copied under the
# PublicLMS\Vendor namespace with Strauss, so they never clash with another
# plugin's copy (ADR-013, ADR-044). The result is committed; run this only
# after changing a version in tools/prefix/composer.json.
#
# Needs PHP 8 and Composer 2 on the machine that runs it. The plugin itself
# keeps its PHP 7.4 Composer platform; Strauss lives in tools/strauss.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

composer install --working-dir="$ROOT/tools/strauss" --no-interaction --quiet
composer install --working-dir="$ROOT/tools/prefix" --no-interaction --quiet

(
	cd "$ROOT/tools/prefix"
	rm -rf vendor-prefixed
	../strauss/vendor/bin/strauss
)

rm -rf "$ROOT/vendor-prefixed"
mv "$ROOT/tools/prefix/vendor-prefixed" "$ROOT/vendor-prefixed"

# Strauss cannot see two class names php-font-lib builds at run time: a string
# that starts with "FontLib\$type", and getFontType(), which reads the
# namespace by position. Point both at the prefixed classes.
FONTLIB="$ROOT/vendor-prefixed/dompdf/php-font-lib/src/FontLib/TrueType/File.php"
php -r '$f = $argv[1]; $s = file_get_contents( $f );
$s = str_replace( "\"FontLib\\\\\$type\\\\TableDirectoryEntry\"", "\"PublicLMS\\\\Vendor\\\\FontLib\\\\\$type\\\\TableDirectoryEntry\"", $s, $a );
$s = str_replace( "return \$class_parts[1];", "return \$class_parts[ count( \$class_parts ) - 2 ];", $s, $b );
if ( 1 !== $a || 1 !== $b ) { fwrite( STDERR, "php-font-lib patch did not apply\n" ); exit( 1 ); }
file_put_contents( $f, $s );' "$FONTLIB"

# Trim what certificates never use: the monospace fonts and a font how-to.
FONTS="$ROOT/vendor-prefixed/dompdf/dompdf/lib/fonts"
rm -f "$FONTS"/DejaVuSansMono* "$FONTS/mustRead.html"
php -r '$f = $argv[1]; $j = json_decode( file_get_contents( $f ), true ); unset( $j["dejavu sans mono"] ); file_put_contents( $f, json_encode( $j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );' "$FONTS/installed-fonts.dist.json"

echo "vendor-prefixed/ rebuilt: $(du -sh "$ROOT/vendor-prefixed" | cut -f1)"
