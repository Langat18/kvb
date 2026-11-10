-- Possible PMS matches for practitioners whose registration number PMS did not know.
-- Nothing here is sent; staff tick the ones they accept.
CREATE TABLE IF NOT EXISTS pms_suggestions (
    reg_no        VARCHAR(30)  NOT NULL,
    candidate     VARCHAR(30)  NOT NULL,
    pms_name      VARCHAR(255),
    name_match    BOOLEAN      NOT NULL DEFAULT false,
    id_state      VARCHAR(10)  NOT NULL DEFAULT 'none' CHECK (id_state IN ('match', 'partial', 'differs', 'none')),
    status        VARCHAR(10)  NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved')),
    decided_by    INTEGER,
    decided_at    TIMESTAMPTZ,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    PRIMARY KEY (reg_no, candidate)
);
