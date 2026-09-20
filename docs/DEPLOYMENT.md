# Deploying to the VPS (cpdsystem.ksgcm.com)

This app is deployed alongside the existing `ksg` app on the same VPS,
sharing its Postgres container and Docker network. Run the steps below in
order.

## 1. DNS

Add an A record: `cpdsystem.ksgcm.com` -> `104.248.117.15`

## 2. Get the code onto the VPS

```
cd /var/www
git clone https://github.com/Langat18/kvb cpd
cd cpd
```

(or `git pull` if already cloned)

## 3. Configure environment

```
cp .env.example .env
```

Edit `.env`:

- `DB_HOST=ksg_db`
- `DB_PORT=5432`
- `DB_NAME=cpd_system`
- `DB_USER=cpd_user`
- `DB_PASSWORD=<password generated in step 4>`
- `APP_URL=https://cpdsystem.ksgcm.com`
- `BREVO_API_KEY` / `MAIL_FROM_NAME` / `MAIL_FROM_ADDRESS` — fill in if email sending is wanted, otherwise leave blank

## 4. Create the database

Generate a password once:

```
openssl rand -base64 24
```

Put that value into `docker/db/init_cpd_db.sql` (replacing
`CHANGE_ME_GENERATE_A_PASSWORD`) and into `.env`'s `DB_PASSWORD` — they must
match. Then run it against the existing `ksg_db` container, connecting as the
existing app's Postgres superuser and its own database (e.g. `user1` /
`ksg_reports` — check `ksg`'s `.env` on the VPS for the actual values):

```
docker exec -i ksg_db psql -U user1 -d ksg_reports < docker/db/init_cpd_db.sql
```

**If you already ran this without replacing the placeholder password**, the
`cpd_user` role now has the literal password `CHANGE_ME_GENERATE_A_PASSWORD`
in production. Fix it before going further:

```
docker exec -i ksg_db psql -U user1 -d ksg_reports -c \
  "ALTER USER cpd_user WITH PASSWORD '<the real generated password>';"
```

and make sure `.env`'s `DB_PASSWORD` matches.

## 5. Build and start the app

```
./deploy.sh
```

This builds the image, starts the `cpd_app` container on host port `8081`
(joining the existing `ksg_default` network so it can reach `ksg_db`), and
smoke-tests `http://localhost:8081`.

## 6. Load schema + migrations (first deploy only)

```
sed '/^CREATE DATABASE/,/^\\c /d' database/schema.sql | \
    docker exec -i ksg_db psql -U cpd_user -d cpd_system

for f in migrations/*.sql; do
    docker exec -i ksg_db psql -U cpd_user -d cpd_system < "$f"
done
```

## 7. Add the nginx vhost (HTTP only, for now)

Don't add the `listen 443 ssl` block yet — the cert doesn't exist until step 8,
and `nginx -t` will fail if the config points at cert files that aren't there.

Create `/etc/nginx/sites-available/cpdsystem`:

```nginx
server {
    listen 80;
    server_name cpdsystem.ksgcm.com;

    gzip off;

    location / {
        proxy_pass http://localhost:8081;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_buffering off;
    }
}
```

Enable it:

```
ln -s /etc/nginx/sites-available/cpdsystem /etc/nginx/sites-enabled/cpdsystem
nginx -t && systemctl reload nginx
```

## 8. Get the TLS certificate

```
certbot --nginx -d cpdsystem.ksgcm.com
```

This is the same way the existing `ksgcm` vhost got its SSL block — certbot
edits the file in place, adding the `listen 443 ssl` server block (with the
`ssl_certificate` paths it just created) and the `:80 -> https` redirect
block. Run `nginx -t && systemctl reload nginx` again afterward if certbot
doesn't do it for you.

## 9. Verify

```
curl -I https://cpdsystem.ksgcm.com
```
