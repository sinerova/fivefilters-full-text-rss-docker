# Tests

PHPUnit suite plus a container smoke test. Nothing connects to the internet: feeds and pages come from a local fixture server on `127.0.0.1:8082`, which `SafeHttpUrl::$testAllowedHosts` allows only during tests.

## PHPUnit

```sh
cd tests
composer install
php -S 127.0.0.1:8082 -t fixtures fixtures/router.php &
vendor/bin/phpunit
```

- `unit/`: no network. Covers the SSRF guard, the html5 parser, SimplePie `file://` handling and the feed writer (`FeedWriterTest.php`: RSS 2.0 structure, escaping, repeated elements, JSON and `simplejson` shape).
- `integration/`: runs `makefulltextfeed.php` in a child PHP process against the fixtures. Covers RSS 2.0 and JSON/JSONP output, the XSS default, admin config and fetch limits. Tests that need the fixture server skip if it is not running.

Set `FTR_FIXTURE_PORT` to use a different port.

## Container smoke test

```sh
docker build -t ftr:test .
tests/container/smoke.sh ftr:test
```

Checks the built image: non-root Apache, read-only root, health check, endpoints and blocked targets.

## Not covered

A live FreshRSS instance is not part of the automated suite. FreshRSS compatibility was checked manually.