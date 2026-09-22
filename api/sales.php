<?php
require dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'login required']);
    exit;
}

$period = $_GET['period'] ?? 'day';
if (!in_array($period, ['day', 'week', 'month'], true)) $period = 'day';

echo json_encode(sales_series($period), JSON_UNESCAPED_UNICODE);
