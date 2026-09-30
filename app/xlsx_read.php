<?php
defined('APP_ROOT') || exit;

/**
 * 엑셀(.xlsx) 첫 번째 시트 읽기 — 외부 라이브러리 없이 PHP zip 확장만 사용. CSV(UTF-8/EUC-KR)도 읽는다.
 * @return array<int, array<int, string|int|float|null>> 행 목록 (첫 행 = 머리글), 셀은 열 순서대로
 * @throws RuntimeException 읽을 수 없는 파일
 */
function sheet_read(string $path, string $name = ''): array
{
    $head = (string) file_get_contents($path, false, null, 0, 8);
    if ($head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") return xls_read($path); // 예전 엑셀(.xls, 산림청 통합운영시스템 등)
    if (str_starts_with(ltrim($head), '<')) throw new RuntimeException('이 파일은 엑셀 형식이 아닙니다 (웹 페이지를 .xls 로 저장한 파일). 엑셀에서 열어 "다른 이름으로 저장 › Excel 통합 문서(.xlsx)"로 저장해 올려 주세요.');
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

/**
 * 예전 엑셀(.xls, BIFF8) 첫 번째 시트 읽기 — 외부 라이브러리 없이.
 * OLE2 복합 파일에서 Workbook 스트림을 꺼내 문자열표(SST)와 셀(LABELSST·LABEL·NUMBER·RK·MULRK·FORMULA)만 읽는다.
 */
function xls_read(string $path): array
{
    $f = (string) file_get_contents($path);
    $u16 = fn(string $b, int $o) => unpack('v', $b, $o)[1];
    $u32 = fn(string $b, int $o) => unpack('V', $b, $o)[1];
    $ss = 1 << $u16($f, 0x1E);          // 섹터 크기 (보통 512)
    $mss = 1 << $u16($f, 0x20);         // 미니 섹터 크기 (64)
    $sector = fn(int $sid) => substr($f, ($sid + 1) * $ss, $ss);
    // FAT 섹터 목록 (헤더 109개 + DIFAT 사슬)
    $fatSids = [];
    for ($i = 0; $i < 109; $i++) { $v = $u32($f, 0x4C + $i * 4); if ($v < 0xFFFFFFFA) $fatSids[] = $v; }
    for ($d = $u32($f, 0x44), $n = $u32($f, 0x48); $n > 0 && $d < 0xFFFFFFFA; $n--) {
        $blk = $sector($d);
        for ($i = 0; $i < $ss / 4 - 1; $i++) { $v = $u32($blk, $i * 4); if ($v < 0xFFFFFFFA) $fatSids[] = $v; }
        $d = $u32($blk, $ss - 4);
    }
    $fat = [];
    foreach ($fatSids as $sid) foreach (unpack('V*', $sector($sid)) as $v) $fat[] = $v;
    $chain = function (int $start, array $table) {
        $out = [];
        for ($s = $start, $guard = 0; $s < 0xFFFFFFFA && isset($table[$s]) && $guard < 2000000; $s = $table[$s], $guard++) $out[] = $s;
        return $out;
    };
    $read = fn(int $start) => implode('', array_map($sector, $chain($start, $fat)));
    // 디렉터리에서 Workbook 스트림 찾기
    $dir = $read($u32($f, 0x30));
    $root = null; $wb = null;
    for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
        $nlen = $u16($dir, $o + 0x40);
        $ename = $nlen > 2 ? mb_convert_encoding(substr($dir, $o, $nlen - 2), 'UTF-8', 'UTF-16LE') : '';
        $ent = ['type' => ord($dir[$o + 0x42]), 'start' => $u32($dir, $o + 0x74), 'size' => $u32($dir, $o + 0x78)];
        if ($ent['type'] === 5) $root = $ent;
        if (in_array($ename, ['Workbook', 'Book'], true)) $wb = $ent;
    }
    if (!$wb) throw new RuntimeException('엑셀(.xls) 파일에서 시트를 찾지 못했습니다.');
    if ($wb['size'] < $u32($f, 0x38) && $root) { // 작은 스트림은 미니 스트림에 있다
        $minifat = [];
        foreach ($chain($u32($f, 0x3C), $fat) as $sid) foreach (unpack('V*', $sector($sid)) as $v) $minifat[] = $v;
        $ministream = $read($root['start']);
        $data = implode('', array_map(fn($s) => substr($ministream, $s * $mss, $mss), $chain($wb['start'], $minifat)));
    } else {
        $data = $read($wb['start']);
    }
    $data = substr($data, 0, $wb['size']);
    $len = strlen($data);
    // 문자열 읽기 (BIFF8: 글자 수, 옵션(압축·서식·확장), CONTINUE 경계에서는 옵션 바이트가 다시 온다)
    $str = function (string $buf, int &$p, array $bounds = [], bool $short = false) use ($u16, $u32) {
        $cch = $short ? ord($buf[$p++]) : $u16($buf, $p);
        if (!$short) $p += 2;
        $flags = ord($buf[$p++]);
        $rich = $flags & 0x08 ? $u16($buf, $p) : 0; if ($flags & 0x08) $p += 2;
        $ext = $flags & 0x04 ? $u32($buf, $p) : 0; if ($flags & 0x04) $p += 4;
        $high = (bool) ($flags & 0x01);
        $out = '';
        // 글자가 CONTINUE 레코드 첫머리에서 시작하면 거기에 옵션 바이트가 다시 온다
        if ($cch > 0 && in_array($p, $bounds, true)) $high = (bool) (ord($buf[$p++]) & 0x01);
        while ($cch > 0) {
            $next = PHP_INT_MAX;
            foreach ($bounds as $b) if ($b > $p) { $next = $b; break; }
            $avail = intdiv(min($next, strlen($buf)) - $p, $high ? 2 : 1);
            $take = min($cch, $avail);
            $chunk = substr($buf, $p, $take * ($high ? 2 : 1));
            $out .= $high ? mb_convert_encoding($chunk, 'UTF-8', 'UTF-16LE') : mb_convert_encoding($chunk, 'UTF-8', 'ISO-8859-1');
            $p += strlen($chunk);
            $cch -= $take;
            if ($cch > 0) { if ($p >= strlen($buf)) break; $high = (bool) (ord($buf[$p++]) & 0x01); }
        }
        $p += $rich * 4 + $ext;
        return $out;
    };
    $rk = function (int $v): float|int {
        if ($v & 0x02) { $n = $v >> 2; if ($n & 0x20000000) $n -= 0x40000000; }
        else { $n = unpack('e', pack('V', 0) . pack('V', $v & 0xFFFFFFFC))[1]; }
        return $v & 0x01 ? $n / 100 : $n;
    };
    // 1) 워크북 영역: 문자열표 · 첫 시트 위치
    $sst = [];
    $sheetPos = null;
    for ($p = 0; $p + 4 <= $len;) {
        $type = $u16($data, $p); $rl = $u16($data, $p + 2);
        $rec = substr($data, $p + 4, $rl);
        $p += 4 + $rl;
        if ($type === 0x0085 && $sheetPos === null && ord($rec[5] ?? "\0") === 0) $sheetPos = $u32($rec, 0);
        if ($type === 0x00FC) {
            $bounds = [];
            while ($p + 4 <= $len && $u16($data, $p) === 0x003C) { // CONTINUE
                $cl = $u16($data, $p + 2);
                $bounds[] = strlen($rec);
                $rec .= substr($data, $p + 4, $cl);
                $p += 4 + $cl;
            }
            $count = $u32($rec, 4);
            for ($q = 8, $i = 0; $i < $count && $q < strlen($rec); $i++) $sst[] = $str($rec, $q, $bounds);
        }
        if ($type === 0x000A) break; // 워크북 영역 끝
    }
    if ($sheetPos === null) throw new RuntimeException('엑셀(.xls) 파일에서 시트를 찾지 못했습니다.');
    // 2) 첫 시트의 셀
    $cells = [];
    $pendingFormula = null;
    for ($p = $sheetPos; $p + 4 <= $len;) {
        $type = $u16($data, $p); $rl = $u16($data, $p + 2);
        $rec = substr($data, $p + 4, $rl);
        $p += 4 + $rl;
        if ($type === 0x000A) break; // 시트 끝
        if ($rl < 6 && $type !== 0x0207) continue;
        $r = $rl >= 4 ? $u16($rec, 0) : 0; $c = $rl >= 4 ? $u16($rec, 2) : 0;
        switch ($type) {
            case 0x00FD: $cells[$r][$c] = $sst[$u32($rec, 6)] ?? ''; break;                 // LABELSST
            case 0x0204: $q = 6; $cells[$r][$c] = $str($rec, $q); break;                   // LABEL
            case 0x0203: $cells[$r][$c] = unpack('e', $rec, 6)[1]; break;                  // NUMBER
            case 0x027E: $cells[$r][$c] = $rk($u32($rec, 6)); break;                       // RK
            case 0x00BD:                                                                     // MULRK
                for ($q = 4, $cc = $c; $q + 6 <= $rl - 2; $q += 6, $cc++) $cells[$r][$cc] = $rk($u32($rec, $q + 2));
                break;
            case 0x0006:                                                                     // FORMULA
                if (substr($rec, 12, 2) === "\xFF\xFF") { $pendingFormula = [$r, $c]; if (ord($rec[6]) === 1) $cells[$r][$c] = ord($rec[8]); }
                else $cells[$r][$c] = unpack('e', $rec, 6)[1];
                break;
            case 0x0207:                                                                     // STRING (수식 결과)
                if ($pendingFormula) { $q = 0; $cells[$pendingFormula[0]][$pendingFormula[1]] = $str($rec, $q); $pendingFormula = null; }
                break;
            case 0x0205: $cells[$r][$c] = ord($rec[6]); break;                              // BOOLERR
        }
    }
    ksort($cells);
    $rows = [];
    foreach ($cells as $row) {
        $out = [];
        for ($i = 0; $i <= max(array_keys($row)); $i++) {
            $v = $row[$i] ?? null;
            $out[] = is_float($v) && floor($v) === $v && abs($v) < 1e15 ? $v : $v;
        }
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
