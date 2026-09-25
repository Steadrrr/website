<?php
require dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'login required']);
    exit;
}

$period = $_GET['period'] ?? 'week';
if (!in_array($period, ['week', 'month'], true)) $period = 'week'; // 대시보드는 주별·월별만

echo json_encode(dashboard_series($period), JSON_UNESCAPED_UNICODE);
