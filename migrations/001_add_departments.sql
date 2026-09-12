

BEGIN;

CREATE TABLE departments (
    id           SERIAL PRIMARY KEY,
    name         VARCHAR(150) NOT NULL UNIQUE,
    active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMP    NOT NULL DEFAULT now(),
    updated_at   TIMESTAMP    NOT NULL DEFAULT now()
);

INSERT INTO departments (name)
SELECT DISTINCT trim(department)
FROM events
WHERE department IS NOT NULL AND trim(department) <> ''
ON CONFLICT (name) DO NOTHING;

ALTER TABLE events ADD COLUMN department_id INTEGER REFERENCES departments(id);

UPDATE events e
SET department_id = d.id
FROM departments d
WHERE trim(e.department) = d.name;

COMMIT;