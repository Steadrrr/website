# 작업 규칙

양평쉬자파크 업무일지 사이트 (PHP 8.4 + MariaDB, 프레임워크 없음). 실제 직원들이 매일 쓰는 운영 사이트다.
처음 들어온 세션은 `dev/HANDOFF.md` 를 먼저 읽는다 (구조·진행 중인 일·다음 할 일).

## 배포 · 브랜치 (가장 중요)
- main 에 push 되면 `.github/workflows/deploy.yml` 이 서버 `/www/` 로 **FTP 자동 배포** → 곧바로 실제 운영 사이트에 반영된다.
- **사용자가 "메인에 합쳐줘" 라고 말할 때만 main 에 합친다.** 그 전에는 작업 브랜치에만 push 한다.
  합친 뒤에는 배포 결과를 확인한다: `gh api "repos/Steadrrr/website/actions/runs?branch=main&per_page=1"`
- 배포는 `app/config.php`, `uploads/`, `dev/`, `CLAUDE.md`, `README.md`, `.github/` 를 올리거나 지우지 않는다. 이 제외 목록을 줄이지 않는다.
- FTP 접속 정보는 GitHub Secrets 에만 둔다. 채팅·코드·커밋에 비밀번호·API 키를 절대 남기지 않는다 (Claude API 키도 서버 설정에만).

## 변경할 때마다 함께 고칠 것
- 기능을 추가하거나 고칠 때마다 `app/changelog.php` 의 `CHANGELOG` 맨 위에 항목을 추가한다
  (기타 › 업데이트 화면에 표시됨). 날짜, 한 줄 요약, 관련 메뉴, 사용자 입장에서 알기 쉬운 설명 몇 줄.
- README.md 도 함께 갱신한다.
- 기타 › 도움말(`app/help.php`)도 함께 고친다. 새 화면은 도움말 항목의 'keys' 에 그 화면의 메뉴 키를 넣어 '?' 버튼이 연결되게 하고,
  화면이 바뀌면 `assets/help/` 의 캡처도 새로 찍는다 (Playwright: `/opt/node22/lib/node_modules/playwright`).
- DB 구조가 바뀌면 `sql/schema.sql`(CREATE TABLE IF NOT EXISTS) 과 `app/migrate.php`(DB_VERSION + 단계) 를 함께 고친다.
  단계는 여러 번 실행해도 안전해야 한다 (column_exists / enum_has 로 확인 후 ALTER).
- **새 표는 반드시 `sql/schema.sql` 에 `CREATE TABLE IF NOT EXISTS` 로 넣는다.** 체험판(/demo)이 이 목록을 보고 `demo_` 표로 바꾼다.
- 표 이름을 SQL 문자열에 직접 이어 붙이지 말고 평소처럼 쓴다. migrate 도우미에서 표 이름이 필요하면 `db_table()` 을 쓴다.
- 메뉴를 추가·변경하면 `app/layout.php` 의 체험판 6묶음 `NAV_BUNDLES` 에도 넣는다.

## 체험판 (/demo) · 경진대회
- 작업은 main 쪽(작업 브랜치 → "메인에 합쳐줘")에서 계속 한다. **체험판 반영은 사용자가 "데모에 반영해줘" 라고 할 때만** 한다.
  1) `git fetch origin main contest` → contest 브랜치에서 origin/main 을 merge (contest 에만 있는 커밋은 지우지 않는다)
  2) 체험판 모드로 띄워 확인: app/config.php 없이 app/config.demo.php 로 동작, 같은 DB 의 demo_ 표 사용.
     역할 4개(demo_keeper·demo_worker·demo_officer·demo_leader, 비밀번호 demo1234)로 새 화면이 오류 없이 열리는지, 휴대폰 폭에서 넘치지 않는지
  3) 문제 없으면 커밋 메시지에 `[데모배포]` 를 넣어 contest 에 push → '데모 사이트 배포 (FTP /demo)' Actions 성공 확인
- 커밋 메시지에 `[데모배포]` 가 있을 때만 체험판(/demo)에 배포된다 — 설명으로라도 이 글자를 쓰면 바로 배포되니 주의.
- 평소 main 작업 때 (체험판이 같은 코드를 쓰기 때문):
  - 실제 기록을 넣는 migrate 단계(예: v59 법정점검 기록)는 `is_demo()` 일 때 건너뛴다 — 체험판엔 가상 자료만.
  - 심사 때 보여 줄 새 기능이면 `app/demo_seed.php` 에 가상 자료도 추가한다 (없으면 체험판에서 빈 화면).
- 체험판: `app/demo.php`, `demo_db.php`, `demo_seed.php`, `config.demo.php`. 운영과 같은 DB 의 `demo_` 표를 쓰고 세션은 따로 둔다.
- 체험판 가상 자료(`app/demo_seed.php`)를 바꾸면 `app/demo.php` 의 `DEMO_SEED_VERSION` 을 1 올린다 (배포 후 첫 접속 때 다시 만든다).
- AI 분석 API 키는 서버의 `app/anthropic_key.txt` 에만 둔다 (gitignore, 절대 커밋 금지). 입력은 `ai_key.php` 화면에서.
- 경위·진행 기록·자세한 구조: contest 브랜치의 `dev/contest-plan.md` 10절, `app/demo.php` 맨 위 주석.

## 일하는 방식
- **사용자가 상황을 설명만 했을 때 기능을 먼저 만들지 않는다.** 만들어 달라는 요청인지 애매하면 먼저 묻는다.
  (예전에 "우편으로 지급하기로 했어" 라는 설명만 듣고 기능을 만들다가 취소된 적 있음)
- 업무 규칙(환급 기준 등)은 사용자가 말한 그대로 구현하고, 헷갈리면 예시를 들어 확인한다.
- 개인정보 최소화: 업로드 파일(예: 객실 예약 엑셀의 고객 이름·생년월일·휴대폰·주문번호)은 필요한 열만 읽고 파일은 지운다. 저장하지 않는다.
- 답변·화면 문구·주석은 한국어. 사용자는 개발자가 아닌 현장 담당자이므로 쉬운 말로 설명한다.
- 코드는 주변 코드 스타일(짧은 함수, 한국어 주석, `e()` 이스케이프, `csrf_field()`/`csrf_verify()`, `require_menu()`)을 따른다.

## 로컬 테스트
- 새 컨테이너에서 한 번: `dev/local/setup_local.sh` (config.php·DB·테스트 계정 생성. 계정 비밀번호 모두 password123)
- 서버는 명령 동안만 켠다: `dev/local/with_servers.sh "python3 dev/local/xxx_test.py"` (백그라운드로 켜 두면 시간 제한에 걸려 죽는다)
- 테스트 도우미: `dev/local/testlib.py` (login/post/sql/check/done). 일회용 테스트 스크립트는 스크래치패드에 둔다.
- 화면을 바꾸면 Playwright 로 실제 화면을 열어 확인하고, 커밋 전에 `php -l` 로 문법 검사.
