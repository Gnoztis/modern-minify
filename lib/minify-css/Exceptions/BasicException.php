<?php
/**
 * Vendored library file - not plugin code.
 *
 * Library: matthiasmullie/minify 1.3.76 (MIT), https://github.com/matthiasmullie/minify
 * Only changes: the ABSPATH guard below and, where marked, an analyser pragma on a false positive.
 */


/**
 * Basic exception.
 *
 * Please report bugs on https://github.com/matthiasmullie/minify/issues
 *
 * @author Matthias Mullie <minify@mullie.eu>
 * @copyright Copyright (c) 2012, Matthias Mullie. All rights reserved
 * @license MIT License
 */

namespace MatthiasMullie\Minify\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MatthiasMullie\Minify\Exception;

/**
 * Basic Exception Class.
 *
 * @author Matthias Mullie <minify@mullie.eu>
 */
abstract class BasicException extends Exception {}
