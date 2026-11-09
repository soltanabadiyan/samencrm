#!/usr/bin/env bash
# Run ON THE SERVER after the web installer is finished.
#   sudo bash /opt/vtiger/scripts/post_install.sh [--limit N] [--skip-activities]
# Needs /opt/vtiger/ss/{dataset.json,schema.json,setup_vtiger.php,import_to_vtiger.py,ss_schema.py}
# (run_all.sh on your PC copies them there).
set -euo pipefail
APP_DIR=/opt/vtiger
cd "$APP_DIR"
exec 9>"$APP_DIR/ss/.import.lock"
flock -n 9 || { echo "another import is already running (lock $APP_DIR/ss/.import.lock)"; exit 1; }
set -a; . ./.env; set +a
SS="$APP_DIR/ss"
for f in dataset.json schema.json setup_vtiger.php import_to_vtiger.py ss_schema.py tag_contacts.php; do
  [ -s "$SS/$f" ] || { echo "missing $SS/$f – run run_all.sh from your PC first"; exit 1; }
done

echo "== checking installer finished"
docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -N -e "select count(*) from vtiger_users" >/dev/null \
  || { echo "Vtiger tables not found – finish the web installer first"; exit 1; }

echo "== 1/3 backup before changes"
mkdir -p "$APP_DIR/backups"
docker compose exec -T db mariadb-dump -uroot -p"$DB_ROOT_PASSWORD" --single-transaction "$DB_NAME" | gzip > "$APP_DIR/backups/pre_import_$(date +%F_%H%M).sql.gz"

echo "== 2/3 custom fields, groups, filters"
docker compose exec -T web php /opt/ss/setup_vtiger.php /opt/ss/schema.json | tee "$SS/setup.log"
grep '^SS_GROUPS=' "$SS/setup.log" | tail -1 | sed 's/^SS_GROUPS=//' > "$SS/groups.json"

echo "== 3/3 import"
ACCESSKEY=$(docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -N -e "select accesskey from vtiger_users where user_name='admin'" | tr -d '\r\n')
[ -n "$ACCESSKEY" ] || { echo "could not read admin accesskey"; exit 1; }
python3 "$SS/import_to_vtiger.py" --url http://127.0.0.1:9081 --user admin --accesskey "$ACCESSKEY" \
   --dataset "$SS/dataset.json" --groups "$SS/groups.json" --state "$SS/import_state.json" \
   --errors "$SS/import_errors.jsonl" "$@"

echo "== tags (Level / Major)"
docker compose exec -T web php /opt/ss/tag_contacts.php /opt/ss/dataset.json /opt/ss/import_state.json

echo
echo "Verification:"
docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e "
 select 'contacts' what, count(*) n from vtiger_contactdetails c join vtiger_crmentity e on e.crmid=c.contactid and e.deleted=0
 union all select 'tasks', count(*) from vtiger_activity a join vtiger_crmentity e on e.crmid=a.activityid and e.deleted=0 where a.activitytype='Task'
 union all select 'events', count(*) from vtiger_activity a join vtiger_crmentity e on e.crmid=a.activityid and e.deleted=0 and e.setype='Calendar' where a.activitytype<>'Task';"
echo "== removing auto 'Regarding Contact Assignment' notification e-mail records created by the import (soft delete)"
docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e "
 update vtiger_crmentity e join vtiger_activity a on a.activityid=e.crmid set e.deleted=1
 where e.setype='Emails' and a.subject='Regarding Contact Assignment' and e.deleted=0;"
echo "Errors (if any): $SS/import_errors.jsonl   |   state: $SS/import_state.json"
