#!/usr/bin/env bash
# Log in as admin ON THE SERVER (password read from /opt/vtiger/.env there) and print only the PHPSESSID,
# for tools/ui_shot.mjs. The password never leaves the server.  Another instance: APP_DIR=/opt/gama tools/get_session.sh (+ BASE=http://<server>:9071/ for ui_audit)
T="$(cd "$(dirname "$0")" && pwd)"; P="$T/direct_proxy.py"; . "$T/server_env.sh"
: "${SSH_ALIAS:?set SSH_ALIAS (or SSH_TARGET) in server.env}" "${SERVER_HOST:?set SERVER_HOST in server.env}"
ssh -o ProxyCommand="python3 $P %h %p ${DIRECT_IF:-wlp5s0}" -o BatchMode=yes "$SSH_ALIAS" 'SERVER_HOST='"$SERVER_HOST"'; cd '"${APP_DIR:-/opt/vtiger}"'; set -a; . ./.env; set +a; PORT=${WEB_PORT:-9081}; J=$(mktemp); H="Host: ${PUBLIC_HOST:-$SERVER_HOST:$PORT}"; XP=""; if [ -n "${PUBLIC_URL:-}" ]; then H="Host: $(echo $PUBLIC_URL | sed -E "s#^https?://([^/]+)/?.*#\1#")"; case $PUBLIC_URL in https:*) XP="X-Forwarded-Proto: https";; esac; fi; U=http://127.0.0.1:$PORT
curl -s -c $J -b $J -H "$H" -H "$XP" $U/index.php > $J.html; TK=$(grep -oE "csrfMagicToken = \"[^\"]+\"" $J.html | cut -d\" -f2)
curl -s -c $J -b $J -H "$H" -H "$XP" -o /dev/null --data-urlencode "__vtrftk=$TK" -d module=Users -d action=Login -d username=admin -d skin=${SKIN:-samen} --data-urlencode "password=$VT_ADMIN_PASSWORD" $U/index.php
awk "\$6==\"PHPSESSID\"{print \$7}" $J | tail -1; rm -f $J $J.html'
