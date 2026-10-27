<?php
defined('APP_ROOT') || exit;

/*
 * 구글 AI(Gemini) 먼저 쓰기 — 충전해 둔 구글 요금을 먼저 쓰고, 안 되면 Claude 로 (사용자 결정 2026-10-27)
 *  - 키: app/gemini_key.txt (gitignore, 웹에서 열 수 없음). 운영 사이트에서만 쓴다 (체험판은 늘 Claude).
 *  - ai_claude_json() 이 맨 먼저 ai_gemini_json() 을 부른다. 실패하면(잔액 소진·오류·답을 못 읽음) 같은 요청을 Claude 로 보낸다.
 *  - 잔액 소진·키 오류(401·403·429)면 1시간 동안 구글을 건너뛴다 (매번 실패를 기다리지 않게).
 *  - 모델은 키를 저장할 때 구글이 알려 주는 목록에서 고른다 (구글 모델 이름이 자주 바뀌어서).
 *  - 사용 기록(횟수·토큰·마지막 오류)은 settings 의 ai_gemini_* 에 남겨 AI 키 화면에서 보여 준다.
 */

function ai_gemini_key_file(): string
{
    return APP_ROOT . '/app/gemini_key.txt';
}

function ai_gemini_key(): string
{
    if (is_demo()) return '';
    $k = trim((string) config('gemini_api_key', ''));
    if ($k === '' && is_file(ai_gemini_key_file())) $k = trim((string) file_get_contents(ai_gemini_key_file()));
    return $k;
}

function ai_gemini_url(string $path): string
{
    return rtrim((string) config('gemini_api_url', 'https://generativelanguage.googleapis.com'), '/') . '/v1beta/' . $path;
}

/** 저장해 둔 모델 (예: gemini-2.5-flash) */
function ai_gemini_model(): string
{
    return setting('ai_gemini_model', '');
}

/** 구글을 쓸 수 있는가 (키·모델이 있고, 잠시 쉬는 중이 아님) */
function ai_gemini_on(): bool
{
    return ai_gemini_key() !== '' && ai_gemini_model() !== '' && (int) setting('ai_gemini_pause_until', '0') <= time();
}

/** Claude 나 구글 중 하나라도 연결돼 있는가 */
function ai_any_key(): bool
{
    return ai_key() !== '' || (ai_gemini_key() !== '' && ai_gemini_model() !== '');
}

/** 구글 GET 요청 → [HTTP 코드, 응답 배열|null, curl 오류] */
function ai_gemini_get(string $key, string $path): array
{
    $ch = curl_init(ai_gemini_url($path));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $key]]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, $raw === false ? null : json_decode((string) $raw, true), $raw === false ? $err : ''];
}

/**
 * 키 확인 + 쓸 수 있는 글쓰기 모델 목록 (모델 목록 조회 — 비용 없음)
 * @return array{error: ?string, models: string[]}
 */
function ai_gemini_verify(string $key): array
{
    if (!function_exists('curl_init')) return ['error' => '서버에 curl 기능이 없어 AI 에 연결할 수 없습니다.', 'models' => []];
    if (!preg_match('/^[A-Za-z0-9_\-]{30,60}$/', $key)) return ['error' => "키 모양이 맞지 않습니다. 구글 AI Studio 의 'API 키'(보통 AIza 로 시작) 전체를 붙여 넣어 주세요.", 'models' => []];
    [$code, $res, $err] = ai_gemini_get($key, 'models?pageSize=1000');
    if ($err !== '') return ['error' => '서버에서 구글에 연결하지 못했습니다. (' . $err . ')', 'models' => []];
    if ($code !== 200) {
        return ['error' => in_array($code, [400, 401, 403], true) ? '키가 올바르지 않거나 사용이 막혀 있습니다. 구글 AI Studio 에서 키를 다시 확인해 주세요.' : "확인 중 오류가 났습니다 (HTTP $code). 잠시 뒤 다시 해 주세요.", 'models' => []];
    }
    $models = [];
    foreach ($res['models'] ?? [] as $m) {
        $id = preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
        // 글을 쓰는 gemini 모델만 (그림·음성·임베딩·실시간 전용 제외)
        if (!str_starts_with($id, 'gemini-') || !in_array('generateContent', (array) ($m['supportedGenerationMethods'] ?? []), true)) continue;
        if (preg_match('/image|tts|audio|live|embed|robotics|computer-use|native/', $id)) continue;
        $models[] = $id;
    }
    usort($models, fn($a, $b) => ai_gemini_rank($b) <=> ai_gemini_rank($a) ?: strcmp($a, $b));
    return ['error' => $models ? null : '이 키로 쓸 수 있는 글쓰기 모델이 없습니다.', 'models' => $models];
}

/** 기본으로 고를 모델 순서: 정식(미리보기 아님) → flash → 버전 높은 것 → lite 는 뒤로 */
function ai_gemini_rank(string $id): array
{
    preg_match('/gemini-(\d+(?:\.\d+)?)/', $id, $m);
    return [
        preg_match('/preview|exp|latest/', $id) ? 0 : 1,
        str_contains($id, 'flash') && !str_contains($id, 'lite') ? 2 : (str_contains($id, 'pro') ? 1 : 0),
        (float) ($m[1] ?? 0),
        -strlen($id),
    ];
}

function ai_gemini_key_save(string $key): bool
{
    $f = ai_gemini_key_file();
    if ($key === '') return !is_file($f) || @unlink($f);
    $ok = @file_put_contents($f, $key . "\n", LOCK_EX) !== false;
    if ($ok) @chmod($f, 0600);
    return $ok;
}

/** 사용 기록 */
function ai_gemini_log(bool $ok, array $usage = [], string $error = ''): void
{
    if ($ok) {
        foreach (['calls' => 1, 'in' => (int) ($usage['input_tokens'] ?? 0), 'out' => (int) ($usage['output_tokens'] ?? 0)] as $k => $v) {
            db()->prepare("INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = CAST(value AS UNSIGNED) + ?")->execute(["ai_gemini_$k", (string) $v, $v]);
        }
        setting_set('ai_gemini_last_ok', date('Y-m-d H:i'));
    } else {
        setting_set('ai_gemini_last_error', date('Y-m-d H:i') . ' ' . mb_substr($error, 0, 200));
    }
}

/**
 * 구글 호출 (JSON 응답) — ai_claude_json 과 같은 모양으로 돌려준다
 * @return array{ok: bool, data?: array, error?: string, model?: string, usage?: array}
 */
function ai_gemini_json(string $system, string $user, array $schema, int $maxTokens): array
{
    $key = ai_gemini_key();
    $model = ai_gemini_model();
    $body = [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
        // 생각(thinking) 토큰도 출력 한도에 들어가므로 넉넉히
        'generationConfig' => ['responseMimeType' => 'application/json', 'responseJsonSchema' => $schema, 'maxOutputTokens' => max(8192, $maxTokens)],
    ];
    @set_time_limit(200);
    $ch = curl_init(ai_gemini_url('models/' . rawurlencode($model) . ':generateContent'));
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 150,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $res = $raw === false ? null : json_decode((string) $raw, true);
    $fail = function (string $msg) use ($code) {
        // 잔액 소진·한도·키 오류는 1시간 쉬었다가 다시 해 본다
        if (in_array($code, [401, 403, 429], true)) setting_set('ai_gemini_pause_until', (string) (time() + 3600));
        ai_gemini_log(false, [], $msg);
        return ['ok' => false, 'error' => $msg];
    };
    if ($raw === false) return $fail('구글에 연결하지 못했습니다. (' . $err . ')');
    if ($code !== 200 || !is_array($res)) {
        $msg = is_array($res) ? (string) ($res['error']['message'] ?? '') : '';
        $hint = match (true) {
            $code === 429 => '구글 사용 한도 또는 충전금이 다 됐습니다.',
            $code === 401 || $code === 403 => '구글 키가 올바르지 않거나 결제가 막혀 있습니다.',
            $code >= 500 => '구글 AI 서버가 바쁩니다.',
            default => '구글 AI 요청이 거절되었습니다.',
        };
        return $fail("$hint (HTTP $code" . ($msg !== '' ? ': ' . mb_substr($msg, 0, 150) : '') . ')');
    }
    $cand = $res['candidates'][0] ?? [];
    $finish = (string) ($cand['finishReason'] ?? '');
    if ($finish !== 'STOP') return $fail('구글 AI 답이 끝나지 않았습니다 (' . ($finish ?: '답 없음') . ').');
    $text = '';
    foreach ($cand['content']['parts'] ?? [] as $p) if (empty($p['thought'])) $text .= (string) ($p['text'] ?? '');
    $data = json_decode($text, true);
    if (!is_array($data)) return $fail('구글 AI 답을 읽지 못했습니다.');
    $u = $res['usageMetadata'] ?? [];
    $usage = ['input_tokens' => (int) ($u['promptTokenCount'] ?? 0), 'output_tokens' => (int) ($u['candidatesTokenCount'] ?? 0) + (int) ($u['thoughtsTokenCount'] ?? 0)];
    ai_gemini_log(true, $usage);
    return ['ok' => true, 'data' => $data, 'model' => (string) ($res['modelVersion'] ?? $model), 'usage' => $usage];
}
