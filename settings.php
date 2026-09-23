<?php
/**
 * 설정 (최고관리자): settings.php?tab=general (기본 정보) | org (조직 구성: 팀·반)
 * 다른 하위 메뉴(회원관리, 상품·요금, 시설 구역, 장비 분류)는 각 페이지에서 같은 설정 메뉴를 보여준다.
 */
require __DIR__ . '/app/bootstrap.php';

$me = require_admin();
$pdo = db();
$tab = ($_GET['tab'] ?? 'general') === 'org' ? 'org' : 'general';

if (is_post()) {
    csrf_verify();
    $target = post('target');
    $id = (int) post('id');
    $name = mb_substr(post('name'), 0, 50);
    $sort = (int) post('sort_order', '0');
    $del = post('action') === 'delete';
    $count = function (string $sql, int $id) use ($pdo): int {
        $st = $pdo->prepare($sql);
        $st->execute([$id]);
        return (int) $st->fetchColumn();
    };

    if ($target === 'general') {
        setting_set('org_top_name', mb_substr(post('org_top_name'), 0, 100));
        setting_set('org_park_name', mb_substr(post('org_park_name'), 0, 100));
        setting_set('program_fee', (string) to_int(post('program_fee')));
        flash('기본 정보를 저장했습니다.', 'success');
        redirect('settings.php?tab=general');
    }

    if ($target === 'team') {
        if ($del) {
            $used = $count('SELECT COUNT(*) FROM asset_groups WHERE team_id = ?', $id) + $count('SELECT COUNT(*) FROM squads WHERE team_id = ?', $id)
                  + $count('SELECT COUNT(*) FROM users WHERE team_id = ?', $id);
            if ($used) flash('반·직원·시설 구역·장비 분류가 연결된 팀은 삭제할 수 없습니다. 먼저 옮기거나 지워 주세요.', 'error');
            else { $pdo->prepare('DELETE FROM teams WHERE id = ?')->execute([$id]); flash('팀을 삭제했습니다.', 'success'); }
        } elseif ($name === '') {
            flash('팀 이름을 입력하세요.', 'error');
        } elseif ($id) {
            $pdo->prepare('UPDATE teams SET name = ?, sort_order = ? WHERE id = ?')->execute([$name, $sort, $id]);
            flash("'{$name}' 저장했습니다.", 'success');
        } else {
            $pdo->prepare('INSERT INTO teams (name, sort_order) VALUES (?, ?)')->execute([$name, $sort]);
            flash("팀 '{$name}'을(를) 추가했습니다.", 'success');
        }
        redirect('settings.php?tab=org');
    }

    if ($target === 'squad') {
        $teamId = (int) post('team_id');
        if ($del) {
            if ($count('SELECT COUNT(*) FROM users WHERE squad_id = ?', $id)) flash('반원이 있는 반은 삭제할 수 없습니다. 회원관리에서 먼저 다른 반으로 옮겨 주세요.', 'error');
            else { $pdo->prepare('DELETE FROM squads WHERE id = ?')->execute([$id]); flash('반을 삭제했습니다.', 'success'); }
        } elseif ($name === '' || !isset(teams_all()[$teamId])) {
            flash('반 이름과 팀을 확인하세요.', 'error');
        } elseif ($id) {
            $pdo->prepare('UPDATE squads SET team_id = ?, name = ?, sort_order = ? WHERE id = ?')->execute([$teamId, $name, $sort, $id]);
            // 반을 다른 팀으로 옮기면 반원의 팀도 함께 바꾼다
            $pdo->prepare('UPDATE users SET team_id = ? WHERE squad_id = ?')->execute([$teamId, $id]);
            flash("'{$name}' 저장했습니다.", 'success');
        } else {
            $pdo->prepare('INSERT INTO squads (team_id, name, sort_order) VALUES (?, ?, ?)')->execute([$teamId, $name, $sort]);
            flash("반 '{$name}'을(를) 추가했습니다.", 'success');
        }
        redirect('settings.php?tab=org#team' . $teamId);
    }
    redirect('settings.php');
}

layout_header('설정', 'settings');
settings_nav($tab);

if ($tab === 'general'): ?>
<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="target" value="general">
  <h1>기본 정보</h1>
  <p class="muted small">조직도 맨 위에 표시되는 이름입니다.</p>
  <div class="row">
    <label>상위 부서 (팀장·주무관 소속)<input name="org_top_name" value="<?= e(setting('org_top_name', '')) ?>" placeholder="예: 양평군청 산림과 산림휴양팀"></label>
    <label>사업장 (팀들이 속한 곳)<input name="org_park_name" value="<?= e(setting('org_park_name', '')) ?>" placeholder="예: 양평쉬자파크"></label>
  </div>
  <div class="row">
    <label>프로그램 1인 참가비 (유료, 원)<input name="program_fee" value="<?= e(number_format(program_fee())) ?>" class="num" inputmode="numeric" data-money></label>
    <p class="muted small">산림치유센터·유아숲체험원·숲해설 운영보고에서 '유료' 회차의 금액 = 인원 합계 × 이 금액. 바꿔도 이미 작성된 보고서 금액은 그대로입니다.</p>
  </div>
  <p class="muted small">사이트 이름(상단 제목)은 서버의 <code>app/config.php</code> 의 <code>site_name</code> 에서 바꿉니다.</p>
  <div class="actions"><button class="btn primary">저장</button></div>
</form>

<section class="card">
  <h2>설정 메뉴 안내</h2>
  <ul class="list">
    <li><a href="<?= e(url('settings.php?tab=org')) ?>">조직 구성</a><span class="muted">팀과 팀 아래 반을 만들고 이름·순서를 정합니다</span></li>
    <li><a href="<?= e(url('admin/users.php')) ?>">회원관리</a><span class="muted">가입 승인, 직급·팀·반·반장·보직 지정, 전결권한</span></li>
    <li><a href="<?= e(url('admin/products.php')) ?>">상품·요금</a><span class="muted">입장권·객실 상품, 객실 할인율, 기간요금(성수기·동절기)</span></li>
    <li><a href="<?= e(url('groups.php?kind=facility')) ?>">시설 구역·건물</a><span class="muted">팀별 시설 구역·건물 (세부시설은 시설물 메뉴에서 등록)</span></li>
    <li><a href="<?= e(url('groups.php?kind=equipment')) ?>">장비 분류</a><span class="muted">팀별 장비 분류 (장비는 장비 메뉴에서 등록)</span></li>
  </ul>
</section>
<?php else:
    $teamCounts = array_column($pdo->query("SELECT team_id, COUNT(*) AS n FROM users WHERE status = 'active' GROUP BY team_id")->fetchAll(), 'n', 'team_id');
    $squadMembers = [];
    foreach ($pdo->query("SELECT id, name, squad_id, is_squad_leader FROM users WHERE status = 'active' AND squad_id IS NOT NULL ORDER BY is_squad_leader DESC, name") as $u) {
        $squadMembers[(int) $u['squad_id']][] = $u;
    }
    $row = function (string $target, ?array $r, int $teamId, int $sort, string $placeholder, string $info) {
        $fid = $target[0] . ($r['id'] ?? 'new' . $teamId); ?>
      <tr class="<?= $r ? '' : 'new-row' ?>">
        <td><input form="<?= $fid ?>" name="sort_order" value="<?= e($r['sort_order'] ?? $sort) ?>" class="num tiny"></td>
        <td><input form="<?= $fid ?>" name="name" value="<?= e($r['name'] ?? '') ?>" placeholder="<?= e($placeholder) ?>" required></td>
        <?php if ($target === 'squad'): ?>
          <td><select form="<?= $fid ?>" name="team_id"><?php foreach (teams_all() as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $t['id'] === $teamId ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach ?></select></td>
        <?php endif ?>
        <td class="small"><?= $info ?></td>
        <td class="nowrap">
          <form method="post" id="<?= $fid ?>">
            <?= csrf_field() ?><input type="hidden" name="target" value="<?= $target ?>"><input type="hidden" name="id" value="<?= (int) ($r['id'] ?? 0) ?>">
            <button class="btn small primary" name="action" value="save"><?= $r ? '저장' : '추가' ?></button>
            <?php if ($r): ?><button class="btn small ghost danger" name="action" value="delete" formnovalidate onclick="return confirm('삭제할까요?')">삭제</button><?php endif ?>
          </form>
        </td>
      </tr>
    <?php }; ?>
<section class="card">
  <div class="card-head">
    <h1>조직 구성</h1>
    <a class="btn ghost" href="<?= e(url('org.php')) ?>">조직도 보기 ›</a>
  </div>
  <div class="org-structure muted small">
    <b><?= e(setting('org_top_name', '상위 부서')) ?></b> (팀장·주무관)
    → <b><?= e(setting('org_park_name', '사업장')) ?></b>
    → 팀 (맨 위에 공무직) → 반 (맨 위에 반장, 그 아래 반원)
  </div>
  <p class="muted small">직원을 팀·반에 배치하고 반장을 지정하는 것은 <a href="<?= e(url('admin/users.php')) ?>">회원관리</a>에서 합니다.
    팀은 시설물·장비의 관리팀으로도 쓰입니다.</p>

  <h2>팀</h2>
  <table class="table product-table">
    <thead><tr><th>순서</th><th>팀 이름</th><th>현황</th><th></th></tr></thead>
    <tbody>
    <?php foreach (teams_all() as $tid => $t) {
        $n = count(array_filter(squads_all(), fn($s) => (int) $s['team_id'] === $tid));
        $row('team', $t, $tid, 0, '', '직원 ' . (int) ($teamCounts[$tid] ?? 0) . '명 · 반 ' . $n . '개');
    }
    $row('team', null, 0, (count(teams_all()) + 1) * 10, '새 팀 (예: 휴양림팀)', ''); ?>
    </tbody>
  </table>

  <?php foreach (teams_all() as $tid => $t):
      $squads = array_filter(squads_all(), fn($s) => (int) $s['team_id'] === $tid); ?>
    <h2 class="team-head" id="team<?= $tid ?>"><?= e($t['name']) ?> · 반</h2>
    <table class="table product-table">
      <thead><tr><th>순서</th><th>반 이름</th><th>팀</th><th>반장 · 반원</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($squads as $sid => $s) {
          $ms = $squadMembers[$sid] ?? [];
          $info = $ms ? implode(', ', array_map(fn($m) => e($m['name']) . ($m['is_squad_leader'] ? ' <b class="leader-tag">반장</b>' : ''), $ms)) : '<span class="muted">반원 없음</span>';
          $row('squad', $s, $tid, 0, '', $info);
      }
      $row('squad', null, $tid, (count($squads) + 1) * 10, '새 반 (예: 숙박반)', ''); ?>
      </tbody>
    </table>
  <?php endforeach ?>
</section>
<?php endif;
layout_footer();
