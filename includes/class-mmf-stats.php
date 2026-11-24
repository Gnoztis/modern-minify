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
	 * `last_external` is not a number: it is the list of external hosts seen on
	 * the last counted page, with what happened to each of them.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'pages'              => 0,
			'files'              => 0,
			'inline'             => 0,
			'async'              => 0,
			'defer'              => 0,
			'moved'              => 0,
			'print'              => 0,
			'failed'             => 0,
			'delayed'            => 0,
			'last_delayed'       => 0,
			'rocket'             => 0,
			'bundles'            => 0,
			'bundled_files'      => 0,
			'bundle_bytes'       => 0,
			'external_localized' => 0,
			'external_kept'      => 0,
			'external_failed'    => 0,
			'external_removed'   => 0,
			'font_warnings'      => 0,
			'last_external'      => array(),
			'updated'            => 0,
		);
	}

	/**
	 * Stored counters, unknown keys dropped.
	 *
	 * The option is a boundary value: it can hold anything after a bad import or
	 * a broken update. Every value is type-checked before it is used.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$known = array();

		foreach ( self::defaults() as $key => $default ) {
			$value = $stored[ $key ] ?? $default;

			if ( 'last_external' === $key ) {
				$known[ $key ] = self::sanitize_report( $value );
			} elseif ( is_bool( $value ) ) {
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
			if ( 'last_external' === $key ) {
				$clean[ $key ] = self::sanitize_report( $count );
				continue;
			}

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
			if ( 'last_delayed' === $key || 'last_external' === $key ) {
				// Snapshots of the last counted page, not running totals.
				$stats[ $key ] = $count;
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
	 * Normalises the list of external hosts of the last page.
	 *
	 * @param mixed $value Raw stored or reported value.
	 * @return array<int,array{host:string,status:string}>
	 */
	private static function sanitize_report( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$report = array();

		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$host   = isset( $entry['host'] ) && is_string( $entry['host'] ) ? sanitize_text_field( $entry['host'] ) : '';
			$status = isset( $entry['status'] ) && is_string( $entry['status'] ) ? sanitize_key( $entry['status'] ) : '';

			if ( '' === $host || '' === $status ) {
				continue;
			}

			$report[] = array(
				'host'   => $host,
				'status' => $status,
			);

			if ( count( $report ) >= 50 ) {
				break;
			}
		}

		return $report;
	}

	/**
	 * Drops the counters.
	 */
	public static function reset(): void {
		delete_option( self::OPTION );
	}
}
