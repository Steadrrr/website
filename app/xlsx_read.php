<?php
defined('APP_ROOT') || exit;

/**
 * 엑셀(.xlsx) 첫 번째 시트 읽기 — 외부 라이브러리 없이 PHP zip 확장만 사용. CSV(UTF-8/EUC-KR)도 읽는다.
 * @return array<int, array<int, string|int|float|null>> 행 목록 (첫 행 = 머리글), 셀은 열 순서대로
 * @throws RuntimeException 읽을 수 없는 파일
 */
function sheet_read(string $path, string $name = ''): array
{
    if (preg_match('/\.csv$/i', $name) || !class_exists('ZipArchive')) return csv_read($path);
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('엑셀(.xlsx) 파일을 열 수 없습니다. 엑셀에서 "다른 이름으로 저장 › Excel 통합 문서(.xlsx)"로 저장해 올려 주세요.');
    $strings = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = simplexml_load_string($xml);
        foreach ($sx->si as $si) {
            if (isset($si->t)) $strings[] = (string) $si->t;
            else { $t = ''; foreach ($si->r as $r) $t .= (string) $r->t; $strings[] = $t; }
        }
    }
    // 첫 번째 시트 파일 찾기 (workbook.xml 의 첫 sheet → rels)
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb && $rels) {
        $w = simplexml_load_string($wb);
        $first = $w->sheets->sheet[0] ?? null;
        $rid = $first ? (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : '';
        foreach (simplexml_load_string($rels)->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) { $t = ltrim((string) $rel['Target'], '/'); $sheetPath = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t; }
        }
    }
    $xml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($xml === false) throw new RuntimeException('엑셀 파일에서 시트를 찾지 못했습니다.');
    $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_COMPACT | LIBXML_PARSEHUGE);
    $rows = [];
    foreach ($sx->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $col = xlsx_col_index(preg_replace('/\d+/', '', (string) $c['r']));
            $t = (string) $c['t'];
            $v = match ($t) {
                's'         => $strings[(int) $c->v] ?? '',
                'inlineStr' => (string) ($c->is->t ?? ''),
                'b'         => (int) $c->v,
                'str'       => (string) $c->v,
                default     => isset($c->v) ? (is_numeric((string) $c->v) ? (float) $c->v : (string) $c->v) : null,
            };
            $cells[$col] = $v;
        }
        if (!$cells) continue;
        $out = [];
        for ($i = 0; $i <= max(array_keys($cells)); $i++) $out[] = $cells[$i] ?? null;
        $rows[] = $out;
    }
    return $rows;
}

/** "A" → 0, "AB" → 27 */
function xlsx_col_index(string $letters): int
{
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}

function csv_read(string $path): array
{
    $raw = (string) file_get_contents($path);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'CP949');
    $rows = [];
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) if ($r !== [null]) $rows[] = $r;
    fclose($fh);
    return $rows;
}

/** 엑셀 날짜 셀 → Y-m-d (문자열 2026-01-02, 2026.1.2, 2026/01/02 또는 엑셀 일련번호) */
function sheet_date(mixed $v): ?string
{
    if (is_float($v) || is_int($v) || (is_string($v) && preg_match('/^\d{5}(\.\d+)?$/', $v))) {
        $n = (int) floor((float) $v);
        return $n > 20000 && $n < 80000 ? date('Y-m-d', strtotime('1899-12-30 +' . $n . ' days')) : null;
    }
    if (!is_string($v)) return null;
    if (preg_match('/^(\d{4})[-.\/년 ]\s*(\d{1,2})[-.\/월 ]\s*(\d{1,2})/u', trim($v), $m)) {
        $d = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        return valid_date($d) ? $d : null;
    }
    return null;
}
