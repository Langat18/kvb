BEGIN;

ALTER TABLE participants DROP CONSTRAINT chk_reg_no_format;
ALTER TABLE participants ADD CONSTRAINT chk_reg_no_format CHECK (reg_no ~ '^(\d{4}|VT[ABC]\d{5})$');

ALTER TABLE participants DROP COLUMN cadre;
ALTER TABLE participants ADD COLUMN cadre cadre_t GENERATED ALWAYS AS (
    CASE
        WHEN reg_no ~ '^\d{4}$'    THEN 'veterinary_surgeon'::cadre_t
        WHEN reg_no ~ '^VTA\d{5}$' THEN 'vet_bachelors'::cadre_t
        WHEN reg_no ~ '^VTB\d{5}$' THEN 'vet_diploma'::cadre_t
        WHEN reg_no ~ '^VTC\d{5}$' THEN 'vet_certificate'::cadre_t
    END
) STORED;

COMMIT;
