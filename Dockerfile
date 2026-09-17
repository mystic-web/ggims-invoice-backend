FROM php:8.2-cli

# System deps
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpq-dev libonig-dev \
    && docker-php-ext-install pdo pdo_pgsql zip mbstring \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction

# Render provides $PORT at runtime; Laravel's built-in server binds to it.
ENV PORT=10000
EXPOSE 10000

# TEMPORARY: wipes all invoice + processed-email data on startup.
# Remove the truncate line after this deploy runs once!
CMD php artisan config:cache && php artisan migrate --force && php artisan tinker --execute="\DB::table('invoices')->truncate(); \DB::table('processed_emails')->truncate(); \App\Models\User::firstOrCreate(['email' => 'accounts@ggims.com'], ['name' => 'Accounts Team', 'password' => bcrypt('test1234')]);" && php artisan serve --host=0.0.0.0 --port=$PORT
