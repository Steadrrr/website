-- 휴양림 업무일지 시스템 스키마 (MariaDB 10.x / utf8mb4)
-- install.php 가 자동 실행합니다. 수동 설치 시 phpMyAdmin 등에서 그대로 실행해도 됩니다.

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name          VARCHAR(50)  NOT NULL,
  phone         VARCHAR(30)  NULL,
  rank_level    TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1 관리원, 2 공무직, 3 주무관, 4 팀장',
  is_admin      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '최고관리자(사이트 관리)',
  status        ENUM('pending','active','disabled') NOT NULL DEFAULT 'pending',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journals (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type         ENUM('daily','sales','facility') NOT NULL COMMENT '업무일지/매출보고/시설물관리',
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
  status        ENUM('waiting','approved','rejected') NOT NULL DEFAULT 'waiting',
  comment       VARCHAR(500) NULL,
  acted_at      DATETIME     NULL,
  INDEX idx_wait (status, required_rank),
  CONSTRAINT fk_appr_journal FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE,
  CONSTRAINT fk_appr_user FOREIGN KEY (approver_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 일일매출보고 항목 (구분별 건수/결제수단별 금액)
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
