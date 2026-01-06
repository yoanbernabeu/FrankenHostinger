FROM dunglas/frankenphp

# Dépendances système
RUN apt-get update && apt-get install -y unzip && rm -rf /var/lib/apt/lists/*

# Extension PostgreSQL
RUN install-php-extensions pdo_pgsql

# Installer Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Symfony en mode prod
ENV APP_ENV=prod

# Copier l'application
COPY . /app

# Installer les dépendances (APP_ENV=prod évite le chargement des bundles dev)
RUN composer install --no-dev --optimize-autoloader --no-scripts \
    && composer dump-autoload --optimize

# Script d'entrypoint pour init DB + migrations
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
