<?php
/**
 * Read-only advice about which resources may be delayed.
 *
 * The owner sees "render-blocking requests" in PageSpeed and asks which of them
 * can be postponed. The answer is a verdict with a reason, never an automatic
 * change: the plugin only suggests, and the owner presses the button.
 *
 * The non-critical list is data, not a guess. Every entry is a host (or a host
 * with a path) whose script is not needed for the first paint: counters,
 * conversion pixels and chats load independently of the layout.
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_Advisor' ) ) {
	return;
}

/**
 * Builds deferral verdicts from the last page's resources.
 */
final class MMF_Advisor {

	public const CAN = 'can';

	public const RISKY = 'risky';

	public const CANNOT = 'cannot';

	public const DONE = 'done';

	/**
	 * Hosts (or host + path) that are not needed before the first paint.
	 *
	 * - analytics: the counter sends a hit after it loads, the layout does not
	 *   wait for it;
	 * - pixels: conversion tracking is fire-and-forget;
	 * - chats: the widget shows a button and loads the conversation on demand.
	 *
	 * The value is a reason key, the label lives in `reason_label()`.
	 */
	public const NON_CRITICAL = array(
		'mc.yandex.ru'            => 'analytics',
		'mc.yandex.com'           => 'analytics',
		'google-analytics.com'    => 'analytics',
		'googletagmanager.com'    => 'analytics',
		'top.mail.ru'             => 'analytics',
		'top-freemail.ru'         => 'analytics',
		'hotjar.com'              => 'analytics',
		'clarity.ms'              => 'analytics',
		'jivo'                    => 'chat',
		'carrotquest'             => 'chat',
		'talk-me'                 => 'chat',
		'vk.com/rtrg'             => 'pixel',
		'connect.facebook.net'    => 'pixel',
		'facebook.net'            => 'pixel',
		'googleadservices.com'    => 'pixel',
		'doubleclick.net'         => 'pixel',
	);

	/**
	 * Analyses the resources of the last page.
	 *
	 * @param array<int,array<string,mixed>> $resources Resources from the stats.
	 * @return array{items:array<int,array<string,mixed>>,blocking:int,deferable:int,bytes:int}
	 */
	public static function analyse( array $resources ): array {
		$items = array();

		foreach ( $resources as $resource ) {
			if ( ! is_array( $resource ) ) {
				continue;
			}

			$kind = (string) ( $resource['kind'] ?? '' );
			$url  = (string) ( $resource['url'] ?? '' );
			$mode = (string) ( $resource['mode'] ?? 'normal' );

			if ( 'js' !== $kind && 'css' !== $kind ) {
				continue;
			}

			if ( 'js' === $kind && 'inline' === $mode ) {
				continue;
			}

			$items[] = self::verdict( $resource );
		}

		$blocking  = 0;
		$deferable = 0;
		$bytes     = 0;

		foreach ( $items as $item ) {
			if ( self::DONE !== $item['verdict'] ) {
				$blocking++;
			}

			if ( self::CAN === $item['verdict'] ) {
				$deferable++;
				$bytes += (int) $item['size'];
			}
		}

		return array(
			'items'     => $items,
			'blocking'  => $blocking,
			'deferable' => $deferable,
			'bytes'     => $bytes,
		);
	}

	/**
	 * One verdict with its reason.
	 *
	 * @param array<string,mixed> $resource Resource entry.
	 * @return array<string,mixed>
	 */
	private static function verdict( array $resource ): array {
		$kind   = (string) ( $resource['kind'] ?? '' );
		$url    = (string) ( $resource['url'] ?? '' );
		$name   = (string) ( $resource['name'] ?? '' );
		$mode   = (string) ( $resource['mode'] ?? 'normal' );
		$reason = '';

		if ( 'css' === $kind ) {
			$verdict = self::CANNOT;
			$reason  = 'css';
		} elseif ( in_array( $mode, array( 'defer', 'async', 'delayed', 'rocket' ), true ) ) {
			$verdict = self::DONE;
			$reason  = 'already';
		} elseif ( preg_match( '#/mmf/|^mmf#i', $url . ' ' . $name ) ) {
			$verdict = self::CANNOT;
			$reason  = 'own';
		} elseif ( false !== stripos( $url . ' ' . $name, 'jquery' ) ) {
			$verdict = self::RISKY;
			$reason  = 'jquery';
		} elseif ( ! empty( $resource['module'] ) ) {
			$verdict = self::RISKY;
			$reason  = 'module';
		} elseif ( ! empty( $resource['inline_after'] ) ) {
			$verdict = self::RISKY;
			$reason  = 'inline-after';
		} else {
			$known = self::known_reason( $url );

			if ( '' !== $known ) {
				$verdict = self::CAN;
				$reason  = $known;
			} else {
				$verdict = self::RISKY;
				$reason  = 'local';
			}
		}

		return array(
			'url'      => $url,
			'name'     => '' !== $name ? $name : self::basename( $url ),
			'size'     => (int) ( $resource['size'] ?? 0 ),
			'verdict'  => $verdict,
			'reason'   => $reason,
			'pattern'  => self::pattern( $url ),
			'position' => (int) ( $resource['position'] ?? 0 ),
		);
	}

	/**
	 * Reason key of a known non-critical host, empty when unknown.
	 *
	 * @param string $url Resource URL.
	 */
	private static function known_reason( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$hay  = $host . $path;

		foreach ( self::NON_CRITICAL as $needle => $reason ) {
			if ( str_contains( $hay, strtolower( (string) $needle ) ) ) {
				return (string) $reason;
			}
		}

		return '';
	}

	/**
	 * Delay pattern for a URL: host + path without query.
	 *
	 * A plain substring is what `MMF_Assets::matches()` expects, so the whole
	 * URL is not used (a changing query would stop matching).
	 *
	 * @param string $url Resource URL.
	 */
	public static function pattern( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( '' !== $host ) {
			return $host . $path;
		}

		return self::basename( $url );
	}

	/**
	 * Last path segment.
	 *
	 * @param string $url Resource URL.
	 */
	private static function basename( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return '' !== $path ? basename( $path ) : $url;
	}

	/**
	 * Human verdict.
	 *
	 * @param string $verdict One of the class constants.
	 */
	public static function verdict_label( string $verdict ): string {
		$labels = array(
			self::CAN    => __( 'можно отложить', 'modern-minify' ),
			self::RISKY  => __( 'рискованно', 'modern-minify' ),
			self::CANNOT => __( 'нельзя', 'modern-minify' ),
			self::DONE   => __( 'уже отложен', 'modern-minify' ),
		);

		return $labels[ $verdict ] ?? $verdict;
	}

	/**
	 * Human reason.
	 *
	 * @param string $reason Reason key.
	 */
	public static function reason_label( string $reason ): string {
		$labels = array(
			'analytics'    => __( 'счётчик аналитики, до отрисовки не нужен', 'modern-minify' ),
			'pixel'        => __( 'конверсионный пиксель, до отрисовки не нужен', 'modern-minify' ),
			'chat'         => __( 'чат загружается по требованию, до отрисовки не нужен', 'modern-minify' ),
			'css'          => __( 'CSS блокирует отрисовку: его надо объединять, а не откладывать', 'modern-minify' ),
			'own'          => __( 'наш собственный ресурс, его режимом управляет плагин', 'modern-minify' ),
			'already'      => __( 'defer, async, Delay JS или Rocket уже применены', 'modern-minify' ),
			'jquery'       => __( 'это jQuery, от него зависят другие скрипты', 'modern-minify' ),
			'module'       => __( 'type="module", порядок загрузки задаёт браузер', 'modern-minify' ),
			'inline-after' => __( 'после него инлайн-код, который может от него зависеть', 'modern-minify' ),
			'local'        => __( 'локальный скрипт темы или плагина: неизвестно, нужен ли он до отрисовки', 'modern-minify' ),
		);

		return $labels[ $reason ] ?? $reason;
	}
}
