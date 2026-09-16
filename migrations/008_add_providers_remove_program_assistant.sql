BEGIN;

CREATE TABLE providers (
    id                SERIAL PRIMARY KEY,
    accreditation_no  VARCHAR(100) NOT NULL UNIQUE,
    name              VARCHAR(255) NOT NULL,
    active            BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at        TIMESTAMP    NOT NULL DEFAULT now(),
    updated_at        TIMESTAMP    NOT NULL DEFAULT now()
);

ALTER TABLE events ADD COLUMN provider_id INTEGER REFERENCES providers(id);
ALTER TABLE events DROP COLUMN program_assistant;

INSERT INTO permissions (item_key, label, description) VALUES
    ('manage_providers', 'Manage Providers', 'Add, import, and deactivate CPD providers');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.item_key = 'admin' AND p.item_key = 'manage_providers';

COMMIT;
