BEGIN;
SET LOCAL lock_timeout = '5s';

LOCK TABLE event_sessions, points_ledger IN ACCESS EXCLUSIVE MODE;

DO $$
DECLARE
    ledger_before  NUMERIC;
    ledger_after   NUMERIC;
    session_before NUMERIC;
    session_after  NUMERIC;
BEGIN
    SELECT COALESCE(SUM(points), 0) INTO ledger_before  FROM points_ledger;
    SELECT COALESCE(SUM(points), 0) INTO session_before FROM event_sessions;

    ALTER TABLE event_sessions ALTER COLUMN points TYPE NUMERIC(4,1) USING points::numeric(4,1);
    ALTER TABLE points_ledger  ALTER COLUMN points TYPE NUMERIC(4,1) USING points::numeric(4,1);
    ALTER TABLE registration_deletions ALTER COLUMN points_total TYPE NUMERIC(6,1) USING points_total::numeric(6,1);

    SELECT COALESCE(SUM(points), 0) INTO ledger_after  FROM points_ledger;
    SELECT COALESCE(SUM(points), 0) INTO session_after FROM event_sessions;

    IF ledger_before <> ledger_after OR session_before <> session_after THEN
        RAISE EXCEPTION 'Points changed during conversion (ledger % -> %, sessions % -> %)',
            ledger_before, ledger_after, session_before, session_after;
    END IF;
END
$$;

COMMIT;
