#!/usr/bin/env bash
# Run ON THE SERVER (root) after install.sh started the stack.
# Drives the Vtiger web installer (Step4 -> Step5 -> Step7) with curl, so no browser is needed.
#   sudo APP_DIR=/opt/vtiger bash /opt/vtiger/scripts/auto_install.sh
# Admin login is written to $APP_DIR/.env (VT_ADMIN_USER / VT_ADMIN_PASSWORD, mode 600).
set -euo pipefail
APP_DIR="${APP_DIR:-/opt/vtiger}"
cd "$APP_DIR"
set -a; . ./.env; set +a

PUBLIC_HOST="${PUBLIC_HOST:-212.33.206.241:${WEB_PORT:-9081}}"   # becomes $site_URL in config.inc.php
LOCAL=http://127.0.0.1:${WEB_PORT:-9081}
CURRENCY="${CURRENCY:-Iran, Rials}"
TZ_NAME="${TZ:-Asia/Tehran}"

if [ -f data/vtiger/config.inc.php ] && grep -q "db_name" data/vtiger/config.inc.php; then
  echo "Vtiger already installed (config.inc.php exists) – nothing to do"; exit 0
fi

if ! grep -q '^VT_ADMIN_PASSWORD=' .env; then
  pw="Ss$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 14)#9"
  { echo "VT_ADMIN_USER=admin"; echo "VT_ADMIN_PASSWORD=$pw"; } >> .env
  chmod 600 .env
  set -a; . ./.env; set +a
fi

JAR=$(mktemp); trap 'rm -f "$JAR" /tmp/vt_step*.html' EXIT
C=(curl -sS -b "$JAR" -c "$JAR" -H "Host: $PUBLIC_HOST" -H "Referer: http://$PUBLIC_HOST/index.php")
token() { grep -oE "csrfMagicToken = \"[^\"]+\"" "$1" | head -1 | cut -d'"' -f2; }

echo "== Step4 (form)"
"${C[@]}" "$LOCAL/index.php?module=Install&view=Index&mode=Step4" -o /tmp/vt_step4.html
TK=$(token /tmp/vt_step4.html); [ -n "$TK" ] || { echo "no CSRF token on Step4"; head -50 /tmp/vt_step4.html; exit 1; }

echo "== Step5 (DB check + config)"
"${C[@]}" "$LOCAL/index.php" -o /tmp/vt_step5.html \
  --data-urlencode "__vtrftk=$TK" -d module=Install -d view=Index -d mode=Step5 \
  -d db_type=mysqli -d db_hostname=db -d "db_username=$DB_USER" --data-urlencode "db_password=$DB_PASSWORD" \
  -d "db_name=$DB_NAME" -d db_root_username= -d db_root_password= \
  --data-urlencode "currency_name=$CURRENCY" -d admin=admin \
  --data-urlencode "password=$VT_ADMIN_PASSWORD" --data-urlencode "retype_password=$VT_ADMIN_PASSWORD" \
  --data-urlencode "firstname=${COMPANY:-SamenStudent}" -d lastname=Admin -d "admin_email=admin@$(echo "${COMPANY:-samenstudent}" | tr "A-Z" "a-z" | tr -dc "a-z0-9").local" \
  -d dateformat=yyyy-mm-dd --data-urlencode "timezone=$TZ_NAME"
AUTH=$(grep -oE 'name="auth_key" value="[^"]+"' /tmp/vt_step5.html | head -1 | sed 's/.*value="//; s/"$//')
if [ -z "$AUTH" ] || grep -qi 'Unable to connect to database\|DB_CONNECTION_FAILED' /tmp/vt_step5.html; then
  echo "Step5 failed (DB connection?)"; sed 's/<[^>]*>//g' /tmp/vt_step5.html | grep -v '^\s*$' | head -40; exit 1
fi
TK=$(token /tmp/vt_step5.html)

echo "== Step7 (creating tables + installing modules, takes a few minutes)"
"${C[@]}" -m 1800 "$LOCAL/index.php" -o /tmp/vt_step7.html \
  --data-urlencode "__vtrftk=$TK" -d module=Install -d view=Index -d mode=Step7 \
  -d "auth_key=$AUTH" -d myname= -d myemail= -d "industry=${INDUSTRY:-Education}"

if docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -N -e "select user_name from vtiger_users where id=1" 2>/dev/null | grep -q admin; then
  echo "Vtiger installed.  URL: http://$PUBLIC_HOST/   user: admin   password: in $APP_DIR/.env (VT_ADMIN_PASSWORD)"
else
  echo "Install did not complete – see output:"; sed 's/<[^>]*>//g' /tmp/vt_step7.html | grep -v '^\s*$' | head -40; exit 1
fi
