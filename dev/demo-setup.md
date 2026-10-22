# 체험판(데모) 사이트 서버 준비 — `/www/demo/`

운영 사이트(`/www/`)는 그대로 두고, 같은 호스팅의 `demo` 폴더에 체험판을 올린다.
**체험판은 반드시 운영과 다른 DB를 쓴다.** (체험판은 매일 밤 DB 자료를 전부 지우고 다시 만든다)

## 1. 데모용 DB 만들기 (호스팅 관리화면)
- 새 DB 하나 (예: 이름 `..._demo`). 문자셋 utf8mb4.
- 호스팅 상품이 DB를 하나만 주면 → 개발 담당(Claude)에게 알려 주세요. 다른 방법(추가 DB 신청, 별도 저가 호스팅 등)을 정합니다.

## 2. 설정 파일 올리기 — `/www/demo/app/config.php`
FTP(또는 호스팅 웹FTP)로 아래 내용의 파일을 만든다. DB 정보만 1번에서 만든 것으로 바꾼다.
비밀번호는 이 파일에만 넣고 채팅·카톡으로 보내지 않는다.

```php
<?php
defined('APP_ROOT') || exit;

return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => '데모DB이름',
        'user' => '데모DB아이디',
        'pass' => '데모DB비밀번호',
    ],
    'base_url'  => '/demo',
    'site_name' => '쉬자ON',
    'timezone'  => 'Asia/Seoul',
    'debug'     => false,
    'demo'      => true,   // 체험판: 가상 자료·역할별 체험 로그인·매일 밤 초기화
    'voucher_denoms'   => [1000, 5000, 10000],
    'chart_statuses'   => ['pending', 'approved'],
    'facility_results' => ['정상', '점검필요', '수리요청', '조치완료'],
];
```

## 3. 배포
- 준비가 끝났다고 알려 주면 `contest` 브랜치를 `[데모배포]` 커밋으로 올린다 → Actions 가 `/www/demo/` 에 업로드.
- 첫 접속(`https://사이트주소/demo/`) 때 표가 만들어지고 가상 자료가 채워진다 (몇 초).
- 이후에는 날짜가 바뀐 뒤 첫 접속 때마다 자동으로 처음 상태로 돌아간다.
