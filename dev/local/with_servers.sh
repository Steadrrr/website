#!/bin/bash
# 로컬 MariaDB·PHP 서버를 이 명령 동안만 켜고, 끝나면 끈다. (백그라운드로 계속 켜 두면 세션 시간 제한에 걸려 죽는다)
#   사용: dev/local/with_servers.sh "python3 dev/local/my_test.py"
#   사이트: http://127.0.0.1:8080  ·  DB: forest / forest / pw1234 (로컬 전용)
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
LOG=${TMPDIR:-/tmp}
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
mariadbd --user=mysql > "$LOG/mariadb.log" 2>&1 &
DB=$!
(cd "$ROOT" && exec php -d memory_limit=128M -d upload_max_filesize=10M -d post_max_size=20M -S 127.0.0.1:8080 > "$LOG/php.log" 2>&1) &
WEB=$!
for i in $(seq 1 30); do mariadb -e "select 1" >/dev/null 2>&1 && curl -s -o /dev/null http://127.0.0.1:8080/check.php && break; timeout 1 tail -f /dev/null; done
cd "$ROOT"
bash -c "$1"; rc=$?
kill $WEB; mariadb-admin shutdown 2>/dev/null || kill $DB
wait 2>/dev/null
exit $rc
