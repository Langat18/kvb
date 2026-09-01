CREATE TYPE event_mode_t AS ENUM ('onsite', 'virtual', 'hybrid');
CREATE TYPE session_t    AS ENUM ('morning', 'afternoon');
CREATE TYPE method_t     AS ENUM ('onsite', 'virtual');
CREATE TYPE cadre_t      AS ENUM (
    'veterinary_surgeon',
    'vet_bachelors',
    'vet_diploma',
    'vet_certificate'
);

CREATE TABLE participants (
    reg_no          VARCHAR(10) PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    id_number       VARCHAR(20)  NOT NULL,
    email           VARCHAR(255) NOT NULL,
    phone           VARCHAR(30),
    cadre           cadre_t GENERATED ALWAYS AS (
        CASE
            WHEN reg_no ~ '^\d{4}$'    THEN 'veterinary_surgeon'::cadre_t
            WHEN reg_no ~ '^VTA\d{4}$' THEN 'vet_bachelors'::cadre_t
            WHEN reg_no ~ '^VTB\d{4}$' THEN 'vet_diploma'::cadre_t
            WHEN reg_no ~ '^VTC\d{4}$' THEN 'vet_certificate'::cadre_t
        END
    ) STORED,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT chk_reg_no_format CHECK (reg_no ~ '^(\d{4}|VT[ABC]\d{4})$'),
    CONSTRAINT uq_participants_email     UNIQUE (email),
    CONSTRAINT uq_participants_id_number UNIQUE (id_number)
);

CREATE TABLE events (
    id                  BIGSERIAL PRIMARY KEY,
    title               VARCHAR(255) NOT NULL,
    event_code          VARCHAR(50),
    event_mode          event_mode_t NOT NULL DEFAULT 'onsite',
    venue               VARCHAR(255),
    department          VARCHAR(100),
    coordinator         VARCHAR(255),
    program_assistant   VARCHAR(255),
    start_date          DATE NOT NULL,
    end_date            DATE NOT NULL,
    geo_lat             DECIMAL(10,7),
    geo_lng             DECIMAL(10,7),
    geo_radius_m        INTEGER,
    points_morning      SMALLINT,
    points_afternoon    SMALLINT,
    calendar_uid        VARCHAR(100) UNIQUE,
    token               VARCHAR(64) NOT NULL UNIQUE,
    view_token          VARCHAR(64) NOT NULL UNIQUE,
    created_by          BIGINT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT chk_event_dates CHECK (end_date >= start_date),
    CONSTRAINT chk_geo_required_for_onsite CHECK (
        event_mode = 'virtual'
        OR (geo_lat IS NOT NULL AND geo_lng IS NOT NULL AND geo_radius_m IS NOT NULL)
    ),
    CONSTRAINT chk_geo_lat_range CHECK (geo_lat BETWEEN -90 AND 90),
    CONSTRAINT chk_geo_lng_range CHECK (geo_lng BETWEEN -180 AND 180),
    CONSTRAINT chk_geo_radius_positive CHECK (geo_radius_m IS NULL OR geo_radius_m > 0)
);

CREATE TABLE registrations (
    id              BIGSERIAL PRIMARY KEY,
    event_id        BIGINT NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    reg_no          VARCHAR(10) NOT NULL REFERENCES participants(reg_no) ON DELETE CASCADE,
    signature_image TEXT NOT NULL,
    registered_at   TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT uq_registration_event_participant UNIQUE (event_id, reg_no)
);

CREATE TABLE attendance (
    id              BIGSERIAL PRIMARY KEY,
    registration_id BIGINT NOT NULL REFERENCES registrations(id) ON DELETE CASCADE,
    attendance_date DATE NOT NULL DEFAULT CURRENT_DATE,
    session         session_t NOT NULL,
    method          method_t NOT NULL,
    geo_verified    BOOLEAN,
    submitted_lat   DECIMAL(10,7),
    submitted_lng   DECIMAL(10,7),
    distance_m      INTEGER,
    flagged         BOOLEAN NOT NULL DEFAULT FALSE,
    flag_reason     VARCHAR(255),
    confirmed_at    TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT uq_attendance_slot UNIQUE (registration_id, attendance_date, session)
);

CREATE TABLE points_ledger (
    id              BIGSERIAL PRIMARY KEY,
    attendance_id   BIGINT NOT NULL REFERENCES attendance(id) ON DELETE CASCADE,
    reg_no          VARCHAR(10) NOT NULL REFERENCES participants(reg_no) ON DELETE CASCADE,
    event_id        BIGINT NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    points          SMALLINT NOT NULL DEFAULT 0,
    reason          VARCHAR(255),
    awarded_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE calendar_sync (
    id          BIGSERIAL PRIMARY KEY,
    event_id    BIGINT NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    ics_uid     VARCHAR(150) NOT NULL,
    provider    VARCHAR(50) NOT NULL DEFAULT 'ics',
    synced_at   TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT uq_calendar_event_provider UNIQUE (event_id, provider)
);

CREATE INDEX idx_events_mode         ON events (event_mode);
CREATE INDEX idx_events_dates        ON events (start_date, end_date);
CREATE INDEX idx_registrations_event ON registrations (event_id);
CREATE INDEX idx_attendance_event    ON attendance (registration_id, attendance_date);
CREATE INDEX idx_points_reg_no       ON points_ledger (reg_no);
CREATE INDEX idx_participants_email  ON participants (email);

CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_events_updated_at
    BEFORE UPDATE ON events
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TRIGGER trg_participants_updated_at
    BEFORE UPDATE ON participants
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TYPE staff_role_t AS ENUM ('admin', 'staff');

CREATE TABLE staff (
    id              BIGSERIAL PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    role            staff_role_t NOT NULL DEFAULT 'staff',
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TRIGGER trg_staff_updated_at
    BEFORE UPDATE ON staff
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

ALTER TYPE staff_role_t ADD VALUE IF NOT EXISTS 'ceo';
ALTER TABLE staff ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE staff ADD COLUMN IF NOT EXISTS last_login_at TIMESTAMPTZ;

ALTER TABLE events ADD COLUMN IF NOT EXISTS points_mid_morning SMALLINT;
ALTER TABLE events ADD CONSTRAINT chk_points_daily_cap
    CHECK (COALESCE(points_morning,0) + COALESCE(points_mid_morning,0) + COALESCE(points_afternoon,0) <= 8);

ALTER TABLE attendance ADD COLUMN IF NOT EXISTS reconfirmed_at TIMESTAMPTZ;
