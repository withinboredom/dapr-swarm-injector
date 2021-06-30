FROM php:8-cli AS base
RUN apt-get update && apt-get install -y wget gpg git unzip && apt-get clean && rm -rf /var/cache/apt/lists
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

FROM base AS monitor-build
RUN install-php-extensions curl zip pcntl @composer
RUN mkdir -p /app && \
    wget -O phive.phar "https://phar.io/releases/phive.phar" && \
    wget -O phive.phar.asc "https://phar.io/releases/phive.phar.asc" && \
    gpg --keyserver hkps://keys.openpgp.org --recv-keys 0x9D8A98B29B2D5D79 && \
    gpg --verify phive.phar.asc phive.phar && \
    rm phive.phar.asc && \
    chmod +x phive.phar && \
    mv phive.phar /usr/local/bin/phive
WORKDIR /app
COPY composer.json composer.json
COPY composer.lock composer.lock
RUN composer install
COPY . .
RUN make -j phar

FROM base AS runtime
RUN install-php-extensions curl zip sodium pcntl

FROM runtime AS monitor
COPY --from=monitor-build /app/monitor.phar /monitor.phar
ENTRYPOINT ["/monitor.phar"]

FROM runtime AS injector
COPY --from=monitor-build /app/injector.phar /injector.phar
ENTRYPOINT ["/injector.phar"]
