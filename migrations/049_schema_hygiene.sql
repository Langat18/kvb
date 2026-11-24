-- Schema hygiene (no behaviour change):
--   1. an index on every foreign-key column that had none, so joins and
--      deletes on those columns do not scan the whole table as data grows;
--   2. the remaining timestamp columns now keep their time zone, like the
--      other 44 timestamp columns already do. Existing values are read in the
--      database's own time zone, which is the zone now() filled them in.
-- Safe to run twice.
BEGIN;

CREATE INDEX IF NOT EXISTS idx_attendance_denied_by ON attendance (denied_by);
CREATE INDEX IF NOT EXISTS idx_attendance_quiz_override_by ON attendance (quiz_override_by);
CREATE INDEX IF NOT EXISTS idx_attendance_recorded_by_staff_id ON attendance (recorded_by_staff_id);
CREATE INDEX IF NOT EXISTS idx_attendance_reviewed_by ON attendance (reviewed_by);
CREATE INDEX IF NOT EXISTS idx_day_confirmations_staff_id ON day_confirmations (staff_id);
CREATE INDEX IF NOT EXISTS idx_event_quiz_options_question_id ON event_quiz_options (question_id);
CREATE INDEX IF NOT EXISTS idx_event_quiz_questions_session_id ON event_quiz_questions (session_id);
CREATE INDEX IF NOT EXISTS idx_event_quiz_questions_session_window_id ON event_quiz_questions (session_window_id);
CREATE INDEX IF NOT EXISTS idx_event_session_points_session_window_id ON event_session_points (session_window_id);
CREATE INDEX IF NOT EXISTS idx_events_event_mode_id ON events (event_mode_id);
CREATE INDEX IF NOT EXISTS idx_events_provider_id ON events (provider_id);
CREATE INDEX IF NOT EXISTS idx_participant_edit_audit_changed_by ON participant_edit_audit (changed_by);
CREATE INDEX IF NOT EXISTS idx_participant_edit_audit_event_id ON participant_edit_audit (event_id);
CREATE INDEX IF NOT EXISTS idx_pms_checks_event_id ON pms_checks (event_id);
CREATE INDEX IF NOT EXISTS idx_points_ledger_awarded_by ON points_ledger (awarded_by);
CREATE INDEX IF NOT EXISTS idx_points_ledger_event_id ON points_ledger (event_id);
CREATE INDEX IF NOT EXISTS idx_points_ledger_session_id ON points_ledger (session_id);
CREATE INDEX IF NOT EXISTS idx_quiz_attempts_session_id ON quiz_attempts (session_id);
CREATE INDEX IF NOT EXISTS idx_quiz_attempts_session_window_id ON quiz_attempts (session_window_id);
CREATE INDEX IF NOT EXISTS idx_registration_deletions_deleted_by ON registration_deletions (deleted_by);
CREATE INDEX IF NOT EXISTS idx_registration_deletions_event_id ON registration_deletions (event_id);
CREATE INDEX IF NOT EXISTS idx_registrations_reg_no ON registrations (reg_no);
CREATE INDEX IF NOT EXISTS idx_role_permissions_permission_id ON role_permissions (permission_id);
CREATE INDEX IF NOT EXISTS idx_session_presentations_reg_no ON session_presentations (reg_no);
CREATE INDEX IF NOT EXISTS idx_staff_role_id ON staff (role_id);

DO $$ BEGIN
    IF (SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'cadres' AND column_name = 'created_at') = 'timestamp without time zone' THEN
        ALTER TABLE cadres ALTER COLUMN created_at TYPE TIMESTAMPTZ;
    END IF;
    IF (SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'cadres' AND column_name = 'updated_at') = 'timestamp without time zone' THEN
        ALTER TABLE cadres ALTER COLUMN updated_at TYPE TIMESTAMPTZ;
    END IF;
END $$;
DO $$ BEGIN
    IF (SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'departments' AND column_name = 'created_at') = 'timestamp without time zone' THEN
        ALTER TABLE departments ALTER COLUMN created_at TYPE TIMESTAMPTZ;
    END IF;
    IF (SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'departments' AND column_name = 'updated_at') = 'timestamp without time zone' THEN
        ALTER TABLE departments ALTER COLUMN updated_at TYPE TIMESTAMPTZ;
    END IF;
END $$;
DO $$ BEGIN
    IF (SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'event_modes' AND column_name = 'created_at') = 'timestamp without time zone' THEN
        ALTER TABLE event_modes ALTER COLUMN created_at TYPE TIMESTAMPTZ;
    END IF;
    IF (SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'event_modes' AND column_name = 'updated_at') = 'timestamp without time zone' THEN
        ALTER TABLE event_modes ALTER COLUMN updated_at TYPE TIMESTAMPTZ;
    END IF;
END $$;
DO $$ BEGIN
    IF (SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'providers' AND column_name = 'created_at') = 'timestamp without time zone' THEN
        ALTER TABLE providers ALTER COLUMN created_at TYPE TIMESTAMPTZ;
    END IF;
    IF (SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'providers' AND column_name = 'updated_at') = 'timestamp without time zone' THEN
        ALTER TABLE providers ALTER COLUMN updated_at TYPE TIMESTAMPTZ;
    END IF;
END $$;
DO $$ BEGIN
    IF (SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'roles' AND column_name = 'created_at') = 'timestamp without time zone' THEN
        ALTER TABLE roles ALTER COLUMN created_at TYPE TIMESTAMPTZ;
    END IF;
    IF (SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'roles' AND column_name = 'updated_at') = 'timestamp without time zone' THEN
        ALTER TABLE roles ALTER COLUMN updated_at TYPE TIMESTAMPTZ;
    END IF;
END $$;
DO $$ BEGIN
    IF (SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'schema_migrations' AND column_name = 'applied_at') = 'timestamp without time zone' THEN
        ALTER TABLE schema_migrations ALTER COLUMN applied_at TYPE TIMESTAMPTZ;
    END IF;
END $$;
DO $$ BEGIN
    IF (SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'session_windows' AND column_name = 'created_at') = 'timestamp without time zone' THEN
        ALTER TABLE session_windows ALTER COLUMN created_at TYPE TIMESTAMPTZ;
    END IF;
    IF (SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'session_windows' AND column_name = 'updated_at') = 'timestamp without time zone' THEN
        ALTER TABLE session_windows ALTER COLUMN updated_at TYPE TIMESTAMPTZ;
    END IF;
END $$;

COMMIT;
