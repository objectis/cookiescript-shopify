# Deploying an update (1.1.0)

The live app runs from a git checkout on the server (e.g. `/home/shopify/public_html`). This release has
**no database migrations** and no new required settings, so it can be rolled back by checking out the previous commit.

## 1. On your PC: commit and push

Check what will be committed:

```bash
git status
```

**Commit** (all part of the release):
- `CHANGELOG.md`, `DEPLOY.md`, `package.json` (version 1.1.0), `shopify.app.toml`, `.gitignore`, `.dockerignore`, `Dockerfile`
- everything changed under `web/app`, `web/config`, `web/routes`, `web/frontend` (incl. `web/frontend/assets/fonts/`)
- `web/composer.json` **and** `web/composer.lock` (new: pins the tested package versions)
- `web/.env.example`, `web/.htaccess`, `web/storage/.htaccess`

**Do not commit** (local only):
- `web/.env`, `shopify.app.dev.toml`, `.shopify/` (ignored automatically)
- `web/bootstrap/cache/packages.php` and `web/bootstrap/cache/services.php`, which your local `composer install`
  regenerated. Undo them with: `git checkout -- web/bootstrap/cache`
- `yarn.lock` if git shows it as changed (only line endings changed locally): `git checkout -- yarn.lock`

```bash
git add -A
git status          # double-check the lists above
git commit -m "Release 1.1.0: security fixes, staging script URLs, Inter font"
git tag v1.1.0
git push && git push --tags
```

## 2. On the server: back up

```bash
cd /home/shopify/public_html
git rev-parse HEAD > ~/previous-release.txt          # commit to roll back to
cp web/storage/db.sqlite ~/db-backup-$(date +%F).sqlite
cp web/.env ~/env-backup-$(date +%F)
```

## 3. Check the production `.env` (`web/.env`)

| Setting | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `SCOPES_READ` (or `SCOPES`) | must include `read_themes,write_themes` |
| `SHOPIFY_LOCAL_THEME_PREVIEW` | **not set** (development only, ignored in production anyway) |
| `LOG_LEVEL` | `info` (or `error`) |
| `SHOPIFY_REST_API_VERSION` | optional; leave unset to keep `2023-04` as before |

If `APP_KEY` is `base64:lF7bCUnYai5A1BoX6tM1v/h4ZbYYi6Ph6P0mp2hbKN4=` (the key that used to be in
`.env.example`), generate a new one with `php artisan key:generate`. This only resets encrypted cookies; merchants
who are in the middle of installing the app just start the install again.

## 4. On the server: update the code

```bash
cd /home/shopify/public_html
php -v                               # must be PHP 8.1 or newer (composer.lock requires it)
git status                           # must not list modified files, except possibly web/bootstrap/cache
git checkout -- web/bootstrap/cache  # discard the server's regenerated cache files so git pull can't conflict
git pull
cd web

# Cached package lists from the repository can reference packages that aren't installed; Laravel rebuilds them.
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php

composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force          # nothing to migrate in this release, safe to run
php artisan config:clear && php artisan route:clear && php artisan view:clear && php artisan cache:clear
```

## 5. Build the admin UI

The live app's **API key** (Partner Dashboard → live app → Client ID) is compiled into the frontend. Building with the
dev app's key breaks the admin UI.

```bash
cd /home/shopify/public_html/web/frontend
yarn install            # or: npm install, whichever you used before
SHOPIFY_API_KEY=<live app client id> yarn build
grep -l "<live app client id>" dist/assets/*.js    # must print a file
```

## 6. Restart PHP

Restart PHP-FPM (or the hosting panel's PHP service) so OPcache loads the new code, e.g.
`sudo systemctl restart php8.1-fpm`.

## 7. Smoke test on a real store

1. Open the app in a store's admin: the page loads in Inter with no errors.
2. Add a `/s/` URL, then a `/staging/` URL. Both appear in the list.
3. In the theme code editor, `snippets/google_consent_mode_script.liquid` contains both scripts and
   `layout/theme.liquid` contains `{% render 'google_consent_mode_script' %}`.
4. Save Google Consent Mode and the domain map once each; no error message appears.
5. The storefront shows the banner.
6. `tail -n 50 web/storage/logs/laravel.log` shows no new `ERROR` lines
   (in particular no `Failed to create script snippet`).

## Rollback

```bash
cd /home/shopify/public_html
git checkout $(cat ~/previous-release.txt)
cd web
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
composer install --no-dev --optimize-autoloader --no-interaction
php artisan config:clear && php artisan route:clear && php artisan view:clear
cd frontend && SHOPIFY_API_KEY=<live app client id> yarn build
```

Restart PHP-FPM. No database changes need to be undone. Saved settings stay compatible with the previous version.

## Notes

- **Don't run `npm run deploy`** for this release. The live app's Shopify configuration doesn't need to change, and
  the CLI could push the development app's settings.
- **Local testing:** run `npm run shopify -- app config use dev` once, then `npm run dev`. This uses
  `shopify.app.dev.toml` (the "test.cookiescript" development app) and leaves `shopify.app.toml` untouched.
- **Composer 2.10 or newer** refuses to *resolve* Laravel 8 because of security advisories. Installing from the
  committed `composer.lock` works; if `composer update` is needed, use `composer update --no-blocking`. The lasting
  fix is upgrading Laravel and `shopify/shopify-api`.
