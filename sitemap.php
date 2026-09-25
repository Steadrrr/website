<?php
/** 사이트맵 (기타 › 사이트맵): 내가 쓸 수 있는 모든 메뉴와 하는 일을 한눈에 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();

// 메뉴 키 => 설명
const SITEMAP_DESC = [
    'home'       => '공지사항, 오늘의 일정, 판매 현황 그래프, 내 결재 대기, 최근 일지',
    'schedule'   => '행사·공사·프로그램 일정과 공휴일·휴관일을 달력으로 공유 (사원 근태도 함께 보기)',
    'attendance' => '연차·병가·공가·조퇴·외출·결근·초과근무 입력과 결재, 팀·사람별 근태 달력',
    'att_sheet'  => '사원별 한 달 근태표 (엑셀 다운로드·인쇄)',
    'daily'      => '일일 업무일지 작성·결재',
    'sales'      => '입장권·시설대관 판매를 매일 보고',
    'rooms'      => '객실 판매(입실인원·요금구분·할인)와 지역상품권 환급을 매일 입력·결재 (일일객실판매)',
    'supplies'   => '객실 소모품 등록(사진·품명·규격·단위·적정재고)과 일별 수불대장(입고·출고·재고)',
    'ar'         => '아르바이트(AR) 사용계획 달력, 사용가능·계획·사용 횟수, 사용일별 AR 사용보고 결재 (공무직 이상)',
    'voucher'    => '지역상품권 금고·담당자 재고, 일일 불출·반납과 대조, 금고점검 보고서, 수불부',
    'stats'      => '매출 기간별·월별·연간 통계, 업무일지 모아보기 (엑셀·인쇄)',
    'visit_stats' => '입장권 판매(유료·무료) 입장객 수를 기간별·주간·월간·연간으로, 추이 그래프·입장권별·요일별 (엑셀·인쇄)',
    'room_stats' => '객실 판매·입실인원·가동률·평균 객실단가를 일간·주간·월간·연간으로, 두 기간 비교 (엑셀·인쇄)',
    'complaints' => '업무일지에 입력한 민원을 일자·주·월별로 조회, 처리상태 변경·완료 처리',
    'cpl_stats'  => '민원 월별·연간 대분류·중분류별 건수와 객실별 불편·불만(재발 객실) (엑셀·인쇄)',
    'lost'       => '유실물 사진 갤러리, 등록과 처리 상태(접수·연락완료·택배발송·본인수령)',
    'healing'    => '산림치유센터 프로그램 운영보고 (회차·인원·금액·활동사진)',
    'kidsforest' => '유아숲체험원 프로그램 운영보고',
    'kidsdirect' => '유아숲(직영) 프로그램 운영보고',
    'guide2'     => '숲해설(용문산) 프로그램 운영보고',
    'guide'      => '숲해설 프로그램 운영보고',
    'prog_stats' => '프로그램 일별·주별·월별 통계, 분야별·성별·연령별 인원 (엑셀·인쇄)',
    'updates'    => '사이트 업데이트(기능 추가·수정) 내역',
    'facility'   => '팀별 시설물 점검일지 작성·결재',
    'facilities' => '시설 구역·세부시설 목록, 사진·사양, 이상 발생 이력',
    'equipment'  => '장비 목록, 점검·수리·불용 이력',
    'purchase'   => '물품 구매 증빙 (구매처·품목·검수·영수증 사진, 카드/외상) → 주무관 결재·지출 완료, 월간·연간 금액 (공무직 이상)',
    'notices'    => '공지사항 게시판 (공무직 이상 작성)',
    'org'        => '팀·반별 조직도 (사진·보직·연락처)',
    'approval'   => '내 결재 차례인 문서와 내가 올린 문서 진행 상황',
    'docs'       => '모든 결재 문서를 분류·기간·상태·작성자로 조회·정렬하고 수정 (공무직 이상, 삭제는 최고관리자)',
    'sitemap'    => '이 페이지',
    'admin'      => '가입 승인, 직급·팀·반·보직, 근무 설정, 메뉴 권한',
];
const SITEMAP_SETTINGS_DESC = [
    'general'   => '상위 부서·사업장 이름',
    'org'       => '팀과 반 만들기·이름·순서',
    'users'     => '가입 승인, 직급·팀·반·보직, 근무 설정, 메뉴 권한, 비밀번호 초기화',
    'products'  => '입장권·객실·시설대관·대관 숙박시설·프로그램 요금, 할인율, 상품권 환급액, 기간요금',
    'facility'  => '팀별 시설 구역·건물',
    'equipment' => '팀별 장비 분류',
];
const SITEMAP_ICON = ['home' => '🏠', 'schedule' => '📅', 'personal' => '🗂', 'ops' => '📋', 'room' => '🛏', 'prog' => '🌲', 'stat' => '📊', 'fac' => '🛠', 'etc' => '📌', 'settings' => '⚙'];

$groups = nav_groups($user);
if (!empty($user['is_admin'])) $groups['settings'] = ['label' => '⚙ 설정', 'href' => 'settings.php']; // 상단바 오른쪽 설정도 사이트맵에 표시
layout_header('사이트맵', 'sitemap');
?>
<section class="card">
  <div class="card-head">
    <h1>사이트맵</h1>
    <button class="btn ghost no-print" type="button" onclick="window.print()">인쇄</button>
  </div>
  <p class="muted small">내 계정으로 사용할 수 있는 메뉴입니다. 이름을 누르면 해당 화면으로 이동합니다.</p>
  <div class="sitemap">
    <?php foreach ($groups as $gkey => $g):
        $items = $g['items'] ?? [$gkey => [$g['href'], $g['label']]];
        if ($gkey === 'settings') $items = array_map(fn($m) => [$m[0], $m[1]], SETTINGS_MENU); ?>
      <div class="sitemap-group">
        <h2><span><?= SITEMAP_ICON[$gkey] ?? '•' ?></span> <?= e(str_replace('⚙ ', '', $g['label'])) ?></h2>
        <ul>
          <?php foreach ($items as $key => [$href, $label]): ?>
            <li><a href="<?= e(url($href)) ?>"><?= e($label) ?></a>
              <?php if ($d = ($gkey === 'settings' ? SITEMAP_SETTINGS_DESC : SITEMAP_DESC)[$key] ?? null): ?><small><?= e($d) ?></small><?php endif ?></li>
          <?php endforeach ?>
        </ul>
      </div>
    <?php endforeach ?>
    <div class="sitemap-group">
      <h2><span>👤</span> 내 계정</h2>
      <ul>
        <li><a href="<?= e(url('mypage.php')) ?>">내 정보</a><small>이름·연락처·비밀번호 변경</small></li>
        <li><a href="<?= e(url('member_photo.php')) ?>">내 사진</a><small>조직도·대시보드에 보이는 사진</small></li>
        <li><a href="<?= e(url('logout.php')) ?>">로그아웃</a></li>
      </ul>
    </div>
  </div>
</section>
<?php layout_footer();
