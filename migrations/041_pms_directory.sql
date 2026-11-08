BEGIN;

CREATE TABLE IF NOT EXISTS pms_providers (
    accred_num         VARCHAR(60)  PRIMARY KEY,
    name               VARCHAR(255) NOT NULL,
    status             VARCHAR(20)  NOT NULL DEFAULT 'unknown',
    accreditation_year VARCHAR(10),
    contact_person     VARCHAR(255),
    telephone          VARCHAR(60),
    email              VARCHAR(255),
    synced_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    removed_at         TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS pms_activities (
    id                INTEGER      PRIMARY KEY,
    title             VARCHAR(255) NOT NULL,
    activity_number   VARCHAR(40),
    activity_type     VARCHAR(60),
    coordinator       VARCHAR(255),
    coordinator_phone VARCHAR(60),
    coordinator_email VARCHAR(255),
    provider_name     VARCHAR(255),
    start_date        DATE,
    end_date          DATE,
    venue             VARCHAR(255),
    schedules         JSONB        NOT NULL DEFAULT '[]'::jsonb,
    content_hash      CHAR(32)     NOT NULL,
    first_seen_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    last_seen_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    removed_at        TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_pms_activities_number ON pms_activities (upper(trim(activity_number)));
CREATE INDEX IF NOT EXISTS idx_pms_activities_dates  ON pms_activities (start_date, end_date);

CREATE TABLE IF NOT EXISTS pms_event_links (
    event_id        BIGINT      PRIMARY KEY REFERENCES events(id) ON DELETE CASCADE,
    pms_activity_id INTEGER     NOT NULL REFERENCES pms_activities(id),
    method          VARCHAR(10) NOT NULL CHECK (method IN ('auto', 'manual')),
    linked_by       BIGINT,
    linked_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_pms_event_links_activity ON pms_event_links (pms_activity_id);

CREATE TABLE IF NOT EXISTS pms_sync_runs (
    id                 BIGSERIAL   PRIMARY KEY,
    started_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    finished_at        TIMESTAMPTZ,
    trigger_source     VARCHAR(10) NOT NULL CHECK (trigger_source IN ('cron', 'manual')),
    status             VARCHAR(10) NOT NULL DEFAULT 'running' CHECK (status IN ('running', 'ok', 'failed')),
    providers_seen     INTEGER,
    activities_seen    INTEGER,
    activities_added   INTEGER,
    activities_changed INTEGER,
    events_linked      INTEGER,
    message            VARCHAR(255)
);

CREATE TABLE IF NOT EXISTS pms_lookup_log (
    id      BIGSERIAL    PRIMARY KEY,
    at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    ip      VARCHAR(45)  NOT NULL,
    reg_no  VARCHAR(10)  NOT NULL,
    outcome VARCHAR(12)  NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_pms_lookup_log_at ON pms_lookup_log (at);

INSERT INTO integration_settings (setting_key, setting_value)
VALUES ('pms_registration_autofill', 'off')
ON CONFLICT (setting_key) DO NOTHING;

COMMIT;
