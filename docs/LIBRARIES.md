# Bundled library review

Reviewed 2026-11-19. Sources are web advisories and the library repositories; no
automated dependency scanner covers these (they are vendored, not installed by Composer).

| Library | Bundled version | Source | Licence | Advisories found | Status |
|---|---|---|---|---|---|
| masterminds/html5 | 2.12.0 (commit `15e16663…`) | github.com/Masterminds/html5-php | MIT | GHSA-6c7p-58mg-f5jv, GHSA-pj59-fvjc-74pp | Fixed by upgrade from 2.3.0. Regression tests fail on 2.3.0 and pass on 2.12.0 (`tests/unit/Html5ParserTest.php`). |
| htmLawed | 1.2.15 (file header; Packagist release 1.2.15.1) | github.com/kesar/HTMLawed tag 1.2.15.1 | LGPL-3 / GPL-2+ | Drupal SA-CONTRIB-2026-314/-315 and GHSA-3h6j-9x8m-rg3g concern the Drupal module or an unsafe `elements` config | Upgraded from 1.2.4.1. Our config is `safe=1` with no `elements` override and no `tidy`. I did not confirm that the Drupal module fixes are in the library release, so this is a residual risk. |
| SimplePie | 1.9.0 | github.com/simplepie/simplepie | BSD-3 | CVE-2026-111234 (file:// read via redirects or autodiscovery, reported for versions <= 1.9.0). Advisories: NVD, VulnCheck, GHSA-9fwh-fc64-58p8. | Exploitability in this deployment: **not verified**. Fixed version: **not verified**. Feeds are fetched through `SimplePie_HumbleHttpAgent`, and `tests/unit/SimplePieFileSchemeTest.php` checks that it rejects non-HTTP(S) schemes; that test does not establish exploitability either way. |
| Zend Framework 1 (Cache only) | 2012–2015 code | zf1 | BSD-3 | None found for Zend_Cache. ZF1 is end of life. | Unverified. Uses `unserialize()` on cache files that the app itself writes under the writable cache volume. Risk depends on nobody else writing to that volume. |
| Text_LanguageDetect | SVN 2012 | pear.php.net | BSD-3-Clause selected for the code (see below); `lang.dat` unverified | None found | Unverified. `unserialize()` reads the bundled `lang.dat` model, which is read-only in the image. |
| Readability (PHP port) | 1.7.1 port | FiveFilters | Apache-2.0 / AGPL port | None found | Unverified. Old upstream code. Outputs pass through htmLawed. |
| FeedWriter (upstream) | v1.1.2, commit `b32ed2a68e612a1f6045d00d342df6928ecfecad` (signed tag, verified by GitHub) | github.com/mibe/FeedWriter | GPL-3.0-or-later (`COPYING`) | None found | Replaces the unversioned 2008 copy. Four PHP 8.4 signature fixes are listed in `app/libraries/feedwriter/upstream/NOTICE.md`. RSS 2.0 only; JSON/JSONP output is app code. Covered by `tests/unit/FeedWriterTest.php` and `tests/integration/FeedOutputTest.php`. |
| HumbleHttpAgent, content-extractor | upstream 3.8 | FiveFilters | AGPL-3 | n/a (application code) | Reviewed and hardened in this fork. |
| jQuery (`app/js/jquery.min.js`) | 1.7.2 | jquery.com (bundled upstream) | MIT (header link `jquery.org/license`; standalone MIT text not bundled) | Legacy 1.x branch has known client-side XSS/prototype-pollution CVEs (e.g. CVE-2015-9251, CVE-2019-11358, CVE-2020-11022/-11023) | Residual risk in web UI (`index.php` and `/admin/`); not loaded by RSS/JSON feed endpoints. |
| Bootstrap (`app/css/bootstrap.min.css`, `app/js/bootstrap-*.js`) | 2.0.3 | twitter.github.com/bootstrap (bundled upstream) | Apache-2.0 (in file headers; standalone `LICENSE` text not bundled) | Legacy 2.0.x tooltip/popover XSS advisories (e.g. CVE-2018-14040, CVE-2019-8331) when untrusted HTML is passed to `title`/`data-content` | Residual risk in web UI (`index.php` and `/admin/`); static attributes used in shipped templates. |
| CodeMirror (`app/admin/codemirror/*`) | 2.x (minified, unversioned header) | codemirror.net (bundled upstream) | MIT-style upstream (standalone licence header/file not bundled in `app/admin/codemirror/`) | Unverified (minified legacy 2.x bundle) | Used only on authenticated `/admin/edit-pattern.php`. |

## Change made during this review

`HumbleHttpAgent` previously included legacy `pecl_http` (`METHOD_REQUEST_POOL`) and
`file_get_contents` (`METHOD_FILE_GET_CONTENTS`) transports that could not pin resolved
IP addresses against DNS rebinding. Both legacy transports are now disabled and rejected
with `InvalidArgumentException`; only `METHOD_CURL_MULTI` (with `SafeHttpUrl::resolve()`
and `CURLOPT_RESOLVE` dual-stack pinning on every hop) is permitted.

## Not verified

- No machine-readable scan (OSV, Composer audit) of the vendored code was run.
- Advisory pages were read through search and fetch results. I could not confirm fix
  versions for CVE-2026-111234 or the htmLawed Drupal-module issues in the library itself.

## Text_LanguageDetect licence

- Code: the file header and PEAR package.xml say only BSD. The upstream repository has no LICENSE file. The 3-Clause BSD text in app/libraries/language-detect/LICENSE-BSD-3-Clause.txt is the licence **selected** for the vendored code. It is not a verified copy of the 2005-2006 author's notice.
- Data: lang.dat has no stated licence and unknown provenance. The BSD selection does not cover it. Its licence is **unverified**.
- See `app/libraries/language-detect/NOTICE.md`.
- Release decision: `lang.dat` is redistributed at the maintainer's accepted risk. Its licence remains unverified.
