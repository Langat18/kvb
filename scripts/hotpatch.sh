#!/usr/bin/env bash
# Generic live patch: copies chosen files from a GitHub branch into the running cpd_app.
# No rebuild, no restart, nobody is signed out. Backs up first; restores everything if a check fails.
#   bash hotpatch.sh BRANCH [--migration migrations/NNN_name.sql] path/one.php path/two.php ...
# Paths are relative to the repository root (for example src/VetBoard/PmsPointsSender.php).
set -uo pipefail

REPO="https://github.com/Langat18/kvb.git"
APP=/var/www/cpd; C=cpd_app; PGC=ksg_db
SRC=/root/hotpatch_src; STAMP=$(date +%Y%m%d_%H%M%S); BK=/root/backup/hotpatch_$STAMP
LOCAL="http://127.0.0.1:8081"

die() { echo "STOP: $*" >&2; exit 1; }
getv() { grep -E "^$2=" "$1" | head -1 | cut -d= -f2- | tr -d '\r"'"'"; }

BRANCH="${1:-}"; shift || true
MIG=""
if [ "${1:-}" = "--migration" ]; then MIG="${2:-}"; shift 2 || die "--migration needs a file"; fi
FILES="$*"
[ -n "$BRANCH" ] && [ -n "$FILES" ] || die "usage: hotpatch.sh BRANCH [--migration FILE] file..."

[ "$(id -u)" -eq 0 ] || die "run as root"
docker inspect -f '{{.State.Running}}' "$C" 2>/dev/null | grep -q true || die "$C is not running"
NEW=""

echo "1/6 Fetch and check the code ($BRANCH)"
rm -rf "$SRC"; git clone --quiet --depth 1 --branch "$BRANCH" "$REPO" "$SRC" || die "clone failed"
echo "    commit: $(git -C "$SRC" rev-parse --short HEAD)"
for f in $FILES $MIG; do [ -s "$SRC/$f" ] || die "missing $f: wrong branch?"; done

echo "2/6 Back up the current files"
mkdir -p "$BK"; chmod 700 "$BK"
for f in $FILES; do
  mkdir -p "$BK/$(dirname "$f")"
  if docker exec "$C" test -e "/var/www/html/$f"; then
    docker cp "$C:/var/www/html/$f" "$BK/$f" >/dev/null || die "backup of $f failed"
  else
    NEW="$NEW $f"      # a new file: rollback removes it
  fi
done
echo "    saved in $BK${NEW:+ (new files:$NEW)}"

echo "3/6 Check the new files run (syntax) inside the container"
docker exec "$C" rm -rf /tmp/hp; docker exec "$C" mkdir -p /tmp/hp
for f in $FILES; do
  n=$(echo "$f" | tr / _); docker cp "$SRC/$f" "$C:/tmp/hp/$n" >/dev/null || die "copy for check failed"
  docker exec "$C" php -l "/tmp/hp/$n" >/dev/null 2>&1 || die "syntax error in $f"
done
docker exec "$C" rm -rf /tmp/hp

echo "4/6 Database migration"
if [ -n "$MIG" ]; then
  PGSU=$(getv /var/www/ksg/.env DB_USER); DB_NAME=$(getv "$APP/.env" DB_NAME)
  [ -n "$PGSU" ] && [ -n "$DB_NAME" ] || die "cannot read database settings"
  docker exec -i "$PGC" psql -U "$PGSU" -d "$DB_NAME" -v ON_ERROR_STOP=1 -q < "$SRC/$MIG" || die "migration failed; nothing was changed in the app"
  echo "    applied $MIG"
else
  echo "    none needed"
fi

restore() {
  echo "ROLLING BACK the files"
  for f in $FILES; do
    case " $NEW " in
      *" $f "*) docker exec "$C" rm -f "/var/www/html/$f" ;;
      *) docker cp "$BK/$f" "$C:/var/www/html/$f" >/dev/null; docker exec "$C" chown www-data:www-data "/var/www/html/$f" ;;
    esac
  done
}

echo "5/6 Copy the files into the running app"
for f in $FILES; do
  docker cp "$SRC/$f" "$C:/var/www/html/$f" >/dev/null || { restore; die "copy of $f failed"; }
  docker exec "$C" chown www-data:www-data "/var/www/html/$f"
done
docker exec "$C" apachectl graceful >/dev/null 2>&1; sleep 3

echo "6/6 Check the site"
login=$(curl -s -m 8 -o /dev/null -w '%{http_code}' "$LOCAL/login.php")
look=$(curl -s -L -m 8 -o /dev/null -w '%{http_code}' "$LOCAL/events/lookup?reg_no=abc")
errs=$(docker logs --since 1m "$C" 2>&1 | grep -ciE "PHP (Fatal|Parse) error" || true)
echo "    login page: HTTP $login | lookup: HTTP $look | PHP fatal errors in the last minute: $errs"
case "$login" in 200|301|302|303) okl=1;; *) okl=0;; esac
if [ "$okl" = "1" ] && [ "${look:-0}" -lt 500 ] && [ "${look:-0}" -gt 0 ] && [ "${errs:-0}" = "0" ]; then
  echo "OK. The patch is live. Old files are in $BK"
else
  restore; sleep 3; echo "Restored. Login page now: HTTP $(curl -s -m 8 -o /dev/null -w '%{http_code}' "$LOCAL/login.php")"; die "check failed; the old files are back"
fi
