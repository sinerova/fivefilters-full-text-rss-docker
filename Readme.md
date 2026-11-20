# Full-Text RSS (FiveFilters) - hardened Docker image

A containerized [Full-Text RSS](https://www.fivefilters.org/full-text-rss/) service. It extracts full article text for single pages and turns feeds into full-text feeds that FreshRSS and other readers can subscribe to.

> [!IMPORTANT]
> This is a maintained fork of the discontinued `heussd/fivefilters-full-text-rss-docker` image. The old PHP 5 image is no longer supported and should not be used. See [Migration](#migration).
>
> **Internet exposure:** this service fetches arbitrary URLs on behalf of its users. It has SSRF protections, but it has not been independently audited. Do not expose it to the internet without a reverse proxy that adds authentication or rate limits. See [Security](#security).

## What it includes

- Article extraction: `/extract.php?url=...` (JSON)
- Feed conversion: `/makefulltextfeed.php?url=...` (RSS 2.0)
- Web UI at `/` and an optional admin area at `/admin/`
- Site-specific extraction rules from [fivefilters/ftr-site-config](https://github.com/fivefilters/ftr-site-config) (CC0), pinned to a commit
- Custom site rules in `./custom` (mounted at `site_config/custom`)
- On-disk RSS cache in a Docker volume
- PHP 8.4 on Apache, with the `tidy` extension

## Licence

This repository has several parts, and each has its own licence. The image carries no OCI `licenses` label, because it mixes several licences.

| Part | Licence | Where |
| --- | --- | --- |
| Full-Text RSS application (fork of FiveFilters v3.8) | **GNU AGPL v3** | `app/` (see [`app/license.txt`](app/license.txt)) |
| Docker wrapper files (`Dockerfile`, `compose.yml`, `docker-bake.hcl`, `docker/`) | **MIT** (root `LICENSE`) | repository root |
| Site rules (`fivefilters/ftr-site-config`, pinned commit) | CC0 1.0, per upstream | fetched at build time |
| Bundled libraries | Each keeps its own licence | `app/libraries/*/` |

Notes:

- The root [`LICENSE`](LICENSE) (MIT, Fraunhofer FKIE 2017) covers the Docker wrapper only. It does **not** automatically apply to the application or to any bundled component.
- If you run a modified version of the AGPL application for other users over a network, AGPL section 13 requires you to offer them the source of your modified version.
- Bundled components:
  - FeedWriter (`app/libraries/feedwriter/upstream/`): GPL-3.0-or-later, mibe/FeedWriter v1.1.2 (`COPYING`, `NOTICE.md`).
  - Zend Framework 1, Cache only (`app/libraries/Zend/`): New BSD (`LICENSE.txt`).
  - Text_LanguageDetect code (`app/libraries/language-detect/`): BSD 3-Clause, selected for this vendored copy (`LICENSE-BSD-3-Clause.txt`). This is not a verified copy of the original package's notice. See `NOTICE.md` there.
  - Text_LanguageDetect data `lang.dat`: **licence unverified**, separate from the code licence. Upstream provenance is unknown and shipping it does not establish permission to redistribute it.
  - htmLawed (LGPL-3 / GPL-2+), SimplePie (BSD-3), html5php (see its `LICENSE.txt`), Readability (Apache-2.0 header): see `docs/LIBRARIES.md`.

This project is not affiliated with fivefilters.org.
## Quick start

```sh
docker compose up -d --build
```

The service definition is in [compose.yml](compose.yml).

Then open <http://localhost> for the web UI.

Example calls:

```text
http://localhost/extract.php?url=https://example.org/article.html
http://localhost/makefulltextfeed.php?url=https://example.org/feed.xml&max=10
```

See [calls.http](calls.http) for more examples.

## Building

Build locally:

```sh
docker build -t ftr:local .
```

Build the multi-arch release with buildx (linux/amd64 and linux/arm64):

```sh
docker buildx bake
```

Platform status:

- `linux/amd64`: built and tested (smoke test and PHPUnit suite).
- `linux/arm64`: runtime-tested by the maintainer on an ARM64 server. The container started healthy and served requests through a reverse proxy (HAProxy). The full smoke test and PHPUnit suite have not been reported for this platform.
- `linux/arm/v7`: not built or published.

The base image and the site-rules commit are pinned in the `Dockerfile` (`PHP_IMAGE`, `SITE_CONFIG_COMMIT`). Override them with `--build-arg` if needed.

## Configuration

Settings live in `custom_config.php` (copied into the image). Set environment variables in `compose.yml`.

| Variable | Default | Purpose |
| --- | --- | --- |
| `FTR_ADMIN_PASSWORD` | empty | Admin password. **Empty disables the admin area.** Use a long random value. |

Config values in `custom_config.php` that matter for security:

| Option | Shipped value | Meaning |
| --- | --- | --- |
| `debug` | `'admin'` | Debug output is only shown to a logged-in admin. |
| `user_submitted_config` | `false` | Users cannot supply their own site rules via the URL. |
| `xss_filter` | `true` | Scripts and event-handler attributes are removed from every response. See [Limitations](#limitations) before changing it. |

Behaviour from the upstream `config.php` (caching, rate limits, allowed/blocked URLs) can be changed in `custom_config.php`.

## Volumes and paths

| Container path | Writable | Purpose |
| --- | --- | --- |
| `/var/www/html/cache` | yes (volume `rss-cache`) | RSS and content cache |
| `/var/www/html/site_config/custom` | yes (bind mount `./custom`) | Your own site rules |
| everything else | no (`read_only: true`, root-owned) | Application code and bundled rules |
| `/tmp`, `/run/apache2`, `/var/lock/apache2` | tmpfs | Runtime state |

## FreshRSS setup

Compatibility with FreshRSS (`freshrss/freshrss:edge`) has been checked and passed.

1. Put both containers on one user-defined Docker network, so FreshRSS can resolve the feed service by name:
   ```sh
   docker network create <network>
   docker network connect <network> <feed-container>
   docker network connect <network> <freshrss-container>
   ```
2. On the FreshRSS container, allow it to reach the feed service. FreshRSS blocks private addresses (including Docker network addresses) by default. Add only this service and port:
   ```sh
   -e INTERNAL_HOST_ALLOWLIST=<feed-container>:8080
   ```
   Use your feed container's name and port. Do not use `*` or a whole subnet; both disable FreshRSS's SSRF checks.
3. In FreshRSS, add a new feed using `http://<feed-container>:8080/makefulltextfeed.php?url=<feed-url>`. Use the container name, not `127.0.0.1` (that is the FreshRSS container itself).
4. `max=` limits the number of items. It is capped by `max_entries` in `custom_config.php`, so raise that cap there if you need more.

Blocked targets return HTTP 400 with the message `This URL cannot be fetched`. FreshRSS shows that as a feed error. This is expected for private or metadata addresses.

Feed downloads have a 20-second timeout, and each article fetch has a 15-second timeout.

## Security

Outbound requests (the target URL, feed item links and redirects) go through `app/libraries/SafeHttpUrl.php`:

- Only `http` and `https` are allowed. No credentials in URLs. Ports limited to 80, 443, 8080 and 8443.
- Host names are resolved once, and every resolved address must be public. Loopback, private (RFC 1918), link-local (including `169.254.169.254` cloud metadata), CGNAT, multicast, reserved, IPv6 ULA and link-local, and IPv4-mapped/NAT64/6to4 forms of these are refused.
- The connection is pinned to the checked address (`CURLOPT_RESOLVE`), which blocks DNS rebinding.
- Every redirect hop is checked again before it is followed.
- TLS certificates are verified.
- A response larger than 10 MiB is aborted.
- Timeouts: 15 s per request by default.

Other hardening:

- Apache runs as `www-data` on port 8080 inside the container (no root process). Web access to `/cache` and `/site_config` is denied.
- The admin login compares credentials with `hash_equals`, regenerates the session on login, and escapes the username field.
- Debug output is limited to admins.
- PHP `display_errors` is off. Errors go to the container log.
- The container runs with a read-only root filesystem, `cap_drop: ALL`, and `no-new-privileges`.
- A health check probes port 8080.

### Limitations

- **The guard checks addresses at request time.** A malicious site that changes DNS between our check and our connection is blocked by pinning, but if the resolver returns no public address the request is refused. Targets that fail the guard return `400 This URL cannot be fetched`. The message is generic and does not say which check failed or what the host resolves to. URLs blocked by `blocked_urls` return `403`.
- **XSS filtering is on by default** (`xss_filter = true`), using htmLawed 1.2.15.1 in safe mode. Scripts, event handlers and `javascript:` links are removed. Setting it to `'user'` or `false` returns the upstream markup, and feed readers that render descriptions as HTML can then run inline handlers. Only do that for a consumer that filters HTML itself.
- **Feed encoding:** titles are encoded once and UTF-8 is preserved (verified by tests).
- **Bundled libraries:** SimplePie 1.9.0, htmLawed 1.2.15 and html5php 2.12.0 (fixes GHSA-6c7p-58mg-f5jv and GHSA-pj59-fvjc-74pp). SimplePie is affected by CVE-2026-111234 (`file://` read via redirects or autodiscovery links, reported for versions up to 1.9.0). Whether this deployment is exploitable has not been verified, and the fixed version has not been verified. See `docs/LIBRARIES.md`. Readability, Zend Cache and LanguageDetect are old upstream code with no advisories found, but unverified. See `docs/LIBRARIES.md` for versions, sources, licences and what could not be verified.
- **Not audited.** Treat this as a hardened hobby-grade service, not a hardened production one.

### Scanning

Image OS packages were scanned with Trivy (`--severity HIGH,CRITICAL --ignore-unfixed`): no findings. Trivy does not check the bundled PHP libraries.

## Updating

1. Base image: pick a new `php:8.4-apache` build, look up its digest (`docker buildx imagetools inspect php:8.4-apache`), and update `PHP_IMAGE` in the `Dockerfile`.
2. Site rules: pick a commit of `fivefilters/ftr-site-config`, verify it, and update `SITE_CONFIG_COMMIT`.
3. Run the test suite (see [Tests](#tests)) on the new image's PHP version.
4. Rebuild and scan.

## Tests

```sh
cd tests
composer install
# start the fixture server (serves on 127.0.0.1:8082):
php -S 127.0.0.1:8082 -t fixtures fixtures/router.php &
vendor/bin/phpunit
```

The tests run offline. They use `SafeHttpUrl::$testAllowedHosts` to allow the local fixture server; production code leaves this unset. The integration tests that need the fixture server skip if it is not running.

## Migration

- The old image ran on PHP 5 with `chmod 777` on the cache and rule directories and as root. The new one runs PHP 8.4 with read-only code and a non-writable web root.
- The `rss-cache` volume is now mounted at `/var/www/html/cache` (it was `/var/www/html/cache/rss`). The app writes to `cache/rss/` under that path, so existing cache data stays in use. The cache is disposable, so you can also delete the volume.
- The `./custom` bind mount is unchanged.
- The container now listens on 8080 internally. The compose file maps host port 80 to it.
- `FTR_ADMIN_PASSWORD` is unchanged. Empty disables admin.
- Existing site rules in `custom/` are read as before.

## Changes from upstream

- Ported to PHP 8.3+ (`__autoload`, `each()`, curly-brace string offsets, `utf8_encode` deprecations addressed where they affect output).
- Replaced SimplePie 1.4.3 with 1.9.0 (BSD-3).
- Added `app/libraries/SafeHttpUrl.php` and wired it into `HumbleHttpAgent` and the feed/article entry points.
- Enabled TLS verification in `RollingCurl` (was disabled).
- Added the response size limit and the 10-MiB abort.
- Fixed PHP 8 `CurlHandle` string conversion in `RollingCurl`.
- Fixed feed title double-encoding (`&amp;amp;`) and Latin-1 output.
- Rejected explicit non-HTTP schemes (`file://`, `gopher://`) before the `http://` prefix is added.
- Admin login: `hash_equals`, session regeneration, escaped username, escaped redirect field.
- `debug` restricted to admin.
- Dockerfile rewritten: pinned base image and site-rules commit, Apache runs as `www-data`, read-only code.
- Blocked targets return 400 with a generic message instead of an empty 200.
- XSS filter on by default. htmLawed updated to 1.2.15.1, with a class wrapper for the existing call site.
- Replaced the bundled 2008-era FeedWriter with upstream mibe/FeedWriter v1.1.2 (GPL-3.0) for RSS 2.0 output. JSON, JSONP and `simplejson` output is generated by code in this repository.
- Removed the compatibility-check page and the APC admin page. Removed an empty stray file in `custom/`.
- Image name defaults to `ghcr.io/sinerova/fivefilters-full-text-rss` (`IMAGE`). The OCI `source` label defaults to this repository's URL and can be overridden with `--build-arg IMAGE_SOURCE=<url>`.

The full investigation and threat model are in [docs/INVESTIGATION.md](docs/INVESTIGATION.md).
