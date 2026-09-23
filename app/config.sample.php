<?php
// 이 파일을 같은 폴더에 config.php 로 복사한 뒤 값을 채우세요.
defined('APP_ROOT') || exit;

return [
    // 호스팅 관리화면에서 생성한 DB 정보
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'DB이름',
        'user' => 'DB아이디',
        'pass' => 'DB비밀번호',
    ],

    // 사이트를 하위 폴더에 올렸다면 '/worklog' 처럼 지정. 도메인 루트면 ''
    'base_url' => '',

    'site_name' => '○○자연휴양림 업무일지',
    'timezone'  => 'Asia/Seoul',
    'debug'     => false, // 개발 중에만 true (오류 화면 표시)

    // 판매 상품(입장권·객실), 가격, 성수기·동절기 같은 기간요금은 관리자 메뉴 '상품관리'에서 설정합니다.
    // 시설물(관리팀 > 구역·건물 > 세부시설)은 '시설물' 메뉴에서 관리합니다.

    // 지역상품권 권종 (원)
    'voucher_denoms' => [1000, 5000, 10000],

    // 대시보드 집계에 포함할 매출보고 상태 (결재완료만 보려면 ['approved'])
    'chart_statuses' => ['pending', 'approved'],

    // 시설 점검결과 선택지 (첫 번째 = 정상. 정상이 아닌 결과는 시설별 '이상 이력'에 모입니다)
    'facility_results' => ['정상', '점검필요', '수리요청', '조치완료'],
];
