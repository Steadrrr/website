# 인수인계 — 새 세션은 여기부터

작성: 2026-10-22 · 이전 세션 브랜치 `claude/focused-noether-h27jvm` (main 과 같은 상태에서 이 문서만 추가)

## 0. 지금 이어서 할 일 (최우선)
**경진대회 출품 준비** — 계획 전문은 [`dev/contest-plan.md`](contest-plan.md).
- 직전 대화에서 큰 틀(이름·5+1 묶음·효과·AI 예측 설계·데이터 점검·설명서 목차·일정)을 사용자에게 제안했고,
  **사용자 답변을 기다리는 상태**에서 이 세션으로 넘어왔다. 구현은 아직 하나도 시작하지 않았다.
- 새 세션의 첫 순서:
  1. `dev/contest-plan.md` 를 읽고, 사용자에게 계획 요약과 **확인 필요 4가지**(이름, 데모 사이트 위치, Claude API 키, 과거 기록 유무)를 다시 묻는다.
     사용자가 첫 메시지에 이미 답을 줬으면 바로 2번으로.
  2. 답을 받으면 대회 전용 브랜치(예: `contest`, 사용자가 정한 브랜치가 있으면 그것)에서 일정 순서대로 진행.
  3. 대회 작업은 **운영 사이트(main)에 섞지 않는다.** 운영에도 필요한 부분(예: 시설점검 결과 코드화)만 사용자가 "메인에 합쳐줘" 할 때 골라서 합친다.
- **마감 2026-10-30(금) 18:00**, 목표 제출 10/29. 평가는 직원 설문 70 + 전문가 30 → 쉽게 이해되는 화면·설명이 핵심.

## 1. 시스템 한눈에
- PHP 8.4 + MariaDB, 프레임워크 없음. 페이지 = 루트의 `*.php`, 공통 로직 = `app/*.php`, 관리자 화면 = `admin/`, 스타일·스크립트 = `assets/`.
- 진입: 모든 페이지가 `require __DIR__.'/app/bootstrap.php'` → 로그인 `require_login()` → 메뉴 권한 `require_menu($user, 'room')`.
- DB: `sql/schema.sql` + `app/migrate.php` (현재 **DB_VERSION 52**). 접속 시 버전이 낮으면 자동 업그레이드.
- 직급(rank_level): 1 사원 · 2 공무직 · 3 주무관 · 4 팀장, `is_admin` = 최고관리자. 메뉴 권한은 회원관리에서 사람별로 (선택 메뉴: att/ops/room/prog/stat/fac).
- 메뉴 정의: `app/layout.php` (대시보드, 일정표, 개인업무, 운영관리, 객실관리, 프로그램, 통계, 시설관리, 기타 + 설정).

### 주요 기능 묶음 (대회용 5+1 묶음 기준)
| 묶음 | 화면 | 핵심 코드 |
|---|---|---|
| 기록·결재 | journal.php / write.php / view.php (문서 종류 daily·sales·rooms·facility·purchase·vcheck·voucher·arwork·attendance·프로그램류), approvals.php, docs.php | app/approval.php (결재선·상신·상신취소), app/daily.php, app/revisions.php |
| 객실 운영 | 객실판매(journal type=rooms, rooms_fill.php, 엑셀 가져오기 app/rooms_xls.php), clean.php(청소관리 PWA·웹푸시), room_ops.php, supplies.php, ar.php | app/products.php (요금·할인·상품권 환급 규칙), app/clean.php, app/webpush.php, app/turnover.php |
| 재정·상품권 | voucher.php (금고 불출·반납·수령자), rooms_payout.php (지급 수불부·서명·A4 인쇄), sales | app/vault.php, app/sales.php |
| 시설·안전 | journal type=facility (시설점검), facilities.php, equipment.php (이력·비용), purchase.php | app/assets.php, app/purchase.php |
| 고객·프로그램 | complaints.php (민원 3단계 분류), lost.php, program_schedule.php | app/complaints.php, app/programs.php |
| 통계 | stats.php, visitor_stats.php, room_stats.php, complaint_stats.php, program_stats.php | app/charts.php |

### 꼭 알아야 할 업무 규칙 (사용자와 확정한 것)
- 지역상품권 환급: 연박은 입실일에 박수만큼 한 번에 지급, 둘째 날 이후가 주말·성수기면 그 기준으로 계산.
  **할인 객실은 환급 없음.** 단 단체할인(그룹 비율 있음)·주말·성수기 할인은 환급, **주중 30% 할인은 환급 불가**, **업무예약(전액 무료)도 환급 없음** (`room_dc_refundable`, `ROOM_DC_FREE`).
- 결재 권한은 공무직 이상. 사원은 결재 완료된 문서를 수정 못 함. 본인이 올린 문서는 결재함에서 '결재 상신 취소' 가능.
- 청소관리: 퇴실대기 → 퇴실처리(청소가능) → 청소완료(입실가능). 연박은 '비품지급'(알림 없음). 같은 상태 객실끼리만 여러 개 선택 처리. 맨 위 공지는 공무직 이상.
  알림은 본인 계정 행동에는 오지 않는다 (같은 계정 기기 제외).
- 상품권 불출: 불출담당자·받는 담당자 모두 '운영관리' 메뉴 권한이 있는 사람으로 한정.
- 보류된 요청: '무료이벤트' 입장권 신설 (사용자가 중간에 멈춤 — 다시 요청하기 전엔 하지 않는다).

## 2. 작업 규칙 요약 (자세히는 `CLAUDE.md`)
- "메인에 합쳐줘" 할 때만 main 에 합침 → 자동 FTP 배포 → Actions 결과 확인.
- 변경마다 changelog · README · 도움말(+캡처) 함께.
- 비밀정보(FTP·API 키)는 GitHub Secrets / 서버 설정에만.
- 개인정보 최소화, 설명만 들은 기능은 먼저 만들지 말고 묻기.

## 3. 로컬 환경
```
dev/local/setup_local.sh                       # 새 컨테이너에서 한 번 (DB·config·계정)
dev/local/with_servers.sh "python3 x_test.py"  # 서버는 명령 동안만
```
- 계정 (비밀번호 password123): admin(팀장·최고관리자), keeper1(사원), worker1(공무직), officer1(주무관)
- 새로 만든 DB는 비어 있다. 객실·상품 등은 설정 › 상품·요금에서 만들거나 테스트 스크립트로 넣는다.
- 운영 DB 데이터는 로컬에 없다. 실제 사용 실적이 필요하면 사용자에게 운영 사이트 화면(통계)이나 내보내기를 부탁한다.

## 4. 새 세션 시작 메시지 (사용자가 붙여 넣을 문장)
> dev/HANDOFF.md 와 dev/contest-plan.md 읽고 경진대회 준비 이어서 하자. 지난번 큰 틀 제안까지 했고, 확인 필요 4가지부터 다시 정리해줘.
> (답을 이미 정했으면 함께 적기: 1) 이름: … 2) 데모 사이트: … 3) API 키: … 4) 과거 기록: …)
