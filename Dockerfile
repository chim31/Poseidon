# Poseidon — image de déploiement (PHP 8.4, SQLite ≥ 3.43, poppler, libarchive, Tesseract français)
# Debian 13 « trixie » : SQLite 3.46 (l'index plein texte exige 3.43+).
FROM php:8.4-cli-trixie

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libzip-dev libicu-dev poppler-utils libarchive-tools tesseract-ocr tesseract-ocr-fra \
 && docker-php-ext-install zip intl \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app
RUN mkdir -p /app/var /data \
 && useradd --system --home /app --shell /usr/sbin/nologin poseidon \
 && chown -R poseidon:poseidon /app/var

USER poseidon
ENV POSEIDON_ROOTS=/data \
    POSEIDON_DEMO=0
EXPOSE 8080
VOLUME ["/app/var"]

HEALTHCHECK --interval=30s --timeout=5s CMD php -r '$c=stream_context_create(["http"=>["header"=>"X-Poseidon: 1\r\n"]]); exit(str_contains((string) @file_get_contents("http://127.0.0.1:8080/api/info", false, $c), "Poseidon") ? 0 : 1);'

CMD ["php", "bin/poseidon", "serve", "--host=0.0.0.0", "--port=8080"]
