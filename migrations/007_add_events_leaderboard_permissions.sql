BEGIN;

INSERT INTO permissions (item_key, label, description) VALUES
    ('create_events',   'Create Events',       'Create new on-site, virtual, or hybrid events'),
    ('view_events',     'View Events',         'Browse the events list and view individual event details'),
    ('view_leaderboard','View Leaderboard',    'View the points leaderboard and per-participant point history');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.item_key = 'admin' AND p.item_key IN ('create_events', 'view_events', 'view_leaderboard');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.item_key = 'staff' AND p.item_key IN ('create_events', 'view_events');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.item_key = 'ceo' AND p.item_key IN ('view_events', 'view_leaderboard');

COMMIT;
