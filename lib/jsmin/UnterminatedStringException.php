<?php
/**
 * Vendored library file - not plugin code.
 *
 * Library: mrclay/jsmin-php 2.4.3 (MIT), https://github.com/mrclay/jsmin-php
 * Only changes: the ABSPATH guard below and, where marked, an analyser pragma on a false positive.
 */


namespace JSMin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UnterminatedStringException extends \Exception {
}
