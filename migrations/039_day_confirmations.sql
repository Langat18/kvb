BEGIN;

ALTER TABLE events ADD COLUMN IF NOT EXISTS day_confirmation BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS day_confirmations (
    id              BIGSERIAL PRIMARY KEY,
    registration_id BIGINT      NOT NULL REFERENCES registrations(id) ON DELETE CASCADE,
    confirm_date    DATE        NOT NULL,
    confirmed_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    method          VARCHAR(10) NOT NULL,
    staff_id        BIGINT      REFERENCES staff(id) ON DELETE SET NULL,
    device_id       VARCHAR(64),
    ip_address      VARCHAR(45),
    geo_verified    BOOLEAN,
    distance_m      INTEGER,
    CONSTRAINT uq_day_confirmation UNIQUE (registration_id, confirm_date),
    CONSTRAINT chk_day_confirmation_method CHECK (method IN ('self', 'staff'))
);

CREATE INDEX IF NOT EXISTS idx_day_confirmations_device
    ON day_confirmations (confirm_date, device_id) WHERE device_id IS NOT NULL;

COMMIT;
