# Modern Minify

Минификация CSS, JavaScript и HTML, отложенная загрузка скриптов и обработка
Google Fonts — без Composer, без внешних зависимостей и без логов.

Версия 0.1.0. Требуется WordPress 7.0+, PHP 8.0+. Лицензия GPL-2.0-or-later.

## Статус: этап 1 — фундамент

Сейчас в плагине есть модель настроек, файловый кеш, обёртки над двумя
минификаторами и штатное обновление из GitHub. Переписывания HTML, `async` /
`defer`, объединения файлов, административных экранов и работы со шрифтами в
этой версии **нет** — они появятся на этапах 2 и 3. Настройки уже описаны
полностью, поэтому этап 2 добавляет к ним экран, а не новые поля.

### Что работает в 0.1.0

| Что | Как |
|---|---|
| Каркас | шапка плагина, константы `MMF_VERSION`, `MMF_PLUGIN_FILE`, `MMF_DIR`, `MMF_URL`, загрузка модулей на `plugins_loaded` |
| Настройки | одна опция `mmf_settings`, дефолты, приведение типов, версия схемы, `MMF_Config::sanitize()` для `register_setting()` |
| Кеш | `uploads/mmf/`, имя файла = хеш содержимого и настроек, `put/get/url/path/delete_all/stats`, ежедневная уборка по TTL через WP-Cron |
| Минификаторы | JS — `JSMin\JSMin`, CSS — `MatthiasMullie\Minify\CSS`, при ошибке возвращается исходник, результат кешируется по хешу |
| Обновления | штатный механизм WordPress: релизы и теги GitHub, класс `MMF_GitHub_Updater` (сгенерирован из общего эталона) |
| Удаление | `uninstall.php`: опция, транзиенты проверки обновлений, cron, каталог кеша |

## Настройки

Все значения живут в одном массиве `mmf_settings`; читаются только через
`MMF_Config::get()`, поэтому дефолты и типы применяются в одном месте.

| Ключ | Тип | По умолчанию | Что задаёт |
|---|---|---|---|
| `enabled` | bool | `false` | главный выключатель: выключенный плагин ничего не трогает |
| `minify_js` | bool | `true` | минифицировать JavaScript |
| `minify_css` | bool | `true` | минифицировать CSS |
| `minify_html` | bool | `true` | минифицировать HTML |
| `merge_js` | bool | `false` | объединять JS-файлы |
| `merge_css` | bool | `false` | объединять CSS-файлы |
| `inline_css` | bool | `false` | встраивать небольшие CSS-файлы в страницу |
| `remove_print_css` | bool | `false` | убирать стили `media="print"` |
| `async_js` | список | `[]` | шаблоны путей, которым ставится `async` |
| `defer_js` | bool | `false` | ставить `defer` всем скриптам, кроме исключений |
| `exclude_js` | список | `[]` | шаблоны путей, которые не обрабатываются |
| `exclude_css` | список | `[]` | то же для CSS |
| `disable_when_logged_in` | bool | `false` | не обрабатывать страницы авторизованных |
| `remove_meta` | bool | `false` | убирать лишние `meta`-теги |
| `remove_emoji` | bool | `false` | убирать emoji-скрипты ядра |
| `fonts_method` | `inherit` \| `inline` \| `async` \| `exclude` | `inherit` | что делать с Google Fonts |
| `fonts_swap` | bool | `true` | добавлять `display=swap` |
| `fonts_local` | bool | `false` | отдавать шрифты локально |
| `fonts_disable` | bool | `false` | не обрабатывать шрифты |
| `cache_ttl` | int, 0…3650 | `30` | срок жизни файлов кеша в днях; `0` — не удалять по возрасту |
| `debug` | bool | `false` | режим отладки для экрана статуса |

Списки принимают и массив, и строку: элементы разделяются переводом строки или
запятой, пустые и повторяющиеся отбрасываются, порядок сохраняется.

Экрана настроек в 0.1.0 нет: значения пишутся через `MMF_Config::update()`.
Ограничение — не архитектурное, а этапное: `sanitize()` и карта полей
(`MMF_Config::fields()`) уже готовы, этап 2 строит по ним форму. Опция уже
зарегистрирована в Settings API (`register_setting( 'mmf', 'mmf_settings' )` на
`admin_init`), поэтому сохранение из будущей формы автоматически проходит через
`sanitize()`.

## Кеш

- Каталог: `wp-content/uploads/mmf/` текущего сайта.
- Имя файла: `md5( исходник + отпечаток настроек + версия плагина )` плюс
  `.min.js` или `.min.css`. Одинаковый вход даёт одинаковое имя, изменённая
  настройка или новая версия плагина — новое имя вместо устаревшего файла.
- Заглушка `index.php` (листинг каталога закрыт), `.htaccess` нет: файлы должны
  отдаваться браузеру.
- Уборка: `wp_schedule_event( …, 'daily', 'mmf_cache_cleanup' )`, удаляются файлы
  старше `cache_ttl`. `0` в TTL означает «хранить до ручной очистки».
- Полная очистка — `MMF_Cache::delete_all()`; каталог при этом остаётся.
  `uninstall.php` удаляет и его.

## Библиотеки

Сторонний код лежит в `lib/` обычными файлами, с лицензиями рядом. Composer,
`vendor/` и PSR-4-автозагрузка не используются: каждый файл подключается через
`require_once` под `class_exists()`, поэтому уже загруженная другим плагином
копия не объявляется второй раз.

| Каталог | Библиотека | Версия | Лицензия |
|---|---|---|---|
| `lib/jsmin/` | [mrclay/jsmin-php](https://github.com/mrclay/jsmin-php) | 2.4.3 | MIT |
| `lib/minify-css/` | [matthiasmullie/minify](https://github.com/matthiasmullie/minify) | 1.3.76 | MIT |
| `lib/minify-css/path-converter/` | [matthiasmullie/path-converter](https://github.com/matthiasmullie/path-converter) | 1.1.3 | MIT |

Правки в скопированных файлах — guard `ABSPATH` в начале (чтобы файл нельзя
было вызвать напрямую) и несколько помеченных pragma анализатора на ложные
срабатывания; подробности — в `lib/README.md`.

## Структура

```
modern-minify/
├── modern-minify.php              шапка, константы, загрузка модулей
├── uninstall.php                  удаление данных
├── includes/
│   ├── class-mmf-config.php       настройки, дефолты, санитайзер
│   ├── class-mmf-cache.php        файловый кеш, TTL, cron
│   ├── class-mmf-minifier.php     обёртки над минификаторами
│   └── class-mmf-github-updater.php   сгенерированный апдейтер
└── lib/                           минификаторы с лицензиями
```

## API

```php
MMF_Config::all();                 // все настройки с дефолтами
MMF_Config::get( 'minify_css' );   // одна настройка
MMF_Config::is_enabled();          // главный выключатель
MMF_Config::update( array( 'minify_js' => false ) );

MMF_Minifier::minify_js( $js );    // исходник при любой ошибке
MMF_Minifier::minify_css( $css );  // то же для CSS
MMF_Minifier::stats();             // счётчики: js, css, cached, failed

MMF_Cache::hash( $source, 'css' ); // имя кеша
MMF_Cache::put( $hash, $minified, 'css' );
MMF_Cache::get( $hash, 'css' );
MMF_Cache::url( $hash, 'css' );
MMF_Cache::delete_all();
MMF_Cache::stats();                // файлы, размер, дата обновления
```

## Что дальше

1. **Этап 2**: переписывание HTML через буферизацию вывода, `async` / `defer`,
   объединение файлов, инлайн CSS, исключения, экраны настроек и статуса.
2. **Этап 3**: Google Fonts и Font Awesome — `display=swap`, локальные копии,
   отключение обработки.

## Проверки

```powershell
node tools/scripts/preflight.mjs modern-minify
node tools/scripts/sync-updater.mjs modern-minify --check
```

Версия в шапке, константа `MMF_VERSION` и этот файл должны совпадать: сейчас
`0.1.0`.
