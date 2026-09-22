# syntax=docker/dockerfile:1

# ---- Stage 1: build the Tailwind CSS bundle -------------------------------
FROM node:20-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm install --legacy-peer-deps
COPY src ./src
RUN npx @tailwindcss/cli -i ./src/input.css -o ./src/output.css --minify

# ---- Stage 2: the actual app image -----------------------------------------
FROM php:8.2-apache AS app

# PostgreSQL client lib + the pgsql extension the app uses (pg_connect),
# plus mbstring/curl which PHPMailer and the Gemini/route-optimization
# calls need.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip curl libonig-dev \
    && docker-php-ext-install pgsql mbstring \
    && a2enmod rewrite headers \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Apache should serve the project root (index.php, landing.php, etc. all live
# there), not a public/ subfolder.
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri -e "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/*.conf \
    && sed -ri -e "s!/var/www/!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && sed -ri -e 's/^Listen 80$/Listen 0.0.0.0:80/' /etc/apache2/ports.conf \
    && echo "Listen 0.0.0.0:80" >> /etc/apache2/ports.conf

WORKDIR /var/www/html

# App code. .dockerignore keeps node_modules, .git, .env and local uploads
# out of the build context.
COPY . .

# Bring in the CSS built in the assets stage instead of shipping node_modules.
COPY --from=assets /build/src/output.css ./src/output.css

# uploads/ must be a persistent volume on the host platform (Hostforge:
# attach a volume mounted at this path) — container filesystem is ephemeral,
# so anything written here is lost on redeploy otherwise.
RUN mkdir -p uploads/fuel_receipts uploads/pod uploads/vehicle_documents \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 750 uploads

EXPOSE 80

# Start Apache in foreground
CMD ["apache2-foreground"]
