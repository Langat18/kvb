#!/usr/bin/env bash
# Live patch for the PMS upload serial (record_sn): one file, no database change: no rebuild, no restart, nobody is signed out.
# Backs up the current file, copies the new one into the running cpd_app, checks the site, and
# puts the old files back automatically if the check fails.
#   bash hotpatch_sender.sh            -> uses branch fix-pms-record-sn
set -uo pipefail

BRANCH="${1:-fix-pms-record-sn}"
REPO="https://github.com/Langat18/kvb.git"
APP=/var/www/cpd; C=cpd_app; PGC=ksg_db
SRC=/root/hotpatch_src; STAMP=$(date +%Y%m%d_%H%M%S); BK=/root/backup/hotpatch_$STAMP
LOCAL="http://127.0.0.1:8081"
# Order matters: library classes first, then the pages that call them.
FILES="src/VetBoard/PmsPointsSender.php"

die() { echo "STOP: $*" >&2; exit 1; }
getv() { grep -E "^$2=" "$1" | head -1 | cut -d= -f2- | tr -d '\r"'"'"; }

[ "$(id -u)" -eq 0 ] || die "run as root"
docker inspect -f '{{.State.Running}}' "$C" 2>/dev/null | grep -q true || die "$C is not running"

echo "1/6 Fetch and check the code ($BRANCH)"
rm -rf "$SRC"; git clone --quiet --depth 1 --branch "$BRANCH" "$REPO" "$SRC" || die "clone failed"
echo "    commit: $(git -C "$SRC" rev-parse --short HEAD)"
for f in $FILES; do [ -s "$SRC/$f" ] || die "missing $f: wrong branch?"; done
grep -q 'function recordSn' "$SRC/src/VetBoard/PmsPointsSender.php" || die "not the new version"

echo "2/6 Back up the current files"
mkdir -p "$BK"; chmod 700 "$BK"
for f in $FILES; do mkdir -p "$BK/$(dirname "$f")"; docker cp "$C:/var/www/html/$f" "$BK/$f" || die "backup of $f failed"; done
echo "    saved in $BK"

echo "3/6 Check the new file runs (syntax) inside the container"
docker exec "$C" rm -rf /tmp/hp; docker exec "$C" mkdir -p /tmp/hp
for f in $FILES; do
  n=$(echo "$f" | tr / _); docker cp "$SRC/$f" "$C:/tmp/hp/$n" || die "copy for check failed"
  docker exec "$C" php -l "/tmp/hp/$n" >/dev/null 2>&1 || die "syntax error in $f"
done
docker exec "$C" rm -rf /tmp/hp

echo "4/6 No database change needed"

restore() {
  echo "ROLLING BACK the files"
  for f in $FILES; do docker cp "$BK/$f" "$C:/var/www/html/$f"; docker exec "$C" chown www-data:www-data "/var/www/html/$f"; done
}

echo "5/6 Copy the files into the running app"
for f in $FILES; do
  docker cp "$SRC/$f" "$C:/var/www/html/$f" || { restore; die "copy of $f failed"; }
  docker exec "$C" chown www-data:www-data "/var/www/html/$f"
done

echo "6/6 Check the site"
probe() { curl -s -L -m 8 "$LOCAL/events/lookup?reg_no=abc"; }
sleep 3
out=$(probe)
if [ -z "$out" ]; then
  echo "    old code still served; reloading Apache gently"
  docker exec "$C" apachectl graceful >/dev/null 2>&1; sleep 3; out=$(probe)
fi
login=$(curl -s -m 8 -o /dev/null -w '%{http_code}' "$LOCAL/login.php")
echo "    lookup reply: $out"; echo "    login page:   HTTP $login"
case "$login" in 200|301|302|303) okl=1;; *) okl=0;; esac
lcode=$(curl -s -L -m 8 -o /dev/null -w '%{http_code}' "$LOCAL/events/lookup?reg_no=abc")
if { echo "$out" | grep -q '"status":"invalid"' || { [ "${lcode:-0}" -lt 500 ] && [ "${lcode:-0}" -gt 0 ] && ! echo "$out" | grep -qiE 'fatal error|parse error|uncaught'; }; } && [ "$okl" = "1" ]; then
  echo "OK. The fix is live. Old files are in $BK"
  echo "To undo: for each file in $BK copy it back with docker cp, or ask for the one-line restore."
else
  restore; sleep 3; echo "Restored. Login page now: HTTP $(curl -s -m 8 -o /dev/null -w '%{http_code}' "$LOCAL/login.php")"; die "check failed; the old files are back"
fi
