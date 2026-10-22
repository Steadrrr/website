"""로컬 테스트 도우미 — with_servers.sh 안에서 실행한다.
    from testlib import *          (sys.path 에 dev/local 추가)
    s = login('keeper1'); r = post(s, '/write.php?type=daily', {...}); check('저장됨', '저장' in r.text)
    print(sql('SELECT COUNT(*) FROM journals')); done()
"""
import re, subprocess, sys
import requests

B = 'http://127.0.0.1:8080'
PW = 'password123'


def sql(q):
    return subprocess.run(['mariadb', '--default-character-set=utf8mb4', '-uforest', '-ppw1234', 'forest', '-N', '-e', q],
                          capture_output=True, text=True).stdout.strip()


def _tok(html):
    m = re.search(r'name="_csrf" value="([^"]+)"', html)
    return m.group(1) if m else None


def login(u, pw=PW):
    s = requests.Session()
    t = _tok(s.get(B + '/login.php').text)
    s.post(B + '/login.php', data={'_csrf': t, 'username': u, 'password': pw})
    s.tok = _tok(s.get(B + '/index.php').text) or t  # 로그인하면 토큰이 바뀐다
    return s


def post(s, path, data, files=None):
    d = {'_csrf': s.tok}
    d.update(data)
    return s.post(B + path, data=d, files=files, allow_redirects=True)


def flashes(r):
    return re.findall(r'<div class="flash flash-(\w+)">([^<]*)</div>', r.text)


ok = bad = 0


def check(name, cond, extra=''):
    global ok, bad
    if cond:
        ok += 1; print('  OK ', name)
    else:
        bad += 1; print('  FAIL', name, extra)


def done():
    print(f'\n{ok} OK, {bad} FAIL')
    sys.exit(1 if bad else 0)
