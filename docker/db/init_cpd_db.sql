-- One-time provisioning: creates the cpd_system database + dedicated user
-- inside the EXISTING ksg_db Postgres container (shared with the ksg app).
--
-- Before running, generate a strong password:
--   openssl rand -base64 24
-- Use that value both below (replacing CHANGE_ME_GENERATE_A_PASSWORD) and as
-- DB_PASSWORD in this app's production .env — they must match.
--
-- Run once, from the VPS host, against the running ksg_db container:
--   docker exec -i ksg_db psql -U <existing_superuser> < docker/db/init_cpd_db.sql

CREATE DATABASE cpd_system;
CREATE USER cpd_user WITH PASSWORD 'CHANGE_ME_GENERATE_A_PASSWORD';
GRANT ALL PRIVILEGES ON DATABASE cpd_system TO cpd_user;

-- Postgres 15+ revokes CREATE on public schema from non-owners by default;
-- grant it explicitly so migrations can create tables/types as cpd_user.
\c cpd_system
GRANT ALL ON SCHEMA public TO cpd_user;
