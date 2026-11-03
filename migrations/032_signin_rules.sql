BEGIN;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key   VARCHAR(60)  PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_by    BIGINT,
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);

INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('signin_lead_virtual', '45'),
    ('signin_lead_onsite',  '45'),
    ('signin_grace',        '60')
ON CONFLICT (setting_key) DO NOTHING;

COMMIT;
