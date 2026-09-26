"""Phase 2 integration tests. Explicit disposable loopback DB only; no Hostinger."""
import hashlib
import html
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
from PIL import Image, ImageDraw
import zxingcpp

ROOT = Path(__file__).resolve().parent.parent
PHP = [os.environ.get('CMS_TEST_PHP', 'php')] + json.loads(os.environ.get('CMS_TEST_PHP_ARGS', '[]'))


def run():
    assert os.environ.get('CMS_TEST_DATABASE') == '1'
    assert os.environ.get('CMS_DB_NAME') == 'cms_phase1_test'
    assert os.environ.get('CMS_DB_HOST') in ('127.0.0.1', 'localhost')
    env = os.environ.copy()
    env['REVIEW_TEST_PASSWORD'] = secrets.token_hex(24)

    def php(source):
        result = subprocess.run(PHP, input="<?php require 'lib/reviews.php'; " + source,
                                text=True, cwd=ROOT, env=env, capture_output=True)
        assert result.returncode == 0, result.stderr
        return result.stdout

    def sql_number(query):
        # Queries below are test constants, not guest input.
        return int(php("echo cms_db()->query(" + json.dumps(query) + ")->fetchColumn();"))

    with tempfile.TemporaryDirectory(prefix='marrydj-phase2-') as temp:
        private = Path(temp)
        (private / 'sessions').mkdir(mode=0o700)
        env['CMS_SESSION_PATH'] = str(private / 'sessions')
        migration = subprocess.run(PHP + ['tools/cms_migrate.php', '--apply-phase2'],
                                   cwd=ROOT, env=env, text=True, capture_output=True)
        assert migration.returncode == 0, migration.stderr
        assert 'backup verified' in migration.stdout
        backups = list((private / 'backups').glob('*.sql'))
        assert len(backups) == 1
        assert hashlib.sha256(backups[0].read_bytes()).hexdigest() == backups[0].with_suffix('.sql.sha256').read_text().strip()
        assert b'CREATE TABLE `cms_users`' in backups[0].read_bytes()
        again = subprocess.run(PHP + ['tools/cms_migrate.php', '--apply-phase2'], cwd=ROOT, env=env, text=True, capture_output=True)
        assert again.returncode == 0 and 'already installed' in again.stdout
        assert len(list((private / 'backups').glob('*.sql'))) == 1
        assert sql_number('SELECT MAX(version) FROM cms_schema_versions') == 2
        # The runtime can also be tested with a data-only local user supplied by the runner.
        if os.environ.get('CMS_REVIEW_RUNTIME_USER'):
            env['CMS_DB_USER'] = os.environ['CMS_REVIEW_RUNTIME_USER']
            env['CMS_DB_PASSWORD'] = os.environ['CMS_REVIEW_RUNTIME_PASSWORD']
        php("cms_db()->prepare('INSERT INTO cms_users(email,password_hash) VALUES(?,?)')->execute(['phase2@example.invalid',cms_hash(getenv('REVIEW_TEST_PASSWORD'))]);")

        router = private / 'router.php'
        router.write_text("<?php if(!in_array($_SERVER['REMOTE_ADDR'],['127.0.0.1','::1'],true)){http_response_code(403);exit;} $_SERVER['HTTPS']='on'; return false;", encoding='utf-8')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        with (private / 'server.log').open('wb') as log:
            server = subprocess.Popen(PHP + ['-S', f'127.0.0.1:{port}', '-t', str(ROOT), str(router)], cwd=ROOT, env=env, stdout=log, stderr=log)
            try:
                for _ in range(100):
                    assert server.poll() is None
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=.2): break
                    except OSError: time.sleep(.05)

                class Client:
                    def __init__(self): self.cookie = ''
                    def request(self, path, data=None, method=None):
                        conn = http.client.HTTPConnection('127.0.0.1', port, timeout=20)
                        headers = {'Cookie': self.cookie} if self.cookie else {}
                        payload = urlencode(data) if data is not None else None
                        if payload is not None: headers['Content-Type'] = 'application/x-www-form-urlencoded'
                        conn.request(method or ('POST' if data is not None else 'GET'), path, payload, headers)
                        response = conn.getresponse(); h = dict(response.getheaders()); body = response.read().decode('utf-8'); status = response.status
                        if 'Set-Cookie' in h: self.cookie = h['Set-Cookie'].split(';')[0]
                        conn.close()
                        assert not any(x in body for x in ['Fatal error:', 'Warning:', 'Deprecated:', 'Uncaught ']), body[:200]
                        return status, h, body

                def csrf(body): return re.search(r'name="csrf" value="([a-f0-9]{64})"', body)[1]
                def code(body): return re.search(r'https://marrydj.com/review/#code=([a-f0-9]{64})', body)[1]
                admin = Client(); anon = Client(); guest = Client()
                assert anon.request('/admin/?page=weddings')[0] == 200
                assert 'name="password"' in anon.request('/admin/?page=reviews')[2]
                assert anon.request('/admin/phase2.php')[0] == 404
                assert anon.request('/tools/cms_migrate.php')[0] == 404
                assert anon.request('/tools/cms_cleanup.php')[0] == 404
                s, h, b = admin.request('/admin/')
                s, h, b = admin.request('/admin/', {'action': 'login', 'csrf': csrf(b), 'email': 'phase2@example.invalid', 'password': env['REVIEW_TEST_PASSWORD']})
                assert s == 303
                s, h, b = admin.request('/admin/?page=weddings'); token = csrf(b)
                wedding = dict(action='wedding_save', id='0', title='Ana & Marko <script>', wedding_date='2026-09-20', location='Poreč & Rovinj', internal_note='PRIVATE NOTE DO NOT PUBLISH', csrf=token)
                assert admin.request('/admin/?page=weddings', dict(wedding, csrf='bad'))[0] == 403
                assert sql_number('SELECT COUNT(*) FROM cms_weddings') == 0
                for bad in [dict(wedding, wedding_date='2026-02-30'), dict(wedding, title=''), dict(wedding, location='x'*201)]:
                    assert admin.request('/admin/?page=weddings', bad)[0] == 422
                s, h, b = admin.request('/admin/?page=weddings', wedding); assert s == 303
                wedding_id = int(re.search(r'edit=(\d+)', h['Location'])[1])
                s, h, b = admin.request(h['Location']); first = code(b)
                assert '&lt;script&gt;' in b and 'Ana & Marko <script>' not in b
                stored = php(f"echo cms_db()->query('SELECT code_hash FROM cms_weddings WHERE id={wedding_id}')->fetchColumn();")
                assert stored == hashlib.sha256(first.encode()).hexdigest() and stored != first
                s, h, svg = admin.request('/admin/?page=weddings', dict(action='qr_download', id=wedding_id, csrf=token)); assert s == 200 and 'attachment' in h['Content-Disposition']
                size = int(re.search(r'viewBox="0 0 (\d+)', svg)[1]); image = Image.new('L', (size*8, size*8), 255); draw = ImageDraw.Draw(image)
                for x,y in re.findall(r'M(\d+) (\d+)h1v1h-1z', svg):
                    x,y=int(x)*8,int(y)*8;draw.rectangle((x,y,x+7,y+7),fill=0)
                decoded = zxingcpp.read_barcode(image)
                assert decoded and decoded.text == 'https://marrydj.com/review/#code='+first
                # Editing leaves the code unchanged.
                assert admin.request('/admin/?page=weddings', dict(wedding,id=wedding_id,title='Edited wedding'))[0] == 303
                assert code(admin.request(f'/admin/?page=weddings&edit={wedding_id}')[2]) == first

                translations = json.loads((ROOT/'review/translations.json').read_text(encoding='utf-8'))
                assert all(set(v)==set(translations['de']) and all(isinstance(t,str) and t for t in v.values()) for v in translations.values())
                for lang, messages in translations.items():
                    s,h,b=guest.request('/review/?lang='+lang)
                    assert s==200 and 'lang="'+lang+'"' in b and html.escape(messages['title'],quote=True) in b
                    assert 'Secure' in h.get('Set-Cookie','') or guest.cookie
                    assert 'no-store' in h['Cache-Control'] and h['Referrer-Policy']=='no-referrer'
                s,h,b=guest.request('/review/');gt=csrf(b)
                assert guest.request('/review/',dict(action='open',csrf='bad',code=first))[0]==403
                assert guest.request('/review/',dict(action='open',csrf=gt,code='a'*64))[0]==422
                assert guest.request('/review/',dict(action='submit',csrf=gt,stars='5',display_name='Guest',body='Without code attempted',consent='1'))[0]==422
                s,h,b=guest.request('/review/',dict(action='open',csrf=gt,code=first));assert s==303
                s,h,b=guest.request('/review/?lang=hr');gt=csrf(b)
                assert 'Edited wedding' in b and '2026-09-20' in b and 'PRIVATE NOTE' not in b
                payload=dict(action='submit',csrf=gt,stars='5',display_name='<img src=x>',body='<script>alert(1)</script> Great party!',consent='1')
                for bad in [dict(payload,stars='0'),dict(payload,stars='6'),dict(payload,consent='0'),dict(payload,display_name='x'*81),dict(payload,body='short'),dict(payload,body='x'*3001),dict(payload,csrf='bad')]:
                    assert guest.request('/review/?lang=hr',bad)[0] in (422,403)
                assert sql_number('SELECT COUNT(*) FROM cms_reviews')==0
                s,h,b=guest.request('/review/?lang=hr',payload);assert s==303
                s,h,b=guest.request('/review/?lang=hr');assert html.escape(translations['hr']['success'],quote=True) in b
                assert guest.request('/review/?lang=hr',dict(payload,csrf=csrf(b)))[0]==409
                assert sql_number('SELECT COUNT(*) FROM cms_reviews')==1
                assert json.loads(anon.request('/review/feed.php')[2])=={'reviews':[]}
                review_id=sql_number('SELECT MAX(id) FROM cms_reviews')
                s,h,b=admin.request('/admin/?page=reviews');assert '&lt;script&gt;' in b and '<script>alert' not in b
                moderation=dict(action='review_moderate',id=review_id,status='approved',csrf=token)
                assert admin.request('/admin/?page=reviews',dict(moderation,csrf='bad'))[0]==403
                assert admin.request('/admin/?page=reviews',dict(moderation,status='invented'))[0]==422
                assert admin.request('/admin/?page=reviews',moderation)[0]==303
                feed=json.loads(anon.request('/review/feed.php')[2])['reviews'];assert len(feed)==1 and set(feed[0])=={'stars','display_name','body','language','wedding_date','location'}
                assert feed[0]['language']=='hr' and feed[0]['body']==payload['body']
                assert feed[0]['wedding_date']=='2026-09-20' and feed[0]['location']=='Poreč & Rovinj'
                assert 'PRIVATE NOTE' not in json.dumps(feed) and 'Edited wedding' not in json.dumps(feed)
                assert admin.request('/admin/?page=weddings',dict(wedding,id=wedding_id,wedding_date='2026-09-21',location='Opatija'))[0]==303
                updated=json.loads(anon.request('/review/feed.php')[2])['reviews'][0]
                assert updated['location']=='Opatija' and updated['wedding_date']=='2026-09-21'
                assert sql_number('SELECT COUNT(*) FROM cms_reviews')==1
                for status in ['rejected','approved','pending']:
                    assert admin.request('/admin/?page=reviews',dict(moderation,status=status))[0]==303
                    assert bool(json.loads(anon.request('/review/feed.php')[2])['reviews'])==(status=='approved')
                # An already open guest session must lose access when its code rotates.
                guest2=Client();_,_,b=guest2.request('/review/');assert guest2.request('/review/',dict(action='open',csrf=csrf(b),code=first))[0]==303
                _,_,b=guest2.request('/review/');gt2=csrf(b)
                assert admin.request('/admin/?page=weddings',dict(action='code_rotate',id=wedding_id,csrf=token))[0]==303
                second=code(admin.request(f'/admin/?page=weddings&edit={wedding_id}')[2]);assert second!=first
                assert guest2.request('/review/',dict(payload,csrf=gt2))[0]==422
                assert sql_number('SELECT COUNT(*) FROM cms_reviews')==1
                for action in ['code_disable','wedding_disable']:
                    assert admin.request('/admin/?page=weddings',dict(action=action,id=wedding_id,csrf=token))[0]==303
                    fresh=Client();_,_,b=fresh.request('/review/')
                    assert fresh.request('/review/',dict(action='open',csrf=csrf(b),code=second))[0]==422
                assert admin.request('/admin/?page=weddings',dict(action='code_rotate',id=wedding_id,csrf=token))[0]==422
                assert admin.request('/admin/?page=weddings',dict(action='wedding_enable',id=wedding_id,csrf=token))[0]==303
                assert sql_number('SELECT code_active FROM cms_weddings LIMIT 1')==0
                # Stored identifiers are keyed hashes and expire, never raw IPs.
                limits=json.loads(php("echo json_encode(cms_db()->query('SELECT bucket,expires_at FROM cms_review_limits')->fetchAll(PDO::FETCH_ASSOC));"))
                assert limits and all(re.fullmatch('[a-f0-9]{64}',r['bucket']) and int(r['expires_at'])<=time.time()+900 for r in limits)
                # Use a separate bucket for exact boundary checks without polluting real HTTP throttles.
                outcome=php("$d=cms_db();for($i=0;$i<3;$i++)echo review_rate($d,'test','fixture',2)?'1':'0';")
                assert outcome=='110'
                php("cms_db()->exec('UPDATE cms_review_limits SET expires_at=0');")
                subprocess.run(PHP+['tools/cms_cleanup.php'],cwd=ROOT,env=env,check=True,capture_output=True)
                assert sql_number('SELECT COUNT(*) FROM cms_review_limits')==0
                assert anon.request('/review/',method='PUT')[0]==405
                assert anon.request('/review/feed.php',data={})[0]==405
                # Native public endpoint throttle, including across fresh sessions.
                for _ in range(61):
                    new=Client();_,_,b=new.request('/review/');s,h,b=new.request('/review/',dict(action='open',csrf=csrf(b),code='a'*64))
                assert s==429 and h['Retry-After']=='900'
                # No JavaScript execution is needed to use the manually entered guest code.
                assert 'noscript' in b
            finally:
                server.terminate();server.wait(timeout=10)
        logs=(private/'server.log').read_text(errors='replace')
        assert not any(s in logs for s in ['PHP Fatal','PHP Warning','PHP Deprecated',first,env['REVIEW_TEST_PASSWORD']])
    print('PASS phase 2: migration/backup/idempotency, authenticated wedding CRUD, hash-only codes, independent QR decode, four languages, guest validation/CSRF, pending-only submissions, moderation/escaping, code revocation, throttling/cleanup, private-data minimization, unchanged admin security.')
    print('NOT TESTED: production migration, real LiteSpeed headers, physical phone/browser rendering.')


if __name__ == '__main__':
    run()
