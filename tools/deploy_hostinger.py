"""Allowlisted explicit FTPS deployment. --check never connects to a server."""
import argparse
import ftplib
import hashlib
import ipaddress
import os
from pathlib import Path, PurePosixPath
import re
import ssl
import sys

ROOT_FILES = {
    'index.html', 'style.css', 'script.js', 'translations.js',
    'send-offer.php', 'whatsapp-notify.php',
}
MEDIA_TYPES = {'images': {'.jpg', '.jpeg', '.png', '.webp', '.avif', '.svg'},
               'videos': {'.mp4', '.webm'}}
ADMIN_FILES = {'admin/index.php', 'admin/core.php', 'admin/admin.css', 'admin/.htaccess'}
WEBROOT = '/public_html'


def deployment_files(root):
    root = Path(root).resolve()
    manifest = root / 'deploy-files.txt'
    if manifest.is_symlink():
        raise ValueError('Deployment list must not be a symbolic link.')
    names = manifest.read_text(encoding='utf-8-sig').splitlines()
    files = []
    for name in names:
        if not name or name != name.strip() or not re.fullmatch(r'[A-Za-z0-9_./-]+', name):
            raise ValueError('Deployment list contains an invalid path.')
        parts = name.split('/')
        p = PurePosixPath(name)
        if p.is_absolute() or (name != 'admin/.htaccess' and any(not part or part.startswith('.') for part in parts)):
            raise ValueError('Absolute, hidden or parent paths are forbidden.')
        allowed = name in ROOT_FILES or name in ADMIN_FILES or (
            len(parts) >= 2 and parts[0] in MEDIA_TYPES
            and p.suffix.lower() in MEDIA_TYPES[parts[0]]
        )
        if not allowed or name in files:
            raise ValueError('Unapproved or duplicate deployment file: ' + name)
        source = root / name
        if any((root.joinpath(*parts[:i])).is_symlink() for i in range(1, len(parts) + 1)):
            raise ValueError('Symbolic links are forbidden: ' + name)
        if not source.is_file() or not source.resolve().is_relative_to(root):
            raise ValueError('Missing or out-of-project deployment file: ' + name)
        with source.open('rb') as handle:
            if handle.read(100).startswith(b'version https://git-lfs.github.com/spec/v1'):
                raise ValueError('Unresolved Git LFS pointer: ' + name)
        files.append(name)
    if not ROOT_FILES.issubset(files):
        raise ValueError('The complete PHP/frontend release must be in deploy-files.txt.')
    if ADMIN_FILES.intersection(files) and not ADMIN_FILES.issubset(files):
        raise ValueError('Admin deployment must contain all approved files.')
    # HTML last reduces the chance of referencing assets not yet uploaded.
    return sorted(files, key=lambda name: (name == 'index.html', name))


class SessionFTP_TLS(ftplib.FTP_TLS):
    """Reuse the verified control TLS session for protected passive transfers."""
    def ntransfercmd(self, cmd, rest=None):
        conn, size = ftplib.FTP.ntransfercmd(self, cmd, rest)
        if self._prot_p:
            try:
                conn = self.context.wrap_socket(
                    conn, server_hostname=self.host, session=self.sock.session)
            except Exception:
                conn.close()
                raise
        return conn, size


def connection_settings(env):
    keys = ['HOSTINGER_FTPS_HOST', 'HOSTINGER_FTPS_IP',
            'HOSTINGER_FTPS_USER', 'HOSTINGER_FTPS_PASSWORD']
    if any(not env.get(key) for key in keys):
        raise ValueError('Missing required GitHub Actions FTPS secrets.')
    host, address, user, password = [env[key] for key in keys]
    if not re.fullmatch(r'[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+', host):
        raise ValueError('FTPS hostname must be a valid FQDN.')
    ipaddress.IPv4Address(address)
    if any(c in user + password for c in '\r\n\x00'):
        raise ValueError('FTPS credentials contain invalid control characters.')
    return host, address, user, password


def upload_files(ftp, root, files):
    ftp.cwd(WEBROOT)
    # Exercise a protected passive data connection before writing anything.
    ftp.nlst()
    for name in files:
        ftp.cwd(WEBROOT)
        parts = name.split('/')
        for directory in parts[:-1]:
            try:
                ftp.cwd(directory)
            except ftplib.error_perm:
                ftp.mkd(directory)
                ftp.cwd(directory)
        source = Path(root) / name
        expected = hashlib.sha256()
        with source.open('rb') as handle:
            for block in iter(lambda: handle.read(1024 * 1024), b''):
                expected.update(block)
        with source.open('rb') as handle:
            ftp.storbinary('STOR ' + parts[-1], handle, blocksize=256 * 1024)
        actual = hashlib.sha256()
        ftp.retrbinary('RETR ' + parts[-1], actual.update, blocksize=256 * 1024)
        if actual.digest() != expected.digest():
            raise RuntimeError('Uploaded file verification failed.')
        print('Uploaded and verified: ' + name, flush=True)
    # No delete, recursive sync, remote temporary files or state files.


def main():
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check', action='store_true')
    mode.add_argument('--deploy', action='store_true')
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    phase = 'local validation'
    ftp = None
    try:
        files = deployment_files(root)
        print(f'Allowlist validated: {len(files)} production files.')
        if args.check:
            return 0
        phase = 'secret validation'
        host, address, user, password = connection_settings(os.environ)
        context = ssl.create_default_context()
        context.minimum_version = ssl.TLSVersion.TLSv1_2
        ftp = SessionFTP_TLS(context=context, timeout=45)
        phase = 'FTP connection and greeting'
        ftp.connect(address, 21)
        # Connect to the hPanel FTP IP, validate TLS against the server FQDN.
        ftp.host = host
        phase = 'AUTH TLS and certificate validation'
        ftp.auth()
        phase = 'FTP login'
        ftp.login(user, password)
        password = None
        phase = 'protected passive data connection'
        ftp.prot_p()
        ftp.set_pasv(True)
        # IPv4 PASV uses the verified control peer address, not an advertised IP.
        ftp.trust_server_pasv_ipv4_address = False
        phase = 'allowlisted upload and checksum verification'
        upload_files(ftp, root, files)
        print('Deployment complete. Unlisted remote files were not changed.')
        return 0
    except Exception as error:
        # Never emit raw exceptions, server replies, credentials or tracebacks.
        print(f'Deployment failed during {phase} ({type(error).__name__}).', file=sys.stderr)
        return 1
    finally:
        if ftp is not None:
            ftp.close()


if __name__ == '__main__':
    sys.exit(main())
