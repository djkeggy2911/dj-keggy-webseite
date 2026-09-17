"""Loopback HTTP integration tests; never connect to Hostinger.

Requires tools/test_cms.php to have initialized a disposable local database.
The temporary router simulates the web server HTTPS flag. It is NOT a TLS test
and is never deployed. No production HTTPS bypass is added to the application.
"""
import http.client
import json
import os
from pathlib import Path
import re
import secrets
import socket
import subprocess
import tempfile
import time
from urllib.parse import urlencode

ROOT = Path(__file__).resolve().parent.parent
PHP = [os.environ.get('CMS_TEST_PHP', 'php')] + json.loads(os.environ.get('CMS_TEST_PHP_ARGS', '[]'))


def run():
    if (os.environ.get('CMS_TEST_DATABASE') != '1'
            or os.environ.get('CMS_DB_NAME') != 'cms_phase1_test'
            or os.environ.get('CMS_DB_HOST') not in ('127.0.0.1', 'localhost')):
        raise RuntimeError('Requires explicit disposable loopback test database')
    env = os.environ.copy()
    env['CMS_HTTP_TEST_PASSWORD'] = secrets.token_hex(20)
    # Only the explicitly guarded disposable DB is cleared. Exercise the private
    # setup pipe even when Hostinger-style disabled shell functions are active.
    subprocess.run(PHP, input="<?php require 'admin/core.php'; cms_db()->exec('DELETE FROM cms_users');",
                   text=True, cwd=ROOT, env=env, check=True, capture_output=True)
    setup_command = PHP + ['-d', 'disable_functions=exec,shell_exec',
                           'tools/cms_create_admin.php', '--stdin-json']
    payload = {'email': 'setup@example.invalid', 'password': env['CMS_HTTP_TEST_PASSWORD'],
               'repeat': 'different'}
    result = subprocess.run(setup_command, input=json.dumps(payload), text=True,
                            cwd=ROOT, env=env, capture_output=True)
    assert result.returncode == 1 and env['CMS_HTTP_TEST_PASSWORD'] not in result.stdout + result.stderr
    payload['repeat'] = payload['password']
    result = subprocess.run(setup_command, input=json.dumps(payload), text=True,
                            cwd=ROOT, env=env, capture_output=True)
    assert result.returncode == 0 and 'Administrator created.' in result.stdout
    result = subprocess.run(setup_command, input=json.dumps(payload), text=True,
                            cwd=ROOT, env=env, capture_output=True)
    assert result.returncode == 1 and env['CMS_HTTP_TEST_PASSWORD'] not in result.stdout + result.stderr
    del payload
    # Test account uses an example.invalid address; its password is memory-only.
    setup = """<?php
    require 'admin/core.php';
    $db=cms_db();
    $db->prepare('INSERT INTO cms_users (email,password_hash) VALUES (?,?)')->execute(
      ['http@example.invalid',cms_hash(getenv('CMS_HTTP_TEST_PASSWORD'))]);
    """
    subprocess.run(PHP, input=setup, text=True, cwd=ROOT, env=env, check=True, capture_output=True)
    with tempfile.TemporaryDirectory(prefix='marrydj-cms-test-') as temp:
        private = Path(temp)
        sessions = private / 'sessions'
        sessions.mkdir()
        env['CMS_SESSION_PATH'] = str(sessions)
        router = private / 'router.php'
        router.write_text("""<?php
        if (!in_array($_SERVER['REMOTE_ADDR'],['127.0.0.1','::1'],true)) {http_response_code(403);exit;}
        if (getenv('CMS_TEST_DATABASE') !== '1') {http_response_code(403);exit;}
        $_SERVER['HTTPS']='on';
        return false;
        """, encoding='utf-8')

        def request(port, path='/admin/', data=None, cookie=None, method=None):
            conn = http.client.HTTPConnection('127.0.0.1', port, timeout=15)
            headers = {}
            if cookie:
                headers['Cookie'] = cookie
            body = urlencode(data) if data is not None else None
            if body is not None:
                headers['Content-Type'] = 'application/x-www-form-urlencoded'
            conn.request(method or ('POST' if data is not None else 'GET'), path, body, headers)
            response = conn.getresponse()
            result = response.status, dict(response.getheaders()), response.read().decode()
            conn.close()
            return result

        def serve(server_env, checks, simulate_https=True):
            with socket.socket() as sock:
                sock.bind(('127.0.0.1', 0))
                port = sock.getsockname()[1]
            command = PHP + ['-S', f'127.0.0.1:{port}', '-t', str(ROOT)]
            if simulate_https:
                command.append(str(router))
            with (private / 'php-test.log').open('wb') as log:
                proc = subprocess.Popen(command, cwd=ROOT, env=server_env, stdout=log, stderr=log)
                try:
                    for _ in range(100):
                        if proc.poll() is not None:
                            raise RuntimeError('Local PHP server failed to start')
                        try:
                            with socket.create_connection(('127.0.0.1', port), timeout=.2):
                                break
                        except OSError:
                            time.sleep(.05)
                    checks(port)
                finally:
                    proc.terminate()
                    proc.wait(timeout=10)

        def csrf(body):
            return re.search(r'name="csrf" value="([a-f0-9]+)"', body)[1]

        def cookie(headers):
            return headers['Set-Cookie'].split(';')[0]

        def no_config(port):
            for path in ['/admin/', '/admin/?page=settings']:
                status, headers, body = request(port, path)
                assert status == 503 and 'Dashboard' not in body and 'PDO' not in body
                assert 'no-store' in headers['Cache-Control']
        missing = {k:v for k,v in env.items() if not k.startswith('CMS_') or k == 'CMS_TEST_DATABASE'}
        serve(missing, no_config)

        def no_https(port):
            assert request(port)[0] == 403
        serve(env, no_https, False)

        def public_sessions(port):
            assert request(port)[0] == 503
        serve(dict(env, CMS_SESSION_PATH=str(ROOT / 'admin')), public_sessions)

        def workflow(port):
            status, headers, body = request(port, '/admin/?page=settings', cookie='__Secure-marrydj_admin=attackerchosenid')
            assert status == 200 and 'name="password"' in body and 'Sicher angemeldet' not in body
            assert 'attackerchosenid' not in headers['Set-Cookie']
            for attr in ['secure', 'httponly', 'samesite=strict', 'path=/admin']:
                assert attr in headers['Set-Cookie'].lower(), attr
            assert 'no-store' in headers['Cache-Control']
            assert "frame-ancestors 'none'" in headers['Content-Security-Policy']
            assert headers['X-Robots-Tag'] == 'noindex, nofollow'
            first_cookie, token = cookie(headers), csrf(body)
            status, _, other_body = request(port)
            other_token = csrf(other_body)
            login = {'action':'login','email':'http@example.invalid','password':env['CMS_HTTP_TEST_PASSWORD']}
            for invalid in ['', 'wrong', other_token]:
                assert request(port, data=dict(login, csrf=invalid), cookie=first_cookie)[0] == 403
            status, _, body = request(port, data=dict(login, password='wrong', csrf=token), cookie=first_cookie)
            assert status == 401 and 'Sicher angemeldet' not in body
            status, headers, _ = request(port, data=dict(login, csrf=token), cookie=first_cookie)
            assert status == 303 and headers['Location'] == '/admin/'
            auth_cookie = cookie(headers)
            assert auth_cookie != first_cookie
            status, _, body = request(port, cookie=auth_cookie)
            assert status == 200 and 'Sicher angemeldet' in body
            assert 'Sicher angemeldet' in request(port, '/admin/?action=logout', cookie=auth_cookie)[2]
            assert 'name="password"' in request(port, cookie=first_cookie)[2]
            logout_token = csrf(body)
            for page in ['reviews','weddings','gallery','videos','references','content','inquiries','analytics','settings']:
                status, _, body = request(port, '/admin/?page='+page, cookie=auth_cookie)
                assert status == 200 and 'noch nicht aktiviert' in body
            assert request(port, '/admin/?page=%3Cscript%3E', cookie=auth_cookie)[2].count('<script>') == 0
            assert request(port, data={'action':'logout','csrf':token}, cookie=auth_cookie)[0] == 403
            # Force expiry in this test's private session file, never via an HTTP backdoor.
            session_file = sessions / ('sess_' + auth_cookie.split('=', 1)[1])
            state = session_file.read_text()
            session_file.write_text(re.sub(r'seen\|i:\d+;', 'seen|i:1;', state))
            assert 'name="password"' in request(port, cookie=auth_cookie)[2]
            status, headers, body = request(port)
            fresh_cookie, fresh_token = cookie(headers), csrf(body)
            status, headers, _ = request(port, data=dict(login, csrf=fresh_token), cookie=fresh_cookie)
            assert status == 303
            auth_cookie = cookie(headers)
            logout_token = csrf(request(port, cookie=auth_cookie)[2])
            status, _, _ = request(port, data={'action':'logout','csrf':logout_token}, cookie=auth_cookie)
            assert status == 303
            assert 'name="password"' in request(port, cookie=auth_cookie)[2]
            # A new session cannot bypass the database account/IP limit.
            status, headers, body = request(port)
            current_cookie, current_token = cookie(headers), csrf(body)
            assert request(port, data=dict(login, password='wrong', csrf=current_token), cookie=current_cookie)[0] == 401
            for _ in range(6):
                status, _, body = request(port, data=dict(login, password='wrong', csrf=current_token), cookie=current_cookie)
            assert status == 429
            assert request(port, method='PUT')[0] == 405
            assert request(port, '/admin/core.php')[2] == ''
            assert request(port, '/tools/cms_create_admin.php')[0] == 404
        serve(env, workflow)
    print('PASS: HTTP login, CSRF, cookies, session fixation/rotation/expiry/logout, protected modules, throttling, missing config, HTTPS enforcement and headers.')


if __name__ == '__main__':
    run()
