<?php
/**
 * 탭 화면: 상단 메뉴 + 페이지들을 탭(최대 10개)으로 띄우는 틀.
 *  - 메뉴를 누르면 새 탭으로 열고(이미 연 페이지면 그 탭으로), 탭을 바꿔도 입력하던 내용이 그대로 남는다.
 *  - 탭 목록은 브라우저 창마다 기억(sessionStorage) — 새로고침해도 탭은 다시 열린다(입력 중이던 내용은 사라짐).
 *  - 화면 폭 900px 미만(휴대폰)이나 '탭 끄기'를 누르면 예전처럼 한 화면으로 연다.
 * 여는 주소는 shell.php#/journal.php?type=daily 처럼 # 뒤에 붙인다 (layout_header 의 스크립트가 붙여 줌).
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
layout_header('업무일지', '', ['shell' => true]);
?>
<div class="tab-frames" data-frames></div>
<div class="tabbar no-print">
  <div class="tabbar-tabs" data-tabs></div>
  <div class="tabbar-tools">
    <span class="tabbar-count" data-tab-count></span>
    <button type="button" class="tabbar-off" data-tabs-off title="탭 없이 한 화면으로 보기 (상단의 '탭 켜기'로 다시 켬)">탭 끄기</button>
  </div>
</div>
<script>window.FORESTLOG_TABS = { max: 10, home: <?= json_encode(url('index.php')) ?>, shell: <?= json_encode(url('shell.php')) ?>, site: <?= json_encode(' · ' . config('site_name', '휴양림 업무일지')) ?> };</script>
<?php layout_footer([url('assets/tabs.js')]);
