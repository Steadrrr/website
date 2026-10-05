<?php
defined('APP_ROOT') || exit;

/*
 * 휴대폰(브라우저) 알림 — Web Push (VAPID)
 * 알림 서버(구글·애플·모질라)에 '알림 있음'만 보내고 내용은 싣지 않는다(암호화 불필요).
 * 알림을 받은 앱(clean-sw.js)이 서버에서 내용을 읽어 띄운다 → Firebase 같은 외부 계정 없이 동작.
 * VAPID 키는 처음 쓸 때 서버에서 만들어 settings(push_vapid_private · push_vapid_public)에 둔다.
 */

function b64u(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** VAPID 키 ['public' => base64url(04||x||y), 'private' => PEM] — 없으면 만든다 */
function webpush_keys(): array
{
    static $keys = null;
    if ($keys) return $keys;
    $pem = setting('push_vapid_private');
    $pub = setting('push_vapid_public');
    if (!$pem || !$pub) {
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$k || !openssl_pkey_export($k, $pem)) throw new RuntimeException('알림 키를 만들지 못했습니다 (서버 openssl 확인).');
        $d = openssl_pkey_get_details($k)['ec'];
        $pub = b64u("\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT));
        setting_set('push_vapid_private', $pem);
        setting_set('push_vapid_public', $pub);
    }
    return $keys = ['public' => $pub, 'private' => $pem];
}

/** openssl 의 DER 서명 → JWT(ES256)용 r||s 64바이트 */
function webpush_der_to_raw(string $der): string
{
    $pos = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0); // SEQUENCE 머리
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = ltrim(substr($der, $pos + 2, $len), "\0");
        $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}

/** 알림 서버에 보낼 VAPID 인증 (서버 주소마다) */
function webpush_auth(string $endpoint): string
{
    static $cache = [];
    $p = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (isset($cache[$aud])) return $cache[$aud];
    $keys = webpush_keys();
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $sub = (string) config('push_subject', 'https://' . preg_replace('/[^a-z0-9.\-:]/i', '', $host)); // 연락처 (mailto: 또는 https 주소)
    $data = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub]));
    if (!openssl_sign($data, $der, $keys['private'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('알림 서명 실패');
    return $cache[$aud] = 'vapid t=' . $data . '.' . b64u(webpush_der_to_raw($der)) . ', k=' . $keys['public'];
}

/** 알림 보내기 (내용 없이). [endpoint => ['code' => 알림 서버 HTTP 코드(0 = 연결 실패), 'err' => 오류 내용]] */
function webpush_send(array $endpoints, bool $ipv4 = false): array
{
    $res = [];
    if (!$endpoints) return $res;
    $headers = fn(string $ep) => ['TTL: 3600', 'Urgency: high', 'Content-Length: 0', 'Authorization: ' . webpush_auth($ep)];
    if (function_exists('curl_multi_init')) {
        $mh = curl_multi_init();
        $hs = [];
        foreach ($endpoints as $ep) {
            $ch = curl_init($ep);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_HTTPHEADER => $headers($ep),
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 8]);
            if ($ipv4) curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_multi_add_handle($mh, $ch);
            $hs[$ep] = $ch;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $st === CURLM_OK);
        foreach ($hs as $ep => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = trim((string) curl_multi_getcontent($ch));
            $res[$ep] = ['code' => $code, 'err' => $code === 0 ? 'curl ' . curl_errno($ch) . ': ' . curl_error($ch) : ($code >= 300 ? mb_substr($body, 0, 200) : '')];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        // 연결 자체가 안 된 주소는 IPv4 로 한 번 더 (IPv6 경로가 막힌 서버 대비)
        $retry = array_keys(array_filter($res, fn($r) => $r['code'] === 0));
        if ($retry && !$ipv4) $res = webpush_send($retry, true) + $res;
        return $res;
    }
    foreach ($endpoints as $ep) { // curl 이 없는 서버
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers($ep)), 'content' => '', 'timeout' => 8, 'ignore_errors' => true]]);
        $body = @file_get_contents($ep, false, $ctx);
        $code = preg_match('#HTTP/\S+ (\d{3})#', (string) ($http_response_header[0] ?? ''), $m) ? (int) $m[1] : 0;
        $res[$ep] = ['code' => $code, 'err' => $code === 0 ? (string) (error_get_last()['message'] ?? '연결 실패') : ($code >= 300 ? mb_substr((string) $body, 0, 200) : '')];
    }
    return $res;
}

/** 구독 기기들에 보내고 결과 기록. 없어진 구독(404·410)은 지운다. [구독 id => 결과] */
function push_send_subs(array $subs): array
{
    if (!$subs) return [];
    try {
        $res = webpush_send(array_keys($subs));
    } catch (Throwable $e) {
        error_log('push: ' . $e->getMessage());
        $res = array_fill_keys(array_keys($subs), ['code' => 0, 'err' => $e->getMessage()]);
    }
    $out = [];
    $log = db()->prepare('UPDATE push_subs SET last_sent_at = NOW(), last_code = ?, last_error = ?, last_ok_at = IF(? BETWEEN 200 AND 299, NOW(), last_ok_at) WHERE id = ?');
    foreach ($res as $ep => $r) {
        $id = (int) $subs[$ep];
        $out[$id] = $r;
        if ($r['code'] === 404 || $r['code'] === 410) db()->prepare('DELETE FROM push_subs WHERE id = ?')->execute([$id]);
        else $log->execute([$r['code'], $r['err'] !== '' ? mb_substr($r['err'], 0, 250) : null, $r['code'], $id]);
    }
    return $out;
}

/** 구독한 모든 기기에 알림 (보낸 사람 기기 제외). 보낸 기기 수 */
function push_notify_all(int $exceptUserId = 0): int
{
    $st = db()->prepare("SELECT s.id, s.endpoint FROM push_subs s JOIN users u ON u.id = s.user_id AND u.status = 'active' WHERE s.user_id <> ?");
    $st->execute([$exceptUserId]);
    $res = push_send_subs(array_column($st->fetchAll(), 'id', 'endpoint'));
    return count(array_filter($res, fn($r) => $r['code'] >= 200 && $r['code'] < 300));
}

/** 알림 테스트: 이 기기에만 보낸다 (앱이 '알림 테스트'를 띄움). ['code', 'err'] 또는 null(구독 없음) */
function push_test(int $userId, string $endpoint): ?array
{
    $sub = push_find($endpoint);
    if (!$sub || (int) $sub['user_id'] !== $userId) return null;
    db()->prepare('UPDATE push_subs SET test_at = NOW() WHERE id = ?')->execute([(int) $sub['id']]);
    return push_send_subs([$sub['endpoint'] => (int) $sub['id']])[(int) $sub['id']] ?? null;
}

/** 이 기기(endpoint) 구독 저장 — 알림 서버 주소는 https 만 */
function push_subscribe(int $userId, string $endpoint): bool
{
    if (!preg_match('#^https://[^\s/]+/\S+$#', $endpoint) || strlen($endpoint) > 2000) return false;
    $maxEv = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM room_clean_events')->fetchColumn();
    db()->prepare('INSERT INTO push_subs (user_id, endpoint_hash, endpoint, last_event_id) VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)')
        ->execute([$userId, hash('sha256', $endpoint), $endpoint, $maxEv]);
    return true;
}

function push_unsubscribe(string $endpoint): void
{
    db()->prepare('DELETE FROM push_subs WHERE endpoint_hash = ?')->execute([hash('sha256', $endpoint)]);
}

function push_find(string $endpoint): ?array
{
    $st = db()->prepare('SELECT * FROM push_subs WHERE endpoint_hash = ?');
    $st->execute([hash('sha256', $endpoint)]);
    return $st->fetch() ?: null;
}
