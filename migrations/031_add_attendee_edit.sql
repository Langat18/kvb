BEGIN;

ALTER TABLE registrations DROP CONSTRAINT registrations_reg_no_fkey,
    ADD CONSTRAINT registrations_reg_no_fkey FOREIGN KEY (reg_no)
    REFERENCES participants(reg_no) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE points_ledger DROP CONSTRAINT points_ledger_reg_no_fkey,
    ADD CONSTRAINT points_ledger_reg_no_fkey FOREIGN KEY (reg_no)
    REFERENCES participants(reg_no) ON DELETE CASCADE ON UPDATE CASCADE;

CREATE TABLE IF NOT EXISTS participant_edit_audit (
    id             bigserial PRIMARY KEY,
    reg_no_before  varchar(10) NOT NULL,
    reg_no_after   varchar(10) NOT NULL,
    field          varchar(20) NOT NULL,
    old_value      text,
    new_value      text,
    changed_by     integer REFERENCES staff(id),
    event_id       bigint REFERENCES events(id) ON DELETE SET NULL,
    changed_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_participant_edit_audit_reg ON participant_edit_audit (reg_no_after);

INSERT INTO permissions (item_key, label, description)
VALUES ('edit_attendee_details', 'Edit Attendee Details',
        'Correct an attendee''s registration number, name, email and phone')
ON CONFLICT (item_key) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.item_key = 'admin' AND p.item_key = 'edit_attendee_details'
ON CONFLICT DO NOTHING;

COMMIT;
