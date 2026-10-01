#!/usr/bin/env bash
# First-run setup for a fresh clone. Safe to run again: it only fills in what is
# missing and never overwrites a value you have set.
#
# A plain `docker compose up -d` also works: docker/init.sh installs everything
# then. This adds what a container cannot do: your UID, free host ports, and
# Traefik.
#
#   ./scripts/setup.sh              # built assets; Vite only on `docker compose up -d vite`
#   ./scripts/setup.sh --dev        # Vite with HMR on every `up`, APP_DEBUG=true
#   ./scripts/setup.sh --traefik zyrenn.example.com
#                                   # behind Traefik over HTTPS
set -euo pipefail
cd "$(dirname "$0")/.."

DEV=0 HOST=
while [ $# -gt 0 ]; do
    case $1 in
        --dev) DEV=1 ;;
        --build) ;; # the default now; still accepted
        --traefik) HOST=${2:?--traefik needs the public hostname}; shift ;;
        *) echo "unknown option: $1" >&2; exit 1 ;;
    esac
    shift
done
[ "$DEV" = 1 ] && [ -n "$HOST" ] && { echo "--dev is for this machine only; leave it off with --traefik" >&2; exit 1; }

command -v docker >/dev/null || { echo "Docker is required: https://docs.docker.com/engine/install/" >&2; exit 1; }
docker compose version >/dev/null 2>&1 || { echo "Docker Compose v2 is required ('docker compose')." >&2; exit 1; }

[ -f .env ] || { cp .env.example .env; echo "• created .env"; }

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

# Set KEY=value in .env, replacing any line or commented-out example of it.
put() {
    if grep -q -E "^#? ?${1}=" .env; then sed -i "s|^#\? \?${1}=.*|${1}=${2}|" .env; else echo "${1}=${2}" >> .env; fi
}

if [ "$DEV" = 1 ]; then
    sed -i 's|^COMPOSE_PROFILES=.*|COMPOSE_PROFILES=dev|; s|^APP_DEBUG=.*|APP_DEBUG=true|' .env
    echo "• dev: Vite with every up, APP_DEBUG=true"
elif [ -n "$HOST" ]; then
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

# The `init` service (docker/init.sh) does the rest before app starts: secrets,
# dependencies, the key, built assets, migrations.
echo "• starting the stack (the first time installs everything: a few minutes)"
docker compose up -d --wait app || { docker compose logs --tail 40 init; exit 1; }
docker compose up -d

port=$(grep -E '^APP_PORT=' .env | cut -d= -f2)
url=http://localhost:${port:-8000}
[ -z "$HOST" ] || url=https://$HOST
cat <<MSG

Done. ZyrenN is at ${url}

Next: register an account there (or 'docker compose exec app php artisan db:seed'
for test@example.com / password), then follow docs/SETUP.md to connect an AI client.
MSG
