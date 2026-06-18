# README-DEV

## Purpose

This plugin is developed against the WordPress demo site at:

- Site: `https://wpdemo.williamresearch.com/`
- Local plugin path on server: `/home/duongtannghia/projects/wpdemo/wp-content/plugins/wp-admin-assistant`

This demo site exists mainly to exercise the plugin in a realistic WordPress admin and WooCommerce environment.

## Current demo environment

- WordPress: `7.0`
- PHP: `8.3`
- Theme: `Kadence`
- Active plugins:
  - `wp-admin-assistant`
  - `woocommerce`
  - `wp-mail-smtp`
  - `query-monitor`
  - `user-switching`
  - `wp-crontrol`

## Demo site details

- URL: `https://wpdemo.williamresearch.com/`
- Admin user: `william`
- Admin email: `william@levincigroup.com`

The site is configured as a sandbox for plugin development, not as a production storefront.

## SMTP

Outbound mail is configured through SMTP2GO using WP Mail SMTP.

- Mailer: `SMTP`
- Host: `mail.smtp2go.com`
- Port: `587`
- Encryption: `TLS`
- Auth: `true`
- Username: `william-dev-server`
- From email: `william@levincigroup.com`

## Working with the repo

The plugin directory is initialized as a git repository on the server.

- SSH remote: `github-wp-admin-asistant:williamduong/wp-admin-asistant.git`
- HTTPS remote: `https://github.com/williamduong/wp-admin-asistant.git`

Useful commands:

```bash
cd /home/duongtannghia/projects/wpdemo/wp-content/plugins/wp-admin-assistant
git status
git remote -v
```

## Build

```bash
cd /home/duongtannghia/projects/wpdemo/wp-content/plugins/wp-admin-assistant
npm install
npm run build
```

Watch mode:

```bash
npm run dev
```

## Tests

JavaScript tests:

```bash
npm run test:js
```

Runtime-focused JS tests:

```bash
npm run test:js:runtime
```

PHP tests:

```bash
npm run test:php:setup
npm run test:php
```

End-to-end browser tests:

```bash
npm run test:e2e
```

`npm run test:js` is scoped to `tests/js` so it stays separate from the Playwright suite under `tests/e2e`.
The current Vitest coverage floor is `60%` for lines. Raise it again when more UI surfaces are covered.

## WordPress operations

Use WP-CLI through the running container so you are talking to the actual demo site. Running `wp` from other project folders on the server can point at a different WordPress install.

List active plugins:

```bash
docker exec wpdemo_web sh -lc 'wp --allow-root --path=/var/www/html plugin list'
```

List themes:

```bash
docker exec wpdemo_web sh -lc 'wp --allow-root --path=/var/www/html theme list'
```

Check site URL:

```bash
docker exec wpdemo_web sh -lc 'wp --allow-root --path=/var/www/html option get siteurl'
```

## Deploying changes to the demo site

Because the plugin folder is mounted directly into the running WordPress container, file changes in:

- `/home/duongtannghia/projects/wpdemo/wp-content/plugins/wp-admin-assistant`

are reflected on the demo site immediately.

Typical workflow:

1. Edit plugin code in the repo folder.
2. Run `npm run build` if frontend assets changed.
3. Refresh `https://wpdemo.williamresearch.com/wp-admin/`.
4. Use Query Monitor and WP Crontrol to inspect behavior.

## Demo content

The site includes generated demo posts and sample WooCommerce products so the plugin can exercise:

- post creation and updates
- plugin and theme management
- WooCommerce product listing and mutation
- settings and admin workflows

## Recommended next cleanup

- add a real release/build workflow once the GitHub repo is populated
