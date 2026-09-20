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
match. Then run it against the existing `ksg_db` container:

```
docker exec -i ksg_db psql -U <existing_superuser> < docker/db/init_cpd_db.sql
```

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

## 7. Add the nginx vhost

Create `/etc/nginx/sites-available/cpdsystem`:

```nginx
server {
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

    listen 443 ssl;
    ssl_certificate /etc/letsencrypt/live/cpdsystem.ksgcm.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/cpdsystem.ksgcm.com/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;
}
server {
    if ($host = cpdsystem.ksgcm.com) {
        return 301 https://$host$request_uri;
    }
    listen 80;
    server_name cpdsystem.ksgcm.com;
    return 404;
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

(certbot will rewrite the `ssl_certificate` lines in step 7's file — that's
expected, matches the existing `ksgcm` vhost pattern.)

## 9. Verify

```
curl -I https://cpdsystem.ksgcm.com
```
