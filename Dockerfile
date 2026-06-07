# Dockerfile per LibreMailApi
FROM php:8.3-cli

# Installa estensioni PHP necessarie
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libsqlite3-dev \
    && docker-php-ext-install zip pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

# Installa Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Imposta directory di lavoro
WORKDIR /app

# Copia file di configurazione Composer
COPY composer.json ./

# Installa dipendenze
RUN composer install --no-dev --optimize-autoloader

# Copia il codice dell'applicazione
COPY . .

# Crea directory per storage e log
RUN mkdir -p storage/messages storage/attachments storage/logs logs \
    && chmod -R 755 storage logs

# Espone la porta 8080
EXPOSE 8080

# Suggerimento per mappare volumi locali:
# docker run -p 8080:8080 \
#   -v $(pwd)/config/config.php:/app/config/config.php \
#   -v $(pwd)/storage:/app/storage \
#   libre-mail-api

# Comando di avvio
CMD ["php", "-S", "0.0.0.0:8080", "index.php"]
