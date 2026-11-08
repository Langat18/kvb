BEGIN;

CREATE TABLE IF NOT EXISTS integration_settings (
    setting_key   VARCHAR(60)  PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);

INSERT INTO integration_settings (setting_key, setting_value)
VALUES ('pms_registration_check', 'off')
ON CONFLICT (setting_key) DO NOTHING;

CREATE TABLE IF NOT EXISTS pms_checks (
    id          BIGSERIAL    PRIMARY KEY,
    checked_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    event_id    BIGINT       REFERENCES events(id) ON DELETE SET NULL,
    reg_no      VARCHAR(10)  NOT NULL,
    outcome     VARCHAR(12)  NOT NULL CHECK (outcome IN ('found', 'not_found', 'unavailable')),
    category    VARCHAR(120),
    prefix_ok   BOOLEAN,
    name_match  BOOLEAN,
    email_match BOOLEAN,
    phone_match BOOLEAN,
    http_status SMALLINT,
    elapsed_ms  INTEGER
);

CREATE INDEX IF NOT EXISTS idx_pms_checks_checked ON pms_checks (checked_at);
CREATE INDEX IF NOT EXISTS idx_pms_checks_reg     ON pms_checks (reg_no);

COMMIT;
