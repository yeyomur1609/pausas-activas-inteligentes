#!/usr/bin/env bash
set -euo pipefail

# Render asigna el puerto dinámicamente vía $PORT (fallback a 10000, estándar de Render Web Services)
PORT="${PORT:-10000}"

if [[ ! "$PORT" =~ ^[0-9]+$ ]] || (( PORT < 1 || PORT > 65535 )); then
  echo "[Entrypoint Error] Puerto inválido: $PORT" >&2
  exit 1
fi

# Configurar Apache para escuchar estrictamente en $PORT y servir desde /var/www/html/public
sed -ri "s/^Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Garantizar que el almacenamiento efímero exista y sea escribible por www-data en cada arranque
mkdir -p /var/www/html/storage/cache /var/www/html/storage/logs
chown -R www-data:www-data /var/www/html/storage
chmod -R 775 /var/www/html/storage

echo "[Pausas Activas API] Iniciando Apache en puerto ${PORT} (DocumentRoot=/var/www/html/public, APP_ENV=${APP_ENV:-production}, DB_DRIVER=${DB_DRIVER:-auto})"
exec "$@"
