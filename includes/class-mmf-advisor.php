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

	public const LOCAL = 'local';
	public const EXTERNAL = 'external';
	public const EXTERNAL_KNOWN = 'external-known';

	/**
	 * Third-party hosts that must run before a form is submitted.
	 *
	 * Anti-spam checks, captchas and payment widgets either protect a form or
	 * validate a payment; delaying them breaks the action instead of speeding
	 * the page up. Values are reason keys.
	 */
	public const NON_CRITICAL_CSS = array(
		'jivo'         => 'chat',
		'carrotquest'  => 'chat',
		'talk-me'      => 'chat',
		'vk.com'       => 'social',
		'facebook.net' => 'social',
		'hotjar.com'   => 'analytics',
	);

	public const CRITICAL_THIRD_PARTY = array(
		'cleantalk.org'             => 'antispam',
		'fd-cleantalk.org'          => 'antispam',
		'hcaptcha.com'              => 'captcha',
		'recaptcha.net'             => 'captcha',
		'www.google.com'            => 'captcha',
		'challenges.cloudflare.com' => 'captcha',
		'yoomoney.ru'               => 'payment',
		'yookassa.ru'               => 'payment',
		'securepay.tinkoff.ru'      => 'payment',
		'checkout.qiwi.com'         => 'payment',
		'js.stripe.com'             => 'payment',
		'checkout.stripe.com'       => 'payment',
		'paypal.com'                => 'payment',
	);

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
	public static function analyse( array $resources, array $bundles = array() ): array {
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

			if ( false !== strpos( strtolower( $url ), '/mmf/' ) ) {
				continue;
			}

			if ( 'css' === $kind ) {
				$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

				foreach ( self::NON_CRITICAL_CSS as $needle => $unused ) {
					if ( str_contains( $host, $needle ) ) {
						$resource['css_widget'] = true;
					}
				}

				$limit = max( 0, (int) MMF_Config::get( 'inline_css_limit', 20 ) ) * 1024;

				if ( (int) ( $resource['size'] ?? 0 ) > 0 && (int) $resource['size'] <= $limit ) {
					$resource['css_inline'] = true;
				}
			}

			$items[] = self::verdict( $resource );
		}

		$blocking  = 0;
		$deferable = 0;

		foreach ( $bundles as $bundle ) {
			if ( ! is_array( $bundle ) || empty( $bundle['url'] ) ) {
				continue;
			}

			$files = array();

			foreach ( (array) ( $bundle['files'] ?? array() ) as $file ) {
				if ( is_array( $file ) && isset( $file['url'] ) ) {
					$files[] = array( 'name' => self::basename( (string) $file['url'] ), 'url' => (string) $file['url'], 'size' => (int) ( $file['size'] ?? 0 ) );
				}
			}

			$items[] = array(
				'url'      => '',
				'name'     => sprintf( /* translators: 1: type, 2: count. */ __( 'пакет %1$s (%2$d файла)', 'modern-minify' ), 'css' === ( $bundle['type'] ?? '' ) ? 'CSS' : 'JS', (int) ( $bundle['count'] ?? count( $files ) ) ),
				'size'     => (int) ( $bundle['before'] ?? 0 ),
				'after'    => (int) ( $bundle['after'] ?? 0 ),
				'verdict'  => self::DONE,
				'reason'   => 'bundled',
				'source'   => self::LOCAL,
				'pattern'  => '',
				'position' => 0,
				'files'    => $files,
			);
		}
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
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$source = self::source( $url );
		$reason = '';

		if ( in_array( $mode, array( 'defer', 'async', 'delayed', 'rocket' ), true ) ) {
			$verdict = self::DONE;
			$reason  = 'already';
		} elseif ( 'css' === $kind ) {
			$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			$acts = (string) ( $resource['action'] ?? '' );

			if ( in_array( $acts, array( 'bundled', 'inlined', 'removed' ), true ) ) {
				$verdict = self::DONE;
				$reason  = 'optimized';
			} elseif ( str_contains( $path, '/themes/' ) ) {
				$verdict = self::CANNOT;
				$reason  = 'css-theme';
			} elseif ( ! empty( $resource['css_widget'] ) ) {
				$verdict = self::CAN;
				$reason  = 'css-widget';
			} elseif ( ! empty( $resource['css_inline'] ) ) {
				$verdict = self::CAN;
				$reason  = 'css-inline';
			} elseif ( str_contains( $path, '/plugins/' ) ) {
				$verdict = self::RISKY;
				$reason  = 'css-plugin';
			} else {
				$verdict = self::RISKY;
				$reason  = 'css-unknown';
			}
		} elseif ( false !== stripos( $url . ' ' . $name, 'jquery' ) ) {
			$verdict = self::RISKY;
			$reason  = 'jquery';
		} elseif ( ! empty( $resource['module'] ) ) {
			$verdict = self::RISKY;
			$reason  = 'module';
		} elseif ( ! empty( $resource['inline_after'] ) ) {
			$verdict = self::RISKY;
			$reason  = 'inline-after';
		} elseif ( self::LOCAL !== $source ) {
			if ( self::host_in( $host, self::CRITICAL_THIRD_PARTY ) ) {
				$verdict = self::CANNOT;
				$reason  = 'third-party-critical';
			} else {
				$known = self::known_reason( $url );

				if ( '' !== $known ) {
					$verdict = self::CAN;
					$reason  = $known;
				} else {
					$verdict = self::RISKY;
					$reason  = 'external-unknown';
				}
			}
		} else {
			$verdict = self::RISKY;
			$reason  = 'local';
		}

		return array(
			'url'      => $url,
			'name'     => '' !== $name ? $name : self::basename( $url ),
			'size'     => (int) ( $resource['size'] ?? 0 ),
			'after'    => (int) ( $resource['bytes_after'] ?? 0 ),
			'action'   => (string) ( $resource['action'] ?? '' ),
			'files'    => array(),
			'verdict'  => $verdict,
			'reason'   => $reason,
			'source'   => $source,
			'pattern'  => self::pattern( $url ),
			'position' => (int) ( $resource['position'] ?? 0 ),
		);
	}

	/**
	 * Where a resource comes from.
	 *
	 * A third-party host that is in the external exclusion list is still
	 * external; the badge says so and the reason explains the exception.
	 *
	 * @param string $url Resource URL.
	 */
	private static function source( string $url ): string {
		if ( MMF_Assets::is_local_url( $url ) ) {
			return self::LOCAL;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return MMF_External::host_is_excluded( $host ) ? self::EXTERNAL_KNOWN : self::EXTERNAL;
	}

	/**
	 * Exact-or-dotted-suffix host match, the same normalisation the external
	 * handler uses.
	 *
	 * @param string                $host  Host.
	 * @param array<string,string>  $hosts Hosts with reason keys.
	 */
	private static function host_in( string $host, array $hosts ): bool {
		foreach ( array_keys( $hosts ) as $needle ) {
			$needle = strtolower( (string) $needle );

			if ( $host === $needle || str_ends_with( $host, '.' . $needle ) ) {
				return true;
			}
		}

		return false;
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
	 * Human source badge.
	 *
	 * @param string $source Source constant.
	 */
	public static function source_label( string $source ): string {
		$labels = array(
			self::LOCAL          => __( 'локальный', 'modern-minify' ),
			self::EXTERNAL       => __( 'внешний', 'modern-minify' ),
			self::EXTERNAL_KNOWN => __( 'внешний (исключение)', 'modern-minify' ),
		);

		return $labels[ $source ] ?? $source;
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
			'external-unknown' => __( 'внешний скрипт, домен не из списка известных', 'modern-minify' ),
			'third-party-critical' => __( 'антиспам, капча или платёжный виджет: должен успеть до отправки формы', 'modern-minify' ),
			'css-theme'    => __( 'CSS темы: формирует первый экран', 'modern-minify' ),
			'css-plugin'   => __( 'CSS плагина: влияние на первый экран неизвестно', 'modern-minify' ),
			'css-unknown'  => __( 'CSS не из темы и не из плагина: происхождение неизвестно', 'modern-minify' ),
			'css-widget'   => __( 'виджет/соцсеть: можно загрузить асинхронно, возможен FOUC', 'modern-minify' ),
			'css-inline'   => __( 'небольшой файл: встраивание убирает запрос', 'modern-minify' ),
			'optimized'    => __( 'уже оптимизирован', 'modern-minify' ),
			'bundled'      => __( 'собран в пакет', 'modern-minify' ),
		);

		return $labels[ $reason ] ?? $reason;
	}
}
