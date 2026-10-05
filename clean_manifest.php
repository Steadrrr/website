<?php
/** 객실 청소관리 웹앱 설치 정보 (홈 화면에 추가할 때 이름·아이콘·시작 화면) */
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/manifest+json; charset=utf-8');
echo json_encode([
    'name'             => '객실 청소관리 · ' . config('site_name', '휴양림 업무일지'),
    'short_name'       => '청소관리',
    'id'               => url('clean.php'),
    'start_url'        => url('clean.php'),
    'scope'            => url(''),
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#f4f6f3',
    'theme_color'      => '#1f5a38',
    'lang'             => 'ko',
    'icons'            => [
        ['src' => url('assets/app/clean-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => url('assets/app/clean-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
