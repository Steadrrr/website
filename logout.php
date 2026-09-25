<?php
require __DIR__ . '/app/bootstrap.php';

remember_forget(); // 이 기기의 자동 로그인도 해제 (ID 저장은 그대로)
$_SESSION = [];
session_regenerate_id(true);
redirect('login.php');
