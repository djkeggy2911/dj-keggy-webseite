"""Additional migration failure/restore tests; only a disposable loopback DB."""
import json
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parent.parent
PHP = [os.environ.get('CMS_TEST_PHP', 'php')] + json.loads(os.environ.get('CMS_TEST_PHP_ARGS', '[]'))


def run():
    assert os.environ.get('CMS_TEST_DATABASE') == '1'
    assert os.environ.get('CMS_DB_NAME') == 'cms_phase1_test'
    assert os.environ.get('CMS_DB_HOST') in ('localhost', '127.0.0.1')
    env = os.environ.copy()

    def php(source):
        r = subprocess.run(PHP, input="<?php require 'admin/core.php'; " + source,
                           text=True, cwd=ROOT, env=env, capture_output=True)
        assert r.returncode == 0, r.stderr
        return r.stdout

    def migrate(args=('--apply-phase2',)):
        return subprocess.run(PHP + ['tools/cms_migrate.php', *args], cwd=ROOT,
                              env=env, text=True, capture_output=True)

    with tempfile.TemporaryDirectory(prefix='marrydj-migration-') as temp:
        private = Path(temp)
        (private/'sessions').mkdir(mode=0o700)
        env['CMS_SESSION_PATH'] = str(private/'sessions')
        assert migrate(()).returncode == 1
        assert php("echo cms_db()->query('SELECT MAX(version) FROM cms_schema_versions')->fetchColumn();") == '1'
        assert not (private/'backups').exists()
        # Partial DDL must never be overwritten or silently adopted.
        php("cms_db()->exec('CREATE TABLE cms_weddings (sentinel INT)'); cms_db()->exec('INSERT INTO cms_weddings VALUES(17)');")
        assert migrate().returncode == 1
        assert php("echo cms_db()->query('SELECT sentinel FROM cms_weddings')->fetchColumn();") == '17'
        assert not (private/'backups').exists()
        php("cms_db()->exec('DROP TABLE cms_weddings');")  # guarded disposable fixture only
        env['CMS_SESSION_PATH'] = str(ROOT/'admin')
        assert migrate().returncode == 1
        assert php("echo cms_db()->query('SELECT MAX(version) FROM cms_schema_versions')->fetchColumn();") == '1'
        env['CMS_SESSION_PATH'] = str(private/'sessions')
        php("cms_db()->prepare('INSERT INTO cms_users(email,password_hash) VALUES(?,?)')->execute(['restore@example.invalid',cms_hash(bin2hex(random_bytes(20)))]);")
        result = migrate()
        assert result.returncode == 0, result.stderr
        backup = next((private/'backups').glob('*.sql'))
        env['CMS_RESTORE_FIXTURE'] = str(backup)
        # Restore under separate names in the same isolated test database. No drops
        # or writes touch original fixture tables or any real hosting database.
        php("$s=str_replace('`cms_','`restore_',file_get_contents(getenv('CMS_RESTORE_FIXTURE')));cms_db()->exec($s);")
        assert php("echo cms_db()->query('SELECT MAX(version) FROM restore_schema_versions')->fetchColumn();") == '1'
        assert php("echo cms_db()->query('SELECT COUNT(*) FROM restore_users r JOIN cms_users u ON r.email=u.email AND r.password_hash=u.password_hash')->fetchColumn();") == '1'
        assert php("echo cms_db()->query('SELECT MAX(version) FROM cms_schema_versions')->fetchColumn();") == '2'
    print('PASS: migration requires explicit flag, refuses partial schema/unsafe backup path without mutation, preserves original records, and backup restores successfully in isolated tables.')


if __name__ == '__main__':
    run()
