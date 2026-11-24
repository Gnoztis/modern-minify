<?php
/**
 * Counters for the status screen.
 *
 * The pipeline touches every page, so this class must not become a database
 * write per view. Counters are therefore cumulative and written at most once a
 * minute: a page served inside that window is processed as usual, its numbers
 * are just not added. The status screen shows the totals as an order of
 * magnitude ("the plugin is doing work"), not as an audit log.
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_Stats' ) ) {
	return;
}

/**
 * Stores the pipeline counters in one option.
 */
final class MMF_Stats {

	/**
	 * Option name. Autoloaded: no, it is only read by the admin screen.
	 */
	public const OPTION = 'mmf_stats';

	/**
	 * Seconds between two writes.
	 */
	private const THROTTLE = 60;

	/**
	 * Known counters and their initial value.
	 *
	 * @return array<string,int>
	 */
	public static function defaults(): array {
		return array(
			'pages'        => 0,
			'files'        => 0,
			'inline'       => 0,
			'async'        => 0,
			'defer'        => 0,
			'moved'        => 0,
			'print'        => 0,
			'failed'       => 0,
			'delayed'      => 0,
			'last_delayed' => 0,
			'updated'      => 0,
		);
	}

	/**
	 * Stored counters, unknown keys dropped.
	 *
	 * The option is a boundary value: it can hold anything after a bad import or
	 * a broken update. Every value is type-checked before it is used as a number.
	 *
	 * @return array<string,int>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$known = array();

		foreach ( self::defaults() as $key => $default ) {
			$value = $stored[ $key ] ?? $default;

			if ( is_bool( $value ) ) {
				$known[ $key ] = (int) $value;
			} elseif ( is_numeric( $value ) ) {
				$known[ $key ] = (int) $value;
			} else {
				$known[ $key ] = $default;
			}
		}

		return $known;
	}

	/**
	 * Adds one page's counters.
	 *
	 * @param mixed $delta Counters of the page that was processed.
	 */
	public static function record( $delta ): void {
		if ( ! is_array( $delta ) ) {
			return;
		}

		$clean = array();

		foreach ( $delta as $key => $count ) {
			if ( is_scalar( $count ) && is_numeric( $count ) && (int) $count > 0 ) {
				$clean[ $key ] = (int) $count;
			}
		}

		if ( array() === $clean ) {
			return;
		}

		$stats = self::all();
		$now   = time();

		if ( (int) $stats['updated'] > 0 && ( $now - (int) $stats['updated'] ) < self::THROTTLE ) {
			return;
		}

		foreach ( $clean as $key => $count ) {
			if ( 'last_delayed' === $key ) {
				// A snapshot of the last counted page, not a running total.
				$stats['last_delayed'] = $count;
				continue;
			}

			if ( array_key_exists( $key, $stats ) && 'updated' !== $key ) {
				$stats[ $key ] = (int) $stats[ $key ] + $count;
			}
		}

		$stats['updated'] = $now;

		// Not autoloaded: read once on the admin screen, never on a page view.
		update_option( self::OPTION, $stats, false );
	}

	/**
	 * Drops the counters.
	 */
	public static function reset(): void {
		delete_option( self::OPTION );
	}
}
