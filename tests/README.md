# Tests

PHPUnit suite plus a container smoke test. Nothing connects to the internet: feeds and pages come from a local fixture server on `127.0.0.1:8082`, which `SafeHttpUrl::$testAllowedHosts` allows only during tests.

## PHPUnit

```sh
cd tests
composer install
php -S 127.0.0.1:8082 -t fixtures fixtures/router.php &
vendor/bin/phpunit
```

- `unit/`: no network. Covers the SSRF guard, the html5 parser, SimplePie `file://` handling and the feed writer (`FeedWriterTest.php`: RSS 2.0 structure, escaping, repeated elements, JSON and `simplejson` shape), and the 3.9 parity code: `HostList`, `Srcset`, `JsonLd`, `HtmlToText`, meta refresh parsing and the shipped fingerprints.
- `integration/`: runs `makefulltextfeed.php` in a child PHP process against the fixtures. Covers RSS 2.0 and JSON/JSONP output, the XSS default, admin config, fetch limits, and the 3.9 parity features end to end (`content=text`, `images=0`, srcset, JSON-LD, `allowed_hosts`, default parser). Tests that need the fixture server skip if it is not running.
- **Diagnostics are failures.** The integration harness runs the endpoint with `error_reporting=E_ALL` and fails any test that logs a PHP warning, notice, deprecation or fatal error, and `phpunit.xml.dist` fails on deprecations. The shipped `php.ini` hides deprecations in production, so this is where they show up.
- The harness empties the staged app's `cache/` before every test (the shipped config caches results), so tests do not see each other's output.

Set `FTR_FIXTURE_PORT` to use a different port.

## Container smoke test

```sh
docker build -t ftr:test .
tests/container/smoke.sh ftr:test
```

Checks the built image: non-root Apache, read-only root, health check, endpoints and blocked targets.

## Not covered

A live FreshRSS instance is not part of the automated suite. FreshRSS compatibility was checked manually.