# VPS Deployment

Recommended workflow:

```text
Local VS Code
-> git commit
-> git push
-> VPS git pull
-> composer install
-> npm ci && npm run build
-> php artisan migrate
-> reload queue / scheduler / nginx
```

## Local Responsibilities

- Edit code in `F:\marketx`.
- Keep product docs, Laravel app code, migrations, and tests in Git.
- Do not commit `.env`, `vendor`, `node_modules`, certificates, or local Composer files.

## VPS Responsibilities

- Run PHP, Composer, Node.js, PostgreSQL, Nginx, scheduler, and queues.
- Own production `.env`.
- Store runtime logs and caches.

## First VPS Setup

Install packages:

```bash
sudo apt update
sudo apt install -y nginx postgresql postgresql-contrib git unzip curl
```

Install PHP and extensions needed by Laravel:

```bash
sudo apt install -y php php-cli php-fpm php-pgsql php-mbstring php-xml php-curl php-zip php-bcmath
```

Install Composer:

```bash
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
sudo mv composer.phar /usr/local/bin/composer
rm composer-setup.php
```

Create database:

```bash
sudo -u postgres psql
CREATE DATABASE marketx;
CREATE USER marketx WITH PASSWORD 'change-this-password';
GRANT ALL PRIVILEGES ON DATABASE marketx TO marketx;
\q
```

Clone and install:

```bash
cd /var/www
sudo git clone <repo-url> marketx
sudo chown -R $USER:www-data /var/www/marketx
cd /var/www/marketx
cp .env.example .env
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
```

Set `.env` database values:

```text
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=marketx
DB_USERNAME=marketx
DB_PASSWORD=change-this-password
```

Build frontend assets after Node.js is installed:

```bash
npm ci
npm run build
```

## Nginx Site

Example:

```nginx
server {
    listen 80;
    server_name your-domain;
    root /var/www/marketx/public;

    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

Enable:

```bash
sudo ln -s /etc/nginx/sites-available/marketx /etc/nginx/sites-enabled/marketx
sudo nginx -t
sudo systemctl reload nginx
```

## Daily Operations

Deploy latest code:

```bash
cd /var/www/marketx
git pull
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Scheduler cron:

```bash
* * * * * cd /var/www/marketx && php artisan schedule:run >> /dev/null 2>&1
```

