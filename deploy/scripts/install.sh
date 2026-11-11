#!/usr/bin/env bash
# Run ON THE SERVER (as root, or via sudo):  bash install.sh
# Installs the Vtiger docker stack into $APP_DIR (default /opt/vtiger). Idempotent.
# Another company on the same server:  APP_DIR=/opt/gama WEB_PORT=9071 COMPANY=GAMA bash install.sh
set -euo pipefail
KIT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"      # .../deploy
APP_DIR="${APP_DIR:-/opt/vtiger}"
WEB_PORT="${WEB_PORT:-9081}"
COMPANY="${COMPANY:-SamenStudent}"
VTIGER_VERSION="${VTIGER_VERSION:-8.4.0}"
SF_URL="https://sourceforge.net/projects/vtigercrm/files/vtiger%20CRM%20${VTIGER_VERSION}/Core%20Product/vtigercrm${VTIGER_VERSION}.tar.gz/download"

[ "$(id -u)" -eq 0 ] || { echo "Run as root (sudo bash install.sh)"; exit 1; }

echo "== 1/5 docker"
if ! command -v docker >/dev/null; then
  echo "docker not found – installing from distro packages"
  apt-get update && apt-get install -y docker.io docker-compose-v2 || apt-get install -y docker.io docker-compose-plugin
  systemctl enable --now docker
fi
docker compose version >/dev/null || { echo "docker compose plugin missing (apt install docker-compose-v2)"; exit 1; }

echo "== 2/5 files -> $APP_DIR"
mkdir -p "$APP_DIR"/{data/db,data/vtiger,ss,web,scripts}
cp "$KIT_DIR/docker-compose.yml" "$APP_DIR/"
cp -r "$KIT_DIR/web/." "$APP_DIR/web/"
cp -r "$KIT_DIR/scripts/." "$APP_DIR/scripts/"
cp "$KIT_DIR/.env.example" "$APP_DIR/.env.example"
if [ ! -f "$APP_DIR/.env" ]; then
  rnd() { head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24; }
  sed -e "s/^DB_PASSWORD=.*/DB_PASSWORD=$(rnd)/" -e "s/^DB_ROOT_PASSWORD=.*/DB_ROOT_PASSWORD=$(rnd)/" \
      "$APP_DIR/.env.example" > "$APP_DIR/.env"
  { echo "COMPOSE_PROJECT_NAME=$(basename "$APP_DIR")"; echo "WEB_PORT=$WEB_PORT"; echo "PUBLIC_HOST=212.33.206.241:$WEB_PORT"; echo "COMPANY=$COMPANY"; } >> "$APP_DIR/.env"
  chmod 600 "$APP_DIR/.env"
  echo "generated $APP_DIR/.env (random DB passwords)"
fi

echo "== 3/5 Vtiger ${VTIGER_VERSION} source"
TARBALL="$APP_DIR/web/vtigercrm.tar.gz"
if [ ! -s "$TARBALL" ]; then
  for cand in "$KIT_DIR/vtigercrm.tar.gz" "$KIT_DIR/../vtigercrm.tar.gz" /root/vtigercrm.tar.gz /opt/vtiger/web/vtigercrm.tar.gz "$HOME/vtigercrm.tar.gz"; do
    [ -s "$cand" ] && { cp "$cand" "$TARBALL"; echo "using supplied $cand"; break; }
  done
fi
if [ ! -s "$TARBALL" ]; then
  echo "downloading $SF_URL"
  curl -fL --retry 3 --connect-timeout 20 -o "$TARBALL" "$SF_URL" || true
fi
if ! gzip -t "$TARBALL" 2>/dev/null; then
  rm -f "$TARBALL"
  cat <<MSG
!! Could not obtain a valid Vtiger tarball (SourceForge may be unreachable from this server).
   Download vtigercrm${VTIGER_VERSION}.tar.gz on your PC, then:
     scp vtigercrm${VTIGER_VERSION}.tar.gz <you>@<server>:/tmp/vtigercrm.tar.gz
     sudo cp /tmp/vtigercrm.tar.gz $TARBALL   # then re-run this script
MSG
  exit 1
fi

echo "== 4/5 build & start"
cd "$APP_DIR"
docker image inspect samenstudent/vtiger-web:local >/dev/null 2>&1 || docker compose build web
docker compose up -d db web cron
set -a; . ./.env; set +a
echo "waiting for web on :${WEB_PORT:-9081} ..."
for i in $(seq 1 60); do
  code=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:${WEB_PORT:-9081}/ || true)
  [ "$code" = "200" ] || [ "$code" = "302" ] && break
  sleep 3
done
echo "HTTP $code"

echo "== 5/5 Vtiger web installer (automated)"
APP_DIR="$APP_DIR" bash "$APP_DIR/scripts/auto_install.sh"
cat <<MSG

Next (from your PC):  ./run_all.sh import --limit 20   then   ./run_all.sh import
MSG
