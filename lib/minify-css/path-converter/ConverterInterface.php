<?php
/**
 * Vendored library file - not plugin code.
 *
 * Library: matthiasmullie/path-converter 1.1.3 (MIT), https://github.com/matthiasmullie/path-converter
 * Only changes: the ABSPATH guard below and, where marked, an analyser pragma on a false positive.
 */


namespace MatthiasMullie\PathConverter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convert file paths.
 *
 * Please report bugs on https://github.com/matthiasmullie/path-converter/issues
 *
 * @author Matthias Mullie <pathconverter@mullie.eu>
 * @copyright Copyright (c) 2015, Matthias Mullie. All rights reserved
 * @license MIT License
 */
interface ConverterInterface
{
    /**
     * Convert file paths.
     *
     * @param string $path The path to be converted
     *
     * @return string The new path
     */
    public function convert($path);
}
