# Deployment

Nothing here has been run against a real server. Treat it as a checklist, not a tested script.

## Requirements
PHP 8.2+ (extensions: bcmath or gmp, gd, intl, mbstring, pdo_mysql, zip, xml), MySQL 8, Node 20+ (build only), a web server pointing at `public/`.

## Production `.env`
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://pos.example.com
APP_TIMEZONE=UTC
DB_CONNECTION=mysql
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
LOG_LEVEL=warning
```
Never deploy `APP_DEBUG=true`. Never run the demo seeder in production.

## First deploy
```
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan key:generate --force
php artisan migrate --force
php artisan storage:link
php artisan optimize
```
Create the first Super Admin and business with the seeder or tinker, then change the password.

## Every deploy
`php artisan down`, pull, `composer install --no-dev`, `npm ci && npm run build`, `php artisan migrate --force`, `php artisan optimize`, `php artisan up`. Do `php artisan optimize:clear` if config changed.

## File permissions
Only `storage/` and `bootstrap/cache/` are writable by the web user.

## Scheduler and queue
Cron: `* * * * * php /path/artisan schedule:run`. If you use a queue worker, run it under Supervisor.
Suggested nightly check, added to `routes/console.php` of your Laravel install:
```php
Schedule::command('pos:verify-books')->dailyAt('02:00')->emailOutputOnFailure('you@example.com');
```

## Backups
Back up the database daily (`mysqldump --single-transaction`) and `storage/app/public`. Keep copies off the server and test a restore. The stock and journal ledgers are append-only, so a restore point is the only way to undo a mistake outside the app's own reversal features.

## HTTPS and headers
Serve over HTTPS only. The app sends `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, and HSTS on secure requests. It sets no Content-Security-Policy because the right one depends on your hosting; add one at the web server once you know your asset origins.

## Rate limits
Login is limited per account and IP (Phase 1). The authenticated API is limited to 120 requests per minute per user.
