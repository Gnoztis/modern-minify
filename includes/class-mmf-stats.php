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
	 * Option holding the last action shown on the status screen.
	 *
	 * A toast disappears; the result of an action must also survive somewhere
	 * permanent, so this record is what the status card reads.
	 */
	public const ACTION_OPTION = 'mmf_last_action';

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
			'last_css_before'    => 0,
			'last_css_after'     => 0,
			'rocket'             => 0,
			'gravatar'           => 0,
			'avatar_webp'        => 0,
			'avatar_saved'       => 0,
			'avatar_errors'      => 0,
			'image_created'      => 0,
			'image_served'       => 0,
			'image_saved'        => 0,
			'image_errors'       => 0,
			'image_small'        => 0,
			'image_bigger'       => 0,
			'image_webp_existing' => 0,
			'image_reused'       => 0,
			'image_recreated'    => 0,
			'bundles'            => 0,
			'bundled_files'      => 0,
			'bundle_bytes'       => 0,
			'external_localized' => 0,
			'external_kept'      => 0,
			'external_failed'    => 0,
			'external_removed'   => 0,
			'font_warnings'      => 0,
			'fonts_files'        => 0,
			'last_external'      => array(),
			'last_scripts'       => array(),
			'last_resources'     => array(),
			'inline_merged'      => 0,
			'merge_reasons'      => array(),
			'last_defer'         => array( 'allowed' => false, 'reason' => '', 'blockers' => array() ),
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
			} elseif ( 'last_scripts' === $key ) {
				$known[ $key ] = self::sanitize_scripts( $value );
			} elseif ( 'last_resources' === $key ) {
				$known[ $key ] = self::sanitize_resources( $value );
			} elseif ( 'merge_reasons' === $key ) {
				$known[ $key ] = self::sanitize_reasons( $value );
			} elseif ( 'last_defer' === $key ) {
				$known[ $key ] = self::sanitize_defer( $value );
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

			if ( 'last_scripts' === $key ) {
				$clean[ $key ] = self::sanitize_scripts( $count );
				continue;
			}

			if ( 'last_resources' === $key ) {
				$clean[ $key ] = self::sanitize_resources( $count );
				continue;
			}

			if ( 'merge_reasons' === $key ) {
				$clean[ $key ] = self::sanitize_reasons( $count );
				continue;
			}

			if ( 'last_defer' === $key ) {
				$clean[ $key ] = self::sanitize_defer( $count );
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
			if ( 'last_delayed' === $key || 'last_external' === $key || 'last_scripts' === $key || 'last_resources' === $key || 'last_defer' === $key || 'merge_reasons' === $key ) {
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
	 * Normalises the merge split reasons.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private static function sanitize_reasons( $value ): array {
		$allowed = array( 'inline-id', 'inline-import', 'size' );
		$list    = array();

		foreach ( (array) $value as $reason ) {
			if ( is_string( $reason ) && in_array( $reason, $allowed, true ) ) {
				$list[] = $reason;
			}
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * Normalises the resource list of the last page.
	 *
	 * @param mixed $value Raw stored or reported value.
	 * @return array<int,array{kind:string,url:string,name:string,mode:string,module:bool,inline_after:bool,size:int,media:string,position:int}>
	 */
	private static function sanitize_resources( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$kinds = array( 'js', 'css' );
		$modes = array( 'normal', 'defer', 'async', 'delayed', 'inline', 'rocket' );
		$list  = array();

		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$kind = isset( $entry['kind'] ) && is_string( $entry['kind'] ) ? sanitize_key( $entry['kind'] ) : '';
			$mode = isset( $entry['mode'] ) && is_string( $entry['mode'] ) ? sanitize_key( $entry['mode'] ) : '';

			if ( ! in_array( $kind, $kinds, true ) ) {
				continue;
			}

			if ( ! in_array( $mode, $modes, true ) ) {
				$mode = 'normal';
			}

			$url = isset( $entry['url'] ) && is_string( $entry['url'] ) ? esc_url_raw( $entry['url'] ) : '';

			$list[] = array(
				'kind'         => $kind,
				'url'          => mb_substr( $url, 0, 2000 ),
				'name'         => isset( $entry['name'] ) && is_string( $entry['name'] ) ? mb_substr( sanitize_text_field( $entry['name'] ), 0, 200 ) : '',
				'mode'         => $mode,
				'module'       => ! empty( $entry['module'] ),
				'inline_after' => ! empty( $entry['inline_after'] ),
				'size'         => isset( $entry['size'] ) && is_numeric( $entry['size'] ) ? max( 0, (int) $entry['size'] ) : 0,
				'media'        => isset( $entry['media'] ) && is_string( $entry['media'] ) ? mb_substr( sanitize_text_field( $entry['media'] ), 0, 60 ) : '',
				'position'     => isset( $entry['position'] ) && is_numeric( $entry['position'] ) ? (int) $entry['position'] : count( $list ) + 1,
			);

			if ( count( $list ) >= 200 ) {
				break;
			}
		}

		return $list;
	}

	/**
	 * Normalises the script list of the last page.
	 *
	 * @param mixed $value Raw stored or reported value.
	 * @return array<int,array{name:string,mode:string,position:int}>
	 */
	private static function sanitize_scripts( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$modes = array( 'normal', 'defer', 'async', 'delayed', 'inline', 'rocket' );
		$list  = array();

		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$name = isset( $entry['name'] ) && is_string( $entry['name'] ) ? sanitize_text_field( $entry['name'] ) : '';
			$mode = isset( $entry['mode'] ) && is_string( $entry['mode'] ) ? sanitize_key( $entry['mode'] ) : '';

			if ( ! in_array( $mode, $modes, true ) ) {
				$mode = 'normal';
			}

			$list[] = array(
				'name'     => mb_substr( $name, 0, 200 ),
				'mode'     => $mode,
				'position' => isset( $entry['position'] ) && is_numeric( $entry['position'] ) ? (int) $entry['position'] : count( $list ) + 1,
			);

			if ( count( $list ) >= 100 ) {
				break;
			}
		}

		return $list;
	}

	/**
	 * Normalises the defer decision of the last page.
	 *
	 * @param mixed $value Raw stored or reported value.
	 * @return array{allowed:bool,reason:string,blockers:string[]}
	 */
	private static function sanitize_defer( $value ): array {
		$value    = is_array( $value ) ? $value : array();
		$blockers = array();

		foreach ( (array) ( $value['blockers'] ?? array() ) as $blocker ) {
			if ( is_string( $blocker ) && '' !== trim( $blocker ) ) {
				$blockers[] = sanitize_text_field( $blocker );
			}

			if ( count( $blockers ) >= 10 ) {
				break;
			}
		}

		return array(
			'allowed'  => ! empty( $value['allowed'] ),
			'reason'   => isset( $value['reason'] ) && is_string( $value['reason'] ) ? sanitize_text_field( $value['reason'] ) : '',
			'blockers' => $blockers,
		);
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
		delete_option( self::ACTION_OPTION );
	}

	/**
	 * Remembers the last action, so its result survives the toast.
	 *
	 * @param string $message Human-readable result.
	 */
	/**
	 * Adds to one known counter.
	 *
	 * Used by the image converter: an upload happens outside a page request, so
	 * it cannot go through the per-page record path.
	 *
	 * @param string $key Counter name.
	 * @param int    $by  Amount.
	 */
	public static function increment( string $key, int $by = 1 ): void {
		$all = self::all();

		if ( ! array_key_exists( $key, $all ) ) {
			return;
		}

		$all[ $key ] = (int) $all[ $key ] + $by;
		$all['updated'] = time();

		// Not throttled: conversions and served images are rare, batched events.
		update_option( self::OPTION, $all, false );
	}

	public static function record_action( string $message ): void {
		$message = trim( sanitize_text_field( $message ) );

		if ( '' === $message ) {
			return;
		}

		update_option(
			self::ACTION_OPTION,
			array(
				'message' => mb_substr( $message, 0, 300 ),
				'time'    => time(),
			),
			false
		);
	}

	/**
	 * The last recorded action.
	 *
	 * @return array{message:string,time:int}
	 */
	public static function last_action(): array {
		$stored = get_option( self::ACTION_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$message = isset( $stored['message'] ) && is_string( $stored['message'] ) ? sanitize_text_field( $stored['message'] ) : '';
		$time    = isset( $stored['time'] ) && is_numeric( $stored['time'] ) ? (int) $stored['time'] : 0;

		return array(
			'message' => $message,
			'time'    => $time,
		);
	}
}
