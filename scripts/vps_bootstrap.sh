#!/usr/bin/env bash
set -euo pipefail

sudo apt update
sudo apt install -y \
  nginx \
  git \
  unzip \
  curl \
  ca-certificates \
  php \
  php-cli \
  php-fpm \
  php-pgsql \
  php-mbstring \
  php-xml \
  php-curl \
  php-zip \
  php-bcmath \
  nodejs \
  npm \
  postgresql \
  postgresql-contrib

if ! command -v composer >/dev/null 2>&1; then
  cd /tmp
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  php composer-setup.php
  sudo mv composer.phar /usr/local/bin/composer
  rm -f composer-setup.php
fi

php -v
composer --version
node -v
npm -v
psql --version
nginx -v

