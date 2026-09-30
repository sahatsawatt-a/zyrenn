#!/bin/sh
# Backups of everything ZyrenN keeps: the database AND the Drive's files.
#
# A database dump alone is not a backup of this app. Drive files, pictures in
# notes and boards live on disk (storage/app/private), and the rows only point
# at them -- when drive/1 was wiped, every dump still held rows for files that
# no longer existed anywhere.
#
# Each run writes one folder, both halves together:
#
#     auto-20260930-140000/db.dump          pg_dump custom format (pg_restore)
#     auto-20260930-140000/files.tar.gz     storage/app/private
#
# and only then removes old ones: folders named auto-* older than
# BACKUP_KEEP_DAYS, never the newest BACKUP_KEEP_MIN whatever their age, and
# nothing else in the folder -- hand-made dumps next to them are left alone.
#
#   backup.sh         run for ever, one backup every BACKUP_INTERVAL_HOURS
#   backup.sh once    one backup now, then exit
set -eu

OUT=/backups
INTERVAL_HOURS=${BACKUP_INTERVAL_HOURS:-6}
KEEP_DAYS=${BACKUP_KEEP_DAYS:-14}
KEEP_MIN=${BACKUP_KEEP_MIN:-3}

log() {
    echo "[backup $(date '+%Y-%m-%d %H:%M:%S')] $*"
}

# Every step says `|| return 1` because `set -e` does not reach inside a
# function called as `run || ...` -- without it a failed pg_dump would carry on
# and be named a finished backup.
backup() {
    stamp=$(date +%Y%m%d-%H%M%S)
    work="$OUT/.auto-$stamp.partial"
    rm -rf "$work"
    mkdir -p "$work" || return 1

    pg_dump -h "$PGHOST" -U "$PGUSER" -d "$PGDATABASE" -Fc -f "$work/db.dump" || return 1
    tar -czf "$work/files.tar.gz" -C /storage private || return 1

    # A backup that cannot be read back is not one: check both halves before
    # it counts, and before anything older is removed on its account
    pg_restore --list "$work/db.dump" >/dev/null || return 1
    gzip -t "$work/files.tar.gz" || return 1

    # Named auto-* only once complete, so a run killed halfway never passes
    # for a backup (and its leftovers are cleared at the next start)
    mv "$work" "$OUT/auto-$stamp" || return 1
    log "wrote auto-$stamp: db $(du -h "$OUT/auto-$stamp/db.dump" | cut -f1), files $(du -h "$OUT/auto-$stamp/files.tar.gz" | cut -f1)"
}

rotate() {
    # Newest first; the first KEEP_MIN are kept whatever their age
    ls -1d "$OUT"/auto-* 2>/dev/null | sort -r | tail -n +"$((KEEP_MIN + 1))" | while read -r dir; do
        if [ -n "$(find "$dir" -maxdepth 0 -mmin +"$((KEEP_DAYS * 1440))")" ]; then
            rm -rf "$dir"
            log "removed $(basename "$dir") (older than $KEEP_DAYS days)"
        fi
    done
}

run() {
    backup || return 1
    rotate
}

# Leftovers of a run that was stopped halfway
rm -rf "$OUT"/.auto-*.partial

if [ "${1:-}" = "once" ]; then
    if ! run; then
        rm -rf "$OUT"/.auto-*.partial
        log "FAILED -- nothing older was removed"
        exit 1
    fi
    exit 0
fi

interval=$((INTERVAL_HOURS * 3600))
log "every ${INTERVAL_HOURS}h into $OUT, keeping ${KEEP_DAYS} days (at least ${KEEP_MIN})"

while true; do
    # A restart does not mean a backup is due: wait out what is left of the
    # interval since the newest one, so `docker compose up` all day long does
    # not fill the disk
    newest=$(ls -1d "$OUT"/auto-* 2>/dev/null | sort -r | head -n 1 || true)
    if [ -n "$newest" ]; then
        age=$(($(date +%s) - $(stat -c %Y "$newest")))
        if [ "$age" -lt "$interval" ]; then
            sleep $((interval - age))
        fi
    fi

    # A failed run is logged and retried in ten minutes, not fatal to the loop
    if ! run; then
        rm -rf "$OUT"/.auto-*.partial
        log "FAILED -- nothing older was removed; trying again in 10 minutes"
        sleep 600
    fi
done
