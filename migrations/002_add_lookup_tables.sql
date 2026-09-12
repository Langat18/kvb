
BEGIN;

CREATE TABLE event_modes (
    id           SERIAL PRIMARY KEY,
    item_key     VARCHAR(30)  NOT NULL UNIQUE,
    label        VARCHAR(50)  NOT NULL,
    sort_order   SMALLINT     NOT NULL DEFAULT 0,
    active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMP    NOT NULL DEFAULT now(),
    updated_at   TIMESTAMP    NOT NULL DEFAULT now()
);

CREATE TABLE cadres (
    id           SERIAL PRIMARY KEY,
    item_key     VARCHAR(50)  NOT NULL UNIQUE,
    label        VARCHAR(50)  NOT NULL,
    sort_order   SMALLINT     NOT NULL DEFAULT 0,
    active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMP    NOT NULL DEFAULT now(),
    updated_at   TIMESTAMP    NOT NULL DEFAULT now()
);

CREATE TABLE session_windows (
    id           SERIAL PRIMARY KEY,
    item_key     VARCHAR(30)  NOT NULL UNIQUE,
    label        VARCHAR(50)  NOT NULL,
    start_time   TIME         NOT NULL,
    end_time     TIME         NOT NULL,
    sort_order   SMALLINT     NOT NULL DEFAULT 0,
    active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMP    NOT NULL DEFAULT now(),
    updated_at   TIMESTAMP    NOT NULL DEFAULT now()
);

INSERT INTO event_modes (item_key, label, sort_order) VALUES
    ('onsite',  'On-site', 1),
    ('virtual', 'Virtual', 2),
    ('hybrid',  'Hybrid',  3);

INSERT INTO cadres (item_key, label, sort_order) VALUES
    ('veterinary_surgeon', 'Surgeon',     1),
    ('vet_bachelors',      'Bachelors',   2),
    ('vet_diploma',        'Diploma',     3),
    ('vet_certificate',    'Certificate', 4);

INSERT INTO session_windows (item_key, label, start_time, end_time, sort_order) VALUES
    ('morning',     'Morning',     '08:00', '10:30', 1),
    ('mid_morning', 'Mid-Morning', '11:00', '13:00', 2),
    ('afternoon',   'Afternoon',   '14:00', '17:00', 3);

COMMIT;
