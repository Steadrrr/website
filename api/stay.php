<?php
/** 매출보고 작성 화면: 날짜를 바꿨을 때 '쉬자파크숙박(퇴실)' = 전날 입실인원 합계 */
require dirname(__DIR__) . '/app/bootstrap.php';

require_login();
header('Content-Type: application/json; charset=utf-8');
$date = $_GET['date'] ?? '';
echo json_encode(['out' => valid_date($date) ? stay_out_guests($date) : 0]);
