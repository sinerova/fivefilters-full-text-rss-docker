# Full-Text RSS (FiveFilters) - maintained fork on PHP 8.4
# Application: app/ in this repository (AGPL-3.0, forked from FiveFilters v3.8).
# Site rules: fivefilters/ftr-site-config (CC0), pinned to the commit below.
# Update process: see Readme.md, section "Updating".

ARG PHP_IMAGE=php:8.4-apache@sha256:20da4cbf0dbb1b1951a3f88b2fb16a8272dd42c8a02901e7454bfa6151734402
ARG ALPINE_IMAGE=alpine:3.21@sha256:bc21487101ae47172509130ee1092cc273a831968d64b617776d1f69c81e42ab
ARG SITE_CONFIG_REPO=https://github.com/fivefilters/ftr-site-config.git
ARG SITE_CONFIG_COMMIT=7356cc5e57a3bcb4ed4b8a59833bc9abb22a91cb


FROM ${ALPINE_IMAGE} AS siteconfig
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
# provides the shared libtidy so the runtime stage installs only that shared library.
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


# Prepare the Debian + Apache + PHP runtime rootfs and purge the inherited C/C++
# build toolchain, headers, and PHP source archive before assembling the final image.
FROM ${PHP_IMAGE} AS runtime-os
COPY --from=tidy-build /out/ /tmp/tidy/
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends "$(cat /tmp/tidy/runtime-pkg)"; \
    cp /tmp/tidy/tidy.so "$(php -r 'echo ini_get("extension_dir");')/tidy.so"; \
    echo "extension=tidy" > /usr/local/etc/php/conf.d/docker-php-ext-tidy.ini; \
    a2dismod -f autoindex status || true; \
    echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf; \
    a2enconf servername; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false $PHPIZE_DEPS $(dpkg-query -W -f='${Package}\n' '*-dev' 'linux-libc-dev' 2>/dev/null || true); \
    docker-php-source delete; \
    rm -rf /tmp/tidy /usr/src/* /usr/include/* /usr/local/include/* /var/lib/apt/lists/* /var/cache/apt/archives/* /tmp/*; \
    mkdir -p /var/run/apache2 /var/lock/apache2; \
    chown root:root /var/www/html; \
    chmod 0755 /var/www/html; \
    chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2; \
    chmod 1777 /tmp /var/tmp; \
    php -m | grep -qi '^tidy$'; \
    ! ldd /usr/local/bin/php /usr/lib/apache2/modules/libphp.so "$(php -r 'echo ini_get("extension_dir");')"/*.so | grep -q 'not found'

COPY --chown=root:root --chmod=0644 docker/php.ini /usr/local/etc/php/conf.d/zz-ftr.ini
COPY --chown=root:root --chmod=0644 docker/ports.conf /etc/apache2/ports.conf
COPY --chown=root:root --chmod=0644 docker/mpm_prefork.conf /etc/apache2/mods-available/mpm_prefork.conf
COPY --chown=root:root --chmod=0644 docker/ftr-site.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY --chown=root:root --chmod=u=rwX,go=rX app/ /var/www/html/
COPY --chown=root:root --chmod=0644 docker/custom_config.php /var/www/html/custom_config.php
COPY --from=siteconfig --chown=root:root --chmod=u=rwX,go=rX /ftr-site-config/ /var/www/html/site_config/standard/

# Only cache/ and site_config/custom/ are writable by www-data; code and standard rules stay root-owned go-w.
RUN mkdir -p /var/www/html/cache/rss /var/www/html/cache/rss-with-key /var/www/html/site_config/custom && \
    chown -R www-data:www-data /var/www/html/cache /var/www/html/site_config/custom && \
    chmod 0750 /var/www/html/cache /var/www/html/cache/rss /var/www/html/cache/rss-with-key && \
    chmod 0755 /var/www/html/site_config/custom && \
    apache2ctl -t


FROM scratch

# No licenses label: the image mixes several licences and one unverified data file.
# See Readme.md, "Licence". Pass --build-arg IMAGE_SOURCE=<public repository URL> to set the source label.
ARG IMAGE_SOURCE="https://github.com/sinerova/fivefilters-full-text-rss-docker"
LABEL org.opencontainers.image.title="Full-Text RSS (FiveFilters) - hardened fork" \
      org.opencontainers.image.description="Maintained PHP 8.4 fork of FiveFilters Full-Text RSS 3.8; bundled components carry their own licences" \
      org.opencontainers.image.source="${IMAGE_SOURCE}"

ENV PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
    PHP_INI_DIR=/usr/local/etc/php \
    APACHE_CONFDIR=/etc/apache2 \
    APACHE_ENVVARS=/etc/apache2/envvars

COPY --from=runtime-os / /
WORKDIR /var/www/html

STOPSIGNAL SIGWINCH
USER www-data

EXPOSE 8080
VOLUME ["/var/www/html/cache"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD ["php", "-r", "$c=stream_context_create(['http'=>['timeout'=>3,'ignore_errors'=>true]]); $b=@file_get_contents('http://127.0.0.1:8080/robots.txt',false,$c); exit(($b!==false && isset($http_response_header[0]) && strpos($http_response_header[0],' 200 ')!==false)?0:1);"]

ENTRYPOINT ["docker-php-entrypoint"]
CMD ["apache2-foreground"]
