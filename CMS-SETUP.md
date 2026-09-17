# CMS Phase 1 — local preparation, production approval required

No production schema migration runs during deployment. No homepage, inquiry or
WhatsApp changes. The only new public files are admin/index.php, admin/core.php
and admin/admin.css. The exact allowlist rejects arbitrary admin files, SQL,
setup scripts, secrets and uploads. Do not push master before approval: its
existing workflow still deploys automatically.

## Scope

Email/password login, CSRF on login/logout, 30-minute idle and 8-hour absolute
session expiry, session-ID rotation, account disable/revocation checks on each
request, HTTPS-only cookies and no-store admin responses. Rate limits count all
attempts in fixed 15-minute windows: 5/account and 25/source IP. They work across
sessions; a boundary can permit two adjacent windows' quotas. Failed counters
are not cleared by successful logins. IPs and email identifiers in counters are
HMACed with a server-only key. Remote address is used, never untrusted forwarded
headers. Verify the real client address configuration with Hostinger/CDN before
activation to avoid all visitors sharing one proxy limit.

All ten requested navigation entries exist. Only authentication and navigation
are active: module screens show "not activated", not invented metrics.
The admin UI is German in phase 1; the public website's four languages are untouched.
No public registration, password reset, user management or MFA yet. Account
recovery is a private operator task. Login input is limited to 72 bytes to avoid
bcrypt truncation; new passwords require at least 14 bytes.

## One-time Hostinger prerequisites (after explicit approval)

1. Confirm supported PHP 8.2+ in web and SSH CLI; PDO MySQL and sessions enabled.
   Confirm server HTTPS flag and session-directory access under open_basedir.
2. Create a dedicated CMS database using utf8mb4/InnoDB. Back up before changes.
   Import database/001_cms.sql manually into that EMPTY database only. This file
   creates version, user and throttle tables; later modules get separate migrations.
   MySQL DDL is not transactional: stop on failure and inspect partial results.
   Do not rerun blindly. SQL/setup/docs are never copied to public_html by Actions.
3. Create a private session directory outside public_html, owner-only permissions
   (0700), writable by PHP. Configure server-side PHP environment variables below.
   Alternatively use the protected PHP configuration described below; it works
   independently of SSH environment inheritance. A .env file is NOT automatically
   read. Do not place secrets in .htaccess, source or GitHub.
4. Use a separate limited runtime DB user (SELECT/INSERT/UPDATE/DELETE on this CMS
   database only). Schema creation uses a separate owner account.
5. Generate CMS_APP_KEY on the server using a cryptographically secure generator
   (at least 32 random bytes, encoded as hex). Save it only in private configuration.
6. Stage tools/cms_create_admin.php and admin/core.php under matching tools/ and
   admin/ directories in a PRIVATE temporary folder outside public_html. Over an
   interactive SSH terminal, run php tools/cms_create_admin.php from that folder.
   Email is prompted, password entered twice with terminal echo disabled. Hostinger
   may disable exec/shell_exec: in that case use --stdin-json through a trusted
   local helper that prompts invisibly and sends email/password/repeat as JSON
   over encrypted SSH stdin. Never embed that JSON in shell commands or files.
   The helper must use a pinned/verified SSH host key. No
   password arguments, shell history values, logs or reusable setup endpoint.
   This tool only creates the first account in an empty cms_users table. Delete
   the temporary staging files afterwards. Do not run in a recorded terminal.
7. Only after approval deploy the three allowlisted admin files. Missing config or
   schema returns generic 503 without touching public homepage or form behavior.
8. Verify login/logout, invalid CSRF, expiry, rate limit, direct module access,
   disabled user, security headers and iPhone layout against HTTPS on staging.
   Verify backup covers this DB and private config; exclude expired sessions from
   restoration. Do not import production data into CI.

## Server-only configuration

The loader first checks nonempty server environment variables. Its fallback is
`../cms-private/config.php` relative to `public_html`, returning an associative
PHP array with the variable names below. This file and all credentials are
created only on the server; no real configuration template is committed.
Require owner-only mode 0600 for the file and 0700 for private directories.
The loader rejects a resolved configuration path inside the public root or a
file readable by group/others. Keep private config outside public_html even
when staging; preserve the same public/private sibling directory structure.
Back up config privately before replacing it. CMS_APP_KEY must remain stable
across deployments. Environment values take precedence over the private file.


| Variable | Value |
|---|---|
| CMS_DB_HOST | Database hostname shown by Hostinger (often localhost; verify) |
| CMS_DB_PORT | Optional, defaults to 3306; local tests use a random loopback port |
| CMS_DB_NAME | Newly created dedicated CMS database name |
| CMS_DB_USER | Limited CMS runtime database user |
| CMS_DB_PASSWORD | Its password, entered only privately on Hostinger |
| CMS_APP_KEY | Server-generated random key, never a GitHub secret |
| CMS_SESSION_PATH | Absolute private session directory outside public_html |

Existing HOSTINGER_FTPS_* Actions secrets and WHATSAPP_* server variables stay
unchanged. No additional production GitHub Actions secrets are needed.

## Verification and backup

php tools/test_cms.php runs password, CSRF, escaping and expiry tests without DB.
CMS_TEST_DATABASE=1 enables integration tests ONLY on a disposable database
literally named cms_phase1_test on localhost/127.0.0.1. Tests create tables and leave them for inspection;
use a fresh test database each run. Never set this switch on Hostinger.
The workflow uses an isolated MySQL service with clearly non-production fixture
credentials, and runs all CMS tests before any FTPS upload.
tools/test_cms_http.py then exercises the real PHP entrypoint on loopback using
that database. A temporary, non-deployed router simulates the HTTPS server flag;
The private setup pipe is also tested with shell functions disabled, including
password mismatch and refusal to create a second initial admin. Cookie flags
and HTTP behavior are tested, but this is not a real TLS or browser
rendering test. Temporary sessions are outside the project and removed afterwards.

Local validation on 2026-09-16: PHP 8.4.25 and MariaDB 11.4.12 portable runtimes,
official archives verified with SHA-256 and kept under ignored .deployment-local/.
The local runner installs no Windows service and makes no Hostinger connection;
the test server is bound only to
127.0.0.1 and stopped after tests. Runtime HTTP tests also use data-only DB grants.
The GitHub MySQL 8 service still needs its own workflow run; do not call a local
MariaDB test a completed GitHub Actions test.

## Hostinger preparation and outstanding limitation (2026-09-16)

Private setup succeeded on PHP 8.3.33 / MariaDB 11.8.9: schema version 1 and the
initial administrator exist. Credentials and the application key are stored only
in the server's private configuration (0600); the private session directory is
0700. The pre-change public_html archive was checksum-verified. All ten existing
production files match the local reference (text line endings normalized).

On the real hosting server, private CLI integration tests passed for configuration
loading, login, password rejection, SQL injection rejection, CSRF, secure cookie
settings, strict sessions, session/CSRF rotation, account revocation/disable,
account/IP throttling and idle/absolute expiry. Test records were rolled back.
These are not public LiteSpeed/HTTPS or browser tests: /admin remains unpublished.
Public HTTPS behavior and mobile rendering must be verified after release approval.

Hostinger hPanel limitation reported by the owner: changing the CMS database user
to SELECT, INSERT, UPDATE and DELETE returns a server error and does not save.
The existing account has IS_GRANTABLE=NO (global USAGE only), so no SSH/MySQL
permission-change retry is authorized. Existing database-scoped setup privileges,
including CREATE, ALTER and DROP, remain in place. This is a documented open
least-privilege exception: compromise of the runtime account could also modify
or remove the CMS schema. Resolve through Hostinger support/hPanel when available;
do not silently claim that production uses data-only grants. No permission changes
or public deployment were performed in response to the hPanel failure.

Release requires the owner's explicit approval with this limitation disclosed.
No automated migration, database deletion or WhatsApp configuration change is
part of deployment. Existing public files must retain their contents.

Before release also run the integration suite with the actual Hostinger DB engine
version on staging. Daily hosting backups must include database and private
configuration. Test recovery into a separate database. Schema rollback is NOT an
automated DROP: if disabling phase 1, deny /admin access and retain its database
until recovery is planned. Do not change the homepage entrypoint.

Future content and media are server data, not Git artifacts. Phase 1 intentionally
does not create speculative tables for reviews, uploads, analytics or inquiries.
