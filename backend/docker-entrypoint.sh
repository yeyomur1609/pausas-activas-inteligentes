#!/usr/bin/env bash
set -euo pipefail

# Render asigna el puerto dinámicamente vía $PORT (fallback a 80 en ejecución local Docker)
PORT="${PORT:-80}"

if [[ ! "$PORT" =~ ^[0-9]+$ ]]; then
  echo "[Entrypoint Error] Puerto inválido: $PORT" >&2
  exit 1
fi

# Configurar Apache para escuchar en el puerto asignado por Render
sed -ri "s/^Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Garantizar que el almacenamiento efímero exista y sea escribible por www-data en cada arranque
mkdir -p /var/www/html/storage/cache /var/www/html/storage/logs
chown -R www-data:www-data /var/www/html/storage
chmod -R 775 /var/www/html/storage

echo "[Pausas Activas API] Iniciando Apache en puerto ${PORT} (APP_ENV=${APP_ENV:-production}, DB_DRIVER=${DB_DRIVER:-auto})"
exec "$@"
