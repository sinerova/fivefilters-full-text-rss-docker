# Investigation findings (pre-change)

Status: this was the pre-change investigation. Its findings were the basis for the hardening work on branch `harden/audit-fixes`. Sections 1, 5 and 6 describe the state before that work. See `Readme.md` for the current state.

## 1. What this repository actually contains

- No application code. It holds a Dockerfile, `custom_config.php` (a copy of upstream `config.php` plus an env-var override loop), `compose.yml`, `docker-bake.hcl`, `calls.http` (httpyac smoke tests) and docs. There are no unit tests and no CI (workflows disabled).
- The Dockerfile clones `https://bitbucket.org/fivefilters/full-text-rss.git` at build time and pins commit `384d52fd83361ffd6e7f28bd39b322970a015a28` (2019-04-05, "Fix PHP 7.2/7.3 incompatibilites").
  - `config.php` reports `_FF_FTR_VERSION` 3.8. The bake file tags it 3.8.1.
  - The upstream changelog lists 3.9.x as newer, so this commit is a pre-3.9 snapshot.
- It also clones `fivefilters/ftr-site-config` (HEAD, unpinned) into `site_config/standard/`.
- The runtime is `php:5-apache` (mod_php) on Debian stretch from archive.debian.org, installed with `--allow-unauthenticated`. Cache and site_config are `chmod 777` and the container runs as root.
- Build is not reproducible: the config repo is unpinned and the base image is a floating tag.

## 2. Upstream status and licensing

- The Bitbucket source at the pinned commit includes `license.txt`, which is **GNU AGPL-3.0**. This is the free, publicly cloneable snapshot.
- Bundled libraries have their own licenses:
  - html5php: MIT, with HTML5Lib terms
  - simplepie: BSD-3
  - htmLawed: dual LGPL-3 / GPL-2+
  - Zend Cache: BSD-3
  - Readability and Humble HTTP Agent: authored by FiveFilters, AGPL
- FiveFilters' product page lists **Full-Text RSS 3.9.13 (2023-03-05)** for self-hosting.
  - The changelog says 3.9.12/3.9.13 add PHP 8.1/8.2 fixes and raise the minimum to PHP 7.2.
  - The 2022 blog post says it is available "from the customer portal", free or discounted for existing customers. The page says "We're working on an update…".
  - I found no public source for 3.9.x. I did not try to obtain it and did not touch any paid or private source.
- 3.9.13 is the only maintained upstream path found. It is paid and not redistributable from this repo.
- The hosted alternatives (Feed Control, RapidAPI) are not self-hosted Docker.
- ftr-site-config is the public rules repo. I did not check its license for this report. It must be checked before bundling.

## 3. Compatibility with PHP 8

Static grep over the pinned commit (131 files, 101 PHP) found:

- Removed or fatal on PHP 8:
  - `each()` in `libraries/Zend/Cache/Backend.php` and `admin/apc.php`
  - `__autoload()` in `cleancache.php`
  - `libxml_disable_entity_loader` usage (deprecated)
- Behaviour risks:
  - `unserialize()` in Zend Cache (`Core.php`, `Backend/File.php`) and in LanguageDetect
  - Dynamic `require` in the autoloader
- A full audit still needs a PHP 8.x runtime and linter. Neither `php` nor `docker` is installed in this environment, so I cannot build, run or lint anything here.
- SimplePie in the tree is old (a 1.5.x line was needed for PHP 8). 3.9.x upgraded SimplePie and html5-php, so patching 3.8 means porting those upgrades too.

## 4. Threat model (summary)

| Surface | Concern in the 3.8 code |
|---|---|
| `url=` on `extract.php` and `makefulltextfeed.php`, plus feed item links | **SSRF**. Only a substring allow/block list exists. No private, loopback, link-local or metadata IP checks, no DNS-rebinding pinning, `CURLOPT_SSL_VERIFYPEER` is 0 in RollingCurl, and redirects are followed manually. |
| Response bodies | No visible size cap. Memory and CPU exhaustion from huge feeds and pages. |
| `siteconfig=` request parameter | User-submitted extraction rules. Off by default (`user_submitted_config=false`). Keep it off. |
| `inputhtml` POST | Large input, and XSS if output is rendered. |
| Disk cache (Zend, `unserialize`) | Object injection if the cache dir is attacker-writable. The key is an md5 of parameters, so it is not a traversal vector by itself. |
| Admin (`/admin`) | Password from `FTR_ADMIN_PASSWORD`. Its default is `false` from `getenv`, so admin only works when the variable is set. It edits `site_config` on disk and can fetch rule updates, so it is a write path. |
| `debug` mode | `$options->debug = true` in `custom_config.php`. Lets any user request raw HTML and headers. Should be off. |
| `cors`, `xss_filter` | Defaults are loose (`xss_filter` is `user`, so XSS filtering is opt-in per request). |
| Runtime | PHP 5 with mod_php, as root. Per the repo's own advisory it is unfixable. |
| Secrets | Admin password and API keys by env var. None baked in. |
| Network | Outbound HTTP(S) only. Needs egress controls outside the container as well. |

## 5. Options

1. **Fork the AGPL 3.8 snapshot and port it to PHP 8.2+.**
   - Legal under AGPL-3.0: keep the notices, mark the changes, and offer source to network users.
   - Work needed: port the old bundled libraries, add an SSRF-safe fetcher, add size and time limits, and add tests.
   - Behaviour will drift from 3.9.x. This is a real fork to maintain, and the upstream author's own packager chose not to do it.
2. **Buy or obtain 3.9.13 from FiveFilters** and build the image around it.
   - This is the best compatibility and is upstream-maintained.
   - It is paid and the code cannot be committed here. The Dockerfile would need a user-supplied zip. It cannot be tested or built by me without access.
3. **Replace it** with a different extractor (for example the defuddle container the previous maintainer mentioned) behind the same URLs.
   - It loses site-config rules and feed enrichment. It is a feature removal, so it needs your explicit sign-off.

## 5a. Verified state of the fork (PHP 8.3.37 run locally, built-in server, fixture-only URLs)

Environment for this section: an early run on PHP 8.3.37 with the built-in server and fixture-only URLs. Later work was done with Docker on PHP 8.4.28 (PHPUnit suite and container smoke test).

Fixed so far (all in `app/`):
- Parse errors on PHP 8: `__autoload` (`cleancache.php`), `$str{n}` string offsets (`LanguageDetect.php`).
- Removed `each()` (`Zend/Cache/Backend.php`, `admin/apc.php`).
- `libxml_disable_entity_loader` is only called before PHP 8.0 (it is a no-op since 8.0).
- `display_errors` is now off. Errors go to the log only. Deprecations are still logged, not hidden.
- `utf8_encode()` and `mb_convert_encoding` deprecations remain; they are logged, not fixed yet.
- Feed title/description were double-encoded (`&amp;amp;`). Fixed by decoding before `strip_tags`.
- SimplePie replaced: 1.4.3 (2013-era, broken on PHP 8 with `implode` type errors) → 1.9.0 (BSD-3, upstream, tag 1.9.0 commit 76cccb1). Legacy `SimplePie_*` shims are still used, which keeps the call sites unchanged.

Confirmed working on fixtures: `extract.php` returns JSON with title/excerpt/word_count; `makefulltextfeed.php` returns RSS with items and `dc:*` fields.

Not yet done: SSRF guard, response size limits, TLS verification (`RollingCurl` sets `SSL_VERIFYPEER=0`), admin password comparison and output escaping, Docker image, tests.

Note: `custom_config.php` env-override loop sits in a block guarded by `basename(__FILE__)=='config.php'`, so env overrides are not applied as written. Fix needed.

## 6. Recommendation

Option 1 for the free path, or option 2 if you hold or want a FiveFilters license. I have not started either, because both are decisions you reserved.

Whichever you pick, these apply:

- Pin everything: base image by digest, rules repo by commit, and add a lockfile for any Composer dependencies.
- Run as non-root, with a read-only root filesystem and writable cache and custom paths only.
- Turn debug off, keep user-submitted siteconfig off, and gate admin on a set password.
- Add an SSRF-safe fetcher with an IP check after DNS resolution and pinning of the resolved IP, plus per-hop redirect checks, size limits and timeouts.
- Add PHPUnit tests against a local fixture server.

Unverified: I could not independently confirm the PHP2Shell CVE details. They come from the issue text in this repo. Check them against the PHP release notes before quoting them.
