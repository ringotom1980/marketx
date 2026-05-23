#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/home/ringo/apps/marketx}"
DB_NAME="${DB_NAME:-marketx}"
DB_USER="${DB_USER:-marketx}"
DB_PASSWORD="${DB_PASSWORD:-$(openssl rand -base64 24 | tr -d '\n')}"

cd "$APP_DIR"

sudo -u postgres psql <<SQL
DO
\$do\$
BEGIN
  IF NOT EXISTS (
    SELECT FROM pg_catalog.pg_roles WHERE rolname = '${DB_USER}'
  ) THEN
    CREATE ROLE ${DB_USER} LOGIN PASSWORD '${DB_PASSWORD}';
  ELSE
    ALTER ROLE ${DB_USER} WITH LOGIN PASSWORD '${DB_PASSWORD}';
  END IF;
END
\$do\$;
SQL

if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" | grep -q 1; then
  sudo -u postgres createdb -O "$DB_USER" "$DB_NAME"
fi

sudo -u postgres psql -d "$DB_NAME" <<SQL
GRANT ALL PRIVILEGES ON DATABASE ${DB_NAME} TO ${DB_USER};
GRANT ALL ON SCHEMA public TO ${DB_USER};
ALTER SCHEMA public OWNER TO ${DB_USER};
SQL

php -r '
$path = ".env";
$values = [
    "DB_CONNECTION" => "pgsql",
    "DB_HOST" => "127.0.0.1",
    "DB_PORT" => "5432",
    "DB_DATABASE" => getenv("DB_NAME") ?: "'${DB_NAME}'",
    "DB_USERNAME" => getenv("DB_USER") ?: "'${DB_USER}'",
    "DB_PASSWORD" => getenv("DB_PASSWORD") ?: "'${DB_PASSWORD}'",
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

php artisan migrate --force

echo "Database ready: ${DB_NAME}"
echo "Database user: ${DB_USER}"

