#!/usr/bin/env bash
# SamenStudent – Vtiger rollout, driven from YOUR PC.
#
#   ./run_all.sh build      # 1) Excel -> output/dataset.json (+ csv, raw archive, report)
#   ./run_all.sh install    # 2) copy kit to server, install docker stack in /opt/vtiger  (then finish web wizard)
#   ./run_all.sh import     # 3) after the wizard: push data, create fields/groups/filters, import everything
#   ./run_all.sh import --limit 20   # test import of first 20 contacts
#   ./run_all.sh details    # add derived details (city/country, WhatsApp, operator, title, completeness) to contacts
#   ./run_all.sh persian    # build + install fa_ir pack, PersianCalendar (Jalali) and SamenTheme (skin/RTL/login), users -> Persian
#   ./run_all.sh academy    # SamenAcademy: "راهنما" button on every page + courses/wiki (modules/SamenAcademy)
#
# Env overrides:  SSH_TARGET=root@212.33.206.241  SSH_PORT=9080  DIRECT_IF=auto|wlp5s0|none   XLS_DIR=...   VTIGER_TARBALL=/path/vtigercrm8.4.0.tar.gz
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
XLS_DIR="${XLS_DIR:-$HERE/../xls}"
OUT="$HERE/output"
SSH_TARGET="${SSH_TARGET:-root@212.33.206.241}"
REMOTE_KIT="${REMOTE_KIT:-/tmp/ss-kit}"
SUDO=""; [[ "$SSH_TARGET" == root@* ]] || SUDO="sudo"
cmd="${1:-help}"; shift || true

# If a full-tunnel VPN (tun*) carries traffic to the server, force SSH out of the LAN interface
# (tools/direct_proxy.py, SO_BINDTODEVICE) – no route changes needed.  DIRECT_IF=none disables it.
SSH_OPTS=(-o ServerAliveInterval=30)
SRV_HOST="${SSH_TARGET#*@}"
SSH_PORT="${SSH_PORT:-9080}"
SSH_OPTS+=(-o Port="$SSH_PORT")
if [ "${DIRECT_IF:-auto}" = auto ]; then
  DIRECT_IF=""
  if ip route get "$SRV_HOST" 2>/dev/null | grep -q ' dev tun'; then
    DIRECT_IF=$(ip -4 route show default | grep -v ' dev tun' | sed -n 's/.* dev \([^ ]*\).*/\1/p' | head -1)
  fi
fi
[ -n "$DIRECT_IF" ] && [ "$DIRECT_IF" != none ] && SSH_OPTS+=(-o "ProxyCommand=python3 $HERE/tools/direct_proxy.py %h %p $DIRECT_IF")
ssh() { command ssh "${SSH_OPTS[@]}" "$@"; }
scp() { command scp "${SSH_OPTS[@]}" "$@"; }

build() {
  python3 -c "import python_calamine" 2>/dev/null || {
    python3 -m venv "$HERE/.venv" && "$HERE/.venv/bin/pip" install -q python-calamine
    PY="$HERE/.venv/bin/python3"; }
  PY="${PY:-python3}"; [ -x "$HERE/.venv/bin/python3" ] && PY="$HERE/.venv/bin/python3"
  mkdir -p "$OUT"
  "$PY" "$HERE/data/build_dataset.py" "$XLS_DIR" "$OUT" | grep -v '^  skipped' | tail -25
  python3 "$HERE/vtiger/make_schema.py" "$OUT/dataset.json" "$OUT/schema.json"
  echo "-> $OUT/{dataset.json,students.csv,raw_archive.json,report.txt,schema.json}"
}

push_kit() {
  ssh "$SSH_TARGET" "rm -rf $REMOTE_KIT && mkdir -p $REMOTE_KIT"
  scp -rq "$HERE/deploy" "$SSH_TARGET:$REMOTE_KIT/"
  [ -n "${VTIGER_TARBALL:-}" ] && scp -q "$VTIGER_TARBALL" "$SSH_TARGET:$REMOTE_KIT/deploy/vtigercrm.tar.gz"
  true
}

case "$cmd" in
  build) build ;;
  install)
    push_kit
    ssh "$SSH_TARGET" "$SUDO bash $REMOTE_KIT/deploy/scripts/install.sh" ;;
  import)
    [ -s "$OUT/dataset.json" ] || build
    ssh "$SSH_TARGET" "mkdir -p $REMOTE_KIT/ss"
    scp -q "$OUT/dataset.json" "$OUT/schema.json" "$HERE/vtiger/setup_vtiger.php" "$HERE/vtiger/import_to_vtiger.py" \
        "$HERE/vtiger/ss_schema.py" "$HERE/vtiger/tag_contacts.php" "$SSH_TARGET:$REMOTE_KIT/ss/"
    scp -q "$HERE/deploy/scripts/"*.sh "$SSH_TARGET:$REMOTE_KIT/ss/"
    ssh "$SSH_TARGET" "$SUDO mkdir -p /opt/vtiger/ss && $SUDO mv $REMOTE_KIT/ss/*.sh /opt/vtiger/scripts/ && $SUDO cp $REMOTE_KIT/ss/* /opt/vtiger/ss/"
    # run detached on the server (survives SSH drops), then follow its log
    ssh "$SSH_TARGET" "$SUDO setsid nohup bash /opt/vtiger/scripts/post_install.sh $* > /opt/vtiger/ss/post_install.log 2>&1 < /dev/null &"
    sleep 3; seen=0
    while :; do
      out=$(ssh -o ConnectTimeout=20 "$SSH_TARGET" "tail -n +$((seen+1)) /opt/vtiger/ss/post_install.log; pgrep -f '[p]ost_install.sh' >/dev/null && echo __RUNNING__; true" 2>/dev/null) || { sleep 15; continue; }
      body=$(printf '%s\n' "$out" | grep -v '^__RUNNING__$' || true)
      [ -n "$body" ] && { printf '%s\n' "$body"; seen=$((seen + $(printf '%s\n' "$body" | wc -l))); }
      printf '%s\n' "$out" | grep -q '^__RUNNING__$' || break
      sleep 10
    done ;;
  persian)
    python3 "$HERE/i18n/build_langpack.py" --strict
    bash "$HERE/modules/PersianCalendar/build.sh"
    bash "$HERE/modules/SamenTheme/build.sh"
    ssh "$SSH_TARGET" "mkdir -p $REMOTE_KIT/ss"
    scp -q "$HERE/i18n/dist/Persian_fa_ir.zip" "$HERE/modules/PersianCalendar/dist/PersianCalendar.zip" "$HERE/modules/SamenTheme/dist/SamenTheme.zip" \
        "$HERE/i18n/install_persian.php" "$HERE/i18n/translate_workflows.php" "$SSH_TARGET:$REMOTE_KIT/ss/"
    ssh "$SSH_TARGET" "$SUDO mkdir -p /opt/vtiger/ss && $SUDO cp $REMOTE_KIT/ss/Persian_fa_ir.zip $REMOTE_KIT/ss/PersianCalendar.zip $REMOTE_KIT/ss/SamenTheme.zip $REMOTE_KIT/ss/install_persian.php $REMOTE_KIT/ss/translate_workflows.php /opt/vtiger/ss/ && cd /opt/vtiger && $SUDO docker compose exec -T web sh -c 'php /opt/ss/install_persian.php /opt/ss/Persian_fa_ir.zip /opt/ss/PersianCalendar.zip /opt/ss/SamenTheme.zip ${PERSIAN_ARGS:---all-users} && chown -R www-data:www-data /var/www/html/languages /var/www/html/modules/PersianCalendar /var/www/html/layouts/v7/modules/PersianCalendar /var/www/html/modules/SamenTheme /var/www/html/layouts/v7/modules/SamenTheme /var/www/html/layouts/v7/custom_skins /var/www/html/layouts/v7/modules/Users/Login.tpl /var/www/html/layouts/v7/modules/Vtiger/Footer.tpl /var/www/html/layouts/v7/modules/Reports/PrintReport.tpl /var/www/html/libraries/bootstrap-legacy /var/www/html/test/logo /var/www/html/test/templates_c /var/www/html/user_privileges /var/www/html/config.inc.php'" ;;
  academy)
    # SamenAcademy: "راهنما" on every page + training courses (modules/SamenAcademy)
    bash "$HERE/modules/SamenAcademy/build.sh"
    AC="$HERE/modules/SamenAcademy"
    ssh "$SSH_TARGET" "rm -rf $REMOTE_KIT/academy && mkdir -p $REMOTE_KIT/academy"
    scp -q "$AC/dist/SamenAcademy.zip" "$AC/tools/install_academy.php" "$SSH_TARGET:$REMOTE_KIT/academy/"
    ssh "$SSH_TARGET" "$SUDO mkdir -p /opt/vtiger/ss/academy && $SUDO cp $REMOTE_KIT/academy/* /opt/vtiger/ss/academy/ && cd /opt/vtiger && \
      $SUDO docker compose exec -T web sh -c 'php /opt/ss/academy/install_academy.php /opt/ss/academy/SamenAcademy.zip && chown -R www-data:www-data /var/www/html/modules/SamenAcademy /var/www/html/layouts/v7/modules/SamenAcademy /var/www/html/languages user_privileges tabdata.php parent_tabdata.php'" ;;
  telegram)
    # TelegramBot module: build the vtlib zip, back up the DB, install/update it, minute cron on the host
    bash "$HERE/modules/TelegramBot/build.sh"
    TG="$HERE/modules/TelegramBot"
    ssh "$SSH_TARGET" "rm -rf $REMOTE_KIT/telegram && mkdir -p $REMOTE_KIT/telegram"
    scp -q "$TG/dist/TelegramBot.zip" "$TG/tools/install_telegram.php" "$TG/tools/cron_tick.sh" \
        "$TG/tests/mock_telegram.php" "$TG/tests/e2e_test.php" "$TG/tests/run_tests.sh" "$SSH_TARGET:$REMOTE_KIT/telegram/"
    ssh "$SSH_TARGET" "$SUDO mkdir -p /opt/vtiger/ss/telegram && $SUDO cp $REMOTE_KIT/telegram/* /opt/vtiger/ss/telegram/ && $SUDO chmod +x /opt/vtiger/ss/telegram/*.sh && cd /opt/vtiger && set -a && . ./.env && set +a && \
      $SUDO docker compose exec -T db mariadb-dump -uroot -p\$DB_ROOT_PASSWORD --single-transaction \$DB_NAME | gzip > backups/pre_telegram_\$(date +%F_%H%M).sql.gz && echo 'DB backup done' && \
      $SUDO docker compose exec -T web sh -c 'cd /var/www/html && php /opt/ss/telegram/install_telegram.php /opt/ss/telegram/TelegramBot.zip && mkdir -p storage/TelegramBot && chown -R www-data:www-data modules/TelegramBot layouts/v7/modules/TelegramBot languages cron/modules/TelegramBot storage/TelegramBot user_privileges tabdata.php parent_tabdata.php' && \
      echo '* * * * * root /opt/vtiger/ss/telegram/cron_tick.sh' | $SUDO tee /etc/cron.d/samencrm-telegram > /dev/null && $SUDO chmod 644 /etc/cron.d/samencrm-telegram && echo 'host cron: /etc/cron.d/samencrm-telegram'"
    if [ "${1:-}" = test ]; then ssh "$SSH_TARGET" "$SUDO sh /opt/vtiger/ss/telegram/run_tests.sh"; fi ;;
  migrate-fa)
    # one-time: rename English labels of the 2026-11-10 import to Persian (needs output/dataset.en_labels.json)
    ssh "$SSH_TARGET" "cat /opt/vtiger/ss/import_state.json" > "$OUT/import_state.json"
    python3 "$HERE/vtiger/persianize_existing.py" "$OUT/dataset.en_labels.json" "$OUT/dataset.json" "$OUT/import_state.json" > "$OUT/persianize.sql"
    scp -q "$OUT/persianize.sql" "$OUT/dataset.json" "$OUT/schema.json" "$HERE/vtiger/tag_contacts.php" "$SSH_TARGET:$REMOTE_KIT/ss/"
    ssh "$SSH_TARGET" "$SUDO cp $REMOTE_KIT/ss/persianize.sql $REMOTE_KIT/ss/dataset.json $REMOTE_KIT/ss/schema.json $REMOTE_KIT/ss/tag_contacts.php /opt/vtiger/ss/ && cd /opt/vtiger && set -a && . ./.env && set +a && \
      docker compose exec -T db mariadb-dump -uroot -p\$DB_ROOT_PASSWORD --single-transaction \$DB_NAME | gzip > backups/pre_migrate_fa_\$(date +%F_%H%M).sql.gz && \
      docker compose exec -T db mariadb --default-character-set=utf8 -u\$DB_USER -p\$DB_PASSWORD \$DB_NAME < ss/persianize.sql && echo migrated" ;;
  details|locations)
    # add derived details (city/country, WhatsApp, operator, title, completeness...) to imported contacts;
    # the import step creates any new custom fields first and skips existing records
    [ -s "$OUT/dataset.json" ] || build
    "$0" import --skip-activities >/dev/null      # pushes dataset/schema, creates the new field, skips existing records
    ssh "$SSH_TARGET" "cat /opt/vtiger/ss/import_state.json" > "$OUT/import_state.json"
    python3 "$HERE/vtiger/apply_details.py" "$OUT/dataset.json" "$OUT/import_state.json" > "$OUT/details.sql"
    scp -q "$OUT/details.sql" "$SSH_TARGET:$REMOTE_KIT/ss/"
    ssh "$SSH_TARGET" "$SUDO cp $REMOTE_KIT/ss/details.sql /opt/vtiger/ss/ && cd /opt/vtiger && set -a && . ./.env && set +a && \
      docker compose exec -T db mariadb --default-character-set=utf8 -u\$DB_USER -p\$DB_PASSWORD \$DB_NAME < ss/details.sql && echo 'details applied'" ;;
  *) sed -n '2,12p' "$0" ;;
esac
