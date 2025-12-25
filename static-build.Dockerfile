FROM dunglas/frankenphp:static-builder

RUN apk add --no-cache php84-iconv php84-fileinfo php84-curl php84-dom php84-xml

WORKDIR /go/src/app/dist/static-php-cli
RUN git pull || true
RUN composer install --no-dev -a --no-interaction

WORKDIR /work

COPY . /work/app

ENV SPC_OPT_DOWNLOAD_ARGS="--ignore-cache-sources=php-src --retry 5 --prefer-pre-built"
ENV SPC_OPT_BUILD_ARGS="--no-strip --disable-opcache-jit"

RUN /go/src/app/dist/static-php-cli/bin/spc doctor --auto-fix && \
    /go/src/app/dist/static-php-cli/bin/spc craft /work/app/craft.yml

RUN /go/src/app/dist/static-php-cli/bin/spc micro:combine /work/app/dist/database-mcp.phar -O /work/app/dist/database-mcp
