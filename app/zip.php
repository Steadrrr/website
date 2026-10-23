<?php
defined('APP_ROOT') || exit;

/**
 * 여러 파일을 zip 하나로 내려받기 — PHP zip 확장 없이 직접 만든다 (호스팅에 확장이 없어도 되게).
 * 사진은 이미 압축된 파일이라 압축하지 않고 그대로 담는다(stored). 임시 파일 없이 바로 보낸다.
 * $files = [['name' => 'zip 안 이름', 'path' => '실제 파일 경로', 'time' => 유닉스시간(선택)], ...]
 */
function zip_send(string $filename, array $files): never
{
    $entries = [];
    $offset = 0;
    foreach ($files as $f) {
        if (!is_file($f['path'])) continue;
        $name = (string) $f['name'];
        $size = filesize($f['path']);
        $t = getdate($f['time'] ?? filemtime($f['path']));
        $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
        $dosDate = (max(0, $t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
        $crc = (int) hexdec(hash_file('crc32b', $f['path']));
        // 0x0800 = 파일 이름이 UTF-8 (한글 이름이 윈도우·맥에서 깨지지 않게)
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0) . $name;
        $entries[] = ['local' => $local, 'path' => $f['path'], 'name' => $name, 'size' => $size, 'crc' => $crc, 'time' => $dosTime, 'date' => $dosDate, 'offset' => $offset];
        $offset += strlen($local) + $size;
    }
    $central = '';
    foreach ($entries as $e) {
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $e['time'], $e['date'], $e['crc'], $e['size'], $e['size'],
                         strlen($e['name']), 0, 0, 0, 0, 0, $e['offset']) . $e['name'];
    }
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($central), $offset, 0);

    while (ob_get_level()) ob_end_clean();
    @set_time_limit(0);
    header('Content-Type: application/zip');
    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename); // 한글 이름을 못 읽는 프로그램용 대체 이름
    header('Content-Disposition: attachment; filename="' . $ascii . "\"; filename*=UTF-8''" . rawurlencode($filename));
    header('Content-Length: ' . ($offset + strlen($central) + strlen($end)));
    header('Cache-Control: no-store');
    foreach ($entries as $e) {
        echo $e['local'];
        readfile($e['path']);
    }
    echo $central, $end;
    exit;
}

/** zip 안 파일 이름에 쓸 수 없는 글자를 뺀다 */
function zip_safe_name(string $s): string
{
    $s = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/u', ' ', $s));
    return mb_substr(preg_replace('/\s+/u', ' ', $s), 0, 60) ?: '이름없음';
}
