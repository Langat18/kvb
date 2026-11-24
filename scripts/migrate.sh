#!/usr/bin/env bash
# Applies the migrations in migrations/ that this database has not seen yet, in
# file-name order, and records each one in schema_migrations.
#
#   scripts/migrate.sh --status           list applied and pending files, change nothing
#   scripts/migrate.sh                    apply the pending files
#   scripts/migrate.sh --adopt LAST_FILE  mark every file up to and including LAST_FILE as already
#                                         applied, run nothing (once, on a database built by hand;
#                                         later files stay pending)
#
# Database access (any one of):
#   PSQL="docker exec -i ksg_db psql -U <user> -d <db>"   (default: psql with the usual PG* variables)
# Stops at the first file that fails; nothing after it is run.
set -uo pipefail

cd "$(dirname "$0")/.."
PSQL=${PSQL:-psql}
MODE=run
case "${1:-}" in
    --status) MODE=status ;;
    --adopt)  MODE=adopt; UPTO=${2:-}
              [ -n "$UPTO" ] && [ -f "migrations/$UPTO" ] || { echo "Usage: $0 --adopt LAST_FILE  (e.g. 048_duplicate_protection.sql)"; exit 2; } ;;
    "")       ;;
    *) echo "Usage: $0 [--status|--adopt]"; exit 2 ;;
esac

q() { $PSQL -v ON_ERROR_STOP=1 -At -q "$@"; }

q -c "CREATE TABLE IF NOT EXISTS schema_migrations (id SERIAL PRIMARY KEY, filename VARCHAR(255) NOT NULL UNIQUE, applied_at TIMESTAMP NOT NULL DEFAULT now())" >/dev/null \
    || { echo "Cannot reach the database."; exit 1; }

APPLIED=$(q -c "SELECT filename FROM schema_migrations") || exit 1
pending=0
for f in migrations/*.sql; do
    name=$(basename "$f")
    [ "$name" = "000_create_schema_migrations.sql" ] && continue
    if grep -qxF "$name" <<<"$APPLIED"; then
        [ "$MODE" = status ] && echo "  applied  $name"
        continue
    fi
    case $MODE in
        status) echo "  PENDING  $name"; pending=$((pending+1)) ;;
        adopt)  [[ "$name" > "$UPTO" ]] && continue
                q -c "INSERT INTO schema_migrations (filename) VALUES ('$name') ON CONFLICT DO NOTHING" >/dev/null; echo "  marked   $name" ;;
        run)
            echo "  applying $name"
            if ! $PSQL -v ON_ERROR_STOP=1 -q -f - < "$f" >/dev/null; then
                echo "FAILED on $name. Nothing after it was run."; exit 1
            fi
            q -c "INSERT INTO schema_migrations (filename) VALUES ('$name') ON CONFLICT DO NOTHING" >/dev/null
            ;;
    esac
done
[ "$MODE" = status ] && echo "$pending pending."
[ "$MODE" = run ] && echo "Done."
exit 0
