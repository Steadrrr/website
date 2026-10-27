<?php
defined('APP_ROOT') || exit;

/*
 * 법정점검 (시설·안전 › 법정점검): 위탁 업체가 하는 법정·정기 점검을 주기마다 했는지 기록
 *  - 점검 목록(legal_checks): 이름 · 주기 · 업체 · 대상 · 근거 (화면에서 더하고 고침, 기본 목록은 migrate v58)
 *  - 기록(legal_check_logs): 한 날짜 · 업체 · 메모 · 업체 일지 파일(선택 — 문서를 안 주는 업체도 있음)
 *  - '이번 기간': 매월·분기·반기·매년은 달력 기간, 2년·3년마다는 오늘부터 거슬러 그 기간
 */

const LEGAL_CYCLES = ['month' => '매월', 'quarter' => '분기', 'half' => '반기', 'year' => '매년', 'year2' => '2년마다', 'year3' => '3년마다'];

/** 그 날이 들어 있는 점검 기간 [시작, 끝, 이름] */
function legal_period(string $cycle, string $date): array
{
    $t = strtotime($date);
    $y = (int) date('Y', $t);
    $m = (int) date('n', $t);
    return match ($cycle) {
        'month' => [date('Y-m-01', $t), date('Y-m-t', $t), date('n월', $t)],
        'quarter' => [sprintf('%d-%02d-01', $y, intdiv($m - 1, 3) * 3 + 1), date('Y-m-t', strtotime(sprintf('%d-%02d-01', $y, intdiv($m - 1, 3) * 3 + 3))), (intdiv($m - 1, 3) + 1) . '분기'],
        'half' => $m <= 6 ? ["$y-01-01", "$y-06-30", '상반기'] : ["$y-07-01", "$y-12-31", '하반기'],
        'year' => ["$y-01-01", "$y-12-31", "{$y}년"],
        'year2', 'year3' => (function () use ($cycle, $date) {
            $n = $cycle === 'year2' ? 2 : 3;
            return [date('Y-m-d', strtotime("$date -$n years +1 day")), $date, "최근 {$n}년"];
        })(),
        default => [$date, $date, ''],
    };
}

/** @return array<int,array> id => 점검 (+ 이번 기간 기록 'done', 마지막 기록 'last') */
function legal_checks(string $date, bool $activeOnly = true): array
{
    $out = [];
    foreach (db()->query('SELECT * FROM legal_checks' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY is_active DESC, sort_order, id') as $c) {
        [$from, $to, $label] = legal_period($c['cycle'], $date);
        $st = db()->prepare('SELECT * FROM legal_check_logs WHERE check_id = ? AND done_date BETWEEN ? AND ? ORDER BY done_date DESC, id DESC LIMIT 1');
        $st->execute([$c['id'], $from, $to]);
        $c['done'] = $st->fetch() ?: null;
        $st = db()->prepare('SELECT * FROM legal_check_logs WHERE check_id = ? ORDER BY done_date DESC, id DESC LIMIT 1');
        $st->execute([$c['id']]);
        $c['last'] = $st->fetch() ?: null;
        $c['period'] = [$from, $to, $label];
        $out[(int) $c['id']] = $c;
    }
    return $out;
}

/** 이번 기간 [한 것, 전체] (사용 중인 점검) */
function legal_summary(string $date): array
{
    $c = legal_checks($date);
    return [count(array_filter($c, fn($x) => $x['done'])), count($c)];
}

/** 업체 일지 파일 저장 (pdf·사진, 20MB 까지) → [경로, 오류] — 파일이 없으면 [null, null] */
function legal_store_file(array $f): array
{
    $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($err !== UPLOAD_ERR_OK) return [null, $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ? '파일이 너무 큽니다.' : "업로드 실패 (오류 $err)"];
    if ((int) $f['size'] > 20 * 1048576) return [null, '파일이 너무 큽니다 (20MB 까지).'];
    $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!isset($allowed[$mime])) return [null, 'PDF나 사진 파일(jpg, png)만 올릴 수 있습니다. 한글(hwp) 문서는 PDF로 저장해서 올려 주세요.'];
    $dirRel = 'uploads/legal/' . date('Ym');
    $dirAbs = APP_ROOT . '/' . $dirRel;
    if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0755, true)) return [null, 'uploads 폴더를 만들 수 없습니다. FTP에서 uploads 폴더 권한을 707로 설정하세요.'];
    $file = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($f['tmp_name'], "$dirAbs/$file")) return [null, '저장 실패. uploads 폴더 쓰기 권한(707)을 확인하세요.'];
    if ($allowed[$mime] !== 'pdf') image_downscale("$dirAbs/$file", 1600, 80);
    return ["$dirRel/$file", null];
}
