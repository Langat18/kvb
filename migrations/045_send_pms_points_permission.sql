-- Sending points to PMS gets its own permission, and the Admin role is made
-- sure to exist, be active and hold every permission.
BEGIN;

INSERT INTO permissions (item_key, label, description) VALUES
    ('send_pms_points', 'Send Points to PMS',
     'Review PMS matches and upload attendance points to the Kenya Veterinary Board PMS')
ON CONFLICT (item_key) DO NOTHING;

-- The system Admin role: present, active, and holding every permission.
INSERT INTO roles (item_key, label, sort_order, is_system)
VALUES ('admin', 'Admin', 1, TRUE)
ON CONFLICT (item_key) DO NOTHING;

UPDATE roles SET active = TRUE, is_system = TRUE WHERE item_key = 'admin';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.item_key = 'admin'
ON CONFLICT DO NOTHING;

-- Director can review and send as well.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE (lower(r.item_key) LIKE 'director%' OR lower(r.label) = 'director')
  AND p.item_key = 'send_pms_points'
ON CONFLICT DO NOTHING;

COMMIT;
