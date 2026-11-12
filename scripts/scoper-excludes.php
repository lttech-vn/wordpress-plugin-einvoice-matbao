<?php
/**
 * Lists the classes, functions and constants declared by stub files, as JSON for scoper.inc.php.
 *
 *   php scripts/scoper-excludes.php <stub.php>... > excludes.json
 *
 * Used by scripts/scope-vendor.sh on php-stubs/wordpress-stubs and php-stubs/woocommerce-stubs,
 * so WordPress, WooCommerce and Action Scheduler symbols keep their global names after scoping.
 * Uses the tokenizer only: the build tool directory must not need extra packages.
 *
 * @package EinvoiceMatbao
 */

// phpcs:disable WordPress -- Build-time CLI script, never loaded by WordPress.

if ( PHP_SAPI !== 'cli' || $argc < 2 ) {
	fwrite( STDERR, "usage: php scoper-excludes.php <stub.php>...\n" );
	exit( 2 );
}

/**
 * Collects top-level declarations (not class members, closures or imports) of one PHP file.
 *
 * @param string                                                                      $code PHP source.
 * @param array{classes: list<string>, functions: list<string>, constants: list<string>} $symbols Accumulator.
 * @return array{classes: list<string>, functions: list<string>, constants: list<string>}
 */
function scoper_excludes_collect( string $code, array $symbols ): array {
	$tokens    = array_values(
		array_filter(
			token_get_all( $code ),
			static fn( $token ): bool => ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true )
		)
	);
	$namespace = '';
	$ns_depth  = 0;
	$depth     = 0;
	$count     = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		$kind  = is_array( $token ) ? $token[0] : $token;
		$prev  = $i > 0 ? ( is_array( $tokens[ $i - 1 ] ) ? $tokens[ $i - 1 ][0] : $tokens[ $i - 1 ] ) : null;
		$next  = $tokens[ $i + 1 ] ?? null;

		if ( '{' === $kind || T_CURLY_OPEN === $kind || T_DOLLAR_OPEN_CURLY_BRACES === $kind ) {
			++$depth;
			continue;
		}
		if ( '}' === $kind ) {
			--$depth;
			continue;
		}
		if ( T_NAMESPACE === $kind && $depth === 0 ) {
			$namespace = is_array( $next ) && in_array( $next[0], array( T_STRING, T_NAME_QUALIFIED ), true ) ? $next[1] : '';
			// Braced namespaces put their declarations one level deeper.
			$ns_depth = ( '{' === $next || '{' === ( $tokens[ $i + 2 ] ?? null ) ) ? 1 : 0;
			continue;
		}
		if ( $depth !== $ns_depth || ! is_array( $next ) || T_STRING !== $next[0] ) {
			continue;
		}

		$name = ( '' === $namespace ? '' : $namespace . '\\' ) . $next[1];
		if ( in_array( $kind, array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ), true ) && T_NEW !== $prev && T_DOUBLE_COLON !== $prev ) {
			$symbols['classes'][] = $name;
		} elseif ( T_FUNCTION === $kind && T_USE !== $prev ) {
			$symbols['functions'][] = $name;
		} elseif ( T_CONST === $kind && T_USE !== $prev ) {
			$symbols['constants'][] = $name;
		}
	}

	// define( 'NAME', ... ) at any depth (stubs wrap some of them in conditions).
	if ( preg_match_all( '/\bdefine\(\s*[\'"]([A-Za-z_][A-Za-z0-9_\\\\]*)[\'"]/', $code, $matches ) ) {
		array_push( $symbols['constants'], ...$matches[1] );
	}

	return $symbols;
}

$symbols = array(
	'classes'   => array(),
	'functions' => array(),
	'constants' => array(),
);
foreach ( array_slice( $argv, 1 ) as $file ) {
	$code = is_readable( $file ) ? file_get_contents( $file ) : false;
	if ( false === $code ) {
		fwrite( STDERR, "Cannot read $file\n" );
		exit( 1 );
	}
	$symbols = scoper_excludes_collect( $code, $symbols );
}

foreach ( $symbols as $kind => $names ) {
	$names = array_values( array_unique( $names ) );
	sort( $names );
	if ( array() === $names ) {
		fwrite( STDERR, "No $kind found in the stubs.\n" );
		exit( 1 );
	}
	$symbols[ $kind ] = $names;
}

echo json_encode( $symbols, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
