<?php
defined('APP_ROOT') || exit;

/**
 * DB 자동 업그레이드.
 * 새 버전 파일을 FTP로 덮어쓰기만 하면, 첫 접속 때 부족한 테이블/컬럼을 만든다.
 * (기존 자료는 그대로 유지)
 */
const DB_VERSION = 2;

function db_version(): int
{
    try {
        return (int) db()->query("SELECT value FROM settings WHERE name = 'db_version'")->fetchColumn();
    } catch (PDOException) {
        return 0; // settings 테이블 없음 = 최초 설치 또는 v1
    }
}

function column_exists(string $table, string $column): bool
{
    $st = db()->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return (bool) $st->fetchColumn();
}

function db_migrate(): void
{
    $pdo = db();

    // 1) 없는 테이블 생성
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents(APP_ROOT . '/sql/schema.sql'));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }

    // 2) v1 → v2: 전결 권한, 상품권입고 문서, 전결(생략) 결재 상태
    if (!column_exists('users', 'can_delegate')) {
        $pdo->exec("ALTER TABLE users ADD can_delegate TINYINT(1) NOT NULL DEFAULT 0 COMMENT '전결 권한' AFTER is_admin");
    }
    $pdo->exec("ALTER TABLE journals MODIFY type ENUM('daily','sales','facility','voucher') NOT NULL");
    $pdo->exec("ALTER TABLE approvals MODIFY status ENUM('waiting','approved','rejected','skipped') NOT NULL DEFAULT 'waiting'");

    $pdo->prepare("INSERT INTO settings (name, value) VALUES ('db_version', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
        ->execute([(string) DB_VERSION]);
}
