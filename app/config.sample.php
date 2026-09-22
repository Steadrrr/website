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

    // 매출 구분 (매출보고 입력표의 행)
    'sales_categories' => ['입장료', '숙박(휴양관·숲속의집)', '야영장', '주차료', '시설사용료', '기타'],

    // 그래프/합계에 포함할 매출보고 상태 (결재완료만 보려면 ['approved'])
    'chart_statuses' => ['pending', 'approved'],

    // 시설물 점검 기본 항목
    'facilities' => ['숙박동', '야영장·데크', '화장실·샤워장', '산책로·등산로', '주차장', '전기·소방설비', '급수·정화조', '놀이·체육시설'],
    'facility_results' => ['정상', '점검필요', '수리요청', '조치완료'],
];
