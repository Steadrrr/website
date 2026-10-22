<?php
defined('APP_ROOT') || exit;

/*
 * 체험판 표 분리: 체험판은 운영 사이트와 같은 DB를 쓰되, 표 이름 앞에 'demo_' 를 붙인 별도 표만 쓴다.
 *  (호스팅이 DB를 하나만 주어도 데모 DB를 따로 만들 필요가 없다)
 *  - 모든 SQL은 DemoPDO 를 거치며 sql/schema.sql 에 있는 표 이름·외래키 이름(fk_…)을 demo_ 이름으로 바꾼다.
 *  - 따옴표 안 글자·:이름 자리표시자·점(.) 뒤 열 이름은 바꾸지 않는다.
 *  - 안전장치: 바꾼 뒤에도 운영 표 이름이 남아 있거나(백틱 등) 모르는 표를 만들려 하면 실행하지 않고 오류를 낸다.
 *  운영 사이트(config 'demo' 없음)는 이 파일의 어떤 것도 쓰지 않는다.
 */

const DEMO_TABLE_PREFIX = 'demo_';

/** 운영 표 이름 목록 (sql/schema.sql 의 CREATE TABLE) */
function demo_known_tables(): array
{
    static $t = null;
    if ($t === null) {
        preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`?([a-z_]+)`?/i', (string) file_get_contents(APP_ROOT . '/sql/schema.sql'), $m);
        $t = array_fill_keys(array_map('strtolower', $m[1]), true);
    }
    return $t;
}

/** 지금 사이트가 쓰는 실제 표 이름 (체험판이면 demo_ 를 붙임) */
function db_table(string $table): string
{
    return is_demo() && isset(demo_known_tables()[strtolower($table)]) ? DEMO_TABLE_PREFIX . $table : $table;
}

/** SQL 의 표 이름을 demo_ 표로 바꾼다 */
function demo_sql(string $sql): string
{
    $tables = demo_known_tables();
    $infoSchema = stripos($sql, 'information_schema') !== false;
    $out = preg_replace_callback(
        "/'(?:[^'\\\\]|\\\\.|'')*'|\"(?:[^\"\\\\]|\\\\.)*\"|`[^`]*`|(?<![:\\w.@])[A-Za-z_][A-Za-z0-9_]*/",
        function (array $m) use ($tables, $infoSchema): string {
            $tok = $m[0];
            $q = $tok[0];
            if ($q === "'" || $q === '"') {
                // information_schema 조회의 TABLE_NAME = 'settings' 같은 표 이름 글자만 바꾼다
                $inner = substr($tok, 1, -1);
                return $infoSchema && isset($tables[strtolower($inner)]) ? $q . DEMO_TABLE_PREFIX . $inner . $q : $tok;
            }
            if ($q === '`') {
                $inner = substr($tok, 1, -1);
                return isset($tables[strtolower($inner)]) ? '`' . DEMO_TABLE_PREFIX . $inner . '`' : $tok;
            }
            $low = strtolower($tok);
            if (isset($tables[$low]) || str_starts_with($low, 'fk_')) return DEMO_TABLE_PREFIX . $tok;
            return $tok;
        },
        $sql
    );
    if ($out === null) throw new RuntimeException('체험판 SQL 변환 실패');
    // 안전장치: 새 표를 만들거나 지우는 문장은 demo_ 표에만
    if (preg_match('/\b(CREATE|DROP|TRUNCATE|RENAME)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?([A-Za-z0-9_]+)/i', $out, $m) && !str_starts_with(strtolower($m[2]), DEMO_TABLE_PREFIX)) {
        throw new RuntimeException('체험판에서는 demo_ 표만 만들거나 지울 수 있습니다: ' . $m[2]);
    }
    return $out;
}

/** 체험판 DB 연결: 모든 SQL 을 demo_sql() 로 바꿔 실행 */
class DemoPDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(demo_sql($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $fetchMode === null ? parent::query(demo_sql($query)) : parent::query(demo_sql($query), $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec(demo_sql($statement));
    }
}
