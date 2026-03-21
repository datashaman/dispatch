FROM php:8.4-cli

# System dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libzip-dev \
    libsqlite3-dev \
    libonig-dev \
    libxml2-dev \
    nodejs \
    npm \
    && docker-php-ext-install zip pcntl pdo_sqlite mbstring xml xmlwriter \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist

# Install Node dependencies and build assets
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources/ resources/
# Vite reads VITE_* vars from .env at build time; provide defaults via .env.example
COPY .env.example .env
RUN npm run build
RUN rm .env

# Copy application
COPY . .
RUN composer dump-autoload --optimize

# Create SQLite database directory (outside /app to avoid masking migrations)
RUN mkdir -p /data/sqlite && touch /data/sqlite/database.sqlite

# Cache config and routes
RUN php artisan config:cache || true
RUN php artisan route:cache || true
RUN php artisan view:cache || true

EXPOSE 8000 8080

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
