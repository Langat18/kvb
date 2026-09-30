-- Records who approved or denied an attendance record, when, and why.
ALTER TABLE attendance
    ADD COLUMN IF NOT EXISTS reviewed_by integer REFERENCES staff(id),
    ADD COLUMN IF NOT EXISTS reviewed_at timestamptz,
    ADD COLUMN IF NOT EXISTS review_note varchar(255);
