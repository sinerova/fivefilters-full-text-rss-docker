# Full-Text RSS (FiveFilters) - maintained fork on PHP 8.4
# Application: app/ in this repository (AGPL-3.0, forked from FiveFilters v3.8).
# Site rules: fivefilters/ftr-site-config (CC0), pinned to the commit below.
# Update process: see README.md, section "Updating".

ARG PHP_IMAGE=php:8.4-apache@sha256:20da4cbf0dbb1b1951a3f88b2fb16a8272dd42c8a02901e7454bfa6151734402
ARG SITE_CONFIG_REPO=https://github.com/fivefilters/ftr-site-config.git
ARG SITE_CONFIG_COMMIT=7356cc5e57a3bcb4ed4b8a59833bc9abb22a91cb


FROM alpine:3.21 AS siteconfig
ARG SITE_CONFIG_REPO
ARG SITE_CONFIG_COMMIT
RUN apk add --no-cache git
WORKDIR /ftr-site-config
RUN git init -q . && \
    git remote add origin "${SITE_CONFIG_REPO}" && \
    git fetch -q --depth 1 origin "${SITE_CONFIG_COMMIT}" && \
    git checkout -q FETCH_HEAD && \
    test "$(git rev-parse HEAD)" = "${SITE_CONFIG_COMMIT}" && \
    rm -rf .git


# Build the tidy extension against libtidy-dev, then record which runtime package
# provides the shared libtidy so the final image installs that package (arch-independent).
FROM ${PHP_IMAGE} AS tidy-build
RUN apt-get update && \
    apt-get install -y --no-install-recommends libtidy-dev && \
    docker-php-ext-install tidy && \
    mkdir -p /out && \
    lib="$(ldd "$(php -r 'echo ini_get("extension_dir");')/tidy.so" | awk '/libtidy/ {print $3}')" && \
    test -n "$lib" && \
    dpkg -S "$(readlink -f "$lib")" | head -n1 | cut -d: -f1 > /out/runtime-pkg && \
    test -s /out/runtime-pkg && \
    cp "$(php -r 'echo ini_get("extension_dir");')/tidy.so" /out/tidy.so


FROM ${PHP_IMAGE}

# No licenses label: the image mixes several licences and one unverified data file.
# See README.md, "Licence". Pass --build-arg IMAGE_SOURCE=<public repository URL> to set the source label.
ARG IMAGE_SOURCE="https://github.com/sinerova/fivefilters-full-text-rss-docker"
LABEL org.opencontainers.image.description="Full-Text RSS (FiveFilters) application; bundled components have their own licences" \
      org.opencontainers.image.source="${IMAGE_SOURCE}"

COPY --from=tidy-build /out/ /tmp/tidy/
RUN apt-get update && \
    apt-get install -y --no-install-recommends "$(cat /tmp/tidy/runtime-pkg)" && \
    cp /tmp/tidy/tidy.so "$(php -r 'echo ini_get("extension_dir");')/tidy.so" && \
    echo "extension=tidy" > /usr/local/etc/php/conf.d/docker-php-ext-tidy.ini && \
    rm -rf /tmp/tidy /var/lib/apt/lists/* && \
    php -m | grep -qi '^tidy$'

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-ftr.ini
COPY docker/ports.conf /etc/apache2/ports.conf
COPY docker/ftr-site.conf /etc/apache2/sites-available/000-default.conf

RUN a2dismod -f autoindex status || true

WORKDIR /var/www/html
COPY app/ /var/www/html/
COPY custom_config.php /var/www/html/custom_config.php
COPY --from=siteconfig /ftr-site-config/ /var/www/html/site_config/standard/

# Root owns the code; the web user can write only the cache and the custom site rules.
RUN mkdir -p /var/www/html/cache/rss /var/www/html/site_config/custom && \
    chown -R root:root /var/www/html && \
    chmod -R go-w /var/www/html && \
    chown -R www-data:www-data /var/www/html/cache /var/www/html/site_config/custom

# Run unprivileged: Apache needs only port 8080 and its own pid/lock/log paths.
RUN mkdir -p /var/run/apache2 /var/lock/apache2 && \
    chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2
USER www-data

EXPOSE 8080
VOLUME ["/var/www/html/cache"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r '$f = @fsockopen("127.0.0.1", 8080, $e, $s, 3); exit($f ? 0 : 1);'
