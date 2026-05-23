#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/home/ringo/apps/marketx}"
SERVER_NAME="${SERVER_NAME:-45.121.48.35}"
SITE_NAME="${SITE_NAME:-marketx}"
PHP_FPM_SOCKET="${PHP_FPM_SOCKET:-/run/php/php8.2-fpm.sock}"

cd "$APP_DIR"

php -r '
$path = ".env";
$values = [
    "APP_ENV" => "production",
    "APP_DEBUG" => "false",
    "APP_URL" => "http://'${SERVER_NAME}'",
];
$env = file_exists($path) ? file_get_contents($path) : "";
foreach ($values as $key => $value) {
    $line = $key . "=" . $value;
    if (preg_match("/^" . preg_quote($key, "/") . "=.*/m", $env)) {
        $env = preg_replace("/^" . preg_quote($key, "/") . "=.*/m", $line, $env);
    } else {
        $env .= PHP_EOL . $line;
    }
}
file_put_contents($path, $env);
'

if ! command -v setfacl >/dev/null 2>&1; then
  sudo apt update
  sudo apt install -y acl
fi

sudo setfacl -m u:www-data:x /home/ringo
sudo setfacl -m u:www-data:x /home/ringo/apps
sudo setfacl -R -m u:www-data:rX "$APP_DIR"
sudo setfacl -R -m u:www-data:rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
sudo setfacl -R -d -m u:www-data:rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

sudo tee "/etc/nginx/sites-available/${SITE_NAME}" >/dev/null <<NGINX
server {
    listen 80 default_server;
    listen [::]:80 default_server;

    server_name ${SERVER_NAME};
    root ${APP_DIR}/public;

    index index.php index.html;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:${PHP_FPM_SOCKET};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX

sudo rm -f /etc/nginx/sites-enabled/default
sudo ln -sfn "/etc/nginx/sites-available/${SITE_NAME}" "/etc/nginx/sites-enabled/${SITE_NAME}"

php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

sudo nginx -t
sudo systemctl reload nginx

curl -I "http://${SERVER_NAME}/"

