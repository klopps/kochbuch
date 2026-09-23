# Kochbuch

A mobile-first recipe web app: create, organize, search and share recipes,
build a personal cookbook out of custom categories, export to
JSON/PDF/XML, use it as an installable offline-capable PWA or as a
Capacitor-wrapped Android/iOS app.

See [CLAUDE.md](CLAUDE.md) for the full architecture plan and [todo.md](todo.md)
for open feature work. Built to the same technical conventions as the
sibling project YTAN (`c:\dev\www\ytan`) — see CLAUDE.md for what was
ported over and why.

## Architecture

- **Backend**: PHP 8.1+, [Slim 4](https://www.slimframework.com/), PDO/MySQL
  (no ORM), JWT bearer auth (`firebase/php-jwt`, no PHP sessions). All data
  access goes through a REST/JSON API under `/api/v1`.
- **Web frontend**: Bootstrap 5 + plain ES6, a small hash router and one
  file per screen under `public/js/views/` (no build step, no SPA framework).
- **Database**: MySQL/MariaDB, schema in `database/migrations/*.sql`,
  applied with `bin/migrate.php`.
- **Mobile**: Capacitor-wrapped Android/iOS app loading the deployed web
  app; also installable directly as an offline-capable PWA.

## Local setup

Requirements: PHP 8.1+, Composer, MySQL/MariaDB.

```bash
composer install
cp .env.example .env        # then fill in DB credentials and JWT_SECRET (>= 32 bytes)
php bin/migrate.php         # creates the schema (user, recipe, category, ...)
php -S localhost:8000 -t public public/index.php
```

Then open `http://localhost:8000/`. There's no self-registration yet (see
`todo.md`) — create a first user directly in the `user` table, e.g.:

```bash
php -r "require 'vendor/autoload.php'; \Dotenv\Dotenv::createImmutable(__DIR__)->load(); \$p = \Kochbuch\Database\Connection::fromEnv(); \$p->prepare('INSERT INTO user (username, email, password, is_admin, preferred_locale, created_at) VALUES (?, ?, ?, 1, ?, NOW())')->execute(['admin', 'admin@example.test', password_hash('Str0ng!Pass', PASSWORD_DEFAULT), 'de']);"
```

Run the test suite with `composer test` (one-time setup: `composer test-db`, which clones the dev DB's structure into `kochbuch_test`).

`JWT_SECRET` must be at least 32 bytes in every environment (generate one
with `php -r "echo bin2hex(random_bytes(32));"`) — `AuthService` refuses to
boot with a shorter one.

## Deploying

```bash
bin\deploy.bat   # Windows
bin/deploy.sh    # macOS/Linux
```

Builds production dependencies locally and uploads over SSH; see the
comments at the top of either script for what's excluded and how to
override the target (`DEPLOY_USER`/`DEPLOY_HOST`/`DEPLOY_PATH`/...). Fill in
the real `DEPLOY_PATH` (see the `<DOC_ID>` placeholder in both scripts) and
add `DEPLOY_SSH_PASSWORD=...` to your local `.env` before the first run.

## Status

Laid out to mirror YTAN's directory structure (`src/`, `public/{css,js,fonts,images,lib}/`,
`templates/`, `resources/i18n/`, `tests/{Unit,Integration,Fixtures}/`, `docs/`, `bin/`).

**Built**: JWT login, the `user`/`recipe`/`category`/`tag` schema and REST
API (search/filter, portion-scaled ingredients, image upload with a primary
photo, JSON/XML/PDF export, sharing via link), a Bootstrap 5 mobile-first UI
(recipe list/detail/create/edit, categories, login, light/dark theming, a
DE/EN language switcher), and a 19-test PHPUnit suite.

**Not built yet**: the AdminLTE-based `/admin/*` area, admin user
management (invite/reset flow — the `user_token` table already exists for
it), the admin translation-editing tool, Capacitor packaging, and PWA
offline support (service worker) — see `todo.md`.
