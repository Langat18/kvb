-- Keeps PMS's own answer when a practitioner is not matched, so staff can see why.
ALTER TABLE pms_resolutions ADD COLUMN IF NOT EXISTS detail VARCHAR(200);
