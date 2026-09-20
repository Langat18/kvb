
#   bash scripts/system_check.sh

set -uo pipefail
DB="vet_event_attendance"
FAIL=0
pass() { echo "  OK   $1"; }
fail() { echo "  FAIL $1"; FAIL=1; }

echo " 1. Migration files vs schema_migrations table =="
DISK_MIGRATIONS=$(ls migrations/*.sql 2>/dev/null | xargs -n1 basename | sort)
DB_MIGRATIONS=$(psql "$DB" -t -A -c "SELECT filename FROM schema_migrations ORDER BY filename;" 2>/dev/null | sort)
DIFF=$(diff <(echo "$DISK_MIGRATIONS") <(echo "$DB_MIGRATIONS"))
if [ -z "$DIFF" ]; then
  pass "All migration files on disk match schema_migrations exactly."
else
  fail "Mismatch between migrations/*.sql and schema_migrations table:"
  echo "$DIFF" | sed 's/^/       /'
fi

echo ""
echo " 2. PHP syntax check (all src/ and public/ files) =="
LINT_ERR=0
while IFS= read -r -d '' f; do
  OUT=$(php -l "$f" 2>&1)
  if ! echo "$OUT" | grep -q "No syntax errors detected"; then
    fail "$f"
    echo "$OUT" | sed 's/^/       /'
    LINT_ERR=1
  fi
done < <(find src public -name "*.php" -print0)
[ "$LINT_ERR" -eq 0 ] && pass "Every .php file under src/ and public/ lints clean."

echo ""
echo "3. Dead-code references to removed classes =="
for CLASS in SessionCode; do
  HITS=$(grep -rln "$CLASS" src/ public/ 2>/dev/null)
  if [ -z "$HITS" ]; then
    pass "No references to removed class '$CLASS'."
  else
    fail "Found dangling references to '$CLASS':"
    echo "$HITS" | sed 's/^/       /'
  fi
done

echo ""
echo " 4. Required constants defined in config/constants.php =="
# Matches both idioms used in this codebase: define('NAME', ...) and const NAME = ...
for CONST in MEETING_PROVIDERS OFFSITE_HARD_REJECT_EXCESS_M VIRTUAL_SIGNIN_LEAD_MINUTES DEFAULT_GEOFENCE_RADIUS_M; do
  if grep -Eq "(define[[:space:]]*\([[:space:]]*['\"]${CONST}['\"]|const[[:space:]]+${CONST}[[:space:]]*=)" config/constants.php 2>/dev/null; then
    pass "$CONST is defined."
  else
    fail "$CONST is NOT defined in config/constants.php."
  fi
done

echo ""
echo " 5. Required columns exist in the database =="
CHECKS="
attendance|method,flagged,points_denied,quiz_override_by,quiz_override_at
events|meeting_provider,meeting_link,provider_id
event_session_points|event_id,session_window_id,points
"
echo "$CHECKS" | while IFS='|' read -r TABLE COLS; do
  [ -z "$TABLE" ] && continue
  ACTUAL=$(psql "$DB" -t -A -c "SELECT column_name FROM information_schema.columns WHERE table_name = '$TABLE';" 2>/dev/null)
  IFS=',' read -ra COL_ARR <<< "$COLS"
  for COL in "${COL_ARR[@]}"; do
    if echo "$ACTUAL" | grep -qx "$COL"; then
      echo "  OK   $TABLE.$COL exists."
    else
      echo "  FAIL $TABLE.$COL is MISSING from the database."
    fi
  done
done

echo ""
echo " 6. event_modes item_key values match code's hardcoded expectations =="
MODES=$(psql "$DB" -t -A -c "SELECT item_key FROM event_modes ORDER BY item_key;" 2>/dev/null | tr -d ' ')
for EXPECTED in onsite virtual hybrid; do
  if echo "$MODES" | grep -qx "$EXPECTED"; then
    pass "event_modes contains '$EXPECTED'."
  else
    fail "event_modes is MISSING '$EXPECTED' — code hardcodes this literal in several places."
  fi
done

echo ""
echo " 7. Required tables exist =="
for TABLE in staff events attendance registrations participants event_quiz_questions event_quiz_options quiz_attempts points_ledger session_windows event_session_points providers; do
  EXISTS=$(psql "$DB" -t -A -c "SELECT to_regclass('public.$TABLE');" 2>/dev/null | tr -d ' ')
  if [ "$EXISTS" == "$TABLE" ]; then
    pass "Table '$TABLE' exists."
  else
    fail "Table '$TABLE' is MISSING."
  fi
done

echo ""
echo " 8. Duplicate-registration guards in Participant::create() =="
for CHECK in findByRegNo findByEmail findByIdNumber; do
  if grep -q "$CHECK" src/VetBoard/Participant.php 2>/dev/null; then
    pass "Participant::create() guards against duplicate via $CHECK()."
  else
    fail "Participant::create() is missing a $CHECK() guard."
  fi
done

echo ""
if [ "$FAIL" -eq 0 ]; then
  echo ""
  echo " ALL CHECKS PASSED"
  echo ""
else
  echo ""
  echo " ONE OR MORE CHECKS FAILED — see FAIL lines above"
  echo ""
fi
exit $FAIL
