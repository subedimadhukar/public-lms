#!/usr/bin/env node
/**
 * Lists directional declarations that a `-rtl.css` file did not flip.
 *
 * For every built `*.css` with a `*-rtl.css` beside it (build/ and
 * assets/css), the two files are parsed with PostCSS and walked in step
 * (rtlcss keeps the order of rules and declarations). A declaration whose
 * property or value is directional, yet reads the same in both files, is
 * reported unless it is symmetric (for example `inset: 0` or a centred
 * gradient). Exit code 1 when anything is reported.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const postcss = require( 'postcss' );

const root = path.join( __dirname, '..' );
const PHYSICAL =
	/^(left|right|inset|translate|transform|background(-position(-x)?)?|background-image|box-shadow|text-shadow|float|clear|text-align|border-radius|margin|padding|(margin|padding|border)-(left|right)(-.*)?|border-(top|bottom)-(left|right)-radius)$/;

/**
 * Splits a value at top-level commas (not inside parentheses).
 *
 * @param {string} value CSS value.
 * @return {string[]} Parts.
 */
function splitTop( value ) {
	const parts = [];
	let depth = 0;
	let current = '';
	for ( const char of value ) {
		if ( char === '(' ) {
			depth++;
		} else if ( char === ')' ) {
			depth--;
		}
		if ( char === ',' && depth === 0 ) {
			parts.push( current );
			current = '';
		} else {
			current += char;
		}
	}
	parts.push( current );
	return parts.map( ( part ) => part.trim() );
}

const NONZERO_LENGTH = /^-?(\d*\.)?\d+[a-z%]*$/;
const isNonZero = ( token ) =>
	NONZERO_LENGTH.test( token ) && parseFloat( token ) !== 0;

/**
 * Whether a declaration reads differently in a mirrored layout.
 *
 * @param {string} prop  Property.
 * @param {string} value Value.
 * @return {boolean} Asymmetric.
 */
function asymmetric( prop, value ) {
	const v = value.replace( /\s+/g, ' ' ).trim();
	if ( /^(float|clear|text-align)$/.test( prop ) ) {
		return /\b(left|right)\b/.test( v );
	}
	if ( /^(margin|padding|inset|border-radius)$/.test( prop ) ) {
		const parts = v.split( ' ' );
		return parts.length === 4 && parts[ 1 ] !== parts[ 3 ];
	}
	if ( /shadow$/.test( prop ) ) {
		return splitTop( v ).some( ( one ) =>
			isNonZero( one.replace( /^inset\s+/, '' ).split( ' ' )[ 0 ] || '' )
		);
	}
	if ( /gradient\(/.test( v ) ) {
		return /to (left|right)|\b(?!0deg|180deg|360deg)-?\d+deg/.test( v );
	}
	if ( /^(translate|transform)$/.test( prop ) ) {
		return (
			/translate(X|3d)?\(\s*-?(\d*\.)?[1-9]/.test( v ) ||
			( prop === 'translate' && isNonZero( v.split( ' ' )[ 0 ] ) )
		);
	}
	if ( /^background$/.test( prop ) ) {
		return /\b(left|right)\b/.test( v );
	}
	if ( /^background-position(-x)?$/.test( prop ) ) {
		return /\b(left|right)\b/.test( v ) || /(^|\s)(?!50%)\d+%/.test( v );
	}
	return /(left|right)/.test( prop );
}

const swap = ( text ) =>
	text.replace( /left|right/g, ( m ) => ( m === 'left' ? 'right' : 'left' ) );

function walk( dir, out ) {
	for ( const entry of fs.readdirSync( dir, { withFileTypes: true } ) ) {
		const full = path.join( dir, entry.name );
		if ( entry.isDirectory() ) {
			walk( full, out );
		} else if (
			entry.name.endsWith( '.css' ) &&
			! entry.name.endsWith( '-rtl.css' )
		) {
			out.push( full );
		}
	}
	return out;
}

const files = [ path.join( root, 'build' ), path.join( root, 'assets', 'css' ) ]
	.filter( ( dir ) => fs.existsSync( dir ) )
	.flatMap( ( dir ) => walk( dir, [] ) );
let problems = 0;
let missing = 0;
for ( const ltr of files ) {
	const rtl = ltr.replace( /\.css$/, '-rtl.css' );
	const rel = path.relative( root, ltr );
	if ( ! fs.existsSync( rtl ) ) {
		process.stdout.write( `MISSING ${ path.relative( root, rtl ) }\n` );
		missing++;
		continue;
	}
	const a = [];
	const b = [];
	postcss
		.parse( fs.readFileSync( ltr, 'utf8' ) )
		.walkDecls( ( d ) => a.push( d ) );
	postcss
		.parse( fs.readFileSync( rtl, 'utf8' ) )
		.walkDecls( ( d ) => b.push( d ) );
	const seen = new Set();
	b.forEach( ( d ) =>
		seen.add( `${ d.parent.selector }|${ d.prop }|${ d.value }` )
	);
	for ( const d of a ) {
		const prop = d.prop.toLowerCase();
		if (
			( ! PHYSICAL.test( prop ) && ! /gradient\(/.test( d.value ) ) ||
			! asymmetric( prop, d.value )
		) {
			continue;
		}
		const selector = d.parent.selector;
		// A left/right property is flipped when the RTL file has the mirrored property with this value.
		const flipped = /left|right/.test( prop )
			? seen.has( `${ selector }|${ swap( d.prop ) }|${ d.value }` )
			: ! seen.has( `${ selector }|${ d.prop }|${ d.value }` );
		if ( ! flipped ) {
			process.stdout.write(
				`UNFLIPPED ${ rel }: ${ selector } { ${ d.prop }: ${ d.value } }\n`
			);
			problems++;
		}
	}
}
process.stdout.write(
	`${ files.length } stylesheets checked; ${ problems } unflipped, ${ missing } missing RTL copies.\n`
);
process.exit( problems + missing > 0 ? 1 : 0 );
