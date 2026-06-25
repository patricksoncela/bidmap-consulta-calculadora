FROM php:8.2-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates libcurl4-openssl-dev \
    && docker-php-ext-install mysqli pdo pdo_mysql curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html

RUN mkdir -p storage/processos_pdf storage/logs \
    && chown -R www-data:www-data storage

CMD ["php", "scripts/processar_jobs_redis_loop.php", "5"]
