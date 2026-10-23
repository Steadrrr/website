<?php
// 체험판(경진대회 데모, 서버의 /demo 폴더) 설정.
// 체험판 폴더에는 config.php 를 따로 두지 않고, 운영 사이트(한 단계 위 폴더)의 설정을 그대로 읽은 뒤 체험판 값만 덮어쓴다.
// 같은 DB 안에 demo_ 로 시작하는 표를 따로 만들어 쓰므로 운영 자료와 섞이지 않는다 (app/demo_db.php).
// 운영 사이트에는 app/config.php 가 있으므로 이 파일은 쓰이지 않는다.
defined('APP_ROOT') || exit;

$main = dirname(APP_ROOT) . '/app/config.php';
if (!is_file($main)) return null;
return [
    'demo'      => true,
    'base_url'  => '/demo',
    'site_name' => '쉬자!쉼ON',
    'debug'     => false,
] + (require $main);
