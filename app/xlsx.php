<?php
defined('APP_ROOT') || exit;

/**
 * 엑셀(.xlsx) 파일 만들기 — 외부 라이브러리 없이 PHP zip 확장만 사용.
 * zip 확장이 없는 호스팅이면 CSV(엑셀에서 열림)로 대신 내려준다.
 *
 * $sheets = [[
 *   'name'   => '시트이름',
 *   'title'  => '제목', 'subtitle' => '기간·출력일 등',
 *   'header' => ['열1', '열2', ...],
 *   'rows'   => [[값, ...], ...],          // 숫자는 숫자 서식(#,##0)으로 저장
 *   'footer' => [[값, ...], ...],          // 합계 행 (굵게)
 *   'widths' => [12, 30, ...],             // 열 너비 (선택)
 * ], ...]
 */
function xlsx_send(string $filename, array $sheets): never
{
    if (!class_exists('ZipArchive')) {
        csv_send(preg_replace('/\.xlsx$/', '.csv', $filename), $sheets[0]);
    }

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);

    $sheetXml = $rels = $ctypes = '';
    foreach (array_values($sheets) as $i => $sheet) {
        $n = $i + 1;
        $name = mb_substr(preg_replace('/[\[\]:*?\/\\\\]/u', ' ', $sheet['name']), 0, 31);
        $sheetXml .= '<sheet name="' . xlsx_esc($name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $ctypes .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $zip->addFromString("xl/worksheets/sheet$n.xml", xlsx_sheet($sheet));
    }
    $n = count($sheets) + 1;
    $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . $ctypes . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $sheetXml . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
    $zip->addFromString('xl/styles.xml', xlsx_styles());
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    unlink($tmp);
    exit;
}

function xlsx_esc(mixed $s): string
{
    // XML에 쓸 수 없는 제어문자 제거
    return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsx_col(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

// 스타일 번호: 0 기본, 1 머리글, 2 글자칸, 3 숫자칸, 4 합계 글자, 5 합계 숫자, 6 제목
function xlsx_sheet(array $sheet): string
{
    $rows = [];
    $r = 0;
    $cell = function (int $c, mixed $v, int $textStyle, int $numStyle) use (&$r): string {
        $ref = xlsx_col($c) . $r;
        if ($v === null || $v === '') return '<c r="' . $ref . '" s="' . $textStyle . '"/>';
        if (is_int($v) || is_float($v)) return '<c r="' . $ref . '" s="' . $numStyle . '"><v>' . $v . '</v></c>';
        return '<c r="' . $ref . '" s="' . $textStyle . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_esc($v) . '</t></is></c>';
    };
    $line = function (array $values, int $textStyle, int $numStyle) use (&$r, &$rows, $cell): void {
        $r++;
        $cells = '';
        foreach (array_values($values) as $c => $v) $cells .= $cell($c, $v, $textStyle, $numStyle);
        $rows[] = '<row r="' . $r . '">' . $cells . '</row>';
    };

    if (!empty($sheet['title'])) $line([$sheet['title']], 6, 6);
    if (!empty($sheet['subtitle'])) $line([$sheet['subtitle']], 0, 0);
    if (!empty($sheet['title']) || !empty($sheet['subtitle'])) $line([], 0, 0);
    $headerRow = $r + 1;
    $line($sheet['header'], 1, 1);
    foreach ($sheet['rows'] as $row) $line($row, 2, 3);
    foreach ($sheet['footer'] ?? [] as $row) $line($row, 4, 5);

    $cols = '';
    foreach ($sheet['widths'] ?? [] as $i => $w) {
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) $w . '" customWidth="1"/>';
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $headerRow . '" topLeftCell="A' . ($headerRow + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . ($cols ? "<cols>$cols</cols>" : '')
        . '<sheetData>' . implode('', $rows) . '</sheetData>'
        . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>'
        . '</worksheet>';
}

function xlsx_styles(): string
{
    $border = '<border><left style="thin"><color rgb="FFBBBBBB"/></left><right style="thin"><color rgb="FFBBBBBB"/></right>'
            . '<top style="thin"><color rgb="FFBBBBBB"/></top><bottom style="thin"><color rgb="FFBBBBBB"/></bottom><diagonal/></border>';
    $font = fn(string $extra, int $size) => "<font>$extra<sz val=\"$size\"/><name val=\"맑은 고딕\"/><family val=\"2\"/></font>";
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts>'
        . '<fonts count="3">' . $font('', 10) . $font('<b/>', 10) . $font('<b/>', 14) . '</fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE9F4EC"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>' . $border . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="7">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment vertical="top"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="164" fontId="1" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/** zip 확장이 없을 때: 엑셀에서 한글이 깨지지 않도록 BOM을 붙인 CSV */
function csv_send(string $filename, array $sheet): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if (!empty($sheet['title'])) fputcsv($out, [$sheet['title']]);
    if (!empty($sheet['subtitle'])) fputcsv($out, [$sheet['subtitle']]);
    fputcsv($out, $sheet['header']);
    foreach ([...$sheet['rows'], ...($sheet['footer'] ?? [])] as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}
