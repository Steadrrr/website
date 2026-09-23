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
  status        ENUM('pending','active','disabled') NOT NULL DEFAULT 'pending',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journals (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type         ENUM('daily','sales','facility','voucher') NOT NULL COMMENT '업무일지/매출보고/시설물관리/상품권입고',
  work_date    DATE         NOT NULL,
  author_id    INT UNSIGNED NOT NULL,
  weather      VARCHAR(30)  NULL,
  content      TEXT         NULL COMMENT '업무내용/메모',
  remarks      TEXT         NULL COMMENT '특이사항',
  status       ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft',
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
--   객실  : price = 평일 요금, price_weekend = 주말·성수기 요금, dc_* = 할인 요금, max_people = 최대인원
CREATE TABLE IF NOT EXISTS products (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grp           ENUM('ticket','room') NOT NULL,
  name          VARCHAR(100) NOT NULL,
  is_free       TINYINT(1)   NOT NULL DEFAULT 0,
  price         INT UNSIGNED NOT NULL DEFAULT 0,
  price_weekend INT UNSIGNED NOT NULL DEFAULT 0,
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
  rate       ENUM('weekday','weekend') NULL COMMENT '객실 요금 구분',
  discounted TINYINT(1)   NOT NULL DEFAULT 0,
  unit_price INT UNSIGNED NOT NULL DEFAULT 0,
  qty        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '입장권 매수 / 객실 수',
  guests     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '객실 입실인원',
  amount     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  INDEX idx_grp (grp),
  CONSTRAINT fk_line_journal FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE,
  CONSTRAINT fk_line_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 지역상품권 수불: 입고(상품권입고 문서, 결재완료 시 반영) / 출고(매출보고 환급분)
CREATE TABLE IF NOT EXISTS voucher_moves (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  journal_id INT UNSIGNED NOT NULL,
  direction  ENUM('in','out') NOT NULL,
  denom      INT UNSIGNED NOT NULL COMMENT '권종(원)',
  qty        INT UNSIGNED NOT NULL COMMENT '매수',
  CONSTRAINT fk_vm_journal FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
