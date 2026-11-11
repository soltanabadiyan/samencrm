#!/usr/bin/env bash
# Run ON THE SERVER (root): give an instance its public address, e.g. behind a reverse proxy with a domain.
#   sudo bash set_public_url.sh /opt/gama https://crm.samencloud.ir
# Vtiger redirects every request whose address differs from $site_URL to $site_URL (includes/main/WebUI.php),
# so a proxied domain lands on the IP:port and the login breaks (cookie on one host, form on the other).
# This sets $site_URL, records PUBLIC_URL in .env, and makes Apache honour the proxy's X-Forwarded-Proto
# (https at the proxy, plain http to the container) through a marked block in the web root's .htaccess.
set -euo pipefail
APP_DIR="${1:?usage: set_public_url.sh /opt/<instance> https://host[:port]}"
URL="${2:?usage: set_public_url.sh /opt/<instance> https://host[:port]}"
URL="${URL%/}/"
[[ "$URL" =~ ^https?://[A-Za-z0-9.:-]+/$ ]] || { echo "bad URL: $URL"; exit 1; }
CFG="$APP_DIR/data/vtiger/config.inc.php"
HT="$APP_DIR/data/vtiger/.htaccess"
[ -f "$CFG" ] || { echo "no $CFG"; exit 1; }

cp -a "$CFG" "$CFG.bak.$(date +%F_%H%M%S)"
sed -i "s#^\$site_URL = '.*';#\$site_URL = '$URL';#" "$CFG"
grep -q "^\$site_URL = '$URL';" "$CFG" || { echo "could not set site_URL"; exit 1; }

touch "$HT"
sed -i '/# SAMENCRM:PROXY:BEGIN/,/# SAMENCRM:PROXY:END/d' "$HT"
cat >> "$HT" <<'EOF'
# SAMENCRM:PROXY:BEGIN – reverse proxy terminates https and forwards plain http (set_public_url.sh)
<IfModule mod_setenvif.c>
SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on
</IfModule>
# SAMENCRM:PROXY:END
EOF
chown www-data:www-data "$HT" 2>/dev/null || chown 33:33 "$HT"

ENV="$APP_DIR/.env"
if grep -q '^PUBLIC_URL=' "$ENV"; then sed -i "s#^PUBLIC_URL=.*#PUBLIC_URL=$URL#" "$ENV"; else echo "PUBLIC_URL=$URL" >> "$ENV"; fi
# compiled templates / cached config pick the new address up at once
rm -f "$APP_DIR"/data/vtiger/test/templates_c/v7/*.php 2>/dev/null || true
echo "site_URL = $URL  ($APP_DIR)"
