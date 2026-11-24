-- Safe on a new database too: the address column only exists on older installs.
BEGIN;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'providers' AND column_name = 'address')
       AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'providers' AND column_name = 'email') THEN
        ALTER TABLE providers RENAME COLUMN address TO email;
    END IF;
END $$;
ALTER TABLE providers ADD COLUMN IF NOT EXISTS phone TEXT;
COMMIT;
