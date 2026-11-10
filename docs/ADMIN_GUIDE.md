# CPD Attendance System: Administrator and Deployment Guide

## What this system does

The CPD Attendance System records who attended Kenya Veterinary Board continuing professional development (CPD) events, checks that they were really there, and awards CPD points. It runs as a web application that staff use to set up events and review attendance, and that practitioners use on their phones to register and sign in.

A practitioner scans an event's QR code, registers once, and then signs in to each session. For on-site sessions the phone's location is compared with the venue. For virtual sessions the practitioner passes a short quiz. When the checks pass, points are added to their record. Anything that fails a check lands in a queue for a staff member to approve or deny.

### Who uses it

| Role | What they do |
| --- | --- |
| Practitioner | Registers for an event, signs in to sessions, takes quizzes, looks up their own points. No account needed. |
| Staff | Creates and edits events, records manual attendance, reviews flagged sign-ins, prints registers. What each person can do is set by their role. |
| Administrator | Everything staff can do, plus staff accounts, roles, providers, lookups and sign-in settings. |

### The parts

| Part | Technology | Notes |
| --- | --- | --- |
| Web application | PHP 8.2 or newer, Apache | The code lives in `src/VetBoard` (logic) and `public` (pages). Production runs PHP 8.4. |
| Database | PostgreSQL | Schema in `database/schema.sql`, changes in `migrations/`. Production runs Postgres 17. |
| Email | Brevo HTTP API | Used for password resets. Optional. |
| PMS connection | Kenya Veterinary Board PMS API | Imports approved CPD activities and providers, and can check registration numbers. Optional. |
| Scheduled job | `scripts/pms_sync.php` | Refreshes the PMS copy once a night. |

There is no separate front-end build. The pages are server-rendered PHP with a little JavaScript, so nothing needs compiling when the code changes.

## Requirements and configuration

A small server is enough. The app does very little work per request, and the busiest moment is the few minutes when a room of practitioners signs in at once.

### What you need

- A Linux server with 1 CPU, 2 GB of RAM and 10 GB of disk to start with. Ubuntu 22.04 or 24.04 is what this guide assumes.
- A domain name pointing at the server, and a TLS certificate. Phones will not share their location over plain HTTP, so HTTPS is required for on-site sign-in, not optional.
- PHP 8.2 or newer with these extensions: `pdo_pgsql`, `mbstring`, `xml`, `zip`, `gd`, `curl`, `opcache`.
- Apache with `mod_rewrite` and `mod_headers`, or any web server that can run PHP and send every request under `public/`.
- PostgreSQL 15 or newer. Version 17 is used in production.
- Composer 2 to install the PHP libraries.
- If you use Docker: Docker Engine 24 or newer with the Compose plugin. Nothing else needs installing on the host.

### Settings in `.env`

All settings live in one `.env` file in the project root. Copy `.env.example` to `.env` and edit it. Never commit this file to Git.

| Setting | Required | What it is |
| --- | --- | --- |
| `APP_URL` | Yes | The public address, for example https://cpdsystem.example.org. Used to build the QR codes and the links in password-reset emails, so it must be the address practitioners actually use. |
| `APP_ENV` | Yes | Use production on a live server. Only the value development turns off secure-only cookies, which you want solely when testing over plain HTTP on your own machine. |
| `DB_HOST` | Yes | Database host. With the bundled Docker setup this is `db`. |
| `DB_PORT` | Yes | Usually `5432`. |
| `DB_NAME` | Yes | Database name. |
| `DB_USER` | Yes | Database user. |
| `DB_PASSWORD` | Yes | Database password. The app refuses to start without the five `DB_` settings. |
| `BREVO_API_KEY` | No | Key for sending email through Brevo. Leave blank and password-reset emails will not be sent. |
| `MAIL_FROM_NAME` | No | Sender name on emails. |
| `MAIL_FROM_ADDRESS` | No | Sender address on emails. |
| `PMS_API_BASE` | No | Base address of the PMS API. Defaults to `https://mms.kenyavetboard.or.ke/api`. |
| `PMS_API_TOKEN` | No | Bearer token issued by the Board. Without it, the PMS features stay switched off. |
| `PMS_API_TIMEOUT` | No | Seconds to wait for a PMS reply. Default 5. |
| `PMS_API_CONNECT_TIMEOUT` | No | Seconds to wait to connect. Default 3. |

### Behaviour set in code

A few rules are constants in `config/constants.php`. Change them there and redeploy.

| Constant | Default | Meaning |
| --- | --- | --- |
| `DEFAULT_GEOFENCE_RADIUS_M` | 100 | Metres from the venue that count as on site, unless an event sets its own radius. |
| `OFFSITE_HARD_REJECT_EXCESS_M` | 1000 | A sign-in this many metres beyond the radius is rejected automatically instead of waiting for review. |
| `ONSITE_SIGNIN_LEAD_MINUTES` | 45 | How early before a session an on-site sign-in opens. |
| `VIRTUAL_SIGNIN_LEAD_MINUTES` | 45 | The same for virtual sessions. |
| `LATE_SIGNIN_GRACE_MINUTES` | 60 | How long sign-in stays open after a session ends. Set 0 to close it at the end. |

The time zone is fixed to Africa/Nairobi in the same file. The quiz pass mark (90%) and attempts (2) are set in `src/VetBoard/QuizAttempt.php`.

## Hosting with Docker

This is the simplest route. The repository ships a `Dockerfile` for the web application and a `docker-compose.yml` that starts it together with its own PostgreSQL database. The steps below assume a fresh Ubuntu server and a user with `sudo`.

### 1. Install Docker

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER
```

Log out and back in so the group change applies, then check `docker compose version` prints a version.

### 2. Get the code

```bash
sudo mkdir -p /var/www && sudo chown $USER /var/www
cd /var/www
git clone https://github.com/Langat18/kvb cpd
cd cpd
```

### 3. Write the `.env` file

```bash
cp .env.example .env
nano .env
```

For the bundled database, set `DB_HOST=db`, pick your own `DB_NAME`, `DB_USER` and a long random `DB_PASSWORD` (`openssl rand -base64 24` makes one), and set `APP_URL` to your public address. Leave `APP_ENV=production`. The compose file passes the same three database values to Postgres, so the two always agree.

### 4. Build and start

```bash
docker compose up -d --build
docker compose ps
```

Both `cpd_app` and `cpd_db` should show as running, and the database as healthy. The first build takes a few minutes because it installs the PHP libraries. The site now answers on port 8081 of the server.

### 5. Load the database

The database starts empty. Load the base schema and then every migration, in order. Do this once, on the first start only.

```bash
source .env
sed '/^CREATE DATABASE/,/^\\c /d' database/schema.sql | docker exec -i cpd_db psql -U "$DB_USER" -d "$DB_NAME"
for f in migrations/*.sql; do
  docker exec -i cpd_db psql -U "$DB_USER" -d "$DB_NAME" < "$f"
done
```

Watch the output for the word ERROR. One error is expected and harmless: file 013_provider_phone_and_email.sql complains that column "address" does not exist, because the earlier migration 011 already added the phone and email columns. Anything else is a real problem.

### 6. Create the first administrator

There is no default login. Make the first account by hand, then add everyone else from the Staff page.

```bash
HASH=$(docker exec cpd_app php -r 'echo password_hash("ChooseAStrongPassword", PASSWORD_DEFAULT);')
docker exec -i cpd_db psql -U "$DB_USER" -d "$DB_NAME" <<SQL
INSERT INTO staff (name, email, password_hash, role_id)
SELECT 'Your Name', 'you@example.org', '$HASH', id FROM roles WHERE item_key = 'admin';
SQL
```

Sign in with that email and password, then change the password from the account menu.

### 7. Put HTTPS in front

Port 8081 serves plain HTTP. Put nginx in front of it to handle your domain and certificate.

```bash
sudo apt install -y nginx certbot python3-certbot-nginx
```

Create `/etc/nginx/sites-available/cpd` with the following, using your own domain:

```nginx
server {
    listen 80;
    server_name cpd.example.org;

    location / {
        proxy_pass http://127.0.0.1:8081;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Then enable it and ask for a certificate. Certbot adds the HTTPS block and the redirect for you.

```bash
sudo ln -s /etc/nginx/sites-available/cpd /etc/nginx/sites-enabled/cpd
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d cpd.example.org
```

The DNS record for the domain must already point at the server, or certbot will fail. Close port 8081 to the outside with your firewall once nginx works, so the only way in is through HTTPS.

### 8. Schedule the nightly PMS refresh

Skip this if you are not connecting to PMS. Otherwise add one line to the server's crontab with `crontab -e`:

```bash
30 2 * * * docker exec cpd_app php /var/www/html/scripts/pms_sync.php cron >> /var/log/cpd_pms_sync.log 2>&1
```

### Using an existing PostgreSQL container instead

If the server already runs Postgres in Docker, use `docker-compose.prod.yml` rather than the default file. It starts only the web application and joins an existing Docker network (named `ksg_default` in the file; rename it to yours). Create a separate database and user for this app inside that Postgres, set `DB_HOST` to the Postgres container's name, and load the schema as in step 5 using that container. Start it with `docker compose -f docker-compose.prod.yml up -d --build`, or run `./deploy.sh`, which does the same and checks the site answers.

## Hosting on a physical or virtual server

Use this route when you do not want Docker. You install Apache, PHP and PostgreSQL yourself and point Apache at the `public` folder. The steps are written for Ubuntu 24.04, which ships PHP 8.3. Anything from PHP 8.2 up works.

### 1. Install the software

```bash
sudo apt update
sudo apt install -y apache2 libapache2-mod-php php php-pgsql php-mbstring php-xml php-zip php-gd php-curl \
    postgresql git unzip composer certbot python3-certbot-apache
sudo a2enmod rewrite headers
```

### 2. Create the database

Pick your own password and keep it for the `.env` file.

```bash
sudo -u postgres psql <<'SQL'
CREATE USER cpd_user WITH PASSWORD 'put-a-long-random-password-here';
CREATE DATABASE cpd_system OWNER cpd_user;
SQL
```

### 3. Get the code and its libraries

```bash
cd /var/www
sudo git clone https://github.com/Langat18/kvb cpd
cd cpd
sudo composer install --no-dev --optimize-autoloader
```

### 4. Configure

```bash
sudo cp .env.example .env
sudo nano .env
```

Set `DB_HOST=localhost`, `DB_PORT=5432`, `DB_NAME=cpd_system`, `DB_USER=cpd_user`, the password from step 2, and your `APP_URL`. Keep `APP_ENV=production`. Then make the files readable by Apache and keep the settings private:

```bash
sudo chown -R www-data:www-data /var/www/cpd
sudo chmod 640 /var/www/cpd/.env
```

### 5. Load the database

Load the base schema, then every migration in order. Do this once, on the first install only.

```bash
cd /var/www/cpd
sed '/^CREATE DATABASE/,/^\\c /d' database/schema.sql | PGPASSWORD='your-password' psql -h localhost -U cpd_user -d cpd_system
for f in migrations/*.sql; do
  PGPASSWORD='your-password' psql -h localhost -U cpd_user -d cpd_system < "$f"
done
```

Watch for the word ERROR in the output. The one expected, harmless error is in 013_provider_phone_and_email.sql, which complains that column "address" does not exist because migration 011 already added the phone and email columns. Anything else is a real problem.

### 6. Create the first administrator

```bash
cd /var/www/cpd
HASH=$(php -r 'echo password_hash("ChooseAStrongPassword", PASSWORD_DEFAULT);')
PGPASSWORD='your-password' psql -h localhost -U cpd_user -d cpd_system <<SQL
INSERT INTO staff (name, email, password_hash, role_id)
SELECT 'Your Name', 'you@example.org', '$HASH', id FROM roles WHERE item_key = 'admin';
SQL
```

### 7. Point Apache at the site

Create `/etc/apache2/sites-available/cpd.conf`:

```apache
<VirtualHost *:80>
    ServerName cpd.example.org
    DocumentRoot /var/www/cpd/public

    <Directory /var/www/cpd/public>
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/cpd-error.log
    CustomLog ${APACHE_LOG_DIR}/cpd-access.log combined
</VirtualHost>
```

The document root is `public`, not the project folder. This keeps `.env`, `src` and `migrations` out of reach of the web. Enable the site and add HTTPS:

```bash
sudo a2dissite 000-default
sudo a2ensite cpd
sudo apachectl configtest && sudo systemctl reload apache2
sudo certbot --apache -d cpd.example.org
```

The DNS record must already point at the server before you run certbot.

### 8. Schedule the nightly PMS refresh

Skip this if you are not connecting to PMS. Run `sudo crontab -u www-data -e` and add:

```bash
30 2 * * * php /var/www/cpd/scripts/pms_sync.php cron >> /var/log/cpd_pms_sync.log 2>&1
```

Create the log file first with `sudo touch /var/log/cpd_pms_sync.log && sudo chown www-data /var/log/cpd_pms_sync.log`.

### Using another web server

Nginx with PHP-FPM works the same way: set the root to `/var/www/cpd/public`, send `.php` files to PHP-FPM, and block access to dotfiles. Nothing in the application depends on Apache rewrite rules.

## Using the system

This section follows an event from setup to reporting. Menu names are the ones on the sidebar.

### Setting up an event

Open Events, then create a new event. You give it a title, dates, the provider and the type: on site, virtual or hybrid. If a PMS activity already exists for it, enter the same activity number as the event code so the two can be linked later.

- **Venue.** For on-site and hybrid events, drop a pin on the map, or search for the place, and set the radius. A phone inside the radius counts as present. Use satellite view to place the pin on the actual building. A radius of 100 metres suits most halls; widen it for a large campus.
- **Sessions.** Add each session with its date, start and end time and the points it carries. Add presenters to a session and rooms where sessions run in parallel.
- **Quiz.** For virtual sessions, add questions to a session. Practitioners must pass the quiz to earn the points. The pass mark is 90% and each person gets two attempts. Questions and options are shuffled for every attempt.
- **Meeting link.** For virtual sessions, add the Zoom, Teams or Meet link so practitioners are given it once they have signed in.

Save, then open the event. The event page holds its QR code and registration link. Print the QR code poster from there and display it at the venue, or send the link to virtual participants.

### What practitioners do

1. Scan the QR code or open the link and register once with their registration number, ID number, name, email, phone and a drawn signature.
2. When a session is about to start, open the same link and choose to sign in. Sign-in opens 45 minutes before the session and stays open for 60 minutes after it ends.
3. For on-site sessions the phone shares its location. Inside the radius, the sign-in is accepted.
4. For virtual sessions the practitioner takes the session quiz and passes it to earn the points.
5. Points are added to their record straight away. Anyone can look up their own total on the Points page with their registration number.

### When something does not pass

A sign-in outside the radius is flagged and held. One more than a kilometre beyond the radius is rejected automatically. A virtual practitioner who fails both quiz attempts is held for a decision.

Staff with the right permission work through these on the event's Pending Approvals page. Tick the rows, choose Approve selected or Deny selected, and add a reason if you wish. Approved rows release their points, denied rows do not, and every decision is recorded with the staff member who made it. The shortcut buttons select everyone within 100 or 250 metres in one click. Check any rows far from the venue by hand before approving them.

### Staff tasks

| Task | Where | Permission needed |
| --- | --- | --- |
| Create and edit events | Events | create_events, manage_events |
| Record attendance for someone who could not sign in | Event page, Record manual attendance | record_manual_attendance |
| Correct a registrant's details | Event page, participant Actions menu | edit_attendee_details |
| Add points by hand | Participant Actions menu | award_manual_points |
| Remove a registration | Participant Actions menu | delete_registrations |
| Print the attendance register or the nominal roll | Event page | print_attendance_register |
| Approve or deny held sign-ins | Pending Approvals | review_flagged_attendance |
| Manage providers | Providers | manage_providers |
| See the leaderboard | Points | view_leaderboard |
| See organisation-wide reports | Reports | view_reports |
| Add staff and set roles | Staff, Roles | manage_staff, manage_roles |
| Edit event types, cadres and session windows | Lookups | manage_lookups |

Roles are made up of these permissions. An administrator can create a new role on the Roles page and tick exactly what it should be able to do.

### Reports

The Reports page covers events held and who attended them for the period you pick: this year, the last 12 months, the last 90 days or all time. It compares virtual and on-site attendance, shows the show-up rate for completed events, attendance by cadre, the five providers with the most activities, and the events with the most flagged sign-ins.

### The PMS page

The PMS page lists approved CPD activities and providers copied from the Board's PMS. From it you can create an event pre-filled from an activity, link an existing event to an activity, and see which providers are missing or named differently in your list. It refreshes nightly if you set up the schedule, and you can refresh it by hand with the Sync now button.

Optional settings control how PMS is used. They live in the `integration_settings` table:

- `pms_registration_check` set to `observe` quietly compares each registration with the PMS record and stores the result in the pms_checks table. There is no screen for it yet, so read it with a query. It never blocks anyone.
- `pms_registration_autofill` set to `on` lets a practitioner fill their details from the register by entering the first 4 characters of their ID number. The rest of the number is shown as dots and is never sent back. If the ID does not match, or PMS holds no ID for that number, the person fills the form in themselves.
- `pms_id_check` chooses which 4 characters are asked for: `first4` (the default when the row is absent), `last4`, or `off`.
- `pms_points_send` set to `on` lets staff send an event's points to PMS from the event's PMS Points page. It is off by default.

Change either with a single query, for example:

```sql
UPDATE integration_settings SET setting_value = 'on', updated_at = now()
WHERE setting_key = 'pms_registration_autofill';
```

## Backups, updates and troubleshooting

### Sending points to PMS

Open an event, then PMS Points. The page works in three steps:

1. **Check with PMS** looks up every practitioner who earned points. A number PMS does not know stays under Not in PMS. For those, the page lists close spellings that PMS does hold (for example `0908` as `VTA00908`), showing whether the name and the ID match. You can also type a PMS number for someone and look it up. Nothing is applied automatically.
2. **Review.** On the Not in PMS tab, tick the possible matches you are satisfied are the same person and press Approve selected. They move to Ready. The same PMS number cannot be approved for two practitioners. The not-in-PMS list can be downloaded for that event.
3. **Send.** Tick the confirmation box and press Send to PMS. Points go in batches of 25. Nobody is ever sent twice: each attempt is recorded, and if PMS gives no clear answer the people in that batch are held as outcome unclear until you have checked PMS and released them.

Sending needs `pms_points_send` set to `on`, an activity number that PMS confirms, and an event that has ended. Points are due in PMS 3 days after the event ends. The page `/reports/pms_unsent.php` lists everyone whose points had not reached PMS by then, with the reason.

### Back up the database every night

The database holds everything that matters: events, registrations, attendance and points. The code can always be fetched again from Git, but the data cannot. Keep copies on another machine, not only on the server.

With Docker:

```bash
mkdir -p /var/backups/cpd
docker exec cpd_db pg_dump -U vetadmin vet_event_attendance | gzip > /var/backups/cpd/cpd_$(date +%F).sql.gz
```

Without Docker:

```bash
PGPASSWORD='your-password' pg_dump -h localhost -U cpd_user cpd_system | gzip > /var/backups/cpd/cpd_$(date +%F).sql.gz
```

Use your own database user and name in place of the examples. Put the command in a small script, run it from cron at night, and delete copies older than 14 days with `find /var/backups/cpd -name '*.gz' -mtime +14 -delete`. Also keep a copy of the `.env` file somewhere private. It holds the passwords and is not in Git.

To restore into an empty database, run `gunzip -c backup.sql.gz | psql ...` with the same connection options. Test a restore on a spare machine before you need one.

### Updating to a new version

Take a backup first. Then:

```bash
cd /var/www/cpd
git rev-parse --short HEAD          # note this, it is your way back
git pull
docker compose up -d --build         # Docker only
```

On a server without Docker, run `sudo composer install --no-dev --optimize-autoloader` instead of the last line and reload Apache with `sudo systemctl reload apache2`.

If the update added migration files, apply only the new ones. Migrations are not tracked automatically and some will fail if run twice, so never re-run the whole folder. List what changed since the version you noted:

```bash
git diff --name-only OLD_COMMIT HEAD -- migrations
```

Then run each listed file once, in number order, as you did in the install.

### Going back

If an update misbehaves, check out the commit you noted and rebuild.

```bash
git checkout OLD_COMMIT
docker compose up -d --build
```

Code changes roll back this way. A migration that has already run does not undo itself, which is why the backup comes first. Restore the backup if the data itself is damaged.

### When something is wrong

| What you see | Likely cause | What to do |
| --- | --- | --- |
| A blank page or a plain error | A PHP error | Docker: `docker logs cpd_app --tail 50`. Otherwise read `/var/log/apache2/cpd-error.log`. |
| "Environment variable required" on every page | A missing `DB_` setting in `.env` | Fill in all five `DB_` values and restart the container. |
| Cannot connect to the database | Wrong `DB_HOST`, password, or the database is down | In Docker, `DB_HOST` is the service name, `db` for the bundled setup. Check `docker compose ps`. |
| Practitioners cannot sign in on site | The page is on HTTP, not HTTPS | Phones block location on plain HTTP. Fix the certificate and the `APP_URL`. |
| Many practitioners flagged at the same venue | Pin is off the building, or the radius is too tight | Edit the event, move the pin using satellite view, widen the radius, then review the held rows. |
| Location says the phone could not be found | Location is switched off for the browser | Ask the practitioner to allow location for the site, then try again. |
| The QR code leads to the wrong address | `APP_URL` is wrong | Correct it in `.env` and restart. Reprint the poster. |
| Password-reset emails never arrive | No `BREVO_API_KEY`, or the sender is not verified in Brevo | Set the key and verify the sender address in Brevo. |
| The PMS page says not synced, or the sync fails | No token, a wrong token, or PMS is unreachable | Check `PMS_API_TOKEN`. Run `docker exec cpd_app php /var/www/html/scripts/pms_sync.php manual` and read the message it prints. |
| Autofill by ID does nothing | The setting is off, or PMS holds no ID for that number | Set `pms_registration_autofill` to `on`, as shown in the PMS section above. |

### Checking the installation

From the project folder, `bash scripts/system_check.sh` runs a set of sanity checks: it lints every PHP file and compares the migration files with what the database has recorded. It expects a local `psql` on a database named `vet_event_attendance`, so on other setups read it first and adjust the name at the top.
