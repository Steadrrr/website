-- 휴양림 업무일지 시스템 스키마 (MariaDB 10.x / utf8mb4)
-- install.php 와 자동 업그레이드(app/migrate.php)가 실행합니다.
-- 모든 문장은 여러 번 실행해도 안전해야 합니다 (CREATE TABLE IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name          VARCHAR(50)  NOT NULL,
  phone         VARCHAR(30)  NULL,
  rank_level    TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1 사원, 2 공무직, 3 주무관, 4 팀장',
  is_admin      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '최고관리자(사이트 관리)',
  can_delegate  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '전결 권한 (팀장 부재 시 주무관이 최종 결재)',
  team_id       INT UNSIGNED NULL COMMENT '소속 팀 (관리자가 승인 시 지정)',
  position      VARCHAR(50)  NULL COMMENT '보직 (관리자가 승인 시 입력)',
  photo         VARCHAR(200) NULL COMMENT '개인 사진 경로 (uploads/members/...)',
  squad_id      INT UNSIGNED NULL COMMENT '소속 반',
  is_squad_leader TINYINT(1) NOT NULL DEFAULT 0 COMMENT '반장',
  hire_date     DATE NULL COMMENT '입사일 (연차 발생 기준)',
  contract_end  DATE NULL COMMENT '계약 종료일 (비우면 입사일 + 1년)',
  work_start    TIME NULL COMMENT '근무 시작 시각',
  work_end      TIME NULL COMMENT '근무 종료 시각',
  off_days      VARCHAR(20) NULL COMMENT '휴무 요일 (0=일 ~ 6=토, 쉼표 구분)',
  menu_access   VARCHAR(100) NULL COMMENT '볼 수 있는 선택 메뉴 (att,ops,prog 쉼표 구분, NULL = 전부)',
  hide_in_org   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '조직도에서 숨김 (관리자·테스트 계정)',
  status        ENUM('pending','active','disabled') NOT NULL DEFAULT 'pending',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journals (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type         ENUM('daily','sales','facility','voucher','attendance','healing','kidsforest','guide','kidsdirect','vcheck','rooms','guide2','arwork') NOT NULL COMMENT '업무일지/매출보고/시설물관리/상품권입고/근태/프로그램 운영보고(산림치유센터·유아숲체험원·숲해설·유아숲 직영·숲해설 용문산)/상품권 금고점검/일일객실판매/AR 사용보고',
  team_id      INT UNSIGNED NULL COMMENT '시설점검일지의 관리팀',
  work_date    DATE         NOT NULL,
  author_id    INT UNSIGNED NOT NULL,
  weather      VARCHAR(30)  NULL,
  content      TEXT         NULL COMMENT '업무내용/메모',
  remarks      TEXT         NULL COMMENT '특이사항',
  status       ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft',
  revision     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '상신 후 수정 횟수 (0 = 수정 안 됨)',
  last_edited_at DATETIME NULL,
  last_edited_by INT UNSIGNED NULL,
  submitted_at DATETIME     NULL,
  completed_at DATETIME     NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_type_date (type, work_date),
  INDEX idx_status (status),
  CONSTRAINT fk_journal_author FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 결재선: 문서 상신 시 작성자 직급에 따라 생성 (사원 → 공무직 → 주무관 → 팀장, 공무직 → 주무관 → 팀장)
CREATE TABLE IF NOT EXISTS approvals (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  journal_id    INT UNSIGNED NOT NULL,
  step_order    TINYINT UNSIGNED NOT NULL,
  required_rank TINYINT UNSIGNED NOT NULL,
  approver_id   INT UNSIGNED NULL,
  status        ENUM('waiting','approved','rejected','skipped') NOT NULL DEFAULT 'waiting' COMMENT 'skipped = 전결로 생략',
  comment       VARCHAR(500) NULL,
  acted_at      DATETIME     NULL,
  INDEX idx_wait (status, required_rank),
  CONSTRAINT fk_appr_journal FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE,
  CONSTRAINT fk_appr_user FOREIGN KEY (approver_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (구버전) 일일매출보고 항목. 새 매출보고는 sales_lines 를 사용하며, 이 테이블은 이전 자료 조회용으로만 남겨둔다.
CREATE TABLE IF NOT EXISTS sales_items (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  journal_id INT UNSIGNED NOT NULL,
  category   VARCHAR(50)  NOT NULL,
  qty        INT UNSIGNED NOT NULL DEFAULT 0,
  card       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cash       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  transfer   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_sales_journal FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 시설물관리일지 점검 항목
CREATE TABLE IF NOT EXISTS facility_items (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  journal_id INT UNSIGNED NOT NULL,
  facility_id INT UNSIGNED NULL COMMENT '등록 시설 (NULL = 직접 입력 항목)',
  area       VARCHAR(100) NULL COMMENT '구역·건물명 (작성 당시)',
  facility   VARCHAR(100) NOT NULL,
  result     VARCHAR(20)  NOT NULL DEFAULT '정상',
  note       VARCHAR(500) NULL,
  CONSTRAINT fk_fac_journal FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  name  VARCHAR(50)  NOT NULL PRIMARY KEY,
  value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 판매 상품 (관리자 상품관리 페이지에서 편집)
--   입장권: price = 판매가 (is_free=1 이면 무료)
--   객실  : price = 비수기 평일, price_weekend = 비수기 주말, price_peak = 성수기 요금,
--           refund_amount / refund_weekend / refund_peak = 요금구분별 지역상품권 환급액, max_people = 최대인원
--           (할인은 settings 의 요금구분별 할인율로 일괄 적용. dc_* 는 이전 버전 컬럼으로 사용 안 함)
--   시설대관: price_2h / price_4h / price_day(4시간 이상, 18시까지) + price_night(야간 18~21시 추가요금)
--   대관 숙박시설: price = 정액 요금 (매출보고에서 할인율 % 입력)
--   프로그램: price = 유료 1인 요금, price_discount = 할인 1인 요금, prog_types = 쓰는 분야 (운영보고 회차마다 고름)
CREATE TABLE IF NOT EXISTS products (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grp           ENUM('ticket','room','rental','lodge','program') NOT NULL,
  name          VARCHAR(100) NOT NULL,
  is_free       TINYINT(1)   NOT NULL DEFAULT 0,
  price         INT UNSIGNED NOT NULL DEFAULT 0,
  price_weekend INT UNSIGNED NOT NULL DEFAULT 0,
  price_peak    INT UNSIGNED NOT NULL DEFAULT 0,
  refund_amount INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '객실 지역상품권 환급액 (비수기 평일)',
  refund_weekend INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '환급액 (비수기 주말)',
  refund_peak   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '환급액 (성수기)',
  price_2h      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '시설대관 2시간',
  price_4h      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '시설대관 4시간',
  price_day     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '시설대관 4시간 이상(18시까지)',
  price_night   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '시설대관 야간(18~21시) 추가요금',
  price_discount INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '프로그램 할인 1인 요금 (price = 유료 1인 요금)',
  prog_types    VARCHAR(200) NULL COMMENT '프로그램을 쓰는 분야 (healing,guide… 쉼표 구분, NULL = 모든 분야)',
  dc_weekday    INT UNSIGNED NOT NULL DEFAULT 0,
  dc_weekend    INT UNSIGNED NOT NULL DEFAULT 0,
  base_people   SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '객실 기준인원 (객실 분류에서 복사)',
  max_people    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  room_type_id  INT UNSIGNED NULL COMMENT '객실 분류 (room_types)',
  sys_key       VARCHAR(20)  NULL COMMENT '시스템 상품 (stay_in / stay_out: 쉬자파크숙박 입실·퇴실, 수정·삭제 불가, 수량 자동)',
  sort_order    INT          NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_grp (grp, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 매출보고 상품별 판매 내역. 상품명·단가는 작성 시점 값을 복사해 두어
-- 나중에 상품 정보가 바뀌어도 지난 보고서 금액이 변하지 않는다.
CREATE TABLE IF NOT EXISTS sales_lines (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  journal_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  grp        ENUM('ticket','room','rental','lodge','program') NOT NULL,
  name       VARCHAR(100) NOT NULL,
  is_free    TINYINT(1)   NOT NULL DEFAULT 0,
  rate       ENUM('weekday','weekend','peak') NULL COMMENT '객실 요금 구분: 비수기평일/비수기주말/성수기',
  season     VARCHAR(50)  NULL COMMENT '적용된 기간요금 이름 (예: 동절기)',
  discounted TINYINT(1)   NOT NULL DEFAULT 0,
  unit_price INT UNSIGNED NOT NULL DEFAULT 0,
  qty        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '입장권 매수 / 객실 수 / 프로그램 유료 인원(할인 포함)',
  guests     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '객실 입실인원 / 프로그램 무료 인원',
  refund_expected INT UNSIGNED NULL COMMENT '작성 당시 객실 기준 환급액',
  rent_time  VARCHAR(10)  NULL COMMENT '시설대관 시간: 2h / 4h / day',
  night      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '시설대관 야간 사용',
  dc_pct     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '대관 숙박시설 할인율(%)',
  prog_type  VARCHAR(20)  NULL COMMENT '프로그램 판매: 분야 (healing 등)',
  sessions   SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '프로그램 판매: 회차 수',
  auto       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '프로그램 판매: 1 = 그 날 운영보고에서 자동',
  amount     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  INDEX idx_grp (grp),
  CONSTRAINT fk_line_journal FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE,
  CONSTRAINT fk_line_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 지역상품권 수불: 입고(상품권입고 문서, 결재완료 시 반영) / 출고(매출보고 환급분)
CREATE TABLE IF NOT EXISTS voucher_moves (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  journal_id INT UNSIGNED NOT NULL,
  line_id    INT UNSIGNED NULL COMMENT '환급한 객실 (sales_lines.id)',
  direction  ENUM('in','out') NOT NULL,
  denom      INT UNSIGNED NOT NULL COMMENT '권종(원)',
  qty        INT UNSIGNED NOT NULL COMMENT '매수',
  CONSTRAINT fk_vm_journal FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 매출보고 부가정보 (입장권 현금 수입 등)
CREATE TABLE IF NOT EXISTS sales_meta (
  journal_id  INT UNSIGNED NOT NULL PRIMARY KEY,
  ticket_cash BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '입장권 현금 수입 (나머지는 카드)',
  rent_dc_rule VARCHAR(20) NULL COMMENT '시설대관·대관 숙박시설 통합 할인 기준 (lodge5 / lodge9 / youth20)',
  rent_dc_pct  TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '통합 할인율(%)',
  rent_youth   SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '초등·청소년 인원 (30% 할인 근거)',
  CONSTRAINT fk_meta_journal FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 기간요금: 입장권(기간 중 상품별 가격) / 객실(기간 중 주말·성수기 요금 자동 적용)
--   start_md, end_md 는 'MM-DD'. 11-01 ~ 02-29 처럼 해를 넘겨도 된다.
CREATE TABLE IF NOT EXISTS seasons (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grp        ENUM('ticket','room') NOT NULL,
  name       VARCHAR(50) NOT NULL,
  start_md   CHAR(5)     NOT NULL,
  end_md     CHAR(5)     NOT NULL,
  sort_order INT         NOT NULL DEFAULT 0,
  is_active  TINYINT(1)  NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS season_prices (
  season_id  INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  price      INT UNSIGNED NOT NULL,
  PRIMARY KEY (season_id, product_id),
  CONSTRAINT fk_sp_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
  CONSTRAINT fk_sp_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 기간별 가격표: 상품관리의 가격은 '현재 가격'이고, 지난 기간(예: 2026-01-01 ~ 2026-10-13)에 다른 가격을 썼으면
-- 그 기간과 상품별 가격을 여기에 둔다. 매출보고 일자가 기간 안이면 이 가격으로 계산한다 (가격이 없는 상품은 현재 가격).
CREATE TABLE IF NOT EXISTS price_periods (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(50)  NOT NULL,
  date_from  DATE         NOT NULL,
  date_to    DATE         NOT NULL,
  note       VARCHAR(200) NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_dates (date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS price_period_items (
  period_id      INT UNSIGNED NOT NULL,
  product_id     INT UNSIGNED NOT NULL,
  price          INT UNSIGNED NOT NULL DEFAULT 0,
  price_weekend  INT UNSIGNED NOT NULL DEFAULT 0,
  price_peak     INT UNSIGNED NOT NULL DEFAULT 0,
  refund_amount  INT UNSIGNED NOT NULL DEFAULT 0,
  refund_weekend INT UNSIGNED NOT NULL DEFAULT 0,
  refund_peak    INT UNSIGNED NOT NULL DEFAULT 0,
  price_2h       INT UNSIGNED NOT NULL DEFAULT 0,
  price_4h       INT UNSIGNED NOT NULL DEFAULT 0,
  price_day      INT UNSIGNED NOT NULL DEFAULT 0,
  price_night    INT UNSIGNED NOT NULL DEFAULT 0,
  price_discount INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (period_id, product_id),
  CONSTRAINT fk_ppi_period FOREIGN KEY (period_id) REFERENCES price_periods(id) ON DELETE CASCADE,
  CONSTRAINT fk_ppi_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 상품권 불출·반납: 공무직이 금고에서 꺼내 담당자에게 주는 것(issue)과 담당자가 금고로 돌려주는 것(return).
-- 반납 사유: cancel = 입실 취소, unpaid = 입실했지만 고객에게 주지 못함(미지급)
CREATE TABLE IF NOT EXISTS voucher_issues (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  work_date  DATE         NOT NULL,
  kind       ENUM('issue','return') NOT NULL,
  reason     ENUM('cancel','unpaid') NULL,
  holder_id  INT UNSIGNED NULL COMMENT '담당자 (받은 사람 / 돌려준 사람)',
  room       VARCHAR(100) NULL COMMENT '반납 객실',
  note       VARCHAR(300) NULL,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NULL,
  INDEX idx_date (work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voucher_issue_items (
  issue_id INT UNSIGNED NOT NULL,
  denom    INT UNSIGNED NOT NULL,
  qty      INT UNSIGNED NOT NULL,
  PRIMARY KEY (issue_id, denom),
  CONSTRAINT fk_vii_issue FOREIGN KEY (issue_id) REFERENCES voucher_issues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 불출·반납 입력·수정·삭제 기록 (삭제돼도 남도록 FK 없음)
CREATE TABLE IF NOT EXISTS voucher_issue_logs (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  issue_id   INT UNSIGNED NULL,
  work_date  DATE         NOT NULL,
  user_id    INT UNSIGNED NULL,
  action     VARCHAR(20)  NOT NULL,
  note       VARCHAR(500) NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_date (work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 상품권 금고점검 보고서(journals type vcheck)의 권종별 장부·실제 매수. 결재완료되면 차이(실제−장부)만큼 재고를 맞춘다
CREATE TABLE IF NOT EXISTS voucher_checks (
  journal_id INT UNSIGNED NOT NULL,
  denom      INT UNSIGNED NOT NULL,
  book_qty   INT          NOT NULL,
  actual_qty INT          NOT NULL,
  PRIMARY KEY (journal_id, denom),
  CONSTRAINT fk_vc_journal FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 관리팀 (대분류): 휴양림팀, 산림문화팀
CREATE TABLE IF NOT EXISTS teams (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(50) NOT NULL,
  sort_order INT         NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 반: 팀 아래 조직 (반장 + 반원)
CREATE TABLE IF NOT EXISTS squads (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(50) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_squad_team FOREIGN KEY (team_id) REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 중분류: 시설물은 구역·건물, 장비는 장비 분류
CREATE TABLE IF NOT EXISTS asset_groups (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind       ENUM('facility','equipment') NOT NULL,
  team_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(100) NOT NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  INDEX idx_kind (kind, team_id, sort_order),
  CONSTRAINT fk_group_team FOREIGN KEY (team_id) REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 세부시설
CREATE TABLE IF NOT EXISTS facilities (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id   INT UNSIGNED NOT NULL,
  name       VARCHAR(100) NOT NULL,
  spec       TEXT NULL COMMENT '규격·스펙 (설비류)',
  note       TEXT NULL,
  sort_order INT  NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fac_group FOREIGN KEY (group_id) REFERENCES asset_groups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 장비
CREATE TABLE IF NOT EXISTS equipment (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id       INT UNSIGNED NOT NULL,
  name           VARCHAR(100) NOT NULL,
  model          VARCHAR(100) NULL,
  serial_no      VARCHAR(100) NULL,
  spec           TEXT NULL,
  acquired_on    DATE NULL,
  acquired_cost  INT UNSIGNED NULL,
  location       VARCHAR(100) NULL COMMENT '보관 위치',
  status         ENUM('active','repair','disposed') NOT NULL DEFAULT 'active',
  disposed_on    DATE NULL,
  dispose_reason VARCHAR(500) NULL,
  note           TEXT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  CONSTRAINT fk_eq_group FOREIGN KEY (group_id) REFERENCES asset_groups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 장비 이력: 등록·점검·수리·관리·불용·복구
CREATE TABLE IF NOT EXISTS equipment_logs (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  equipment_id INT UNSIGNED NOT NULL,
  log_date     DATE NOT NULL,
  kind         ENUM('register','inspect','repair','maintain','other','dispose','restore') NOT NULL,
  content      TEXT NULL,
  cost         INT UNSIGNED NULL,
  vendor       VARCHAR(100) NULL,
  status_after ENUM('active','repair','disposed') NULL,
  user_id      INT UNSIGNED NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_eq (equipment_id, log_date),
  CONSTRAINT fk_eqlog_eq FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE,
  CONSTRAINT fk_eqlog_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 사진 (시설물·장비 공용). 파일은 uploads/ 폴더에 저장
CREATE TABLE IF NOT EXISTS photos (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_type ENUM('facility','equipment','program') NOT NULL COMMENT 'program = 프로그램 운영보고(journal_id) 활동사진',
  owner_id   INT UNSIGNED NOT NULL,
  path       VARCHAR(200) NOT NULL,
  user_id    INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_owner (owner_type, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 일지 수정 이력: 상신된 일지를 누군가 수정하면 수정 전·후 내용과 결재 상태를 남긴다
CREATE TABLE IF NOT EXISTS journal_revisions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  journal_id  INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  edited_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  prev_status VARCHAR(20) NOT NULL COMMENT '수정 전 결재 상태',
  prev_approval VARCHAR(500) NULL COMMENT '수정 전 결재 진행 내역 (초기화됨)',
  reason      VARCHAR(500) NULL,
  changes     MEDIUMTEXT NULL COMMENT '변경 내역 JSON',
  INDEX idx_journal (journal_id),
  CONSTRAINT fk_rev_journal FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE,
  CONSTRAINT fk_rev_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 공지사항
CREATE TABLE IF NOT EXISTS notices (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title      VARCHAR(200) NOT NULL,
  body       TEXT NULL,
  is_pinned  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '중요 공지 (대시보드 상단 고정)',
  author_id  INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX idx_pinned (is_pinned, created_at),
  CONSTRAINT fk_notice_author FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 일정표 (누구나 작성). category: event 행사 / construction 공사 / program 프로그램 / etc 기타
CREATE TABLE IF NOT EXISTS events (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(100) NOT NULL,
  category    ENUM('event','construction','program','rental','etc','holiday','closed') NOT NULL DEFAULT 'etc' COMMENT 'rental 대관, holiday 공휴일, closed 휴관일 (관리자만)',
  start_date  DATE NOT NULL,
  end_date    DATE NOT NULL,
  all_day     TINYINT(1) NOT NULL DEFAULT 1,
  start_time  TIME NULL,
  end_time    TIME NULL,
  location    VARCHAR(100) NULL,
  description TEXT NULL,
  author_id   INT UNSIGNED NOT NULL,
  series_id   VARCHAR(20) NULL COMMENT '반복 일정으로 한꺼번에 만든 묶음',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NULL,
  INDEX idx_range (start_date, end_date),
  INDEX idx_series (series_id),
  CONSTRAINT fk_event_author FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 근태 (결재는 journals 의 결재선을 그대로 사용. journals.author_id = 입력한 사람, user_id = 근무자)
CREATE TABLE IF NOT EXISTS attendance (
  journal_id    INT UNSIGNED PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL COMMENT '근무자',
  kind          ENUM('annual','sick','official','early','out','absent','overtime') NOT NULL COMMENT '연차/병가/공가/조퇴/외출/결근/초과근무',
  start_date    DATE NOT NULL,
  end_date      DATE NOT NULL,
  start_time    TIME NULL,
  end_time      TIME NULL,
  days          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '전일 근태의 실제 근무일수 (휴무일·공휴일 제외)',
  minutes       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '시간 단위 근태의 분',
  attachment    VARCHAR(200) NULL COMMENT '진단서 등 첨부 (uploads/attendance/...)',
  cert_required TINYINT(1) NOT NULL DEFAULT 0 COMMENT '진단서 제출 대상 병가',
  INDEX idx_user_date (user_id, start_date, end_date),
  INDEX idx_date (start_date, end_date),
  CONSTRAINT fk_att_journal FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 유실물 (운영관리 › 유실물관리)
CREATE TABLE IF NOT EXISTS lost_items (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reg_no      VARCHAR(20) NULL UNIQUE COMMENT '유실물 등록번호 (등록 연도-일련번호, 예: 2026-0001)',
  name        VARCHAR(100) NOT NULL COMMENT '물품명',
  found_date  DATE NOT NULL COMMENT '습득일',
  place       VARCHAR(100) NULL COMMENT '습득장소',
  finder      VARCHAR(50)  NULL COMMENT '습득자',
  memo        TEXT NULL,
  status      ENUM('received','contacted','shipped','returned') NOT NULL DEFAULT 'received' COMMENT '접수/연락완료/택배발송/본인수령',
  photo       VARCHAR(200) NULL COMMENT 'uploads/lost/... (저해상도)',
  author_id   INT UNSIGNED NOT NULL,
  updated_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NULL,
  INDEX idx_status (status, found_date),
  CONSTRAINT fk_lost_author FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 프로그램 운영보고의 회차 (산림치유센터·유아숲체험원·숲해설). 인원은 성별 × 연령대
CREATE TABLE IF NOT EXISTS program_sessions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  journal_id  INT UNSIGNED NOT NULL,
  session_no  SMALLINT UNSIGNED NOT NULL COMMENT '회차 (1부터)',
  group_name  VARCHAR(100) NOT NULL COMMENT '단체명 또는 개인 성명',
  staff       VARCHAR(100) NULL COMMENT '담당자 (직접 입력)',
  product_id  INT UNSIGNED NULL COMMENT '프로그램 상품 (products grp=program)',
  product_name VARCHAR(100) NULL COMMENT '작성 당시 프로그램명',
  start_time  TIME NULL,
  end_time    TIME NULL,
  m_infant SMALLINT UNSIGNED NOT NULL DEFAULT 0, f_infant SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  m_elem   SMALLINT UNSIGNED NOT NULL DEFAULT 0, f_elem   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  m_teen   SMALLINT UNSIGNED NOT NULL DEFAULT 0, f_teen   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  m_adult  SMALLINT UNSIGNED NOT NULL DEFAULT 0, f_adult  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  m_senior SMALLINT UNSIGNED NOT NULL DEFAULT 0, f_senior SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  total       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '인원 합계',
  is_paid     TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 유료(할인 포함), 0 무료',
  fee_type    ENUM('paid','discount','free') NOT NULL DEFAULT 'paid' COMMENT '유료 / 할인 / 무료',
  fee         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '작성 당시 1인 참가비',
  amount      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '프로그램 금액 = 유료면 인원 × 참가비',
  activity    TEXT NULL COMMENT '활동내용',
  INDEX idx_journal (journal_id, session_no),
  CONSTRAINT fk_prog_journal FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 객실 분류 (예: 2인실·4인실·독채) — 상품관리에서 추가·수정, 객실 상품마다 하나 지정 (products.room_type_id)
CREATE TABLE IF NOT EXISTS room_types (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(50) NOT NULL,
  base_people    SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '기준인원',
  max_people     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '최대인원',
  price          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '비수기 평일',
  price_weekend  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '비수기 주말',
  price_peak     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '성수기',
  refund_amount  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '상품권 환급액 (비수기 평일)',
  refund_weekend INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '환급액 (비수기 주말)',
  refund_peak    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '환급액 (성수기)',
  sort_order     INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 민원 (일일업무일지에 입력, 운영관리 › 민원관리에서 조회·처리). 분류 코드는 app/complaints.php 의 CPL_TREE
CREATE TABLE IF NOT EXISTS complaints (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  journal_id  INT UNSIGNED NOT NULL,
  work_date   DATE NOT NULL COMMENT '접수일 (업무일지 일자)',
  cat1        VARCHAR(10) NOT NULL COMMENT '대분류 (1 단순문의 / 2 요청 / 3 불편·불만 / 4 긴급·안전)',
  cat2        VARCHAR(10) NOT NULL COMMENT '중분류',
  cat3        VARCHAR(12) NOT NULL COMMENT '소분류',
  etc_text    VARCHAR(100) NULL COMMENT '소분류 기타 직접 입력',
  channel     ENUM('phone','visit','online') NOT NULL COMMENT '접수경로',
  place_type  ENUM('room','facility') NULL,
  place_id    INT UNSIGNED NULL COMMENT '객실 상품 id / 시설 구역 id',
  place_name  VARCHAR(100) NULL,
  content     TEXT NOT NULL,
  qty         SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '건수',
  status      ENUM('open','progress','done') NOT NULL DEFAULT 'open' COMMENT '미조치 / 처리중 / 완료',
  done_date   DATE NULL,
  action      TEXT NULL COMMENT '조치내용·비고',
  receiver_id INT UNSIGNED NULL COMMENT '접수자',
  updated_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NULL,
  INDEX idx_date (work_date),
  INDEX idx_status (status, work_date),
  CONSTRAINT fk_cpl_journal FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 일지 작성·수정 기록 (결재와 별개로 누가 언제 작성·임시저장·수정·결재 올리기를 했는지)
CREATE TABLE IF NOT EXISTS journal_logs (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  journal_id INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  action     VARCHAR(30) NOT NULL,
  note       VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_journal (journal_id, id),
  CONSTRAINT fk_log_journal FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 객실 소모품 (객실관리 › 소모품관리): 사진·품명·규격·단위·적정재고
CREATE TABLE IF NOT EXISTS supplies (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(100) NOT NULL COMMENT '품명',
  spec         VARCHAR(200) NULL COMMENT '규격·스펙',
  category     VARCHAR(50)  NULL COMMENT '분류 (예: 욕실용품)',
  unit         VARCHAR(20)  NOT NULL DEFAULT '개' COMMENT '단위',
  safety_stock INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '적정재고 (이보다 적으면 부족 표시)',
  photo        VARCHAR(200) NULL,
  memo         TEXT NULL,
  sort_order   INT NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 소모품 일별 수불 (품목·날짜마다 1줄: 입고·출고). 재고 = 그 날까지의 입고 합 − 출고 합
CREATE TABLE IF NOT EXISTS supply_moves (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supply_id  INT UNSIGNED NOT NULL,
  move_date  DATE NOT NULL,
  in_qty     INT UNSIGNED NOT NULL DEFAULT 0,
  out_qty    INT UNSIGNED NOT NULL DEFAULT 0,
  note       VARCHAR(200) NULL,
  user_id    INT UNSIGNED NULL COMMENT '마지막 입력자',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_supply_date (supply_id, move_date),
  INDEX idx_date (move_date),
  CONSTRAINT fk_supply_move FOREIGN KEY (supply_id) REFERENCES supplies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AR(아르바이트) 사용계획: 날짜별 계획 인원 (1명 하루 = 1회)
CREATE TABLE IF NOT EXISTS ar_plans (
  work_date  DATE PRIMARY KEY,
  people     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '계획 인원',
  memo       VARCHAR(200) NULL,
  user_id    INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AR 월간 사용보고(journals type arwork, work_date = 그 달 1일)의 사용일·아르바이트별 사용시간
CREATE TABLE IF NOT EXISTS ar_workers (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  journal_id INT UNSIGNED NOT NULL,
  work_date  DATE NULL COMMENT '사용일 (보고서는 월 단위, 줄마다 사용일)',
  sort_no    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  name       VARCHAR(50) NOT NULL COMMENT '아르바이트 성명',
  start_time TIME NOT NULL,
  end_time   TIME NOT NULL,
  break_min  SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '휴게(분)',
  minutes    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '근무시간(분) = 종료 − 시작 − 휴게',
  task       VARCHAR(200) NULL COMMENT '업무내용',
  INDEX idx_journal (journal_id, sort_no),
  INDEX idx_work_date (work_date),
  CONSTRAINT fk_ar_journal FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
