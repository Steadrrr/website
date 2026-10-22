#!/bin/bash
# 새 컨테이너에서 로컬 테스트 환경 만들기 (여러 번 실행해도 안전)
#   1) app/config.php 가 없으면 config.sample.php 로 만든다 (로컬 DB: forest / forest / pw1234, git 에는 안 올라감)
#   2) DB·DB 사용자 생성
#   3) 사용자가 없으면 install.php 로 설치(테이블 생성 + admin) 후 테스트 계정 추가
# 테스트 계정 (비밀번호 모두 password123): admin(팀장·최고관리자) keeper1(사원) worker1(공무직) officer1(주무관)
set -e
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
cd "$ROOT"
if [ ! -f app/config.php ]; then
  sed -e "s/'name' *=> *'DB이름'/'name' => 'forest'/" -e "s/'user' *=> *'DB아이디'/'user' => 'forest'/" -e "s/'pass' *=> *'[^']*'/'pass' => 'pw1234'/" -e "s/'debug' *=> *false/'debug' => true/" app/config.sample.php > app/config.php
  echo "app/config.php 생성"
fi
dev/local/with_servers.sh '
set -e
mariadb -e "CREATE DATABASE IF NOT EXISTS forest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS '"'"'forest'"'"'@'"'"'localhost'"'"' IDENTIFIED BY '"'"'pw1234'"'"';
  GRANT ALL ON forest.* TO '"'"'forest'"'"'@'"'"'localhost'"'"';"
n=$(mariadb -N forest -e "SELECT COUNT(*) FROM users" 2>/dev/null || echo 0)
if [ "$n" = "0" ]; then
  J=$(mktemp)
  T=$(curl -s -c $J http://127.0.0.1:8080/install.php | grep -o "name=\"_csrf\" value=\"[^\"]*\"" | head -1 | sed "s/.*value=\"//;s/\"//")
  curl -s -b $J -c $J -o /dev/null --data-urlencode "_csrf=$T" -d "username=admin&password=password123" --data-urlencode "name=김팀장" http://127.0.0.1:8080/install.php
  H=$(php -r "echo password_hash(\"password123\", PASSWORD_DEFAULT);")
  mariadb --default-character-set=utf8mb4 forest -e "INSERT INTO users (username,password_hash,name,rank_level,status) VALUES
    (\"keeper1\",\"$H\",\"이사원\",1,\"active\"),(\"worker1\",\"$H\",\"박공무\",2,\"active\"),(\"officer1\",\"$H\",\"최주무\",3,\"active\")"
  echo "설치 완료"
fi
mariadb --default-character-set=utf8mb4 -N forest -e "SELECT CONCAT(\"계정: \", GROUP_CONCAT(username)) FROM users"
curl -s -o /dev/null -w "check.php HTTP %{http_code}\n" http://127.0.0.1:8080/check.php
'
