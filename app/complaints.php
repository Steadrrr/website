<?php
defined('APP_ROOT') || exit;

/**
 * 민원 (일일업무일지에 입력, 운영관리 › 민원관리에서 조회·처리)
 *  - 분류: 대분류 → 중분류 → 소분류 (코드 '1', '1-1', '1-1-1'). 모든 중분류 끝에 '기타'(직접 입력)
 *  - 대분류 4(긴급·안전)는 빨간색 강조, 기본 상태 '처리중' / 대분류 1(단순문의)은 기본 '완료'·처리일자 = 접수일
 *  - 건수는 같은 문의가 반복될 때 숫자만 올린다 (통계는 건수 합계)
 */

// 대분류 => [이름, 설명, [중분류 => [이름, [소분류 => 이름]]]]
const CPL_TREE = [
    '1' => ['단순문의', '정보만 안내하면 끝나는 건', [
        '1-1' => ['숙박예약', ['1-1-1' => '예약방법·오픈일정', '1-1-2' => '군민우선예약', '1-1-3' => '요금·할인 (성수기, 군민·다자녀·장애인, 바우처)',
                              '1-1-4' => '취소·변경·위약금 규정', '1-1-5' => '숙박확인서 등 증빙']],
        '1-2' => ['숙박이용', ['1-2-1' => '입·퇴실 (시간, 장소, 객실키 반납)', '1-2-2' => '인원·동반입실 (인원 추가, 예약자 외 입실)', '1-2-3' => '반려동물 동반',
                              '1-2-4' => '객실 구성·비치품', '1-2-5' => '바비큐', '1-2-6' => '주차·차량']],
        '1-3' => ['공원이용', ['1-3-1' => '입장·운영시간·휴장', '1-3-2' => '입장요금·예약', '1-3-3' => '반려동물 동반', '1-3-4' => '편의시설 (카페, 매점, 식당, 흡연구역)',
                              '1-3-5' => '물놀이·놀이시설', '1-3-6' => '교통·접근성 (버스, 휠체어, 장애인 차량)', '1-3-7' => '공사·통제구간']],
        '1-4' => ['프로그램·대관', ['1-4-1' => '숲해설', '1-4-2' => '치유센터', '1-4-3' => '유아숲', '1-4-4' => '시설대관 (교육관, 기숙동, 야외무대, 잔디구장)',
                                  '1-4-5' => '행사·기타 체험']],
        '1-5' => ['기타', ['1-5-1' => '분실물 문의', '1-5-2' => '외부기관·업체 연락 (점검, 답사, 기관 행사)', '1-5-3' => '기타']],
    ]],
    '2' => ['요청', '직원이 조치나 물품 제공을 해야 하는 건', [
        '2-1' => ['숙박이용', ['2-1-1' => '물품 대여 (충전기, 파라솔, 선풍기, 토치)', '2-1-2' => '비품 추가 (수건, 침구, 의자)', '2-1-3' => '객실 변경',
                              '2-1-4' => '입·퇴실 시간 조정', '2-1-5' => '기타 편의 (냉장고 보관, 걸레 등)']],
        '2-2' => ['숙박예약', ['2-2-1' => '예약 변경·취소 처리', '2-2-2' => '숙박확인서 발급']],
        '2-3' => ['프로그램·대관', ['2-3-1' => '프로그램 예약 변경·취소', '2-3-2' => '대관 신청']],
        '2-4' => ['기타', ['2-4-1' => '분실물 확인·회수', '2-4-2' => '기타']],
    ]],
    '3' => ['불편·불만', '시설 고장, 서비스 품질 문제', [
        '3-1' => ['객실 청소·비품', ['3-1-1' => '청결 불량 (머리카락, 음식물, 쓰레기)', '3-1-2' => '냄새', '3-1-3' => '침구·수건 부족', '3-1-4' => '주방용품 부족·파손',
                                   '3-1-5' => '소모품 누락 (세제, 요커버)']],
        '3-2' => ['객실 시설', ['3-2-1' => '냉난방', '3-2-2' => '급수·온수·배수', '3-2-3' => '전기·조명', '3-2-4' => '가전·통신 (TV, 와이파이, 냉장고)',
                               '3-2-5' => '문·잠금·욕실설비', '3-2-6' => '외부시설 (테라스, 피크닉테이블)']],
        '3-3' => ['공원·부대시설', ['3-3-1' => '시설 고장·파손', '3-3-2' => '산책로·도로 상태', '3-3-3' => '공사 불편']],
        '3-4' => ['예약시스템', ['3-4-1' => '예약 오류·접속 불량']],
        '3-5' => ['직원응대', ['3-5-1' => '응대 실수 (객실키 잘못 전달 등)', '3-5-2' => '안내 오류', '3-5-3' => '불친절·태도']],
    ]],
    '4' => ['긴급·안전', '즉시 대응해야 하는 건', [
        '4-1' => ['해충·동물', ['4-1-1' => '벌·벌집', '4-1-2' => '지네 등 해충', '4-1-3' => '유기견·야생동물']],
        '4-2' => ['부상·사고', ['4-2-1' => '물림·쏘임', '4-2-2' => '낙상·기타 부상']],
        '4-3' => ['시설 위험', ['4-3-1' => '전체 정전·차단기', '4-3-2' => '단수·흙탕물', '4-3-3' => '보안 (문 열림 등)']],
        '4-4' => ['기상·재난', ['4-4-1' => '폭우·폭설·제설', '4-4-2' => '천재지변 대응']],
        '4-5' => ['미아·실종', ['4-5-1' => '아이', '4-5-2' => '반려동물', '4-5-3' => '길 잃음']],
    ]],
];
const CPL_CHANNELS = ['phone' => '전화', 'visit' => '현장', 'online' => '온라인'];
const CPL_STATUS = ['open' => ['미조치', '#c0392b'], 'progress' => ['처리중', '#e8710a'], 'done' => ['완료', '#0b8043']];
const CPL_URGENT = '4';     // 긴급·안전
const CPL_SIMPLE = '1';     // 단순문의
const CPL_COMPLAINT = '3';  // 불편·불만 (객실별 재발 확인)

/**
 * 분류 트리 (모든 중분류 끝에 '기타' 보장). 이미 '기타'가 있으면 그것이 직접 입력 항목
 * @return array{tree: array, etc: array<string,bool>, names: array<string,string>}
 */
function cpl_tree(): array
{
    static $out = null;
    if ($out) return $out;
    $tree = [];
    $etc = $names = [];
    foreach (CPL_TREE as $c1 => [$n1, $desc, $mids]) {
        $names[$c1] = $n1;
        $tree[$c1] = ['name' => $n1, 'desc' => $desc, 'mids' => []];
        foreach ($mids as $c2 => [$n2, $leaves]) {
            $names[$c2] = $n2;
            if (!in_array('기타', $leaves, true)) {
                $leaves[$c2 . '-' . (count($leaves) + 1)] = '기타';
            }
            foreach ($leaves as $c3 => $n3) {
                $names[$c3] = $n3;
                if ($n3 === '기타') $etc[$c3] = true;
            }
            $tree[$c1]['mids'][$c2] = ['name' => $n2, 'leaves' => $leaves];
        }
    }
    return $out = ['tree' => $tree, 'etc' => $etc, 'names' => $names];
}

function cpl_name(?string $code): string
{
    return $code ? (cpl_tree()['names'][$code] ?? $code) : '';
}

/** "1-1-3 요금·할인" 처럼 분류 한 줄 (기타면 직접 입력 내용) */
function cpl_path(array $c, bool $full = true): string
{
    $leaf = cpl_name($c['cat3']) . (!empty($c['etc_text']) ? ': ' . $c['etc_text'] : '');
    return $full ? cpl_name($c['cat1']) . ' › ' . cpl_name($c['cat2']) . ' › ' . $leaf : $leaf;
}

function cpl_place_options(): array
{
    $rooms = [];
    foreach (products_all() as $p) if ($p['grp'] === 'room' && $p['is_active']) $rooms[(int) $p['id']] = $p['name'];
    $fac = [];
    foreach (asset_groups('facility', true) as $g) $fac[(int) $g['id']] = $g['name'] . ' (' . $g['team_name'] . ')';
    return ['room' => $rooms, 'facility' => $fac];
}

function cpl_load(int $journalId): array
{
    $st = db()->prepare('SELECT c.*, u.name AS receiver_name FROM complaints c LEFT JOIN users u ON u.id = c.receiver_id WHERE c.journal_id = ? ORDER BY c.id');
    $st->execute([$journalId]);
    return $st->fetchAll();
}

function cpl_empty(): array
{
    return ['id' => 0, 'cat1' => '', 'cat2' => '', 'cat3' => '', 'etc_text' => '', 'channel' => '', 'place_type' => '', 'place_id' => null,
        'content' => '', 'qty' => 1, 'status' => '', 'done_date' => '', 'action' => '', 'receiver_name' => ''];
}

/** @return array{0: array, 1: string[]} [민원 행 목록, 오류] */
function cpl_parse(string $workDate): array
{
    $t = cpl_tree();
    $places = cpl_place_options();
    $rows = $errors = [];
    foreach ((array) ($_POST['cp'] ?? []) as $r) {
        if (!is_array($r)) continue;
        $c = cpl_empty();
        $c['id'] = (int) ($r['id'] ?? 0);
        foreach (['cat1', 'cat2', 'cat3', 'channel', 'place_type', 'status'] as $k) $c[$k] = trim((string) ($r[$k] ?? ''));
        $c['etc_text'] = mb_substr(trim((string) ($r['etc_text'] ?? '')), 0, 100);
        $c['content'] = trim((string) ($r['content'] ?? ''));
        $c['action'] = trim((string) ($r['action'] ?? ''));
        $c['qty'] = max(1, min(999, to_int($r['qty'] ?? 1)));
        $c['done_date'] = (string) ($r['done_date'] ?? '');
        $c['place_id'] = to_int($r['place_id'] ?? 0) ?: null;
        // 아무것도 고르지 않은 빈 행은 건너뜀
        if ($c['cat1'] === '' && $c['content'] === '' && !$c['id']) continue;

        $no = count($rows) + 1;
        $mid = $t['tree'][$c['cat1']]['mids'][$c['cat2']] ?? null;
        if (!isset($t['tree'][$c['cat1']]) || !$mid || !isset($mid['leaves'][$c['cat3']])) $errors[] = "민원 {$no}: 대분류·중분류·소분류를 모두 고르세요.";
        elseif (isset($t['etc'][$c['cat3']]) && $c['etc_text'] === '') $errors[] = "민원 {$no}: '기타'를 골랐으면 내용을 직접 입력하세요.";
        if (!isset($t['etc'][$c['cat3']])) $c['etc_text'] = '';
        if (!isset(CPL_CHANNELS[$c['channel']])) $errors[] = "민원 {$no}: 접수경로(전화·현장·온라인)를 고르세요.";
        if ($c['content'] === '') $errors[] = "민원 {$no}: 민원 내용을 입력하세요.";
        if (!isset(CPL_STATUS[$c['status']])) $errors[] = "민원 {$no}: 처리상태를 고르세요.";
        if ($c['status'] === 'done' && !valid_date($c['done_date'])) $errors[] = "민원 {$no}: 처리상태가 '완료'이면 처리일자를 입력하세요.";
        if ($c['status'] !== 'done') $c['done_date'] = '';
        if (!in_array($c['place_type'], ['room', 'facility'], true) || !isset($places[$c['place_type']][$c['place_id']])) {
            // 판매중지된 객실 등 목록에 없는 기존 장소는 유지
            if (!($c['id'] && $c['place_type'] && $c['place_id'])) { $c['place_type'] = ''; $c['place_id'] = null; }
        }
        $rows[] = $c;
    }
    return [$rows, $errors];
}

/** 트랜잭션 안에서 호출. 기존 민원은 id 로 수정(접수자 유지), 새 민원은 추가, 빠진 민원은 삭제 */
function cpl_save(int $journalId, string $workDate, array $rows): void
{
    $pdo = db();
    $user = current_user();
    $places = cpl_place_options();
    $existing = array_column(cpl_load($journalId), null, 'id');
    $keep = [];
    foreach ($rows as $c) {
        $placeName = $c['place_type'] ? ($places[$c['place_type']][$c['place_id']] ?? ($existing[$c['id']]['place_name'] ?? null)) : null;
        $vals = [$workDate, $c['cat1'], $c['cat2'], $c['cat3'], $c['etc_text'] ?: null, $c['channel'], $c['place_type'] ?: null, $c['place_id'], $placeName,
            $c['content'], $c['qty'], $c['status'], $c['done_date'] ?: null, $c['action'] !== '' ? $c['action'] : null];
        if ($c['id'] && isset($existing[$c['id']])) {
            $pdo->prepare('UPDATE complaints SET work_date = ?, cat1 = ?, cat2 = ?, cat3 = ?, etc_text = ?, channel = ?, place_type = ?, place_id = ?, place_name = ?,
                           content = ?, qty = ?, status = ?, done_date = ?, action = ?, updated_by = ?, updated_at = NOW() WHERE id = ? AND journal_id = ?')
                ->execute([...$vals, $user['id'] ?? null, $c['id'], $journalId]);
            $keep[] = $c['id'];
        } else {
            $pdo->prepare('INSERT INTO complaints (journal_id, work_date, cat1, cat2, cat3, etc_text, channel, place_type, place_id, place_name, content, qty, status, done_date, action, receiver_id)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$journalId, ...$vals, $user['id'] ?? null]);
            $keep[] = (int) $pdo->lastInsertId();
        }
    }
    foreach (array_diff(array_keys($existing), $keep) as $gone) {
        $pdo->prepare('DELETE FROM complaints WHERE id = ? AND journal_id = ?')->execute([$gone, $journalId]);
    }
}

/** 요약: 대분류별·처리상태별 건수 (건수 합계) */
function cpl_summary(array $rows): array
{
    $by1 = array_fill_keys(array_keys(CPL_TREE), 0);
    $bySt = array_fill_keys(array_keys(CPL_STATUS), 0);
    foreach ($rows as $c) {
        if (isset($by1[$c['cat1']])) $by1[$c['cat1']] += (int) $c['qty'];
        if (isset($bySt[$c['status']])) $bySt[$c['status']] += (int) $c['qty'];
    }
    return [$by1, $bySt, array_sum($by1)];
}

function cpl_status_badge(string $status): string
{
    [$label, $color] = CPL_STATUS[$status] ?? [$status, '#666'];
    return '<span class="badge cpl-badge" style="--c: ' . $color . '">' . e($label) . '</span>';
}

function cpl_summary_html(array $rows, string $title = '민원 요약'): void
{
    [$by1, $bySt, $total] = cpl_summary($rows);
    ?>
  <div class="cpl-summary" data-cpl-summary>
    <b><?= e($title) ?></b> <span>총 <b data-cs-total><?= $total ?></b>건</span>
    <span class="cpl-sum-group"><?php foreach (CPL_TREE as $c1 => [$n1]): ?>
      <span class="cpl-chip <?= $c1 === CPL_URGENT ? 'urgent' : '' ?>"><?= e($n1) ?> <b data-cs1="<?= $c1 ?>"><?= $by1[$c1] ?></b></span>
    <?php endforeach ?></span>
    <span class="cpl-sum-group"><?php foreach (CPL_STATUS as $s => [$label, $color]): ?>
      <span class="cpl-chip" style="--c: <?= $color ?>"><?= e($label) ?> <b data-css="<?= $s ?>"><?= $bySt[$s] ?></b></span>
    <?php endforeach ?></span>
  </div>
    <?php
}

/* ───────────── 입력 폼 (일일업무일지 작성 화면) ───────────── */

function cpl_row(string $key, array $c, string $workDate): void
{
    $n = fn(string $f) => "cp[$key][$f]";
    $t = cpl_tree();
    $places = cpl_place_options();
    $urgent = $c['cat1'] === CPL_URGENT;
    ?>
  <div class="cpl-row <?= $urgent ? 'urgent' : '' ?>" data-cpl-row>
    <input type="hidden" name="<?= $n('id') ?>" value="<?= (int) $c['id'] ?>">
    <div class="cpl-head">
      <b>민원 <span data-cpl-no></span></b>
      <span class="muted small">접수자 <?= e($c['receiver_name'] ?: (current_user()['name'] ?? '')) ?></span>
      <button type="button" class="btn small ghost danger" data-cpl-remove>삭제</button>
    </div>
    <div class="cpl-cats">
      <select name="<?= $n('cat1') ?>" data-cat1 data-value="<?= e($c['cat1']) ?>"><option value="">대분류</option>
        <?php foreach ($t['tree'] as $c1 => $x): ?><option value="<?= $c1 ?>" <?= $c['cat1'] === (string) $c1 ? 'selected' : '' ?>><?= $c1 ?>. <?= e($x['name']) ?></option><?php endforeach ?>
      </select>
      <select name="<?= $n('cat2') ?>" data-cat2 data-value="<?= e($c['cat2']) ?>"><option value="">중분류</option></select>
      <select name="<?= $n('cat3') ?>" data-cat3 data-value="<?= e($c['cat3']) ?>"><option value="">소분류</option></select>
      <input name="<?= $n('etc_text') ?>" value="<?= e($c['etc_text']) ?>" placeholder="기타 내용 직접 입력" maxlength="100" data-etc <?= isset($t['etc'][$c['cat3']]) ? '' : 'hidden' ?>>
    </div>
    <div class="cpl-fields">
      <div class="cpl-field"><span class="label-text">접수경로</span>
        <span class="cpl-radios"><?php foreach (CPL_CHANNELS as $k => $label): ?>
          <label class="inline-check"><input type="radio" name="<?= $n('channel') ?>" value="<?= $k ?>" <?= $c['channel'] === $k ? 'checked' : '' ?> required> <?= $label ?></label>
        <?php endforeach ?></span></div>
      <label class="cpl-field">장소
        <span class="cpl-place">
          <select name="<?= $n('place_type') ?>" data-place-type>
            <option value="">(선택 안 함)</option><option value="room" <?= $c['place_type'] === 'room' ? 'selected' : '' ?>>객실</option><option value="facility" <?= $c['place_type'] === 'facility' ? 'selected' : '' ?>>시설</option>
          </select>
          <select name="<?= $n('place_id') ?>" data-place-id data-value="<?= (int) $c['place_id'] ?>" <?= $c['place_type'] ? '' : 'hidden' ?>>
            <?php foreach (['room' => '객실', 'facility' => '시설 구역'] as $pt => $pl): ?>
              <optgroup label="<?= $pl ?>" data-pt="<?= $pt ?>">
                <?php foreach ($places[$pt] as $pid => $pname): ?><option value="<?= $pid ?>" <?= $c['place_type'] === $pt && (int) $c['place_id'] === $pid ? 'selected' : '' ?>><?= e($pname) ?></option><?php endforeach ?>
                <?php if ($c['place_type'] === $pt && $c['place_id'] && !isset($places[$pt][(int) $c['place_id']])): ?><option value="<?= (int) $c['place_id'] ?>" selected><?= e($c['place_name'] ?? '') ?></option><?php endif ?>
              </optgroup>
            <?php endforeach ?>
          </select>
        </span></label>
      <label class="cpl-field cpl-qty">건수<input name="<?= $n('qty') ?>" value="<?= (int) $c['qty'] ?: 1 ?>" inputmode="numeric" class="num tiny" data-qty></label>
      <label class="cpl-field">처리상태
        <select name="<?= $n('status') ?>" data-status><option value="">선택</option>
          <?php foreach (CPL_STATUS as $s => [$label]): ?><option value="<?= $s ?>" <?= $c['status'] === $s ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?>
        </select></label>
      <label class="cpl-field" data-done-box <?= $c['status'] === 'done' ? '' : 'hidden' ?>>처리일자<input type="date" name="<?= $n('done_date') ?>" value="<?= e($c['done_date'] ?? '') ?>" data-done></label>
    </div>
    <div class="cpl-texts">
      <label>민원 내용<textarea name="<?= $n('content') ?>" rows="2" placeholder="예: 102호 온수가 나오지 않음"><?= e($c['content']) ?></textarea></label>
      <label>조치내용·비고<textarea name="<?= $n('action') ?>" rows="2" placeholder="예: 보일러 재가동, 15시 확인 완료"><?= e($c['action'] ?? '') ?></textarea></label>
    </div>
  </div>
    <?php
}

function cpl_form(array $rows, string $workDate): void
{
    $t = cpl_tree();
    $jsTree = [];
    foreach ($t['tree'] as $c1 => $x) {
        foreach ($x['mids'] as $c2 => $m) $jsTree[$c1][] = [$c2, $m['name'], array_map(fn($k, $v) => [$k, $v], array_keys($m['leaves']), $m['leaves'])];
    }
    ?>
<section class="cpl-section" data-cpl-form data-work-date="<?= e($workDate) ?>">
  <h3>민원 <small class="muted">대분류 → 중분류 → 소분류 순서로 고르세요. 긴급·안전은 빨간색으로 표시되고 '처리중', 단순문의는 '완료'로 기본 설정됩니다.</small></h3>
  <div data-cpl-list>
    <?php foreach (array_values($rows) as $i => $c) cpl_row((string) $i, $c, $workDate) ?>
  </div>
  <template id="cplTpl"><?php cpl_row('__KEY__', cpl_empty(), $workDate) ?></template>
  <button type="button" class="btn" data-cpl-add>+ 민원 추가</button>
  <?php cpl_summary_html($rows, '오늘 민원 요약') ?>
</section>
<script>window.CPL = <?= json_encode(['tree' => $jsTree, 'etc' => array_keys($t['etc']), 'urgent' => CPL_URGENT, 'simple' => CPL_SIMPLE], JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="<?= e(url('assets/complaints.js')) ?>" defer></script>
    <?php
}

/* ───────────── 보기 ───────────── */

function cpl_view(array $rows): void
{
    if (!$rows) return;
    ?>
  <h3>민원 <small class="muted"><?= count($rows) ?>건 입력</small></h3>
  <div class="table-scroll">
  <table class="table cpl-table">
    <thead><tr><th>분류</th><th>접수</th><th>장소</th><th>민원 내용</th><th class="right">건수</th><th>처리</th><th>조치내용·비고</th><th>접수자</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $c): ?>
      <tr class="<?= $c['cat1'] === CPL_URGENT ? 'urgent' : '' ?>">
        <td><small class="muted"><?= e(cpl_name($c['cat1'])) ?> › <?= e(cpl_name($c['cat2'])) ?></small><br><?= e(cpl_path($c, false)) ?></td>
        <td class="nowrap"><?= e(CPL_CHANNELS[$c['channel']] ?? '') ?></td>
        <td><?= $c['place_type'] ? '<small class="muted">' . ($c['place_type'] === 'room' ? '객실' : '시설') . '</small> ' . e($c['place_name']) : '-' ?></td>
        <td class="pre-wrap"><?= e($c['content']) ?></td>
        <td class="right"><?= (int) $c['qty'] ?></td>
        <td class="nowrap"><?= cpl_status_badge($c['status']) ?><?= $c['done_date'] ? '<br><small class="muted">' . e($c['done_date']) . '</small>' : '' ?></td>
        <td class="pre-wrap small"><?= e($c['action'] ?? '') ?></td>
        <td class="nowrap small"><?= e($c['receiver_name'] ?? '') ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
  <?php cpl_summary_html($rows, '민원 요약') ?>
    <?php
}

/** 수정 이력 비교용 */
function cpl_snapshot(int $journalId): array
{
    return array_map(fn($c) => cpl_path($c) . ' · ' . (CPL_CHANNELS[$c['channel']] ?? '') . ($c['place_name'] ? ' · ' . $c['place_name'] : '')
        . ' · ' . preg_replace('/\s+/', ' ', $c['content']) . ' · ' . $c['qty'] . '건 · ' . (CPL_STATUS[$c['status']][0] ?? '')
        . ($c['done_date'] ? ' ' . $c['done_date'] : '') . ($c['action'] ? ' · 조치: ' . preg_replace('/\s+/', ' ', $c['action']) : ''), cpl_load($journalId));
}
