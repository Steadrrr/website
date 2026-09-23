-- 휴양림 업무일지 시스템 스키마 (MariaDB 10.x / utf8mb4)
-- install.php 와 자동 업그레이드(app/migrate.php)가 실행합니다.
-- 모든 문장은 여러 번 실행해도 안전해야 합니다 (CREATE TABLE IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name          VARCHAR(50)  NOT NULL,
  phone         VARCHAR(30)  NULL,
  rank_level    TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1 관리원, 2 공무직, 3 주무관, 4 팀장',
  is_admin      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '최고관리자(사이트 관리)',
  can_delegate  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '전결 권한 (팀장 부재 시 주무관이 최종 결재)',
  team_id       INT UNSIGNED NULL COMMENT '소속 팀 (관리자가 승인 시 지정)',
  position      VARCHAR(50)  NULL COMMENT '보직 (관리자가 승인 시 입력)',
  status        ENUM('pending','active','disabled') NOT NULL DEFAULT 'pending',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journals (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type         ENUM('daily','sales','facility','voucher') NOT NULL COMMENT '업무일지/매출보고/시설물관리/상품권입고',
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

-- 결재선: 문서 상신 시 작성자 직급에 따라 생성 (관리원/공무직 → 주무관 → 팀장)
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
--           refund_amount = 지역상품권 환급액, max_people = 최대인원
--           (할인은 settings 의 요금구분별 할인율로 일괄 적용. dc_* 는 이전 버전 컬럼으로 사용 안 함)
CREATE TABLE IF NOT EXISTS products (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grp           ENUM('ticket','room') NOT NULL,
  name          VARCHAR(100) NOT NULL,
  is_free       TINYINT(1)   NOT NULL DEFAULT 0,
  price         INT UNSIGNED NOT NULL DEFAULT 0,
  price_weekend INT UNSIGNED NOT NULL DEFAULT 0,
  price_peak    INT UNSIGNED NOT NULL DEFAULT 0,
  refund_amount INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '객실 지역상품권 환급액',
  dc_weekday    INT UNSIGNED NOT NULL DEFAULT 0,
  dc_weekend    INT UNSIGNED NOT NULL DEFAULT 0,
  max_people    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
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
  grp        ENUM('ticket','room') NOT NULL,
  name       VARCHAR(100) NOT NULL,
  is_free    TINYINT(1)   NOT NULL DEFAULT 0,
  rate       ENUM('weekday','weekend','peak') NULL COMMENT '객실 요금 구분: 비수기평일/비수기주말/성수기',
  season     VARCHAR(50)  NULL COMMENT '적용된 기간요금 이름 (예: 동절기)',
  discounted TINYINT(1)   NOT NULL DEFAULT 0,
  unit_price INT UNSIGNED NOT NULL DEFAULT 0,
  qty        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '입장권 매수 / 객실 수',
  guests     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '객실 입실인원',
  refund_expected INT UNSIGNED NULL COMMENT '작성 당시 객실 기준 환급액',
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

-- 관리팀 (대분류): 휴양림팀, 산림문화팀
CREATE TABLE IF NOT EXISTS teams (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(50) NOT NULL,
  sort_order INT         NOT NULL DEFAULT 0
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
  owner_type ENUM('facility','equipment') NOT NULL,
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
