#!/usr/bin/env bash
# Readies the checkout before `app` starts; the one-off `init` service runs it
# on every `docker compose up`. A fresh clone gets .env and its secrets, PHP and
# JS dependencies, a key, built assets and its tables, so Docker is all a
# machine needs. Later runs catch up after a pull and skip whatever is already
# done, so an ordinary `up` costs a few seconds.
#
# scripts/setup.sh adds what this cannot do from inside a container: your UID
# instead of 1000, free host ports, dev mode, Traefik.
set -euo pipefail
cd /var/www/html

if [ ! -f .env ]; then
    cp .env.example .env
    echo "• created .env"
fi

# Set KEY=value in .env only while KEY is empty or absent.
fill() {
    local key=$1 value=$2
    if grep -q -E "^${key}=$" .env; then
        sed -i "s|^${key}=\$|${key}=${value}|" .env
        echo "• set ${key}"
    elif ! grep -q -E "^${key}=" .env; then
        echo "${key}=${value}" >> .env
        echo "• set ${key}"
    fi
}
secret() { head -c16 /dev/urandom | od -An -tx1 | tr -d ' \n'; }

fill REVERB_APP_ID "$(shuf -i 100000-999999 -n 1)"
fill REVERB_APP_KEY "$(secret)"
fill REVERB_APP_SECRET "$(secret)"
fill COLLAB_SECRET "$(secret)$(secret)"

# Dependencies are installed again whenever their lock file has changed since
# the last install here, which is what a pull that adds a package needs.
changed() { [ "$(sha256sum "$1" | cut -d' ' -f1)" != "$(cat "$2" 2>/dev/null)" ]; }
remember() { sha256sum "$1" | cut -d' ' -f1 > "$2"; }

if [ ! -f vendor/autoload.php ] || changed composer.lock vendor/.lock-sha; then
    echo "• installing PHP dependencies"
    composer install --no-interaction --no-progress
    remember composer.lock vendor/.lock-sha
fi

if [ ! -d node_modules ] || changed package-lock.json node_modules/.lock-sha; then
    echo "• installing JS dependencies"
    # --no-save: install what the lock says without rewriting it, which would
    # otherwise rename the project after the directory it is mounted at
    npm install --no-save --no-audit --no-fund
    remember package-lock.json node_modules/.lock-sha
fi

grep -q -E '^APP_KEY=base64:' .env || php artisan key:generate --force

# Built assets, unless Vite serves them: with the dev profile on, or while
# `docker compose up -d vite` has it running, which an `up` must not undo.
# Rebuilt whenever anything they are made from is newer than the last build: a
# pull, an edit made while Vite was serving.
vite_running() { timeout 2 bash -c 'exec 3<>/dev/tcp/vite/5173' 2>/dev/null; }
case ",${COMPOSE_PROFILES:-}," in *,dev,*) dev=1 ;; *) dev=0 ;; esac
if [ "$dev" = 0 ] && ! vite_running; then
    # A public/hot left behind by Vite would send the browser to a dev server
    # that is not running.
    rm -f public/hot public/fonts-manifest.dev.json
    if [ ! -f public/build/manifest.json ] ||
        [ -n "$(find resources vite.config.ts package-lock.json -newer public/build/manifest.json -print -quit)" ]; then
        echo "• building assets"
        npm run build
    fi
fi

php artisan migrate --force
