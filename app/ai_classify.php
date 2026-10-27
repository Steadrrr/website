<?php
defined('APP_ROOT') || exit;

/*
 * 민원 자동 분류 제안 (일일업무일지 › 민원 입력의 '✨ 분류 제안' 버튼)
 *  - 민원 내용 → 3단계 분류(CPL_TREE 소분류 코드) + 장소(글에 객실·시설 이름이 있으면)
 *  - AI 연결이 있으면 Claude, 없거나 실패하면 키워드 규칙. 담당자가 확인하고 저장한다 (제안만).
 *  - 보내기 전에 전화번호·이메일·호칭 앞 이름을 가린다 (ai_mask). 결과는 저장하지 않는다.
 *  규칙의 낱말은 실제 2026년 객실 민원 관리대장·전화 응대 현황·만족도 조사 문장을 보고 골랐다.
 */

const AI_CLS_MODEL = 'claude-haiku-5-5'; // 분류는 짧고 단순해서 가볍고 싼 Haiku (한 번에 1원 안팎)
const AI_CLS_DAILY_LIMIT = 300; // 하루 최대 AI 분류 (사이트 전체, 비용 보호 — 넘으면 규칙으로)

/** 키워드 규칙 [소분류 코드, 정규식] — 위에서부터 먼저 맞는 것 */
const CPL_RULES = [
    ['4-2-1', '쏘[였임이]|물[렸림]'],
    ['4-1-1', '말벌|벌집|벌이|벌 '],
    ['4-1-2', '지네|바퀴벌레|해충|벌레가 (많|나)'],
    ['4-1-3', '유기견|들개|멧돼지|뱀|야생'],
    ['4-2-2', '다쳤|넘어[졌짐]|미끄러[져졌]|부상|응급|구급|피가'],
    ['4-5-1', '아이[를가]? ?(잃|없어|찾)|미아'],
    ['4-5-2', '(강아지|반려견|개)[를가]? ?(잃|없어|찾)'],
    ['4-5-3', '길[을를]? ?잃'],
    ['4-3-1', '정전|전기가 (다|모두|전체)'],
    ['4-3-2', '단수|흙탕물|물이 (안|아예) ?나(오|와)'],
    ['4-3-3', '문이 열려|도난|보안'],
    ['4-4-1', '폭설|폭우|제설|눈길|빙판|결빙|침수'],
    ['3-5-3', '불친절|태도|반말|무례'],
    ['3-5-1', '키를? ?(잘못|바꿔)|객실키 (잘못|바뀜)'],
    ['3-5-2', '안내(가|를)? ?(잘못|틀)'],
    ['3-4-1', '예약 ?(오류|안 ?됨|안돼|접속)|사이트 ?(오류|안)'],
    ['2-1-3', '(방|객실)[을를]? ?(바꿔|변경)'],
    ['2-1-1', '선풍기|충전기|파라솔|토치|대여|빌려'],
    ['2-1-2', '(수건|침구|이불|의자|베개)[을를]? ?(추가|더 ?주|더 ?필요|요청)'],
    ['2-2-1', '예약 ?(변경|취소)(해|처리|요청)'],
    ['2-2-2', '숙박 ?확인서 ?(발급|요청|보내)'],
    ['3-2-2', '온수|찬물|뜨거운 ?물|물 ?온도|배수|막[혀힘]|물이 ?(잘 ?)?안 ?(빠|내려)|역류|하수구|수압|변기|누수|물이 ?샘'],
    ['3-2-1', '난방|보일러|바닥이 ?(차|미지근)|추[워웠]|에어컨|냉방|더[워웠]|온풍기|라디에이터'],
    ['3-2-4', '와이파이|wifi|티비|tv|텔레비|셋톱|냉장고|인터넷|핸드폰|휴대폰|kt|통신|전자레인지|밥솥|인덕션|하이라이트'],
    ['3-2-3', '전등|조명|불이 ?안|불 ?나감|등 ?[0-9]*개? ?(이 ?)?(안|나감)|안 ?들어[오와옴]|콘센트|차단기|전기'],
    ['3-2-5', '문[이을]|중문|잠금|도어|현관|샤워기|세면대|욕실|화장실|휴지걸이|말발굽|방충망'],
    ['3-2-6', '테라스|데크|피크닉|야외 ?테이블|송진|어닝'],
    ['3-1-2', '냄새|악취|비린내'],
    ['3-1-3', '(수건|침구|이불|베개)[은는이가]? ?(부족|모자|없|[0-9]+ ?(개|채|장))'],
    ['3-1-4', '수저|숟가락|젓가락|그릇|접시|프라이팬|후라이팬|집게|국자|주방|냄비|고무장갑|칼'],
    ['3-1-5', '세제|휴지|요 ?커버|소모품|비누|샴푸'],
    ['3-1-1', '머리카락|청소|더럽|지저분|쓰레기|먼지|끈적|얼룩|곰팡이|거미|벌레|찌꺼기|방치|씨가? ?있'],
    ['3-3-2', '산책로|숲길|등산로|길이 ?(미끄|파|패)'],
    ['3-3-3', '공사.*(시끄|소음|불편)'],
    ['3-3-1', '놀이터|그네|미끄럼틀|정자|벤치|파손|고장|부서'],
    ['1-1-2', '군민 ?우선'],
    ['1-1-5', '확인서'],
    ['1-1-4', '취소|위약|환불|변경 ?(가능|규정)'],
    ['1-1-3', '할인|요금|가격|얼마|바우처|다자녀|장애인'],
    ['1-1-1', '예약|에약|오픈|빈 ?방|잔여|숙박 ?(문의|가능한 ?날)'],
    ['1-2-3', '(반려|강아지|애견|애완).*(객실|숙박|방)|(객실|숙박|방).*(반려|강아지|애견|애완)'],
    ['1-2-1', '입실|퇴실|체크 ?인|체크 ?아웃|키 ?반납|짐 ?보관'],
    ['1-2-2', '인원|몇 ?명|추가 ?인원|동생|가족이'],
    ['1-2-5', '바베큐|바비큐|숯|그릴'],
    ['1-2-6', '주차'],
    ['1-2-4', '비치|집기|구비|있나요|있는지'],
    ['1-3-3', '반려|강아지|애견|애완'],
    ['1-3-5', '물놀이|발목 ?풀장|수영'],
    ['1-3-4', '카페|식당|매점|편의'],
    ['1-3-6', '버스|교통|휠체어|장애인 ?차량|길 ?안내|위치|가는 ?길'],
    ['1-3-7', '공사|통제'],
    ['1-3-2', '입장료|입장 ?요금'],
    ['1-3-1', '운영 ?시간|개방|휴관|휴장|몇 ?시|폐장|입장 ?시간|공원 ?입장|입장 ?가능|공원 ?이용 ?(시간|안내)|연휴'],
    ['1-3-8', '캠핑|텐트|돗자리|도시락|흡연|산책|소풍|숲길|지여우길|공원 ?이용|공원 ?문의|공원에|공원내'],
    ['1-4-1', '숲 ?해설'],
    ['1-4-2', '치유'],
    ['1-4-3', '유아숲|어린이집|유치원'],
    ['1-4-4', '대관|교육관|강의실|야외 ?무대'],
    ['1-4-5', '체험|행사|프로그램'],
    ['1-5-1', '분실|잃어버|두고 ?(갔|왔)|습득'],
    ['1-2-4', '객실|숙박|초가원|침구'],
    ['1-5-2', '점검|답사|업체|기관'],
];

/**
 * 키워드 규칙 분류
 * @return array{code: string, reason: string}
 */
function cpl_rule_classify(string $text): array
{
    $t = mb_strtolower($text);
    // 묻는 말인가 ('~문의', '~나요?') — 문제가 생겼다는 말(안 돼요·고장·부족 …)이 없을 때만
    $asking = preg_match('/문의|나요|가요|까요|되는지|있는지|가능한지|어디|어떻게|언제|\?/u', $t)
        && !preg_match('/안 ?(돼|되|나와|나오|빠|켜|들어)|고장|부족|모자|없[다어]|더럽|깨[져졌]|부러|막[혀힘]|해 ?주세요|주세요/u', $t);
    foreach (CPL_RULES as [$code, $re]) {
        if (!preg_match('/' . $re . '/u', $t, $m)) continue;
        if ($asking && $code[0] === '3') $code = substr($code, 0, 3) === '3-3' ? '1-3-8' : '1-2-4'; // 시설이 있는지·되는지 묻는 말 = 단순문의
        return ['code' => $code, 'reason' => "'" . trim($m[0]) . "' 낱말" . ($asking ? ', 묻는 말' : '')];
    }
    return ['code' => '1-5-3', 'reason' => '맞는 낱말이 없어 단순문의 › 기타'];
}

/** 글에 나온 객실·시설 구역 이름 → [place_type, place_id] (긴 이름부터) */
function cpl_guess_place(string $text): ?array
{
    $opts = cpl_place_options();
    $cands = [];
    foreach ($opts['room'] as $id => $name) $cands[] = ['room', $id, $name];
    foreach ($opts['facility'] as $id => $label) $cands[] = ['facility', $id, preg_replace('/\s*\(.*\)$/u', '', $label)];
    usort($cands, fn($a, $b) => mb_strlen($b[2]) <=> mb_strlen($a[2]));
    $norm = fn(string $s) => mb_strtolower(preg_replace('/\s+/u', '', $s));
    $t = $norm($text);
    foreach ($cands as [$type, $id, $name]) {
        if ($name !== '' && mb_strlen($name) >= 2 && str_contains($t, $norm($name))) return [$type, (int) $id];
    }
    return null;
}

/** AI 분류 지시문: 분류표 전체 + 구분 기준 */
function cpl_ai_system(): string
{
    $lines = [];
    foreach (cpl_tree()['tree'] as $c1 => $x) {
        $lines[] = "$c1 {$x['name']} — {$x['desc']}";
        foreach ($x['mids'] as $c2 => $m) {
            foreach ($m['leaves'] as $c3 => $leaf) $lines[] = "  $c3 {$m['name']} › $leaf";
        }
    }
    return "당신은 공공 휴양시설(숙박 객실·공원·치유센터·숲해설·유아숲)의 민원 접수를 돕습니다. 직원이 적은 민원 한 건을 아래 분류표의 소분류 코드 하나로 분류합니다.\n\n"
        . "구분 기준:\n- 1 단순문의: 정보만 안내하면 끝나는 질문 (\"~문의\", \"~되나요?\")\n- 2 요청: 직원이 물품을 주거나 처리해 줘야 하는 건 (대여, 추가 비품, 객실 변경, 예약 처리)\n"
        . "- 3 불편·불만: 이미 생긴 문제 (고장, 청결, 부족, 직원 응대)\n- 4 긴급·안전: 다치거나 위험하거나 물·전기가 끊긴 건, 벌·해충·동물, 기상 재난, 미아\n"
        . "- 같은 낱말이라도 문의인지 불편인지 보세요: '에어컨 있나요?'는 1-2-4, '에어컨이 안 돼요'는 3-2-1.\n- 딱 맞는 소분류가 없으면 그 중분류의 '기타'를 고르고 etc_text 에 10자 안팎으로 요약하세요. 기타가 아니면 etc_text 는 빈 글자.\n"
        . "- reason 은 고른 이유 한 줄 (쉬운 우리말).\n\n분류표:\n" . implode("\n", $lines);
}

/** @return array{ok: bool, code?: string, etc_text?: string, reason?: string, error?: string} */
function cpl_ai_classify(string $text): array
{
    $codes = array_keys(array_filter(cpl_tree()['names'], fn($k) => substr_count((string) $k, '-') === 2, ARRAY_FILTER_USE_KEY));
    $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'etc_text', 'reason'], 'properties' => [
        'code' => ['type' => 'string', 'enum' => array_map('strval', $codes)], 'etc_text' => ['type' => 'string'], 'reason' => ['type' => 'string'],
    ]];
    $res = ai_claude_json(cpl_ai_system(), "민원 내용: " . ai_mask(mb_substr($text, 0, 500)), $schema, 'low', 2000, AI_CLS_MODEL);
    if (!$res['ok']) return $res;
    $code = (string) ($res['data']['code'] ?? '');
    if (!in_array($code, $codes, true)) return ['ok' => false, 'error' => 'AI가 분류표에 없는 코드를 골랐습니다.'];
    return ['ok' => true, 'code' => $code, 'etc_text' => mb_substr((string) ($res['data']['etc_text'] ?? ''), 0, 100), 'reason' => mb_substr((string) ($res['data']['reason'] ?? ''), 0, 120)];
}

/** 오늘 AI 분류 횟수 +1 (상한을 넘으면 false) — settings 한 줄을 원자적으로 올린다 */
function cpl_ai_take_quota(): bool
{
    $name = 'ai_cls_' . date('Ymd');
    db()->prepare("INSERT INTO settings (name, value) VALUES (?, '1') ON DUPLICATE KEY UPDATE value = CAST(value AS UNSIGNED) + 1")->execute([$name]);
    $st = db()->prepare('SELECT value FROM settings WHERE name = ?');
    $st->execute([$name]);
    return (int) $st->fetchColumn() <= AI_CLS_DAILY_LIMIT;
}

/**
 * 분류 제안 (AI → 실패하면 규칙)
 * @return array{code: string, cat1: string, cat2: string, path: string, etc_text: string, reason: string, by: string, place: ?array}
 */
function cpl_suggest(string $text): array
{
    $by = 'rule';
    $r = null;
    if (ai_any_key() && cpl_ai_take_quota()) {
        $ai = cpl_ai_classify($text);
        if ($ai['ok']) {
            $r = $ai;
            $by = 'ai';
        }
    }
    $r ??= cpl_rule_classify($text) + ['etc_text' => ''];
    $code = $r['code'];
    return ['code' => $code, 'cat1' => substr($code, 0, 1), 'cat2' => substr($code, 0, 3),
            'path' => cpl_name(substr($code, 0, 1)) . ' › ' . cpl_name(substr($code, 0, 3)) . ' › ' . cpl_name($code),
            'etc_text' => $r['etc_text'] ?? '', 'reason' => $r['reason'] ?? '', 'by' => $by, 'place' => cpl_guess_place($text)];
}
