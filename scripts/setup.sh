#!/usr/bin/env bash
# First-run setup for a fresh clone. Safe to run again: it only fills in what is
# missing and never overwrites a value you have set.
#
#   ./scripts/setup.sh              # dev: Vite with HMR
#   ./scripts/setup.sh --build      # serve the built bundle (no Vite), for a server
#   ./scripts/setup.sh --traefik zyrenn.example.com
#                                   # behind Traefik over HTTPS (implies --build)
set -euo pipefail
cd "$(dirname "$0")/.."

BUILD=0 HOST=
while [ $# -gt 0 ]; do
    case $1 in
        --build) BUILD=1 ;;
        --traefik) HOST=${2:?--traefik needs the public hostname}; BUILD=1; shift ;;
        *) echo "unknown option: $1" >&2; exit 1 ;;
    esac
    shift
done

command -v docker >/dev/null || { echo "Docker is required: https://docs.docker.com/engine/install/" >&2; exit 1; }
docker compose version >/dev/null 2>&1 || { echo "Docker Compose v2 is required ('docker compose')." >&2; exit 1; }

[ -f .env ] || { cp .env.example .env; echo "• created .env"; }

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
secret() { openssl rand -hex 16 2>/dev/null || head -c16 /dev/urandom | od -An -tx1 | tr -d ' \n'; }

# The host user, so bind-mounted storage/ stays writable (compose cannot read $UID itself)
sed -i "s|^UID=.*|UID=$(id -u)|; s|^GID=.*|GID=$(id -g)|" .env

# Host ports: if something else already listens on one, move to the next free
# port. Skipped while this project's own stack is up, since it holds them itself.
in_use() { (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null; }
if [ -z "$(docker compose ps -q 2>/dev/null)" ]; then
    for key in APP_PORT VITE_PORT FORWARD_DB_PORT; do
        port=$(grep -E "^${key}=" .env | cut -d= -f2)
        [ -n "$port" ] || continue
        wanted=$port
        while in_use "$port"; do port=$((port + 1)); done
        if [ "$port" != "$wanted" ]; then
            sed -i "s|^${key}=.*|${key}=${port}|" .env
            echo "• port $wanted is taken, using ${key}=${port}"
            # The browser reaches the app and the websocket on APP_PORT
            if [ "$key" = APP_PORT ]; then
                sed -i "s|^APP_URL=http://localhost:.*|APP_URL=http://localhost:${port}|; s|^VITE_REVERB_PORT=.*|VITE_REVERB_PORT=${port}|" .env
            fi
        fi
    done
fi

fill REVERB_APP_ID "$(shuf -i 100000-999999 -n 1)"
fill REVERB_APP_KEY "$(secret)"
fill REVERB_APP_SECRET "$(secret)"
fill COLLAB_SECRET "$(secret)$(secret)"

# Set KEY=value in .env, replacing any line or commented-out example of it.
put() {
    if grep -q -E "^#? ?${1}=" .env; then sed -i "s|^#\? \?${1}=.*|${1}=${2}|" .env; else echo "${1}=${2}" >> .env; fi
}

if [ "$BUILD" = 1 ]; then
    sed -i 's|^COMPOSE_PROFILES=.*|COMPOSE_PROFILES=|; s|^APP_DEBUG=.*|APP_DEBUG=false|' .env
fi

if [ -n "$HOST" ]; then
    docker network inspect traefik >/dev/null 2>&1 || {
        echo "• creating the external 'traefik' network"
        docker network create traefik >/dev/null
    }
    # The overlay wants a vite host even when Vite is off: one label, same parent domain
    put COMPOSE_FILE docker-compose.yml:docker-compose.traefik.yml
    put APP_HOST "$HOST"
    put VITE_HOST "${HOST%%.*}-vite.${HOST#*.}"
    put APP_URL "https://$HOST"
    echo "• Traefik: https://$HOST (needs Traefik running; see docs/SETUP.md)"
fi

echo "• building the image"
docker compose build app

echo "• installing PHP and JS dependencies"
docker compose run --rm --no-deps app composer install --no-interaction
docker compose run --rm --no-deps app npm install

grep -q -E '^APP_KEY=base64:' .env || docker compose run --rm --no-deps app php artisan key:generate --force

echo "• starting the stack"
[ "$BUILD" = 1 ] && docker compose run --rm --no-deps app npm run build
docker compose up -d --wait postgres app

docker compose exec app php artisan migrate --force
docker compose up -d

port=$(grep -E '^APP_PORT=' .env | cut -d= -f2)
url=http://localhost:${port:-8000}
[ -z "$HOST" ] || url=https://$HOST
cat <<MSG

Done. ZyrenN is at ${url}

Next: register an account there (or 'docker compose exec app php artisan db:seed'
for test@example.com / password), then follow docs/SETUP.md to connect an AI client.
MSG
