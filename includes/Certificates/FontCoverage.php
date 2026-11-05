<?php
/**
 * Which characters a font file has glyphs for.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Certificates;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the character map (`cmap`) of a TrueType font, or of an OpenType
 * font with TrueType outlines, so certificate fonts can be chosen by what
 * they actually cover (ADR-052).
 *
 * Only fonts mPDF can embed are accepted: an OpenType font with PostScript
 * (CFF) outlines, a font collection or a WOFF file is "not usable". The
 * Unicode subtables read are format 12 (all planes) and format 4 (the
 * Basic Multilingual Plane), which every current font has.
 */
final class FontCoverage {

	/**
	 * Parsed maps by file, for this request.
	 *
	 * @var array<string, array<int, array{0: int, 1: int}>|null>
	 */
	private static $maps = array();

	/**
	 * Whether mPDF can embed the file: a TrueType outline font with a Unicode map.
	 *
	 * @param string $file Font file.
	 * @return bool
	 */
	public static function usable( string $file ): bool {
		return null !== self::ranges( $file );
	}

	/**
	 * Returns the code points the font has no glyph for.
	 *
	 * @param string     $file        Font file.
	 * @param array<int> $code_points Code points.
	 * @return array<int>
	 */
	public static function missing( string $file, array $code_points ): array {
		$ranges = self::ranges( $file );
		if ( null === $ranges ) {
			return array_values( $code_points );
		}
		$missing = array();
		foreach ( $code_points as $code_point ) {
			if ( ! self::in_ranges( $ranges, (int) $code_point ) ) {
				$missing[] = (int) $code_point;
			}
		}
		return $missing;
	}

	/**
	 * Whether the font has a glyph for every code point.
	 *
	 * @param string     $file        Font file.
	 * @param array<int> $code_points Code points.
	 * @return bool
	 */
	public static function covers( string $file, array $code_points ): bool {
		return array() === self::missing( $file, $code_points );
	}

	/**
	 * Returns the font's mapped code points as sorted, merged ranges, or null when unusable.
	 *
	 * @param string $file Font file.
	 * @return array<int, array{0: int, 1: int}>|null
	 */
	public static function ranges( string $file ): ?array {
		if ( ! array_key_exists( $file, self::$maps ) ) {
			self::$maps[ $file ] = self::read( $file );
		}
		return self::$maps[ $file ];
	}

	/**
	 * Forgets parsed maps (tests replace files).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$maps = array();
	}

	/**
	 * Reads the map from the file.
	 *
	 * @param string $file Font file.
	 * @return array<int, array{0: int, 1: int}>|null
	 */
	private static function read( string $file ): ?array {
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return null;
		}
		$data = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local font file.
		if ( strlen( $data ) < 12 ) {
			return null;
		}
		// TrueType outlines only: 0x00010000 or 'true'. 'OTTO' is CFF, 'ttcf' a collection, 'wOFF'/'wOF2' web fonts.
		$version = substr( $data, 0, 4 );
		if ( "\x00\x01\x00\x00" !== $version && 'true' !== $version ) {
			return null;
		}
		$tables = self::u16( $data, 4 );
		$cmap   = -1;
		$glyf   = false;
		for ( $i = 0; $i < $tables; $i++ ) {
			$record = 12 + 16 * $i;
			$tag    = substr( $data, $record, 4 );
			if ( 'cmap' === $tag ) {
				$cmap = self::u32( $data, $record + 8 );
			} elseif ( 'glyf' === $tag ) {
				$glyf = true;
			}
		}
		if ( $cmap < 0 || ! $glyf ) {
			return null;
		}

		// Prefer a full-Unicode format 12 subtable, then a BMP format 4 one.
		$best     = -1;
		$best_fmt = 0;
		$count    = self::u16( $data, $cmap + 2 );
		for ( $i = 0; $i < $count; $i++ ) {
			$platform = self::u16( $data, $cmap + 4 + 8 * $i );
			$encoding = self::u16( $data, $cmap + 6 + 8 * $i );
			$offset   = $cmap + self::u32( $data, $cmap + 8 + 8 * $i );
			$unicode  = 0 === $platform || ( 3 === $platform && ( 1 === $encoding || 10 === $encoding ) );
			if ( ! $unicode || $offset + 2 > strlen( $data ) ) {
				continue;
			}
			$format = self::u16( $data, $offset );
			if ( 12 === $format && 12 !== $best_fmt ) {
				$best     = $offset;
				$best_fmt = 12;
			} elseif ( 4 === $format && 0 === $best_fmt ) {
				$best     = $offset;
				$best_fmt = 4;
			}
		}
		if ( $best < 0 ) {
			return null;
		}
		$ranges = 12 === $best_fmt ? self::format12( $data, $best ) : self::format4( $data, $best );
		return self::merge( $ranges );
	}

	/**
	 * Reads a format 4 subtable (segments of the BMP).
	 *
	 * @param string $data   Font bytes.
	 * @param int    $offset Subtable offset.
	 * @return array<int, array{0: int, 1: int}>
	 */
	private static function format4( string $data, int $offset ): array {
		$segments = (int) ( self::u16( $data, $offset + 6 ) / 2 );
		$ends     = $offset + 14;
		$starts   = $ends + 2 * $segments + 2;
		$deltas   = $starts + 2 * $segments;
		$range_at = $deltas + 2 * $segments;
		$ranges   = array();
		for ( $s = 0; $s < $segments; $s++ ) {
			$end   = self::u16( $data, $ends + 2 * $s );
			$start = self::u16( $data, $starts + 2 * $s );
			$delta = self::u16( $data, $deltas + 2 * $s );
			$roff  = self::u16( $data, $range_at + 2 * $s );
			if ( 0xFFFF === $start || $start > $end ) {
				continue;
			}
			if ( 0 === $roff ) {
				// Glyph = (code + delta) mod 65536; only the code mapping to glyph 0 is missing.
				$zero = ( 0x10000 - $delta ) & 0xFFFF;
				if ( $zero >= $start && $zero <= $end ) {
					if ( $zero > $start ) {
						$ranges[] = array( $start, $zero - 1 );
					}
					if ( $zero < $end ) {
						$ranges[] = array( $zero + 1, $end );
					}
				} else {
					$ranges[] = array( $start, $end );
				}
				continue;
			}
			$run = -1;
			for ( $code = $start; $code <= $end; $code++ ) {
				$at    = $range_at + 2 * $s + $roff + 2 * ( $code - $start );
				$glyph = $at + 2 <= strlen( $data ) ? self::u16( $data, $at ) : 0;
				if ( 0 !== $glyph ) {
					$glyph = ( $glyph + $delta ) & 0xFFFF;
				}
				if ( 0 !== $glyph && $run < 0 ) {
					$run = $code;
				} elseif ( 0 === $glyph && $run >= 0 ) {
					$ranges[] = array( $run, $code - 1 );
					$run      = -1;
				}
			}
			if ( $run >= 0 ) {
				$ranges[] = array( $run, $end );
			}
		}
		return $ranges;
	}

	/**
	 * Reads a format 12 subtable (groups over all planes).
	 *
	 * @param string $data   Font bytes.
	 * @param int    $offset Subtable offset.
	 * @return array<int, array{0: int, 1: int}>
	 */
	private static function format12( string $data, int $offset ): array {
		$groups = self::u32( $data, $offset + 12 );
		$ranges = array();
		for ( $g = 0; $g < $groups; $g++ ) {
			$at = $offset + 16 + 12 * $g;
			if ( $at + 12 > strlen( $data ) ) {
				break;
			}
			$start = self::u32( $data, $at );
			$end   = self::u32( $data, $at + 4 );
			$glyph = self::u32( $data, $at + 8 );
			if ( 0 === $glyph ) {
				++$start; // The group's first code maps to .notdef.
			}
			if ( $start <= $end ) {
				$ranges[] = array( $start, $end );
			}
		}
		return $ranges;
	}

	/**
	 * Sorts and merges ranges.
	 *
	 * @param array<int, array{0: int, 1: int}> $ranges Ranges.
	 * @return array<int, array{0: int, 1: int}>
	 */
	private static function merge( array $ranges ): array {
		usort(
			$ranges,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);
		$merged = array();
		foreach ( $ranges as $range ) {
			$last = count( $merged ) - 1;
			if ( $last >= 0 && $range[0] <= $merged[ $last ][1] + 1 ) {
				$merged[ $last ][1] = max( $merged[ $last ][1], $range[1] );
			} else {
				$merged[] = $range;
			}
		}
		return $merged;
	}

	/**
	 * Binary search for a code point.
	 *
	 * @param array<int, array{0: int, 1: int}> $ranges     Sorted ranges.
	 * @param int                               $code_point Code point.
	 * @return bool
	 */
	private static function in_ranges( array $ranges, int $code_point ): bool {
		$low  = 0;
		$high = count( $ranges ) - 1;
		while ( $low <= $high ) {
			$mid = ( $low + $high ) >> 1;
			if ( $code_point < $ranges[ $mid ][0] ) {
				$high = $mid - 1;
			} elseif ( $code_point > $ranges[ $mid ][1] ) {
				$low = $mid + 1;
			} else {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads a big-endian unsigned 16-bit number.
	 *
	 * @param string $data   Bytes.
	 * @param int    $offset Offset.
	 * @return int
	 */
	private static function u16( string $data, int $offset ): int {
		if ( $offset < 0 || $offset + 2 > strlen( $data ) ) {
			return 0;
		}
		return ( ord( $data[ $offset ] ) << 8 ) | ord( $data[ $offset + 1 ] );
	}

	/**
	 * Reads a big-endian unsigned 32-bit number.
	 *
	 * @param string $data   Bytes.
	 * @param int    $offset Offset.
	 * @return int
	 */
	private static function u32( string $data, int $offset ): int {
		return ( self::u16( $data, $offset ) << 16 ) | self::u16( $data, $offset + 2 );
	}
}
