<?php
/**
 * Fonts for certificate PDFs.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Certificates;

use PublicLMS\Vendor\Mpdf\Language\ScriptToLanguage;
use PublicLMS\Vendor\Mpdf\Ucdn;
use ReflectionClass;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Chooses a font for every script a certificate's text uses (ADR-052).
 *
 * Fonts are tried in this order, and a script gets the first font that has
 * a glyph for every one of its characters in the text:
 * 1. the bundled fonts: Noto Sans for Arabic, Hebrew, Devanagari, Bengali,
 *    Tamil and Thai (assets/fonts/noto/), then DejaVu Sans, which also
 *    covers Latin, Greek, Cyrillic, Armenian, Georgian and more;
 * 2. the site's Font Library fonts (`wp_font_face`) stored as TTF, or as OTF
 *    with TrueType outlines; WOFF and WOFF2 files cannot be embedded;
 * 3. fonts added with the `publiclms_certificate_fonts` filter.
 * Chinese, Japanese and Korean text that no font covers falls back to
 * mPDF's Adobe CJK fonts, which embed nothing and are drawn by the reader's
 * PDF viewer. Any other script no font covers is "missing": the Certificate
 * screen and Site Health name it.
 *
 * mPDF marks each run of non-Latin text with a language (its script-to-
 * language table); `LanguageToFont` then answers with the font chosen here.
 * Latin text keeps the stylesheet's DejaVu Sans or Serif.
 */
class CertificateFonts {

	/**
	 * Bundled Noto families by script, as mPDF font keys with their files.
	 *
	 * @var array<int, array{key: string, name: string, R: string, B: string}>
	 */
	private static $noto = array();

	/**
	 * Returns the bundled Noto families by Ucdn script.
	 *
	 * @return array<int, array{key: string, name: string, R: string, B: string}>
	 */
	public static function noto(): array {
		if ( array() === self::$noto ) {
			$families = array(
				Ucdn::SCRIPT_ARABIC     => 'Arabic',
				Ucdn::SCRIPT_HEBREW     => 'Hebrew',
				Ucdn::SCRIPT_DEVANAGARI => 'Devanagari',
				Ucdn::SCRIPT_BENGALI    => 'Bengali',
				Ucdn::SCRIPT_TAMIL      => 'Tamil',
				Ucdn::SCRIPT_THAI       => 'Thai',
			);
			foreach ( $families as $script => $family ) {
				self::$noto[ $script ] = array(
					'key'  => 'notosans' . strtolower( $family ),
					'name' => 'Noto Sans ' . $family,
					'R'    => self::noto_dir() . "NotoSans{$family}-Regular.ttf",
					'B'    => self::noto_dir() . "NotoSans{$family}-Bold.ttf",
				);
			}
		}
		return self::$noto;
	}

	/**
	 * Folder of the bundled Noto fonts.
	 *
	 * @return string
	 */
	public static function noto_dir(): string {
		return PUBLICLMS_PATH . 'assets/fonts/noto/';
	}

	/**
	 * Folder of DejaVu, inside the prefixed mPDF.
	 *
	 * @return string
	 */
	public static function dejavu_dir(): string {
		return PUBLICLMS_PATH . 'vendor-prefixed/mpdf/mpdf/ttfonts/';
	}

	/**
	 * Returns the extra fonts: Font Library faces, then the filter's.
	 *
	 * Each font is a family: a name and up to four files by style
	 * (R regular, B bold, I italic, BI bold italic), absolute paths.
	 *
	 * @return array<string, array{name: string, R: string, B?: string, I?: string, BI?: string}> By mPDF font key.
	 */
	public function extra(): array {
		$fonts = $this->font_library();

		/**
		 * Filters the fonts certificates may use after the bundled ones.
		 *
		 * The list starts with the site's Font Library fonts stored as TTF, or as
		 * OTF with TrueType outlines. Fonts are tried in order: append a font to
		 * add a fallback, prepend it to win over the Font Library. A font is
		 * `array( 'name' => 'Noto Sans Sinhala', 'R' => '/path/Regular.ttf', 'B' => '/path/Bold.ttf' )`;
		 * `B`, `I` and `BI` are optional.
		 *
		 * @param array<int, array<string, string>> $fonts Fonts.
		 */
		$fonts = (array) apply_filters( 'publiclms_certificate_fonts', $fonts );

		$out = array();
		foreach ( $fonts as $font ) {
			if ( ! is_array( $font ) ) {
				continue;
			}
			$files = array();
			foreach ( array( 'R', 'B', 'I', 'BI' ) as $style ) {
				$file = isset( $font[ $style ] ) ? wp_normalize_path( (string) $font[ $style ] ) : '';
				if ( '' !== $file && FontCoverage::usable( $file ) ) {
					$files[ $style ] = $file;
				}
			}
			if ( ! isset( $files['R'] ) ) {
				if ( array() === $files ) {
					continue;
				}
				$files['R'] = (string) reset( $files );
			}
			$stamp = '';
			foreach ( $files as $file ) {
				$stamp .= $file . '|' . (int) filesize( $file ) . '|' . (int) filemtime( $file ) . ';';
			}
			$key = 'plmsfont' . substr( md5( $stamp ), 0, 12 );
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array( 'name' => (string) ( $font['name'] ?? $key ) ) + $files;
			}
		}
		return $out;
	}

	/**
	 * Reads the Font Library's TTF and OTF faces, grouped by family.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function font_library(): array {
		if ( ! function_exists( 'wp_get_font_dir' ) || ! post_type_exists( 'wp_font_face' ) ) {
			return array();
		}
		$dir      = wp_get_font_dir();
		$base     = (string) $dir['url'];
		$path     = (string) $dir['path'];
		$query    = new WP_Query(
			array(
				'post_type'              => 'wp_font_face',
				'post_status'            => 'publish',
				'posts_per_page'         => 100,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
				'lang'                   => '',
			)
		);
		$families = array();
		foreach ( $query->posts as $face ) {
			if ( ! $face instanceof \WP_Post ) {
				continue;
			}
			$settings = json_decode( (string) $face->post_content, true );
			if ( ! is_array( $settings ) ) {
				continue;
			}
			$file = '';
			foreach ( (array) ( $settings['src'] ?? array() ) as $src ) {
				$src = (string) $src;
				if ( 0 === strpos( $src, $base ) && preg_match( '/\.(ttf|otf)$/i', $src ) ) {
					$file = $path . substr( $src, strlen( $base ) );
					break;
				}
			}
			if ( '' === $file || false !== strpos( $file, '..' ) ) {
				continue;
			}
			$weight = (string) ( $settings['fontWeight'] ?? '400' );
			$bold   = is_numeric( $weight ) && (int) $weight >= 600;
			$italic = 'italic' === ( $settings['fontStyle'] ?? '' ) || 'oblique' === ( $settings['fontStyle'] ?? '' );
			$style  = ( $bold ? 'B' : '' ) . ( $italic ? 'I' : '' );
			$style  = '' === $style ? 'R' : $style;
			$parent = (int) $face->post_parent;
			if ( ! isset( $families[ $parent ] ) ) {
				$families[ $parent ] = array( 'name' => $parent > 0 ? (string) get_the_title( $parent ) : trim( (string) ( $settings['fontFamily'] ?? '' ), '"\' ' ) );
			}
			if ( ! isset( $families[ $parent ][ $style ] ) ) {
				$families[ $parent ][ $style ] = $file;
			}
		}
		return array_values( $families );
	}

	/**
	 * Chooses fonts for a text.
	 *
	 * @param string $text Text or HTML to print.
	 * @return array{fontdata: array<string, array<string, mixed>>, font_dirs: array<int, string>, languages: array<string, string>, missing: array<int, string>, viewer: array<int, string>}
	 */
	public function plan( string $text ): array {
		$dejavu   = self::dejavu_dir() . 'DejaVuSans.ttf';
		$fontdata = array(
			'dejavusans'  => array(
				'R'          => 'DejaVuSans.ttf',
				'B'          => 'DejaVuSans-Bold.ttf',
				'useOTL'     => 0xFF,
				'useKashida' => 75,
			),
			'dejavuserif' => array(
				'R' => 'DejaVuSerif.ttf',
				'B' => 'DejaVuSerif-Bold.ttf',
			),
		);
		$dirs     = array( rtrim( self::dejavu_dir(), '/' ), rtrim( self::noto_dir(), '/' ) );
		$extra    = $this->extra();
		$by_cjk   = array( Ucdn::SCRIPT_HAN, Ucdn::SCRIPT_HIRAGANA, Ucdn::SCRIPT_KATAKANA, Ucdn::SCRIPT_HANGUL, Ucdn::SCRIPT_BOPOMOFO );
		$scripts  = self::scripts( $text );
		$chosen   = array();
		$missing  = array();
		$viewer   = array();

		foreach ( $scripts as $script => $code_points ) {
			// The chain: the script's Noto font, DejaVu Sans, then the extra fonts in order.
			// Latin is mPDF's base script and never handed to another font, so DejaVu alone serves it.
			$chain = array();
			if ( isset( self::noto()[ $script ] ) ) {
				$chain[ self::noto()[ $script ]['key'] ] = self::noto()[ $script ]['R'];
			}
			$chain['dejavusans'] = $dejavu;
			foreach ( Ucdn::SCRIPT_LATIN === $script ? array() : $extra as $key => $font ) {
				$chain[ $key ] = $font['R'];
			}

			$pick       = '';
			$best       = '';
			$best_count = -1;
			foreach ( $chain as $key => $file ) {
				$lacks = count( FontCoverage::missing( $file, $code_points ) );
				if ( 0 === $lacks ) {
					$pick = $key;
					break;
				}
				if ( count( $code_points ) - $lacks > $best_count ) {
					$best       = $key;
					$best_count = count( $code_points ) - $lacks;
				}
			}

			if ( '' === $pick && in_array( $script, $by_cjk, true ) ) {
				$viewer[] = self::script_name( $script );
				$pick     = 'adobe-cjk';
			} elseif ( '' === $pick ) {
				$missing[] = self::script_name( $script );
				$pick      = $best_count > 0 ? $best : 'dejavusans';
			}
			$chosen[ $script ] = $pick;
		}

		// Font data and folders for what was chosen.
		foreach ( $chosen as $key ) {
			foreach ( self::noto() as $font ) {
				if ( $font['key'] === $key ) {
					$fontdata[ $key ] = array(
						'R'          => basename( $font['R'] ),
						'B'          => basename( $font['B'] ),
						'useOTL'     => 0xFF,
						'useKashida' => 'notosansarabic' === $key ? 75 : 0,
					);
				}
			}
			if ( isset( $extra[ $key ] ) && ! isset( $fontdata[ $key ] ) ) {
				$entry = array( 'useOTL' => 0xFF );
				foreach ( array( 'R', 'B', 'I', 'BI' ) as $style ) {
					if ( ! isset( $extra[ $key ][ $style ] ) ) {
						continue;
					}
					$file = $extra[ $key ][ $style ];
					$dir  = dirname( $file );
					if ( ! in_array( $dir, $dirs, true ) ) {
						$dirs[] = $dir;
					}
					$entry[ $style ] = basename( $file );
				}
				$fontdata[ $key ] = $entry;
			}
		}

		// mPDF finds a file by name, folder by folder: drop a style another folder's same-named file would shadow.
		foreach ( $extra as $key => $font ) {
			if ( ! isset( $fontdata[ $key ] ) ) {
				continue;
			}
			foreach ( array( 'R', 'B', 'I', 'BI' ) as $style ) {
				if ( isset( $font[ $style ] ) && self::find( $dirs, basename( $font[ $style ] ) ) !== $font[ $style ] ) {
					unset( $fontdata[ $key ][ $style ] );
				}
			}
			if ( ! isset( $fontdata[ $key ]['R'] ) ) {
				unset( $fontdata[ $key ] );
				foreach ( array_keys( $chosen, $key, true ) as $script ) {
					$chosen[ $script ] = 'dejavusans';
					$missing[]         = self::script_name( (int) $script );
				}
			}
		}

		return array(
			'fontdata'  => $fontdata,
			'font_dirs' => $dirs,
			'languages' => self::languages( $chosen, isset( $scripts[ Ucdn::SCRIPT_HIRAGANA ] ) || isset( $scripts[ Ucdn::SCRIPT_KATAKANA ] ) ),
			'missing'   => array_values( array_unique( $missing ) ),
			'viewer'    => array_values( array_unique( $viewer ) ),
		);
	}

	/**
	 * Maps mPDF's language tags to the chosen fonts.
	 *
	 * @param array<int, string> $chosen   Font key by script.
	 * @param bool               $japanese Whether the text has kana, so Han is drawn as Japanese.
	 * @return array<string, string>
	 */
	private static function languages( array $chosen, bool $japanese ): array {
		$tags      = new ScriptToLanguage();
		$languages = array();
		foreach ( $chosen as $script => $key ) {
			$tag = (string) $tags->getLanguageByScript( $script );
			if ( '' === $tag ) {
				continue;
			}
			if ( 'adobe-cjk' === $key ) {
				$key = Ucdn::SCRIPT_HANGUL === $script ? 'uhc' : ( Ucdn::SCRIPT_HAN === $script && ! $japanese ? 'gb' : 'sjis' );
			} elseif ( 'dejavusans' === $key && in_array( $script, array( Ucdn::SCRIPT_LATIN, Ucdn::SCRIPT_GREEK, Ucdn::SCRIPT_CYRILLIC ), true ) ) {
				$key = ''; // Keep the stylesheet's DejaVu Sans or Serif.
			}
			$languages[ strtolower( $tag ) ] = $key; // mPDF lowercases lang attributes before asking.
			if ( Ucdn::SCRIPT_ARABIC === $script ) {
				// mPDF tags Persian, Urdu, Pashto and Sindhi by their letters.
				foreach ( array( 'fa', 'ur', 'ps', 'sd' ) as $arabic ) {
					$languages[ $arabic ] = $key;
				}
			}
		}
		return $languages;
	}

	/**
	 * Groups a text's characters by script, leaving out common and inherited characters.
	 *
	 * @param string $text Text or HTML.
	 * @return array<int, array<int, int>> Code points by Ucdn script.
	 */
	public static function scripts( string $text ): array {
		$text    = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$scripts = array();
		foreach ( self::code_points( $text ) as $code_point ) {
			$script = (int) Ucdn::get_script( $code_point );
			if ( Ucdn::SCRIPT_COMMON === $script || Ucdn::SCRIPT_INHERITED === $script || Ucdn::SCRIPT_UNKNOWN === $script ) {
				continue;
			}
			$scripts[ $script ][] = $code_point;
		}
		return $scripts;
	}

	/**
	 * Returns the distinct code points of a UTF-8 string.
	 *
	 * @param string $text Text.
	 * @return array<int, int>
	 */
	private static function code_points( string $text ): array {
		$points = array();
		$length = strlen( $text );
		for ( $i = 0; $i < $length; ) {
			$byte = ord( $text[ $i ] );
			if ( $byte < 0x80 ) {
				$points[ $byte ] = true;
				++$i;
				continue;
			}
			if ( $byte >= 0xF0 ) {
				$code  = $byte & 0x07;
				$bytes = 4;
			} elseif ( $byte >= 0xE0 ) {
				$code  = $byte & 0x0F;
				$bytes = 3;
			} elseif ( $byte >= 0xC0 ) {
				$code  = $byte & 0x1F;
				$bytes = 2;
			} else {
				++$i; // A stray continuation byte.
				continue;
			}
			for ( $k = 1; $k < $bytes && $i + $k < $length; $k++ ) {
				$code = ( $code << 6 ) | ( ord( $text[ $i + $k ] ) & 0x3F );
			}
			$points[ $code ] = true;
			$i              += $bytes;
		}
		return array_keys( $points );
	}

	/**
	 * Returns a script's English name from Ucdn's constant, e.g. "Sinhala" or "Old Italic".
	 *
	 * @param int $script Ucdn script.
	 * @return string
	 */
	public static function script_name( int $script ): string {
		static $names = null;
		if ( null === $names ) {
			$names = array();
			foreach ( ( new ReflectionClass( Ucdn::class ) )->getConstants() as $name => $value ) {
				if ( 0 === strpos( $name, 'SCRIPT_' ) && ! isset( $names[ $value ] ) ) {
					$names[ $value ] = ucwords( strtolower( str_replace( '_', ' ', substr( $name, 7 ) ) ) );
				}
			}
		}
		return $names[ $script ] ?? (string) $script;
	}

	/**
	 * Returns the file mPDF would load for a font file name: the first folder that has it.
	 *
	 * @param array<int, string> $dirs Folders, in search order.
	 * @param string             $name File name.
	 * @return string
	 */
	private static function find( array $dirs, string $name ): string {
		foreach ( $dirs as $dir ) {
			if ( file_exists( $dir . '/' . $name ) ) {
				return $dir . '/' . $name;
			}
		}
		return '';
	}
}
