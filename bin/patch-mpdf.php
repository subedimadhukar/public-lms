<?php
/**
 * Patches mPDF 8.3 for certificates (ADR-052): its OpenType layout learns
 * what current Noto fonts use, and WebP images stop needing GD.
 *
 * 1. GSUB lookup type 5 (contextual substitution) in format 3, coverage
 *    based. mPDF parses the chaining twin (type 6, format 3) and even builds
 *    type 5 format 3 into its substitution arrays, but its table reader and
 *    shaper stop with "not supported" / "not tested". The new code reads
 *    and applies it as type 6 format 3 without backtrack or lookahead.
 * 2. Mark filtering sets (GDEF MarkGlyphSetsDef, lookup flag 0x0010).
 *    The code exists behind "not supported" exceptions. Removing the guards
 *    exposes two bugs that are fixed here: each set's coverage offset is
 *    relative to the MarkGlyphSetsDef table, and the stored string must be
 *    the marks to ignore, i.e. every mark except those in the set (as mPDF
 *    already does for mark attachment classes). The "set not given" check
 *    is made strict, because set 0 == '' is true on PHP 7.
 * 3. Two crashes in the reader: contextual lookups that call contextual
 *    lookups (a TypeError on PHP 8 with Noto Sans Bengali) and contextual
 *    rules under the Indic `locl` feature.
 * 4. Arabic joining forms that are 1-to-n substitutions (Noto's Farsi yeh
 *    and kaf: a dotless body plus a dots mark), which the shaper read as one
 *    code point and drew blank.
 * 5. WebP images on a server without GD: left out, not a fatal error.
 *
 * Usage: php patch-mpdf.php <path to mPDF's src folder>
 * Each change must apply exactly once; anything else stops with an error,
 * so an mPDF update cannot silently drop a patch. Namespaces are matched
 * loosely so the patch works before and after Strauss prefixing.
 */

$src = rtrim( (string) ( $argv[1] ?? '' ), '/' );
if ( ! is_file( $src . '/TTFontFile.php' ) || ! is_file( $src . '/Otl.php' ) ) {
	fwrite( STDERR, "Usage: php patch-mpdf.php <mPDF src folder>\n" );
	exit( 1 );
}

/**
 * Applies one regex replacement exactly once, or stops.
 *
 * @param string   $file     File.
 * @param string   $label    What the patch does.
 * @param string   $pattern  PCRE pattern.
 * @param callable $callback Builds the replacement.
 * @param int      $expected How many matches there must be.
 */
function publiclms_patch( string $file, string $label, string $pattern, callable $callback, int $expected = 1 ): void {
	$code = (string) file_get_contents( $file );
	if ( false !== strpos( $code, 'PublicLMS patch: ' . $label ) ) {
		echo "already patched: $label\n";
		return;
	}
	$new = preg_replace_callback( $pattern, $callback, $code, -1, $count );
	if ( $expected !== $count || null === $new ) {
		fwrite( STDERR, "mPDF patch did not apply ($count matches): $label in " . basename( $file ) . "\n" );
		exit( 1 );
	}
	file_put_contents( $file, $new );
	echo "patched: $label\n";
}

$ttf = $src . '/TTFontFile.php';
$otl = $src . '/Otl.php';

// TTFontFile: read GSUB 5.3 subtables (counts, coverage offsets, lookup records).
publiclms_patch(
	$ttf,
	'read GSUB 5.3',
	'/(\} else \{\s*throw new [\\\\\w]+FontException\("GPOS Lookup Type " \. \$Lookup\[\$i\]\[\'Type\'\] \. ", Format " \. \$SubstFormat \. " not supported \(ttfontsuni\.php\)\."\);)/',
	static function ( $m ) {
		return '} // Format 3: Coverage-based Context Glyph Substitution. PublicLMS patch: read GSUB 5.3
					elseif ($SubstFormat == 3) {
						$Lookup[$i][\'Subtable\'][$c][\'InputGlyphCount\'] = $this->read_ushort();
						$Lookup[$i][\'Subtable\'][$c][\'SubstCount\'] = $this->read_ushort();
						for ($b = 0; $b < $Lookup[$i][\'Subtable\'][$c][\'InputGlyphCount\']; $b++) {
							$Lookup[$i][\'Subtable\'][$c][\'CoverageInput\'][] = $Lookup[$i][\'Subtable\'][$c][\'Offset\'] + $this->read_ushort();
						}
						for ($b = 0; $b < $Lookup[$i][\'Subtable\'][$c][\'SubstCount\']; $b++) {
							$Lookup[$i][\'Subtable\'][$c][\'SubstLookupRecord\'][$b][\'SequenceIndex\'] = $this->read_ushort();
							$Lookup[$i][\'Subtable\'][$c][\'SubstLookupRecord\'][$b][\'LookupListIndex\'] = $this->read_ushort();
						}
						$Lookup[$i][\'Subtable\'][$c][\'BacktrackGlyphCount\'] = 0;
						$Lookup[$i][\'Subtable\'][$c][\'LookaheadGlyphCount\'] = 0;
					' . $m[1];
	}
);

// TTFontFile: the coverage glyphs of GSUB 5.3 are read already; drop the "not tested" stop after them.
publiclms_patch(
	$ttf,
	'build GSUB 5.3',
	'/\n(\s*)throw new [\\\\\w]+FontException\("Lookup Type 5, SubstFormat 3 not tested\.[^\n]*\n/',
	static function ( $m ) {
		return "\n" . $m[1] . "// PublicLMS patch: build GSUB 5.3\n";
	}
);

// TTFontFile: mark filtering sets in the GSUB ignore string.
publiclms_patch(
	$ttf,
	'GSUB mark filtering sets',
	'/\n(\s*)throw new [\\\\\w]+FontException\("Font \\\\"" \. \$this->fontkey \. "\\\\" contains MarkGlyphSets which is not supported"\);\n/',
	static function ( $m ) {
		return "\n" . $m[1] . "// PublicLMS patch: GSUB mark filtering sets\n";
	}
);

// TTFontFile: read each mark set at its real offset, and keep the marks to ignore (all marks outside the set).
publiclms_patch(
	$ttf,
	'mark set offsets and complement',
	'/\$this->seek\(\$MarkSetOffset\[\$i\]\);\s*\$glyphs = \$this->_getCoverage\(\);\s*\$this->MarkGlyphSets\[\$i\] = \' \' \. implode\(\'\| \', \$glyphs\);/',
	static function () {
		return '// PublicLMS patch: mark set offsets and complement
					$this->seek($gdef_offset + $MarkGlyphSetsDef_offset + $MarkSetOffset[$i]);
					$glyphs = $this->_getCoverage();
					$ignored = (is_array($Marks) && count($Marks)) ? array_diff($Marks, $glyphs) : [];
					sort($ignored, SORT_STRING);
					$this->MarkGlyphSets[$i] = \' \' . implode(\'| \', $ignored);';
	}
);

// Otl: apply GSUB 5.3 at run time, as 6.3 without backtrack or lookahead.
publiclms_patch(
	$otl,
	'apply GSUB 5.3',
	'/throw new [\\\\\w]+MpdfException\("GSUB Lookup Type " \. \$Type \. " Format " \. \$SubstFormat \. " not TESTED YET\."\);/',
	static function () {
		return '// PublicLMS patch: apply GSUB 5.3
				$InputGlyphCount = $this->read_ushort();
				$SubstCount = $this->read_ushort();
				$CoverageInputOffset = [];
				for ($b = 0; $b < $InputGlyphCount; $b++) {
					$CoverageInputOffset[] = $subtable_offset + $this->read_ushort();
				}
				$save_pos = $this->_pos;
				$CoverageInputGlyphs = [];
				for ($b = 0; $b < $InputGlyphCount; $b++) {
					$this->seek($CoverageInputOffset[$b]);
					$CoverageInputGlyphs[$b] = implode("|", $this->_getCoverage());
				}
				$matched = $this->checkContextMatchMultiple($CoverageInputGlyphs, [], [], $ignore, $ptr);
				if ($matched) {
					$this->seek($save_pos);
					$SubstLookupRecord = [];
					for ($p = 0; $p < $SubstCount; $p++) {
						$SubstLookupRecord[$p][\'SequenceIndex\'] = $this->read_ushort();
						$SubstLookupRecord[$p][\'LookupListIndex\'] = $this->read_ushort();
					}
					for ($p = 0; $p < $SubstCount; $p++) {
						if ($SubstLookupRecord[$p][\'SequenceIndex\'] >= $InputGlyphCount) {
							continue;
						}
						$lu = $SubstLookupRecord[$p][\'LookupListIndex\'];
						$luType = $this->GSUBLookups[$lu][\'Type\'];
						$luFlag = $this->GSUBLookups[$lu][\'Flag\'];
						$luMarkFilteringSet = $this->GSUBLookups[$lu][\'MarkFilteringSet\'];
						$luptr = $matched[$SubstLookupRecord[$p][\'SequenceIndex\']];
						$lucurrGlyph = $this->OTLdata[$luptr][\'hex\'];
						$lucurrGID = $this->OTLdata[$luptr][\'uni\'];
						foreach ($this->GSUBLookups[$lu][\'Subtables\'] as $luc => $lusubtable_offset) {
							$shift = $this->_applyGSUBsubtable($lu, $luc, $luptr, $lucurrGlyph, $lucurrGID, ($lusubtable_offset - $this->GSUB_offset), $luType, $luFlag, $luMarkFilteringSet, $this->GSLuCoverage[$lu][$luc], 1, $currentTag, $is_old_spec, $tagInt);
							if ($shift) {
								break;
							}
						}
					}
					if (!defined("OMIT_OTL_FIX_3") || OMIT_OTL_FIX_3 != 1) {
						return (isset($shift) ? $shift : 0);
					}
					return $InputGlyphCount;
				}
				return 0;';
	}
);

// Otl: mark filtering sets in the common ignore string, with a strict "set given" check.
publiclms_patch(
	$otl,
	'GCOM mark filtering sets',
	'/throw new [\\\\\w]+MpdfException\("This font \[" \. \$this->fontkey \. "\] contains MarkGlyphSets - Not tested yet"\);\s*\/\/ Change also in ttfontsuni\.php\s*if \(\$MarkFilteringSet == \'\'\) \{/',
	static function () {
		return '// PublicLMS patch: GCOM mark filtering sets
			if ($MarkFilteringSet === \'\' || !isset($this->MarkGlyphSets[$MarkFilteringSet])) {';
	}
);

// TTFontFile: contextual builders count the substitutions of the lookups they call, which are unset
// when that lookup is itself contextual (a TypeError on PHP 8). The chaining 6.3 builder already guards it.
publiclms_patch(
	$ttf,
	'guard nested substitutions',
	'/if \(count\(\$Lookup\[\$lup\]\[\'Subtable\'\]\[\$lus\]\[\'subs\'\]\)\) \{/',
	static function () {
		return 'if (!empty($Lookup[$lup][\'Subtable\'][$lus][\'subs\']) && is_array($Lookup[$lup][\'Subtable\'][$lus][\'subs\'])) { // PublicLMS patch: guard nested substitutions';
	},
	4
);

// TTFontFile: Indic 'locl' collection reads `replace`, which contextual rules do not have.
publiclms_patch(
	$ttf,
	'skip contextual locl rules',
	'/if \(strpos\(\'locl\', \$v\[\'tag\'\]\) !== false\) \{(\s*\$key = \$v\[\'match\'\];)/',
	static function ( $m ) {
		return 'if (strpos(\'locl\', $v[\'tag\']) !== false && !isset($v[\'context\'])) { // PublicLMS patch: skip contextual locl rules' . $m[1];
	}
);

// Arabic joining forms that are 1-to-n substitutions (GSUB type 2 under init/medi/fina, which current
// Noto Sans Arabic uses for Farsi yeh, kaf and others: a dotless body plus a dots mark) are stored as
// "0E008 0E06E". mPDF's joining shaper read that as one code point, so the letter vanished.

// TTFontFile: every glyph of such a form is an RTL glyph for magic_reverse.
publiclms_patch(
	$ttf,
	'multiple-glyph forms are RTL',
	'/(\n\s*)\$rtlpua\[\] = \$sub;\n/',
	static function ( $m ) {
		return $m[1] . 'foreach (explode(\' \', $sub) as $subGlyph) { // PublicLMS patch: multiple-glyph forms are RTL' . $m[1] . "\t" . '$rtlpua[] = $subGlyph;' . $m[1] . "}\n";
	}
);

// TTFontFile: the kashida finals list takes the base glyph of a multiple-glyph final form, not its marks.
publiclms_patch(
	$ttf,
	'base glyph of multiple-glyph finals',
	'/\$finals \.= \$variants\[1\] \. \' \';/',
	static function () {
		return '$finals .= strtok($variants[1], \' \') . \' \'; // PublicLMS patch: base glyph of multiple-glyph finals';
	}
);

// Otl: insert the extra glyphs of a joining form as GSUB type 2 does (last to first, so indexes hold).
publiclms_patch(
	$otl,
	'multiple-glyph Arabic forms',
	'/for \(\$i = 0; \$i < count\(\$this->OTLdata\); \$i\+\+\) \{\s*\$this->OTLdata\[\$i\]\[\'uni\'\] = hexdec\(\$ra\[\$i\]\[0\]\);\s*\$this->OTLdata\[\$i\]\[\'hex\'\] = \$ra\[\$i\]\[0\];\s*\$this->OTLdata\[\$i\]\[\'form\'\] = \$ra\[\$i\]\[1\];[^\n]*\n(\s*)\}/',
	static function ( $m ) {
		$t = $m[1];
		return 'for ($i = count($this->OTLdata) - 1; $i >= 0; $i--) { // PublicLMS patch: multiple-glyph Arabic forms
' . $t . '	$glyphs = explode(\' \', trim($ra[$i][0]));
' . $t . '	$this->OTLdata[$i][\'uni\'] = hexdec($glyphs[0]);
' . $t . '	$this->OTLdata[$i][\'hex\'] = $glyphs[0];
' . $t . '	if (count($glyphs) > 1) {
' . $t . '		$this->GSUBsubstitute($i, array_map(\'hexdec\', $glyphs), 2);
' . $t . '	}
' . $t . '	$this->OTLdata[$i][\'form\'] = $ra[$i][1]; // 0=ISOLATED FORM :: 1=FINAL :: 2=INITIAL :: 3=MEDIAL
' . $t . '}';
	}
);

// ImageProcessor: every GD call is guarded except WebP's, which is an Error on a server without GD
// (the whole certificate would fail instead of leaving the image out).
publiclms_patch(
	$src . '/Image/ImageProcessor.php',
	'webp without GD',
	'/\$im = @imagecreatefromstring\(\$data\);(\s*if \(!function_exists\(\'imagewebp\'\) \|\| false === \$im\) \{)/',
	static function ( $m ) {
		return '$im = function_exists(\'imagecreatefromstring\') ? @imagecreatefromstring($data) : false; // PublicLMS patch: webp without GD' . $m[1];
	}
);

echo "mPDF patched.\n";
