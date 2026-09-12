BEGIN;

CREATE TABLE roles (
    id           SERIAL PRIMARY KEY,
    item_key     VARCHAR(30)  NOT NULL UNIQUE,
    label        VARCHAR(50)  NOT NULL,
    sort_order   SMALLINT     NOT NULL DEFAULT 0,
    is_system    BOOLEAN      NOT NULL DEFAULT FALSE,
    active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMP    NOT NULL DEFAULT now(),
    updated_at   TIMESTAMP    NOT NULL DEFAULT now()
);

CREATE TABLE permissions (
    id           SERIAL PRIMARY KEY,
    item_key     VARCHAR(50)  NOT NULL UNIQUE,
    label        VARCHAR(100) NOT NULL,
    description  VARCHAR(255)
);

CREATE TABLE role_permissions (
    role_id       INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id INTEGER NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

INSERT INTO roles (item_key, label, sort_order, is_system) VALUES
    ('admin', 'Admin', 1, TRUE),
    ('staff', 'Staff', 2, FALSE),
    ('ceo',   'CEO',   3, FALSE);

INSERT INTO permissions (item_key, label, description) VALUES
    ('manage_staff',              'Manage Staff',              'Create, deactivate, and change roles for staff accounts'),
    ('manage_departments',        'Manage Departments',        'Add, rename, and deactivate departments'),
    ('manage_lookups',            'Manage Lookups',            'Manage event modes, cadres, and session windows'),
    ('manage_roles',              'Manage Roles',              'Create roles and assign their permissions'),
    ('review_flagged_attendance', 'Review Flagged Attendance', 'View and resolve geofence-flagged check-ins'),
    ('print_attendance_register', 'Print Attendance Register', 'Print the physical attendance register for an event'),
    ('view_reports',              'View Reports',              'Access the organisation-wide reports dashboard');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.item_key = 'admin';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.item_key = 'staff' AND p.item_key = 'print_attendance_register';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.item_key = 'ceo' AND p.item_key = 'view_reports';

ALTER TABLE staff ADD COLUMN role_id INTEGER REFERENCES roles(id);

UPDATE staff s
SET role_id = r.id
FROM roles r
WHERE r.item_key = s.role::text;

COMMIT;
