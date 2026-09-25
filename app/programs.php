<?php
defined('APP_ROOT') || exit;

/**
 * 프로그램 운영보고 (산림치유센터 · 유아숲체험원 · 유아숲(직영) · 숲해설)
 *  - 분야별로 하루 1건. 보고서 안에 회차(1회차부터 자동 번호)를 여러 개 입력
 *  - 회차: 단체명(개인 성명), 담당자, 운영시간, 인원(남·여 × 유아·초등·중고등·성인·65세이상), 유료/무료, 활동내용
 *  - 회차마다 프로그램(설정 › 상품·요금 › 프로그램)을 고르고, 프로그램 금액 = 인원 합계 × 그 프로그램의 1인 요금 (유료 / 할인 / 무료)
 *  - 활동사진은 보고서에 여러 장 (photos.owner_type 'program', 긴 변 PROGRAM_PHOTO_MAX px)
 */
const PROGRAM_PHOTO_MAX = 1000;

function is_program_type(string $type): bool
{
    return isset(PROGRAM_TYPES[$type]);
}

const PROGRAM_FEE_TYPES = ['paid' => '유료', 'discount' => '할인', 'free' => '무료'];

/** 프로그램 상품을 쓰는 분야 목록 (비어 있으면 모든 분야) */
function program_product_types(array $p): array
{
    return array_values(array_intersect(explode(',', (string) ($p['prog_types'] ?? '')), array_keys(PROGRAM_TYPES)));
}

/**
 * 그 분야 운영보고에서 고를 수 있는 프로그램 상품 (그 날짜 가격 = 기간별 가격 적용)
 * 판매중지·다른 분야 상품도 $includeIds(이미 보고서에 들어 있는 것)면 포함
 */
function program_products(string $type, string $date, array $includeIds = []): array
{
    $out = [];
    foreach (valid_date($date) ? products_at($date) : products_all() as $id => $p) {
        if ($p['grp'] !== 'program') continue;
        $types = program_product_types($p);
        if (($p['is_active'] && (!$types || in_array($type, $types, true))) || in_array((int) $id, $includeIds, true)) $out[(int) $id] = $p;
    }
    return $out;
}

/** 요금 구분별 1인 참가비 (유료 = 1인 요금, 할인 = 할인 1인 요금, 무료 = 0) */
function program_fee_for(?array $product, string $feeType): int
{
    if (!$product) return 0;
    return match ($feeType) { 'paid' => (int) $product['price'], 'discount' => (int) $product['price_discount'], default => 0 };
}

/** 인원 컬럼 목록: ['m_infant', 'f_infant', ...] */
function program_people_cols(): array
{
    $cols = [];
    foreach (array_keys(PROGRAM_AGES) as $a) { $cols[] = "m_$a"; $cols[] = "f_$a"; }
    return $cols;
}

function program_empty_session(): array
{
    return ['group_name' => '', 'staff' => '', 'product_id' => null, 'product_name' => null, 'start_time' => '', 'end_time' => '', 'is_paid' => 1, 'fee_type' => 'paid', 'activity' => '', 'total' => 0, 'amount' => 0]
        + array_fill_keys(program_people_cols(), 0);
}

function program_load(int $journalId): array
{
    $st = db()->prepare('SELECT * FROM program_sessions WHERE journal_id = ? ORDER BY session_no, id');
    $st->execute([$journalId]);
    return ['sessions' => $st->fetchAll()];
}

/** @return array{0: array, 1: string[]} */
function program_parse(string $type, string $workDate, int $journalId): array
{
    $errors = [];
    $sessions = [];
    $time = '/^([01]\d|2[0-3]):[0-5]\d$/';
    // 수정 중인 보고서에 이미 들어 있는 프로그램은 판매중지여도 그대로 고를 수 있다
    $keep = [];
    if ($journalId) {
        $st = db()->prepare('SELECT DISTINCT product_id FROM program_sessions WHERE journal_id = ? AND product_id IS NOT NULL');
        $st->execute([$journalId]);
        $keep = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    $products = program_products($type, $workDate, $keep);
    foreach ((array) ($_POST['s'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $s = program_empty_session();
        $s['group_name'] = mb_substr(trim((string) ($row['group_name'] ?? '')), 0, 100);
        $s['staff'] = mb_substr(trim((string) ($row['staff'] ?? '')), 0, 100);
        foreach (program_people_cols() as $c) $s[$c] = min(9999, to_int($row[$c] ?? 0));
        $s['total'] = array_sum(array_intersect_key($s, array_flip(program_people_cols())));
        $s['activity'] = trim((string) ($row['activity'] ?? ''));
        $s['start_time'] = (string) ($row['start_time'] ?? '');
        $s['end_time'] = (string) ($row['end_time'] ?? '');
        $s['fee_type'] = isset(PROGRAM_FEE_TYPES[$row['fee_type'] ?? '']) ? $row['fee_type'] : 'paid';
        $s['is_paid'] = (int) ($s['fee_type'] !== 'free');
        $product = $products[(int) ($row['product_id'] ?? 0)] ?? null;
        $s['product_id'] = $product ? (int) $product['id'] : null;
        $s['product_name'] = $product['name'] ?? null;
        // 아무것도 입력하지 않은 회차는 건너뜀
        if ($s['group_name'] === '' && $s['total'] === 0 && $s['activity'] === '' && $s['start_time'] === '') continue;

        $no = count($sessions) + 1;
        if ($s['group_name'] === '') $errors[] = "{$no}회차: 단체명(또는 개인 성명)을 입력하세요.";
        if ($s['total'] === 0) $errors[] = "{$no}회차: 인원을 입력하세요.";
        if (!$product) $errors[] = $products ? "{$no}회차: 프로그램을 고르세요." : "{$no}회차: 고를 수 있는 프로그램이 없습니다. 설정 › 상품·요금 › 프로그램에서 먼저 등록하세요.";
        if ($s['start_time'] !== '' && !preg_match($time, $s['start_time'])) $errors[] = "{$no}회차: 시작 시간을 확인하세요.";
        if ($s['end_time'] !== '' && !preg_match($time, $s['end_time'])) $errors[] = "{$no}회차: 종료 시간을 확인하세요.";
        if ($s['start_time'] !== '' && $s['end_time'] !== '' && $s['end_time'] <= $s['start_time']) $errors[] = "{$no}회차: 종료 시간이 시작 시간보다 늦어야 합니다.";
        $s['session_no'] = $no;
        $s['fee'] = program_fee_for($product, $s['fee_type']);
        $s['amount'] = $s['total'] * $s['fee'];
        $sessions[] = $s;
    }
    if (!$sessions) $errors[] = '회차를 1개 이상 입력하세요.';

    // 분야별 하루 1건 (통계 중복 방지)
    if (valid_date($workDate)) {
        $st = db()->prepare('SELECT id FROM journals WHERE type = ? AND work_date = ? AND id <> ?');
        $st->execute([$type, $workDate, $journalId]);
        if ($dup = $st->fetchColumn()) $errors[] = "해당 날짜의 " . JOURNAL_TYPES[$type] . "가 이미 있습니다. (문서번호 $dup) 그 보고서에 회차를 추가하세요.";
    }
    return [['sessions' => $sessions ?: [program_empty_session()]], $errors];
}

/** 트랜잭션 안에서 호출. 회차 저장 + 활동사진 추가·삭제 */
function program_save(int $journalId, array $payload): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM program_sessions WHERE journal_id = ?')->execute([$journalId]);
    $cols = ['session_no', 'group_name', 'staff', 'product_id', 'product_name', 'start_time', 'end_time', ...program_people_cols(), 'total', 'is_paid', 'fee_type', 'fee', 'amount', 'activity'];
    $ins = $pdo->prepare('INSERT INTO program_sessions (journal_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')');
    foreach ($payload['sessions'] as $s) {
        $s['start_time'] = $s['start_time'] ?: null;
        $s['end_time'] = $s['end_time'] ?: null;
        $s['activity'] = $s['activity'] !== '' ? $s['activity'] : null;
        $s['staff'] = $s['staff'] !== '' ? $s['staff'] : null;
        $ins->execute([$journalId, ...array_map(fn($c) => $s[$c], $cols)]);
    }
    photos_delete('program', $journalId, (array) ($_POST['delete_photos'] ?? []));
    $user = current_user();
    foreach (photos_save_uploaded('program', $journalId, (int) ($user['id'] ?? 0), PROGRAM_PHOTO_MAX) as $err) flash($err, 'error');
}

/** "10:00~12:00" */
function program_time(array $s): string
{
    $t1 = $s['start_time'] ? substr((string) $s['start_time'], 0, 5) : '';
    $t2 = $s['end_time'] ? substr((string) $s['end_time'], 0, 5) : '';
    return $t1 || $t2 ? "$t1~$t2" : '';
}

/** 회차 합계: 회차 수, 인원, 유료·무료 인원, 금액, 남·여, 연령대별 */
function program_totals(array $sessions): array
{
    $t = ['sessions' => count($sessions), 'total' => 0, 'paid' => 0, 'discount' => 0, 'free' => 0, 'amount' => 0, 'm' => 0, 'f' => 0]
        + array_fill_keys(program_people_cols(), 0);
    foreach ($sessions as $s) {
        $t['total'] += (int) $s['total'];
        $t[$s['fee_type'] ?? ($s['is_paid'] ? 'paid' : 'free')] += (int) $s['total'];
        $t['amount'] += (int) $s['amount'];
        foreach (PROGRAM_AGES as $a => $_) {
            $t["m_$a"] += (int) $s["m_$a"];
            $t["f_$a"] += (int) $s["f_$a"];
            $t['m'] += (int) $s["m_$a"];
            $t['f'] += (int) $s["f_$a"];
        }
    }
    return $t;
}

/* ───────────── 입력 폼 ───────────── */

/** 회차 카드 1개. $key = 폼 배열 키 (JS 가 새 회차는 새 키로 만든다). $products = 고를 수 있는 프로그램 */
function program_session_card(string $key, array $s, int $no, array $products): void
{
    $n = fn(string $c) => "s[$key][$c]";
    $val = fn(string $c) => e((int) ($s[$c] ?? 0) ?: '');
    $pid = (int) ($s['product_id'] ?? 0);
    if (!$pid && count($products) === 1) $pid = (int) array_key_first($products); // 하나뿐이면 미리 골라 둔다
    $cur = $products[$pid] ?? null;
    ?>
  <div class="prog-session" data-session>
    <div class="prog-session-head">
      <b><span data-no><?= $no ?></span>회차</b>
      <button type="button" class="btn small ghost danger" data-remove-session>회차 삭제</button>
    </div>
    <div class="row">
      <label>단체명 (또는 개인 성명)<input name="<?= $n('group_name') ?>" value="<?= e($s['group_name'] ?? '') ?>" maxlength="100" placeholder="예: ○○어린이집 / 홍길동"></label>
      <label>담당자<input name="<?= $n('staff') ?>" value="<?= e($s['staff'] ?? '') ?>" maxlength="100" placeholder="예: 김숲해설, 이치유"></label>
      <label>운영시간
        <span class="time-range"><input type="time" name="<?= $n('start_time') ?>" value="<?= e(substr((string) ($s['start_time'] ?? ''), 0, 5)) ?>" step="600">
          ~ <input type="time" name="<?= $n('end_time') ?>" value="<?= e(substr((string) ($s['end_time'] ?? ''), 0, 5)) ?>" step="600"></span></label>
    </div>
    <div class="row">
      <label>프로그램<select name="<?= $n('product_id') ?>" data-product>
        <option value="" data-price="0" data-discount="0">- 프로그램 선택 -</option>
        <?php foreach ($products as $id => $p): ?>
          <option value="<?= (int) $id ?>" data-price="<?= (int) $p['price'] ?>" data-discount="<?= (int) $p['price_discount'] ?>" <?= $pid === (int) $id ? 'selected' : '' ?>><?= e($p['name']) ?><?= $p['is_active'] ? '' : ' (판매중지)' ?></option>
        <?php endforeach ?>
      </select></label>
      <div class="prog-paid">
        <span class="label-text">요금 구분</span>
        <?php foreach (PROGRAM_FEE_TYPES as $ft => $label): $fee = program_fee_for($cur, $ft); ?>
          <label class="inline-check"><input type="radio" name="<?= $n('fee_type') ?>" value="<?= $ft ?>" data-fee-type <?= ($s['fee_type'] ?? 'paid') === $ft ? 'checked' : '' ?>>
            <?= e($label) ?><?php if ($ft !== 'free'): ?> <small class="muted" data-fee-label="<?= $ft ?>"><?= $cur ? '(1인 ' . number_format($fee) . '원)' : '' ?></small><?php endif ?></label>
        <?php endforeach ?>
      </div>
    </div>
    <div class="table-scroll">
    <table class="table prog-people">
      <thead><tr><th>인원</th><?php foreach (PROGRAM_AGES as $label): ?><th><?= e($label) ?></th><?php endforeach ?><th class="right">계</th></tr></thead>
      <tbody>
        <?php foreach (['m' => '남', 'f' => '여'] as $g => $gl): ?>
          <tr data-gender="<?= $g ?>"><th><?= $gl ?></th>
            <?php foreach (PROGRAM_AGES as $a => $_): ?>
              <td><input name="<?= $n("{$g}_$a") ?>" value="<?= $val("{$g}_$a") ?>" inputmode="numeric" class="num tiny" data-people data-age="<?= $a ?>" data-g="<?= $g ?>" placeholder="0"></td>
            <?php endforeach ?>
            <td class="right" data-gsum="<?= $g ?>">0</td></tr>
        <?php endforeach ?>
      </tbody>
      <tfoot><tr><th>계</th><?php foreach (PROGRAM_AGES as $a => $_): ?><th class="right" data-asum="<?= $a ?>">0</th><?php endforeach ?>
        <th class="right"><b data-total>0</b>명</th></tr></tfoot>
    </table>
    </div>
    <p class="prog-amount">인원 합계 <b data-total-text>0명</b> · 프로그램 금액 <b data-amount>0원</b></p>
    <label>활동내용<textarea name="<?= $n('activity') ?>" rows="3" placeholder="예: 오감 숲체험, 숲길 걷기, 나무 이름 알기"><?= e($s['activity'] ?? '') ?></textarea></label>
  </div>
    <?php
}

function program_form(string $type, array $payload, string $workDate, ?array $journal): void
{
    $sessions = $payload['sessions'] ?: [program_empty_session()];
    $products = program_products($type, $workDate, array_map('intval', array_filter(array_column($sessions, 'product_id'))));
    ?>
<div data-program-form>
  <h3><?= e(PROGRAM_TYPES[$type]) ?> 프로그램 운영 <small class="muted">회차는 1회차부터 자동으로 번호가 붙습니다</small></h3>
  <?php if (!$products): ?>
    <p class="alert error">이 분야에서 고를 수 있는 프로그램이 없습니다. <?= is_admin() ? '<a href="' . e(url('admin/products.php#program')) . '">설정 › 상품·요금 › 프로그램</a>' : '최고관리자에게 설정 › 상품·요금 › 프로그램' ?>에서 프로그램과 1인 요금을 등록하세요.</p>
  <?php endif ?>
  <div data-sessions>
    <?php foreach (array_values($sessions) as $i => $s) program_session_card((string) $i, $s, $i + 1, $products) ?>
  </div>
  <template id="sessionTpl"><?php program_session_card('__KEY__', program_empty_session(), 0, array_filter($products, fn($p) => $p['is_active'])) ?></template>
  <button type="button" class="btn" data-add-session>+ 회차 추가</button>

  <div class="grand prog-grand">합계 <span data-sum-sessions>0회</span> · 인원 <b data-sum-total>0명</b>
    <small class="muted">(남 <span data-sum-m>0</span> · 여 <span data-sum-f>0</span> / 유료 <span data-sum-paid>0</span> · 할인 <span data-sum-discount>0</span> · 무료 <span data-sum-free>0</span>)</small>
    · 금액 <b data-sum-amount>0원</b></div>

  <h3>활동사진</h3>
  <?php render_photo_editor('program', $journal ? (int) $journal['id'] : null, PROGRAM_PHOTO_MAX) ?>
</div>
<script src="<?= e(url('assets/program.js')) ?>" defer></script>
    <?php
}

/* ───────────── 보기 ───────────── */

function program_view(array $journal): void
{
    $sessions = program_load((int) $journal['id'])['sessions'];
    $t = program_totals($sessions);
    ?>
  <div class="kpis k4 prog-kpis">
    <div class="kpi"><span>운영 회차</span><b><?= $t['sessions'] ?>회</b></div>
    <div class="kpi"><span>참여 인원</span><b><?= number_format($t['total']) ?>명</b><small class="muted">남 <?= $t['m'] ?> · 여 <?= $t['f'] ?></small></div>
    <div class="kpi"><span>유료 / 할인 / 무료</span><b><?= number_format($t['paid']) ?> / <?= number_format($t['discount']) ?> / <?= number_format($t['free']) ?>명</b></div>
    <div class="kpi total"><span>프로그램 금액</span><b><?= e(won($t['amount'])) ?></b></div>
  </div>
  <div class="table-scroll">
  <table class="table prog-view">
    <thead>
      <tr><th rowspan="2">회차</th><th rowspan="2">단체명(성명)</th><th rowspan="2">담당자</th><th rowspan="2">프로그램</th><th rowspan="2">운영시간</th>
        <?php foreach (PROGRAM_AGES as $label): ?><th colspan="2" class="center"><?= e($label) ?></th><?php endforeach ?>
        <th rowspan="2" class="right">합계</th><th rowspan="2">구분</th><th rowspan="2" class="right">금액</th></tr>
      <tr><?php foreach (PROGRAM_AGES as $_): ?><th class="right">남</th><th class="right">여</th><?php endforeach ?></tr>
    </thead>
    <tbody>
    <?php foreach ($sessions as $s): ?>
      <tr><td class="center"><?= (int) $s['session_no'] ?></td><td><?= e($s['group_name']) ?></td><td><?= e($s['staff'] ?? '') ?></td><td><?= e($s['product_name'] ?? '') ?></td><td class="nowrap"><?= e(program_time($s)) ?></td>
        <?php foreach (PROGRAM_AGES as $a => $_): ?><td class="right"><?= $s["m_$a"] ?: '' ?></td><td class="right"><?= $s["f_$a"] ?: '' ?></td><?php endforeach ?>
        <td class="right"><b><?= number_format($s['total']) ?></b></td><td><?= e(PROGRAM_FEE_TYPES[$s['fee_type']] ?? '') ?><?= $s['fee'] ? ' <small class="muted">' . number_format($s['fee']) . '</small>' : '' ?></td><td class="right"><?= number_format($s['amount']) ?></td></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="5">합계 <?= $t['sessions'] ?>회</th>
      <?php foreach (PROGRAM_AGES as $a => $_): ?><th class="right"><?= $t["m_$a"] ?></th><th class="right"><?= $t["f_$a"] ?></th><?php endforeach ?>
      <th class="right"><?= number_format($t['total']) ?></th><th></th><th class="right"><?= number_format($t['amount']) ?></th></tr></tfoot>
  </table>
  </div>
  <?php if (array_filter(array_column($sessions, 'activity'))): ?>
    <h3>활동내용</h3>
    <?php foreach ($sessions as $s): if (!$s['activity']) continue; ?>
      <div class="prog-activity"><b><?= (int) $s['session_no'] ?>회차 · <?= e($s['group_name']) ?></b><div class="pre"><?= e($s['activity']) ?></div></div>
    <?php endforeach ?>
  <?php endif ?>
  <?php if ($photos = photos_for('program', (int) $journal['id'])): ?>
    <h3>활동사진 <small class="muted"><?= count($photos) ?>장</small></h3>
    <?php render_gallery($photos) ?>
  <?php endif ?>
    <?php
}

/** 수정 이력 비교용 */
function program_snapshot(array $journal): array
{
    $lines = [];
    foreach (program_load((int) $journal['id'])['sessions'] as $s) {
        $people = [];
        foreach (PROGRAM_AGES as $a => $label) {
            if ($s["m_$a"] || $s["f_$a"]) $people[] = "$label 남{$s["m_$a"]}·여{$s["f_$a"]}";
        }
        $lines[] = "{$s['session_no']}회차 · {$s['group_name']}" . (!empty($s['staff']) ? " · 담당 {$s['staff']}" : '') . (!empty($s['product_name']) ? " · {$s['product_name']}" : '') . (program_time($s) ? ' · ' . program_time($s) : '')
            . ' · ' . implode(', ', $people) . " = {$s['total']}명 · " . (PROGRAM_FEE_TYPES[$s['fee_type']] ?? '') . ($s['amount'] ? ' ' . number_format($s['amount']) . '원' : '')
            . ($s['activity'] ? ' · 활동: ' . preg_replace('/\s+/', ' ', $s['activity']) : '');
    }
    return ['회차' => $lines, '활동사진' => count(photos_for('program', (int) $journal['id'])) . '장'];
}

/* ───────────── 매출보고 '프로그램 판매' 연동 ───────────── */

/**
 * 그 날 프로그램 운영보고의 분야별 합계 (반려·삭제 제외, 임시저장 포함)
 * @return array<string,array{journal_id:int, sessions:int, people:int, paid:int, free:int, amount:int}>  (유료 = 유료 + 할인)
 */
function program_day_summary(string $date): array
{
    $types = array_keys(PROGRAM_TYPES);
    $st = db()->prepare('SELECT j.type, MIN(j.id) AS journal_id, COUNT(p.id) AS sessions, COALESCE(SUM(p.total), 0) AS people,
                                COALESCE(SUM(IF(p.fee_type <> \'free\', p.total, 0)), 0) AS paid, COALESCE(SUM(IF(p.fee_type = \'free\', p.total, 0)), 0) AS free, COALESCE(SUM(p.amount), 0) AS amount
                           FROM journals j LEFT JOIN program_sessions p ON p.journal_id = j.id
                          WHERE j.type IN (' . implode(',', array_fill(0, count($types), '?')) . ") AND j.work_date = ? AND j.status <> 'rejected'
                          GROUP BY j.type");
    $st->execute([...$types, $date]);
    $out = [];
    foreach ($st as $r) $out[$r['type']] = ['journal_id' => (int) $r['journal_id'], 'sessions' => (int) $r['sessions'], 'people' => (int) $r['people'],
        'paid' => (int) $r['paid'], 'free' => (int) $r['free'], 'amount' => (int) $r['amount']];
    return $out;
}

/** 매출보고 판매 줄 하나 (프로그램 판매): qty = 유료 인원(할인 포함), guests = 무료 인원 */
function program_sale_line(string $type, int $sessions, int $paid, int $free, int $amount, bool $auto): array
{
    return ['product_id' => null, 'grp' => 'program', 'name' => PROGRAM_TYPES[$type], 'is_free' => 0, 'rate' => null, 'season' => null, 'discounted' => 0,
        'unit_price' => 0, 'qty' => $paid, 'guests' => $free, 'amount' => $amount, 'prog_type' => $type, 'sessions' => $sessions, 'auto' => (int) $auto];
}

/**
 * 프로그램 운영보고가 저장·삭제·날짜 변경되면 그 날 매출보고(있을 때)의 프로그램 판매를 운영보고 값으로 맞춘다.
 * 기준은 항상 운영보고: 운영보고가 있는 분야는 (직접 입력한 값이 있어도) 운영보고 값으로 바꾸고,
 * 운영보고가 없어진 분야의 자동 줄은 지운다. 바뀐 내용은 매출보고의 작성·수정 기록에 남긴다.
 * $reason: 기록에 남길 이유 (예: '산림치유센터 운영보고 저장 (문서 12)')
 */
function sales_sync_programs(string $date, string $reason = ''): void
{
    $pdo = db();
    $st = $pdo->prepare("SELECT id FROM journals WHERE type = 'sales' AND work_date = ?");
    $st->execute([$date]);
    $jid = (int) $st->fetchColumn();
    if (!$jid) return;
    $fmt = fn(array $l) => (int) $l['sessions'] . '회·유료 ' . number_format((int) $l['qty']) . '·무료 ' . number_format((int) $l['guests']) . '명·' . number_format((int) $l['amount']) . '원';
    $st = $pdo->prepare("SELECT * FROM sales_lines WHERE journal_id = ? AND grp = 'program'");
    $st->execute([$jid]);
    $before = [];
    foreach ($st as $l) $before[$l['prog_type']] = $l;
    $sum = program_day_summary($date);
    $notes = [];
    foreach ($before as $type => $l) {
        if ($l['auto'] && !isset($sum[$type])) { // 운영보고가 없어짐 → 자동 줄 제거
            $pdo->prepare('DELETE FROM sales_lines WHERE id = ?')->execute([$l['id']]);
            $notes[] = (PROGRAM_TYPES[$type] ?? $type) . ' 자동 ' . $fmt($l) . ' → 제거 (운영보고 없음)';
        }
    }
    foreach ($sum as $type => $a) {
        $new = ['sessions' => $a['sessions'], 'qty' => $a['paid'], 'guests' => $a['free'], 'amount' => $a['amount']];
        $old = $before[$type] ?? null;
        if ($old && $old['auto'] && $fmt($old) === $fmt($new)) continue; // 그대로
        $pdo->prepare("DELETE FROM sales_lines WHERE journal_id = ? AND grp = 'program' AND prog_type = ?")->execute([$jid, $type]);
        $pdo->prepare("INSERT INTO sales_lines (journal_id, product_id, grp, name, is_free, unit_price, qty, guests, amount, prog_type, sessions, auto) VALUES (?, NULL, 'program', ?, 0, 0, ?, ?, ?, ?, ?, 1)")
            ->execute([$jid, PROGRAM_TYPES[$type], $a['paid'], $a['free'], $a['amount'], $type, $a['sessions']]);
        $notes[] = PROGRAM_TYPES[$type] . ' ' . ($old ? ($old['auto'] ? '자동 ' : '직접 입력 ') . $fmt($old) : '없음') . ' → 운영보고 ' . $fmt($new);
    }
    if ($notes) journal_log($jid, current_user(), '프로그램 판매 자동 갱신', ($reason !== '' ? "[$reason] " : '') . implode(' / ', $notes));
}
