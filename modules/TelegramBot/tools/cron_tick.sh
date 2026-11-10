#!/bin/sh
# Host cron (every minute): run the "TelegramBot Worker" Vtiger cron task through vtigercron.php, so incoming
# messages and the send queue do not wait for the cron container's 15-minute loop. Installed by run_all.sh telegram
# as /etc/cron.d/samencrm-telegram.  Vtiger's own lock (task status "running") prevents overlapping runs.
cd /opt/vtiger || exit 0
docker compose exec -T -u www-data web php -r '$_REQUEST["service"]="TelegramBot Worker"; chdir("/var/www/html"); include "vtigercron.php";' > /dev/null 2>&1
