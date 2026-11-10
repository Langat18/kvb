-- Points sent to KVB PMS. One row per practitioner per event; a row that is
-- pending, sent or uncertain blocks any further send, so points are never credited twice.
-- Sending is off by default. To switch on:
--   UPDATE integration_settings SET setting_value='on', updated_at=now() WHERE setting_key='pms_points_send';
BEGIN;

CREATE TABLE IF NOT EXISTS pms_resolutions (
    reg_no     VARCHAR(30) PRIMARY KEY,
    pms_reg_no VARCHAR(30),
    status     VARCHAR(12) NOT NULL CHECK (status IN ('found', 'not_found')),
    checked_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS pms_points_sends (
    id              BIGSERIAL PRIMARY KEY,
    event_id        INTEGER      NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    reg_no          VARCHAR(30)  NOT NULL,
    pms_reg_no      VARCHAR(30)  NOT NULL,
    activity_number VARCHAR(40)  NOT NULL,
    cpd_points      NUMERIC(6,2) NOT NULL,
    cpd_hours       NUMERIC(6,2) NOT NULL,
    status          VARCHAR(12)  NOT NULL CHECK (status IN ('pending', 'sent', 'failed', 'uncertain')),
    response        TEXT,
    batch_ref       VARCHAR(40)  NOT NULL,
    sent_by         INTEGER,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_pms_points_sends_live
    ON pms_points_sends (event_id, reg_no) WHERE status <> 'failed';

INSERT INTO integration_settings (setting_key, setting_value)
VALUES ('pms_points_send', 'off')
ON CONFLICT (setting_key) DO NOTHING;

COMMIT;
