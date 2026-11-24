#!/usr/bin/env bash
# Adds browser security headers and hides the nginx version for cpdsystem.ksgcm.com only.
# Backs up the site file, tests the config, reloads (no one is disconnected), and restores everything if a check fails.
#   bash nginx_headers.sh
set -uo pipefail
SITE="cpdsystem.ksgcm.com"; SNIP=/etc/nginx/snippets/cpd-security-headers.conf; TOK=/etc/nginx/conf.d/00-hide-version.conf
STAMP=$(date +%Y%m%d_%H%M%S); BK=/root/backup/nginx_$STAMP
die() { echo "STOP: $*" >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || die "run as root"
command -v nginx >/dev/null || die "nginx not found"

echo "1/5 Find the site file"
FILE=""
for f in /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf /etc/nginx/nginx.conf; do
  [ -e "$f" ] || continue
  r=$(readlink -f "$f"); grep -q "server_name[[:space:]].*$SITE" "$r" && { FILE="$r"; break; }
done
[ -n "$FILE" ] || die "no nginx file defines $SITE"
echo "    $FILE"
mkdir -p "$BK" /etc/nginx/snippets; chmod 700 "$BK"; cp -a "$FILE" "$BK/site.conf"
[ -e "$SNIP" ] && cp -a "$SNIP" "$BK/snippet.conf"; [ -e "$TOK" ] && cp -a "$TOK" "$BK/tokens.conf"
echo "    backup: $BK"

echo "2/5 Write the header snippet"
cat > "$SNIP" <<'EOS'
# Security headers for the CPD system. 'always' keeps them on error pages too.
add_header Strict-Transport-Security "max-age=15552000" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "geolocation=(self), camera=(), microphone=(), payment=()" always;
# Reports problems in the browser console only; nothing is blocked yet.
add_header Content-Security-Policy-Report-Only "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'" always;
EOS
printf '# Hide the nginx version number.\nserver_tokens off;\n' > "$TOK"

echo "3/5 Include it in the HTTPS block of $SITE"
python3 - "$FILE" "$SITE" <<'EOP' || die "could not edit the site file"
import sys,re
path,site=sys.argv[1],sys.argv[2]
L=open(path).read().split('\n'); inc='    include snippets/cpd-security-headers.conf;'
if any('cpd-security-headers.conf' in l for l in L): print('    already included'); sys.exit(0)
done=False
for i,l in enumerate(L):
    if re.match(r'\s*server_name\s.*'+re.escape(site),l):
        s=i
        while s>=0 and not re.match(r'\s*server\s*\{',L[s]): s-=1
        depth=0; e=s
        for j in range(s,len(L)):
            depth+=L[j].count('{')-L[j].count('}')
            if depth==0: e=j; break
        if any('listen' in x and '443' in x for x in L[s:e+1]):
            L.insert(i+1,inc); done=True; break
if not done: sys.exit(1)
open(path,'w').write('\n'.join(L)); print('    included')
EOP

restore() { cp -a "$BK/site.conf" "$FILE"; [ -e "$BK/snippet.conf" ] && cp -a "$BK/snippet.conf" "$SNIP" || rm -f "$SNIP"; [ -e "$BK/tokens.conf" ] && cp -a "$BK/tokens.conf" "$TOK" || rm -f "$TOK"; nginx -t >/dev/null 2>&1 && systemctl reload nginx; }

echo "4/5 Test the config"
if ! nginx -t 2>&1 | tail -2; then restore; die "nginx test failed; everything restored"; fi
systemctl reload nginx || { restore; die "reload failed; restored"; }
sleep 2

echo "5/5 Check the live site"
out=$(curl -sIL -m 10 "https://$SITE/login.php")
code=$(echo "$out" | grep -m1 '^HTTP' | tr -d '\r')
echo "    $code"
if echo "$out" | grep -qi '^strict-transport' && echo "$out" | grep -qi '^x-content-type' && echo "$code" | grep -qE ' (200|301|302)'; then
  echo "OK. Headers are live; the nginx version is hidden. Backup: $BK"
else
  restore; die "check failed; the old config is back"
fi
