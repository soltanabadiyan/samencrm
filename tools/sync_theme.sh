#!/usr/bin/env bash
# Dev helper: rebuild the SamenTheme skin and copy theme/calendar files straight into the running CRM
# (no package reinstall). Use ./run_all.sh persian for a real install.
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
. "$HERE/tools/server_env.sh"; : "${SSH_ALIAS:?set SSH_ALIAS (or SSH_TARGET) in server.env}"
P="$HERE/tools/direct_proxy.py"; O=(-o ProxyCommand="python3 $P %h %p ${DIRECT_IF:-wlp5s0}" -o BatchMode=yes)
command -v "${LESSC:-lessc}" >/dev/null 2>&1 && python3 "$HERE/modules/SamenTheme/tools/build_skin.py" >/dev/null
tar czf /tmp/theme_sync.tgz -C "$HERE/modules/SamenTheme" layouts -C "$HERE/modules/PersianCalendar" layouts
scp -q "${O[@]}" /tmp/theme_sync.tgz "$SSH_ALIAS":/tmp/
ssh "${O[@]}" "$SSH_ALIAS" 'cd '"${APP_DIR:-/opt/crm_students}"'/data/vtiger && tar xzf /tmp/theme_sync.tgz && chown -R 33:33 layouts/v7/modules/SamenTheme layouts/v7/modules/PersianCalendar layouts/v7/custom_skins/samen && echo synced'
