<?php
/**
 * Vendored library file - not plugin code.
 *
 * Library: matthiasmullie/minify 1.3.76 (MIT), https://github.com/matthiasmullie/minify
 * Only changes: the ABSPATH guard below and, where marked, an analyser pragma on a false positive.
 */


/**
 * Base Exception.
 *
 * @deprecated Use Exceptions\BasicException instead
 *
 * @author Matthias Mullie <minify@mullie.eu>
 */

namespace MatthiasMullie\Minify;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base Exception Class.
 *
 * @deprecated Use Exceptions\BasicException instead
 *
 * @author Matthias Mullie <minify@mullie.eu>
 */
abstract class Exception extends \Exception {}
