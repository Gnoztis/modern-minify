# Vendored minifiers

Third-party code only. Nothing here is plugin logic; the wrappers live in
`includes/class-mmf-minifier.php`.

| Directory | Library | Version | License |
|---|---|---|---|
| `jsmin/` | mrclay/jsmin-php | 2.4.3 | MIT (`jsmin/LICENSE.txt`) |
| `minify-css/` | matthiasmullie/minify | 1.3.76 | MIT (`minify-css/LICENSE`) |
| `minify-css/path-converter/` | matthiasmullie/path-converter | 1.1.3 | MIT (`minify-css/path-converter/LICENSE`) |

Rules for these directories:

1. Files are copied, not installed: no Composer, no `vendor/` directory, no
   autoloader. The plugin loads them with `require_once` guarded by
   `class_exists()`, so a copy already declared by another plugin wins.
2. The only changes made to a copied file are the `ABSPATH` guard at the top and
   four analyser pragmas on false positives, all marked in place:

   - `minify-css/Minify.php:202` — `CacheItemInterface` type hint of the optional
     PSR-6 `cache()` method, which this plugin never calls;
   - `minify-css/CSS.php:814` — the closure captures itself by reference, which
     `check-undefined-vars` reads as an undefined variable;
   - `minify-css/path-converter/Converter.php:100` and `:101` — `$count` is a
     by-reference output of `preg_replace()`, not an uninitialised read.

   Removing a pragma makes `node tools/scripts/preflight.mjs modern-minify` fail;
   keep the reason string intact.
3. Updating a library means replacing the files and the licence together,
   updating the version in this table and in `README.md`, and re-running the
   minifier fixtures.
4. `minify-css/` carries the subset the CSS minifier needs: the abstract base
   class, its exceptions and the path converter used when a file (not a string)
   is minified. The JavaScript minifier of that library is not vendored.
