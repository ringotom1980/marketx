#!/usr/bin/env bash
set -euo pipefail

sudo apt update
sudo apt install -y software-properties-common ca-certificates lsb-release apt-transport-https

if ! grep -R "ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d >/dev/null 2>&1; then
  sudo add-apt-repository -y ppa:ondrej/php
fi

sudo apt update
sudo apt install -y \
  php8.2 \
  php8.2-cli \
  php8.2-fpm \
  php8.2-pgsql \
  php8.2-mbstring \
  php8.2-xml \
  php8.2-curl \
  php8.2-zip \
  php8.2-bcmath

sudo update-alternatives --set php /usr/bin/php8.2
sudo systemctl enable --now php8.2-fpm

php -v
php-fpm8.2 -v

