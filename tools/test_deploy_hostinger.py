"""Offline tests: no secrets and no server connections."""
import ftplib
import contextlib
import io
from pathlib import Path
import tempfile
import unittest
from deploy_hostinger import ROOT_FILES, deployment_files, upload_files


class DeploymentTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.names = sorted(ROOT_FILES) + ['videos/example.mp4']
        for name in self.names:
            p = self.root / name
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(b'test-content')
        self.manifest(self.names)

    def manifest(self, names):
        (self.root / 'deploy-files.txt').write_text('\n'.join(names), encoding='utf-8')

    def test_allowlist_and_html_last(self):
        files = deployment_files(self.root)
        self.assertEqual(set(files), set(self.names))
        self.assertEqual(files[-1], 'index.html')

    def test_reject_nonproduction_paths(self):
        for name in ['../private.php', '/etc/passwd', '.env', '.github/workflows/x.yml',
                     '.vscode/sftp.json', 'tools/check-site.cjs', 'WHATSAPP-SETUP.md',
                     'videos/../send-offer.php', 'images/.secret.jpg', 'images/run.php']:
            with self.subTest(name=name):
                self.manifest(self.names + [name])
                with self.assertRaises(ValueError):
                    deployment_files(self.root)

    def test_missing_duplicate_and_symlink(self):
        for names in [self.names + ['style.css'], self.names + ['images/missing.jpg'], ['index.html']]:
            self.manifest(names)
            with self.assertRaises(ValueError):
                deployment_files(self.root)
        self.manifest(self.names)
        p = self.root / 'style.css'
        p.unlink()
        try:
            p.symlink_to(self.root / 'script.js')
        except OSError:
            return  # Windows may lack local symlink privileges.
        with self.assertRaises(ValueError):
            deployment_files(self.root)

    def test_no_unlisted_writes_or_deletes(self):
        class FakeFTP:
            def __init__(self):
                self.folder = '/public_html'
                self.remote = {'/public_html/private.txt': b'keep-me'}
            def cwd(self, name):
                self.folder = name if name.startswith('/') else self.folder + '/' + name
            def nlst(self):
                return ['private.txt']
            def storbinary(self, cmd, handle, **kwargs):
                self.remote[self.folder + '/' + cmd.removeprefix('STOR ')] = handle.read()
            def retrbinary(self, cmd, callback, **kwargs):
                callback(self.remote[self.folder + '/' + cmd.removeprefix('RETR ')])
        ftp = FakeFTP()
        with contextlib.redirect_stdout(io.StringIO()):
            upload_files(ftp, self.root, deployment_files(self.root))
        self.assertEqual(ftp.remote['/public_html/private.txt'], b'keep-me')
        self.assertEqual(set(ftp.remote), {'/public_html/' + n for n in self.names} | {'/public_html/private.txt'})

    def test_passive_preflight_failure_prevents_upload(self):
        class FailedFTP:
            def cwd(self, _): pass
            def nlst(self): raise ftplib.error_temp('test failure')
            def storbinary(self, *args, **kwargs): raise AssertionError('Must not upload')
        with self.assertRaises(ftplib.error_temp):
            upload_files(FailedFTP(), self.root, deployment_files(self.root))


if __name__ == '__main__':
    unittest.main()
