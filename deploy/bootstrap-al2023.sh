#!/usr/bin/env bash
#
# Provision a LinkForge application node on Amazon Linux 2023.
#
# Assumes MySQL lives in RDS and Redis in ElastiCache; this script only sets up
# the web/worker tier. Run as root on a fresh instance.
#
set -euo pipefail

APP_DIR=/var/www/linkforge
LOG_DIR=/var/log/linkforge
REPO="${REPO:-https://github.com/nikhil-ghind/link_forge.git}"

echo "==> Installing packages"
dnf -y update
dnf -y install \
    httpd mod_ssl \
    php8.2 php8.2-cli php8.2-fpm php8.2-mysqlnd php8.2-mbstring \
    php8.2-xml php8.2-opcache php8.2-bcmath php8.2-pecl-redis \
    git unzip

echo "==> Installing Composer"
if [[ ! -x /usr/local/bin/composer ]]; then
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi

echo "==> Tuning PHP for a redirect workload"
cat >/etc/php.d/99-linkforge.ini <<'INI'
; OPcache: the codebase never changes at runtime, so stop stat-ing files.
opcache.enable=1
opcache.memory_consumption=192
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.jit=tracing
opcache.jit_buffer_size=64M

; The redirect path does no heavy work; a tight limit surfaces mistakes early.
memory_limit=128M
max_execution_time=15
expose_php=Off
realpath_cache_size=4096k
realpath_cache_ttl=600
INI

echo "==> Configuring php-fpm pool"
cat >/etc/php-fpm.d/linkforge.conf <<'FPM'
[linkforge]
user = apache
group = apache
listen = /run/php-fpm/linkforge.sock
listen.owner = apache
listen.group = apache
listen.mode = 0660

; Static pool: redirect traffic is steady and process spawning under a spike is
; exactly when you least want to pay for it.
pm = static
pm.max_children = 48
pm.max_requests = 2000

php_admin_value[error_log] = /var/log/linkforge/php-fpm.log
php_admin_flag[log_errors] = on
catch_workers_output = yes
FPM

echo "==> Fetching the application"
mkdir -p "$APP_DIR" "$LOG_DIR"

if [[ -d "$APP_DIR/.git" ]]; then
    git -C "$APP_DIR" pull --ff-only
else
    git clone "$REPO" "$APP_DIR"
fi

cd "$APP_DIR"
composer install --no-dev --optimize-autoloader --no-interaction

if [[ ! -f .env ]]; then
    cp .env.example .env
    php artisan key:generate
    echo "!! Edit $APP_DIR/.env with the RDS and ElastiCache endpoints, then re-run the migration step."
fi

echo "==> Preparing storage and caches"
mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache
chown -R apache:apache "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" "$LOG_DIR"
chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

php artisan config:cache
php artisan route:cache
php artisan event:cache

echo "==> Installing vhosts, units and log rotation"
install -m 0644 deploy/apache/linkforge-redirect.conf /etc/httpd/conf.d/
install -m 0644 deploy/apache/linkforge-api.conf /etc/httpd/conf.d/
install -m 0644 deploy/systemd/linkforge-worker.service /etc/systemd/system/
install -m 0644 deploy/systemd/linkforge-scheduler.service /etc/systemd/system/
install -m 0644 deploy/systemd/linkforge-scheduler.timer /etc/systemd/system/
install -m 0644 deploy/systemd/linkforge.logrotate /etc/logrotate.d/linkforge

systemctl daemon-reload
systemctl enable --now php-fpm httpd linkforge-worker linkforge-scheduler.timer

echo
echo "Done. Remaining manual steps:"
echo "  1. php artisan migrate --force"
echo "  2. php artisan linkforge:token dashboard   # issue an API token"
echo "  3. (cd dashboard && npm ci && npm run build)"
echo "  4. php artisan linkforge:warm-cache        # preload the hot links"
echo "  5. curl -s https://api.linkforge.example/api/health | jq"
