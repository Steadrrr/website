<?php
defined('APP_ROOT') || exit;

/*
 * 일일업무일지(운영관리 › 업무일지): 하루 1건, 업무내용을 시간대(오전·오후·야간·심야)로 나눠 사람마다 따로 적는다.
 *   '업무내용추가'를 누르면 로그인한 사람의 작성 창이 뜨고, 다른 사람이 적은 내용은 고칠 수 없는 글로 보인다.
 *   journals.content 는 시간대별 내용을 모아 자동으로 만든다 (목록·수정 이력·검색에 그대로 쓰임).
 *   이 기능 전에 쓴 일지(시간대 내용 없이 업무내용만 있는 문서)는 예전처럼 업무내용 한 칸으로 보이고 고친다.
 */

const DAILY_SLOTS = ['am' => ['오전', '08~12시'], 'pm' => ['오후', '12~18시'], 'eve' => ['야간', '18~22시'], 'night' => ['심야', '22~08시']];

/** 시간대별 업무내용 [slot => [['id','user_id','user_name','content','created_at','updated_at'], ...]] */
function daily_entries(int $journalId): array
{
    $out = array_fill_keys(array_keys(DAILY_SLOTS), []);
    if (!$journalId) return $out;
    $st = db()->prepare('SELECT d.*, u.name AS user_name FROM daily_entries d LEFT JOIN users u ON u.id = d.user_id WHERE d.journal_id = ? ORDER BY d.id');
    $st->execute([$journalId]);
    foreach ($st as $r) if (isset($out[$r['slot']])) $out[$r['slot']][] = $r;
    return $out;
}

/** 예전 방식(업무내용 한 칸) 문서: 시간대 내용이 없고 업무내용이 있는 문서 */
function daily_is_legacy(?array $journal): bool
{
    if (!$journal || $journal['type'] !== 'daily' || trim((string) $journal['content']) === '') return false;
    $st = db()->prepare('SELECT COUNT(*) FROM daily_entries WHERE journal_id = ?');
    $st->execute([(int) $journal['id']]);
    return !(int) $st->fetchColumn();
}

/** 그 날의 일일업무일지 (하루 1건, 반려 포함 — 있으면 그 문서를 고친다) */
function daily_find_by_date(string $date, int $exceptId = 0): ?array
{
    $st = db()->prepare("SELECT id FROM journals WHERE type = 'daily' AND work_date = ? AND id <> ? ORDER BY id LIMIT 1");
    $st->execute([$date, $exceptId]);
    $id = (int) $st->fetchColumn();
    return $id ? journal_find($id) : null;
}

/**
 * 폼에서 보낸 내 업무내용: mine[id] = 고친 기존 내용, new[slot][] = 새로 추가한 내용
 * @return array{mine: array<int,string>, new: array<string,string[]>}
 */
function daily_parse_post(): array
{
    $mine = [];
    foreach ((array) ($_POST['mine'] ?? []) as $id => $text) {
        $text = trim((string) $text);
        if ((int) $id > 0 && $text !== '') $mine[(int) $id] = mb_substr($text, 0, 5000);
    }
    $new = [];
    foreach (DAILY_SLOTS as $slot => $_) {
        foreach ((array) ($_POST['new'][$slot] ?? []) as $text) {
            $text = trim((string) $text);
            if ($text !== '') $new[$slot][] = mb_substr($text, 0, 5000);
        }
    }
    return ['mine' => $mine, 'new' => $new];
}

/** 저장 후 남을 업무내용 수 (다른 사람 것 + 내 것) — 하나도 없으면 저장 안 함 */
function daily_count_after(int $journalId, array $user, array $posted): int
{
    $n = 0;
    foreach (daily_entries($journalId) as $rows) foreach ($rows as $r) {
        if ((int) $r['user_id'] !== (int) $user['id']) $n++;
        elseif (isset($posted['mine'][(int) $r['id']])) $n++;
    }
    return $n + array_sum(array_map('count', $posted['new']));
}

/** 내 업무내용만 저장 (다른 사람 것은 그대로), journals.content 를 다시 만든다 */
function daily_save(int $journalId, array $user, array $posted): void
{
    $pdo = db();
    $uid = (int) $user['id'];
    foreach (daily_entries($journalId) as $rows) foreach ($rows as $r) {
        if ((int) $r['user_id'] !== $uid) continue; // 다른 사람이 쓴 내용은 손대지 않음
        $id = (int) $r['id'];
        if (!isset($posted['mine'][$id])) $pdo->prepare('DELETE FROM daily_entries WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
        elseif ($posted['mine'][$id] !== $r['content']) $pdo->prepare('UPDATE daily_entries SET content = ? WHERE id = ? AND user_id = ?')->execute([$posted['mine'][$id], $id, $uid]);
    }
    $ins = $pdo->prepare('INSERT INTO daily_entries (journal_id, slot, user_id, content) VALUES (?, ?, ?, ?)');
    foreach ($posted['new'] as $slot => $texts) foreach ($texts as $text) $ins->execute([$journalId, $slot, $uid, $text]);
    $pdo->prepare('UPDATE journals SET content = ? WHERE id = ?')->execute([daily_compose($journalId), $journalId]);
}

/** 시간대별 내용을 한 글로 (journals.content) */
function daily_compose(int $journalId): string
{
    $parts = [];
    foreach (daily_entries($journalId) as $slot => $rows) {
        if (!$rows) continue;
        [$name, $hours] = DAILY_SLOTS[$slot];
        $lines = ["[$name $hours]"];
        foreach ($rows as $r) $lines[] = '- ' . ($r['user_name'] ?? '?') . ': ' . str_replace("\n", "\n  ", trim($r['content']));
        $parts[] = implode("\n", $lines);
    }
    return implode("\n\n", $parts);
}

/** 작성 화면: 시간대 4칸 — 다른 사람 내용은 글로, 내 내용은 고칠 수 있게, '업무내용추가'로 작성 창 */
function daily_form(?array $journal, array $user): void
{
    $entries = daily_entries((int) ($journal['id'] ?? 0));
    $uid = (int) $user['id'];
    $posted = is_post() ? daily_parse_post() : null; // 저장이 막혀 다시 보일 때는 보낸 값을 그대로
    ?>
<div class="daily-slots" data-daily-slots>
  <h3>업무내용 <small class="muted">시간대마다 '업무내용추가'로 각자 적습니다. 다른 사람이 적은 내용은 고칠 수 없습니다.</small></h3>
  <?php foreach (DAILY_SLOTS as $slot => [$name, $hours]): ?>
  <section class="daily-slot" data-slot="<?= $slot ?>">
    <div class="daily-slot-head">
      <h4><?= e($name) ?> <small class="muted"><?= e($hours) ?></small></h4>
      <button type="button" class="btn small" data-add-entry="<?= $slot ?>" data-slot-label="<?= e("$name ($hours)") ?>">+ 업무내용추가</button>
    </div>
    <div class="daily-entries">
      <?php foreach ($entries[$slot] as $r): $id = (int) $r['id']; ?>
        <?php if ((int) $r['user_id'] !== $uid): ?>
          <div class="daily-entry other"><div class="who"><b><?= e((string) $r['user_name']) ?></b> <small class="muted"><?= e(substr((string) ($r['updated_at'] ?? $r['created_at']), 5, 11)) ?></small></div><div class="pre"><?= e($r['content']) ?></div></div>
        <?php elseif (!$posted || isset($posted['mine'][$id])): ?>
          <div class="daily-entry mine"><div class="who"><b><?= e($user['name']) ?></b> <small class="muted">내 내용</small> <button type="button" class="btn small ghost" data-del-entry>삭제</button></div>
            <textarea name="mine[<?= $id ?>]" rows="3"><?= e($posted['mine'][$id] ?? $r['content']) ?></textarea></div>
        <?php endif ?>
      <?php endforeach ?>
      <?php foreach ($posted['new'][$slot] ?? [] as $text): ?>
        <div class="daily-entry mine new"><div class="who"><b><?= e($user['name']) ?></b> <small class="muted">새 내용 (저장 전)</small> <button type="button" class="btn small ghost" data-del-entry>삭제</button></div>
          <textarea name="new[<?= $slot ?>][]" rows="3"><?= e($text) ?></textarea></div>
      <?php endforeach ?>
      <p class="muted small daily-empty">적은 내용이 없습니다.</p>
    </div>
  </section>
  <?php endforeach ?>
</div>
<dialog class="daily-dialog" data-entry-dialog>
  <!-- 작성 화면의 큰 form 안이라 form 을 겹치지 않고 버튼으로 닫는다 -->
  <h3>업무내용추가 · <span data-dialog-slot></span></h3>
  <p class="small muted">작성자: <b><?= e($user['name']) ?></b> — 추가한 뒤 아래 '저장'을 눌러야 일지에 남습니다.</p>
  <textarea rows="7" data-dialog-text placeholder="- 09:00 입장객 안내&#10;- 10:30 산책로 순찰"></textarea>
  <div class="actions"><button type="button" class="btn ghost" data-dialog-cancel>취소</button><button type="button" class="btn primary" data-dialog-ok>추가</button></div>
</dialog>
<script>
(function () {
  const root = document.querySelector('[data-daily-slots]');
  const dlg = document.querySelector('[data-entry-dialog]');
  if (!root || !dlg) return;
  const me = <?= json_encode($user['name'], JSON_UNESCAPED_UNICODE) ?>;
  const esc = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const refresh = () => root.querySelectorAll('.daily-slot').forEach((s) => { s.querySelector('.daily-empty').hidden = !!s.querySelector('.daily-entry'); });
  let slot = null;
  root.addEventListener('click', (ev) => {
    const add = ev.target.closest('[data-add-entry]');
    if (add) {
      slot = add.dataset.addEntry;
      dlg.querySelector('[data-dialog-slot]').textContent = add.dataset.slotLabel;
      dlg.querySelector('[data-dialog-text]').value = '';
      dlg.returnValue = '';
      dlg.showModal ? dlg.showModal() : dlg.setAttribute('open', '');
      dlg.querySelector('[data-dialog-text]').focus();
      return;
    }
    const del = ev.target.closest('[data-del-entry]');
    if (del && confirm('이 내용을 지울까요? (저장해야 반영됩니다)')) { del.closest('.daily-entry').remove(); refresh(); }
  });
  const close = (v) => (dlg.close ? dlg.close(v) : (dlg.removeAttribute('open'), dlg.dispatchEvent(new Event('close'))));
  dlg.querySelector('[data-dialog-cancel]').addEventListener('click', () => close('cancel'));
  dlg.querySelector('[data-dialog-ok]').addEventListener('click', () => close('ok'));
  dlg.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) close('ok'); });
  dlg.addEventListener('close', () => {
    const text = dlg.querySelector('[data-dialog-text]').value.trim();
    if (dlg.returnValue !== 'ok' || !text || !slot) return;
    const box = document.createElement('div');
    box.className = 'daily-entry mine new';
    box.innerHTML = '<div class="who"><b>' + esc(me) + '</b> <small class="muted">새 내용 (저장 전)</small> <button type="button" class="btn small ghost" data-del-entry>삭제</button></div>'
      + '<textarea name="new[' + slot + '][]" rows="3"></textarea>';
    box.querySelector('textarea').value = text;
    root.querySelector('.daily-slot[data-slot="' + slot + '"] .daily-entries').insertBefore(box, root.querySelector('.daily-slot[data-slot="' + slot + '"] .daily-empty'));
    refresh();
  });
  refresh();
})();
</script>
    <?php
}

/** 보기 화면: 시간대별 업무내용 */
function daily_view(int $journalId): void
{
    $entries = daily_entries($journalId);
    ?>
<h3>업무내용</h3>
<div class="daily-view">
  <?php foreach (DAILY_SLOTS as $slot => [$name, $hours]): ?>
  <div class="daily-view-slot">
    <h4><?= e($name) ?> <small class="muted"><?= e($hours) ?></small></h4>
    <?php foreach ($entries[$slot] as $r): ?>
      <div class="daily-entry other"><div class="who"><b><?= e((string) $r['user_name']) ?></b></div><div class="pre"><?= e($r['content']) ?></div></div>
    <?php endforeach ?>
    <?php if (!$entries[$slot]): ?><p class="muted small">-</p><?php endif ?>
  </div>
  <?php endforeach ?>
</div>
    <?php
}
