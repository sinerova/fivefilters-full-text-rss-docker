# AGENTS.md

Hardened Docker image of FiveFilters Full-Text RSS 3.8, running on PHP 8.4. User docs are in `Readme.md`. This file is the map for maintainers and agents.

## Layout

| Path | What it is | Copied into image? |
| --- | --- | --- |
| `app/` | The PHP application (an AGPL fork of upstream), served from `/var/www/html` | yes |
| `app/libraries/` | Vendored third-party code. Versions and sources are in `docs/LIBRARIES.md`. `SafeHttpUrl.php` and `DisableSimplePieSanitize.php` are our own code | yes |
| `app/site_config/standard/` | Placeholder only. The Dockerfile replaces it with `fivefilters/ftr-site-config` at `SITE_CONFIG_COMMIT` | replaced |
| `docker/` | Everything else the image needs: `custom_config.php` (shipped settings), Apache vhost and ports, MPM settings, `php.ini` | yes |
| `custom/` | Bind-mount target for user site rules (`site_config/custom`). Empty in git | no |
| `tests/` | PHPUnit (`unit/`, `integration/`), fixtures, and `container/smoke.sh` for a built image. See `tests/README.md` | no |
| `docs/` | `INVESTIGATION.md` (threat model before hardening), `LIBRARIES.md` (vendored library audit), `calls.http` (example requests), `webui.png` | no |
| `Dockerfile`, `docker-bake.hcl`, `compose.yml` | Build, multi-arch release (amd64 and arm64), runtime defaults | no |

## Commands

```sh
# Unit and integration tests (offline; the fixture server is on 127.0.0.1:8082)
cd tests && composer install
php -S 127.0.0.1:8082 -t fixtures fixtures/router.php &
vendor/bin/phpunit

# Image
docker build -t ftr:test . && tests/container/smoke.sh ftr:test
docker buildx bake          # release: both platforms
```

## Conventions

- Settings: change `docker/custom_config.php`, not `app/config.php`. The image copies it to `/var/www/html/custom_config.php`, which overrides `config.php`. The integration tests copy it the same way.
- All outbound HTTP must go through `app/libraries/SafeHttpUrl.php` (SSRF guard and address pinning). Do not add other transports.
- When you add a file to `app/` that must not be served, also add it to the `LocationMatch` deny rule in `docker/ftr-site.conf` and add a check to `tests/container/smoke.sh`.
- When you change a vendored library, update `docs/LIBRARIES.md`.
- To keep a new top-level file out of the image, add it to `.dockerignore`.
- `docs/INVESTIGATION.md` records the repo as it was before hardening. Do not edit it to match the current state.
