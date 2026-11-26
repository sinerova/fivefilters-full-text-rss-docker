# Parity with upstream Full-Text RSS 3.9 to 3.9.13

FiveFilters' public changelog (`https://www.fivefilters.org/changelogs/full-text-rss.txt`, read 2026-11-26) lists releases up to **3.9.13 (2023-03-05)**. This fork started from the open 3.8 code. This page lists every changelog line from 3.9 onwards and what was done about it.

## How the work was done

- **Clean-room.** No official open release of the 3.9.x source was found (third-party forks of unclear provenance exist), and no copy was used. Each item below was reimplemented from the one-line changelog description. Behaviour can therefore differ from upstream in details the changelog does not state. Where that matters, the entry says what this fork does.
- The version string stays at 3.8. Changes are recorded under `3.8.2 (unreleased)` in `app/changelog.txt`.
- Every ported item has a test in `tests/` (named in the last column), run against the real endpoint code. Items checked by hand against live sites are marked *live*.

Status key: **ported** (new here), **already present** (the fork already behaved this way), **exceeded** (this fork is ahead), **N/A** (does not apply to this image), **skipped** (not done, with the reason).

## 3.9.13 (2023-03-05)

| Upstream change | Status | Notes and tests |
| --- | --- | --- |
| More PHP 8.1 and 8.2 compatibility fixes | **ported**, for 8.4 | Found with a stricter test harness (it now fails on any PHP warning, notice or deprecation, and PHPUnit fails on deprecations): `HTML-ENTITIES` conversion (Readability, JSLikeHTMLElement), three implicit nullable parameters, deprecated SimplePie class setters. All `app/` files lint clean on 8.4. |
| Site config file updates | **N/A** | Rules are pinned at image build time (`SITE_CONFIG_COMMIT` in the `Dockerfile`). Update by rebuilding. |

## 3.9.12 (2022-12-31)

| Upstream change | Status | Notes and tests |
| --- | --- | --- |
| PHP 8.1 and 8.2 compatibility fixes | **ported** | See 3.9.13. |
| HTML5-PHP 2.7.6 | **exceeded** | 2.12.0 is bundled (`docs/LIBRARIES.md`). |
| SimplePie 1.7.0 | **exceeded** | 1.9.0 is bundled. |
| Site config file updates | **N/A** | See 3.9.13. |
| Fingerprints for Substack on custom domains | **ported** | Matches `<link rel="preconnect" href="https://substackcdn.com"`, which Substack pages carry near the top of the head, and loads `.substack.com.txt`. The marker is this fork's choice (upstream's is not documented). `FingerprintTest`. *Live*: a custom-domain Substack post extracts with author, date and body. |
| Minimum PHP 7.2 | **N/A** | The image runs PHP 8.4. |

## 3.9.11 (2021-03-06) and 3.9.10 (2021-03-02)

| Upstream change | Status | Notes and tests |
| --- | --- | --- |
| PHP 8 warnings in ContentExtractor | **already present** | No diagnostics from any fixture or live request. The integration harness fails a test on any PHP diagnostic. |
| Improved JSON-LD extraction | **ported** | See 3.9.7. |
| Minor improvements and API parameter description updates | **ported** | The parameter table in `index.php` documents `content=text`. |

## 3.9.9 (2021-02-26) and 3.9.8 (2021-02-13)

| Upstream change | Status | Notes and tests |
| --- | --- | --- |
| Better JSON-LD handling | **ported** | See 3.9.7. |
| JSON-LD data not cleared between items of a feed | **ported** | `JsonLd::extract()` keeps no state. `testJsonLdDataDoesNotLeakIntoTheNextItem`, `JsonLdTest::testNoStateCarriesOverBetweenCalls`. |
| Fix for older OpenSSL (HumbleHttpAgent) | **N/A** | Only the cURL transport remains, with TLS verification on. |

## 3.9.7 (2021-02-11)

| Upstream change | Status | Notes and tests |
| --- | --- | --- |
| Titles double-encoded | **already present** | Fixed during hardening. `testFeedTitleIsEncodedOnceAndKeepsUtf8`, `testItemTitleIsEncodedOnce`. |
| Automatic site config updates broken by GitHub changes | **N/A** | The admin updater needs `ZipArchive` (not in the image) and a writable `site_config/standard` (read-only here). Rebuild the image instead. |
| `&images=0` | **ported**: it was broken | The XPath used `//img` on the extracted body, which Readability returns detached from the document, so nothing matched. Now relative, and `<picture>` is removed too. `testImagesZeroRemovesImages`. *Live*: 13 images become 0 on a Wikipedia article. |
| Proxy servers with auth and PECL HTTP | **N/A** | The PECL HTTP transport was removed (it could not pin addresses). The cURL transport sets `CURLOPT_PROXY` and `CURLOPT_PROXYUSERPWD` from the same options. That code path is unchanged and was not exercised against a real proxy here. |
| JSON-LD for title, author, date; `skip_json_ld: yes` | **ported** | New `JsonLd` class. Handles `@graph`, lists, `author` as string, object, list or `@id` reference. Only used when the page has exactly one distinct Article entity. Bounded by size, depth and count. Applies only where the site config and microformats found nothing. Values are reduced to plain text, with tags removed before and after entity decoding, so entity-encoded markup in a headline or author name cannot come out as live markup (`testJsonLdTitleAndAuthorWithEncodedMarkupStayPlainText`). `JsonLdTest`, `testJsonLdSuppliesTitleAuthorAndDate`, `testSkipJsonLdSiteConfigDirectiveDisablesIt`. *Live*: Substack post. |
| PHP 8 compatible | **ported** | See 3.9.13. |
| HTML5-PHP 2.7.4, SimplePie 1.5.6 | **exceeded** | |
| `subtome.com` links changed to `subscribe.fivefilters.org` | **skipped** | The `rel="related"` link still points to `subtome.com`. `subscribe.fivefilters.org` answered `400 Invalid URL supplied` to a bare request and its parameter format is undocumented, so the new link could not be verified. |
| Minor fixes | n/a | Not itemised upstream. |

## 3.9.6 (2020-04-24)

| Upstream change | Status | Notes and tests |
| --- | --- | --- |
| PHP 7.4 | **N/A** | Superseded by 8.4. |
| Puppet script for Ubuntu 20.04 | **N/A** | The Docker image replaces it. The old `ubuntu-16.04.pp` is no longer in the repository. |
| HTML5-PHP 2.7, SimplePie 1.5.4 | **exceeded** | |
| Minimum PHP 5.6 | **N/A** | |

## 3.9.5 (2019-03-28)

| Upstream change | Status | Notes and tests |
| --- | --- | --- |
| Character encoding: bundled HTML5-PHP is the default parser | **ported** | New `$options->default_parser`, `html5php` by default. It is a behaviour change: set `default_parser` to `libxml` (or `parser: libxml` in a site config, or `&parser=libxml`) for the old behaviour. `testHtml5ParserIsTheDefault`, `testDefaultParserCanBeSetBackToLibxml`, `testDefaultParserFallsBackToLibxmlWhenNotAllowed`. *Live*: 7 real feeds, old image vs new, same items and failures, content size within 4%. |
| RSS preview broken in Firefox | **skipped** | Not reproducible without a browser. The preview stylesheet is unchanged. The response CSP (`style-src 'self'`) was set during hardening. |
| Google Alert feeds: meta refresh handling | **ported** | `HumbleHttpAgent::parseMetaRefresh()`: any attribute order, multi-digit and decimal delays, `url=` in any case, quoted URLs, comments ignored, only the first 128 KiB scanned. Upstream's exact change is not documented. `MetaRefreshTest`. |
| srcset relative URLs rewritten to absolute | **ported** | New `Srcset` class replaces 3.8's removal. Parses candidates per the HTML rules (URLs may contain commas), keeps only http(s) URLs and valid `1x` / `100w` descriptors, caps length and count. Also applied to `<picture><source>`. `SrcsetTest`, `testRelativeSrcsetIsRewrittenToAbsoluteUrls`, `testSrcsetWithDangerousSchemeIsNeverEmitted`. |
| `&xss=0` disables XSS filtering in extract.php | **skipped, by design** | The filter cannot be switched off by a request in this fork (`xss_filter: true`). `testExplicitXssZeroDoesNotDisableTheFilter`. |
| Default User-Agent strings updated | **ported** | Chrome/60 became Chrome/131 (Chrome freezes the minor parts of its UA string). The feed UA is now `PHP/<major.minor>`. |
| HTML5-PHP 2.6 | **exceeded** | |
| `ubuntu-18.04.pp` | **N/A** | |
| `allowed_urls` deprecated for `allowed_hosts` | **ported** | New `HostList` class: exact host or subdomain at a label boundary, case-insensitive, IDN-aware when `ext/intl` is present. `allowed_urls` still works when `allowed_hosts` is empty. Both can also be set with environment variables (`ftr_allowed_hosts`, comma-separated). `HostListTest`, `testAllowedHosts*`, `testLegacyAllowedUrlsStillWorks`. |
| Removed deprecated `filter_var` flags | **already present** | None are used. |
| Tested with PHP 7.3 | **N/A** | |

## 3.9.1 (2018-05-10) and 3.9 (2018-05-06)

| Upstream change | Status | Notes and tests |
| --- | --- | --- |
| Removed `each()` calls | **already present** | None in `app/` outside vendored minified JavaScript. |
| Gumbo whitespace in `<pre><code>` | **N/A** | The Gumbo extension is not in the image. The Gumbo branch of Readability also lost its `HTML-ENTITIES` round trip, which decoded `&lt;` back into markup. |
| `ubuntu-16.04.pp` replaced by `ubuntu-18.04.pp` | **N/A** | |
| `&content=text80` sets the text width | **ported** | `&content=text<N>`, N from 20 to 500. `HtmlToTextTest`, `testContentText80WrapsAt80`. |
| `&content=text` plain text output | **ported** | New `HtmlToText` class: reads the DOM and emits only text, image alt text and absolute link targets, so no markup can reach the output. Runs before the XSS filter on purpose (htmLawed leaves the text of `<style>` elements behind). Because a page can show markup as text (`&lt;img onerror=…&gt;`), which decoding turns into literal `<img onerror=…>`, the text is HTML-escaped again in RSS output, where readers treat the description as HTML. JSON output keeps the raw text. This escaping is this fork's choice and upstream may differ. It is part of the cache key. `testTextOutputInRssCannotBecomeLiveMarkupInAReader`, `testTextOutputInJsonKeepsTheRawText`. `testContentTextReturnsPlainTextWrappedAt70`, `testTextAndHtmlOutputAreCachedSeparately`. *Live*: Wikipedia and a Substack post. |
| Character encoding with Gumbo | **N/A** | |
| Removed deprecated PHP 7.2 calls | **ported** | See 3.9.13. |
| Codeception tests, `RoboFile.php` | **N/A** | The PHPUnit suite replaces them. Command-line use is `php makefulltextfeed.php` or HTTP. |
| `html5_output` removed (always on) | **ported** | The option is gone and HTML5 output is always used. `content=1` and `content=html5` are accepted and change nothing, so existing subscriber URLs keep working. `testLegacyHtml5ContentValuesAreAcceptedAndChangeNothing`. |

## Items not in the upstream list, found while doing this

| Finding | Fix |
| --- | --- |
| JSONP responses were sent without `Content-Type` and `X-Content-Type-Options` (the callback was echoed before the headers). Only matters if `allow_jsonp` is enabled. | Output is buffered so headers go first. Caught by the new diagnostics check (`PHP Warning: headers already sent`). |
| Shipped result caching meant integration tests shared cached output. | The test harness clears the staged cache before each test. |
| Fixable HIGH findings in the base image (57, in the MIT krb5 libraries and `libzstd1`). | Base image digest bumped (same PHP 8.4.28; the digest is the multi-platform index, with `linux/amd64` and `linux/arm64/v8`) and `libzstd1` upgraded at build time. Trivy (amd64 image) now reports none. The arm64 build was not run here. |
| `apc` and `smart_cache` are `true` in the config but APCu is not in the image. | Harmless: both are switched off at runtime when `apc_add()` does not exist. Not changed. |
