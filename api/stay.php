<?php
/** 매출보고 작성 화면: 날짜를 바꿨을 때 '쉬자파크숙박' 입실 = 그 날 객실 입실인원, 퇴실 = 전날이 마지막 밤인 객실 인원 (연박 중 퇴실 없음) */
require dirname(__DIR__) . '/app/bootstrap.php';

require_login();
header('Content-Type: application/json; charset=utf-8');
$date = $_GET['date'] ?? '';
echo json_encode(['in' => valid_date($date) ? stay_guests($date) : 0, 'out' => valid_date($date) ? stay_out_guests($date) : 0]);
