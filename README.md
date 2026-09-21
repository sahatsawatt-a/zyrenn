# ZyrenN

## Running ZyrenN locally

Everything runs in Docker; nothing but Docker is needed on the host.

```sh
docker compose up -d                     # postgres, app, nginx, reverb, vite
docker compose logs -f app
docker compose exec app php artisan ...
docker compose exec app php artisan test
docker compose down                      # add -v to drop the database too
```

| | |
| --- | --- |
| app | <http://localhost:8001> |
| vite | <http://localhost:5173> (dev profile, HMR) |
| postgres | `localhost:5434`, database and user `zyrenn`, password `secret` |

Those ports were free when this project was scaffolded; they are recorded in
`.env` as `APP_PORT`, `VITE_PORT` and `FORWARD_DB_PORT`. If you lose track of
which port the app is on, ask Docker rather than reading `.env`:

```sh
docker compose port web 8080
```

### Assets

`COMPOSE_PROFILES=dev` in `.env` runs Vite for HMR. While it runs it writes
`public/hot` and Laravel serves assets from the dev server, which is only
reachable from this machine. To serve the built bundle instead — over a tunnel,
or from another device on the LAN — build first, then drop the profile:

```sh
docker compose exec app npm run build
# remove COMPOSE_PROFILES=dev from .env, then
docker compose up -d
```

Set `APP_DEBUG=false` before exposing the app to anyone else: with it on, any
500 renders a stack trace including config values.

### Agent tooling

`laravel/boost` is installed but not configured, because its installer asks
which agents and editors to wire up:

```sh
docker compose exec app php artisan boost:install
```

### Two things that will catch you out

**The tests run on SQLite, the app runs on Postgres.** `phpunit.xml` sets
`DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`, and Pest reads it. A green
suite therefore says nothing about Postgres-specific behaviour — JSON
operators, `ILIKE`, transactional DDL, stricter type coercion, sequences. CI
migrates against a real Postgres, so migrations are covered; queries are not.
Point `phpunit.xml` at a `pgsql` connection if the app leans on any of that.

**A cookie the server reads must be exempt from encryption.**
`bootstrap/app.php` has:

```php
$middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
```

`HandleAppearance` reads the `appearance` cookie server-side so Blade can paint
the theme before first render, with no flash. Any cookie you add for that same
purpose — a second appearance axis, a locale, a density setting — has to go on
that `except` list too. Leave it off and the middleware receives `null`: no
error, no warning, just the default, silently.
