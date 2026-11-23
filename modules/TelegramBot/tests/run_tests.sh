#!/bin/sh
# Run the TelegramBot end-to-end test on the server (inside the web container) against the mock Bot API.
#   sh /opt/crm_students/ss/telegram/run_tests.sh      (tests the instance it lies in)
cd "$(dirname "$0")/../.." && [ -f docker-compose.yml ] || exit 1
docker compose exec -T web sh -c '
  [ -f /tmp/tgmock.pid ] && kill $(cat /tmp/tgmock.pid) 2>/dev/null
  rm -rf /tmp/tgmock
  php -S 127.0.0.1:8099 /opt/ss/telegram/mock_telegram.php > /tmp/tgmock.log 2>&1 &
  echo $! > /tmp/tgmock.pid
  sleep 1
  cd /var/www/html && su -s /bin/sh www-data -c "php /opt/ss/telegram/e2e_test.php"; rc=$?
  kill $(cat /tmp/tgmock.pid) 2>/dev/null; rm -f /tmp/tgmock.pid
  exit $rc'
