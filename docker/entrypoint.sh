#!/bin/sh
set -eu
if [ -z "${APP_KEY:-}" ]; then
 echo "APP_KEY wajib diatur di Coolify. Jangan mengganti key pada redeploy." >&2
 exit 1
fi
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
php artisan config:clear
# Fail rather than serving a half-migrated application. Keep one replica during startup migration.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then php artisan migrate --force; fi
php artisan config:cache
php artisan view:cache
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
