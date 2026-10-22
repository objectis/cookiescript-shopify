# Changelog

## 1.1.0 (2026-10-22)

### New
- Script URLs from the CookieScript staging environment (`/staging/`) are accepted next to production (`/s/`) URLs,
  on `cdn.` and `geo.` hosts, with `https://` or `//`, and with an optional query string (e.g. `?region=eu`).
- The full banner code (`<script ... src="..."></script>`) can be pasted; the URL is extracted automatically.
- Inter is bundled with the app and used for all elements of the admin UI.
- Error messages are shown in the UI when saving scripts, Google Consent Mode settings or the domain map fails.

### Security
- Fixed: a shop could write regional consent settings into another shop's settings.
- Fixed: consent values, wait times, domain names and script URLs could inject JavaScript, HTML or Liquid into the
  storefront snippet. Values are now validated when saved and whitelisted/escaped again when the snippet is built.
- Fixed: path traversal in the `/assets/` route.
- Fixed: open redirect through the `host` query argument.
- Removed the committed `APP_KEY` from `web/.env.example`.
- The GDPR `shop/redact` webhook now deletes all data stored for the shop.
- API CORS is limited to the app host; the Docker image no longer installs development packages.
- `.htaccess` rules block `.env`, the database and logs if the web root is set above `web/public`.
- Removed the unused template routes `/api/products/count` and `/api/products/create`.

### Fixes
- Failed theme updates are reported instead of showing a false success; adding/removing scripts and saving the
  domain map are rolled back when the theme can't be updated.
- Google Consent Mode: the theme was updated twice per save, and the loading spinner could get stuck.
- The settings page could crash when the API returned an error.
- The snippet is rendered once, before `{{ content_for_header }}` (or before `</head>`), never inside a Liquid comment.
- Region codes are written in uppercase as Google expects (e.g. `US-CA`); a saved wait time of `0` is kept.
- Hidden settings (Consent Mode or multi-domain switched off) with older values no longer block saving.
- Missing or expired session tokens return 401 and malformed input returns 422 instead of server errors.
- Adding the same script URL twice is rejected.
- Scope configuration is consistent (`SCOPES_READ`, `shopify.app.toml`).
- Built frontend assets are cached by the browser.
- Composer resolves dependencies for PHP 8.1 (`config.platform.php`), and `composer.lock` is committed.

### Development
- `SHOPIFY_LOCAL_THEME_PREVIEW=true` (local/development only) saves the generated snippet to
  `web/storage/app/theme-preview` when Shopify blocks theme writes for a development app.
- `SHOPIFY_REST_API_VERSION` sets the Admin REST API version used for theme requests (default `2023-04`).
