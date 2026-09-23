# Phase 2 — local implementation, deployment approval required

No production data, server configuration, database rights or existing sessions
were changed while implementing this phase. The Hostinger hPanel rights issue
documented in CMS-SETUP.md remains accepted and unresolved; do not retry GRANT or
REVOKE on Hostinger. Phase 1 authentication and its /admin CSP remain unchanged.

## Data and behavior

- Weddings have a title, date, location, internal note and active status. Disabling
  a wedding also disables its guest code. Re-enabling does not reactivate an old
  code: generate a new one explicitly.
- Guest codes contain 256 random bits. Only SHA-256 is stored in the database.
  The plaintext code is held temporarily in the private authenticated admin
  session so it can be displayed/downloaded during that session. It cannot be
  recovered from the database later. Save the QR then, or rotate the code later.
  Rotation immediately revokes old links and already-open guest forms.
- QR uses the fixed production HTTPS URL with the code in `#code=...`, not a
  query parameter. The local guest script removes the fragment before POSTing
  the code. Without JavaScript, guests may enter the code manually. The guest
  cookie is separate from the admin cookie and scoped to /review. No external
  QR or translation service receives tokens or review content.
- Reviews require 1–5 stars, display name (1–80 Unicode characters), text
  (10–3000 characters), and publication consent. They always start pending.
  Moderation allows approved, rejected, and pending again (hidden). Only approved
  and consented reviews appear in the public JSON feed. Wedding names, notes,
  dates, code hashes and moderation metadata are never included in the feed.
- Reviews remain in their submitted language; quotes are not machine-translated.
  The guest interface and all form/error/consent text support DE/HR/EN/IT.
  Homepage headings use the existing four-language switcher. The existing
  testimonial section remains hidden when empty or when the feed is unavailable.
- No email or telephone is requested or stored for reviewers. A rotating HMAC
  of the source IP and 15-minute window supports limits (60 code exchanges and
  30 submissions per window). Raw IPs are not stored by the CMS. Web hosting
  access logs are separate hosting infrastructure. Fixed-window boundaries can
  permit two adjacent quotas. Session CSRF and unique submission hashes prevent
  accidental duplicate POSTs; this is not a one-person identity verification.
- Expired rate rows are removed on requests and by the required 15-minute cron.
  With that cron running, identifiers remain at most 30 minutes. Backups of rate
  data require the same retention discipline; do not back up this transient table.
- Approved reviews stay visible if their wedding is later deactivated; wedding
  activation controls new submissions, not prior publication consent. Hide the
  review explicitly for a withdrawal. The public notice gives the contact email.

## One-time release sequence (only after explicit approval)

1. Back up current public files and private configuration on the server. Keep
   backups outside public_html with owner-only access. Verify recoverability.
2. Deploy the checked code using the existing 25-file allowlist. New admin code
   accepts schema 1 or 2. Until migration, phase-2 modules stay disabled and the
   guest/feed endpoints fail closed; the homepage hides an unavailable feed.
   Do NOT migrate first: the old phase-1 admin only accepted schema version 1.
3. Over the existing verified SSH connection, stage these files in a private
   `cms-maintenance` sibling of public_html (directories 0700, files 0600):
   `admin/core.php`, `tools/cms_migrate.php`, `tools/cms_cleanup.php`, and
   `database/002_reviews.sql`. This layout uses the existing sibling
   `cms-private/config.php`, without credentials in command arguments or Git.
4. Install a named cron entry every 15 minutes for PHP CLI executing the private
   `cms-maintenance/tools/cms_cleanup.php`. Preserve every unrelated cron entry.
   The cleanup exits without writes under schema 1, so install it before migration.
   Verify its operation and monitor failures. If Hostinger prevents SSH crontab
   changes, use hPanel Cron Jobs for this one entry before activating phase 2.
5. Run privately: `php tools/cms_migrate.php --apply-phase2` from cms-maintenance.
   The script obtains an advisory lock, verifies schema 1 and absence of partial
   phase-2 tables, creates and SHA-256-verifies an owner-only backup of phase-1
   tables, then applies the additive SQL and records schema version 2 last.
   Database secrets are read from private config. Backup includes password hashes:
   never publish it. If any DDL fails, stop and inspect; no DROP or blind retry.
   Re-running a successfully completed version is a no-op.
6. Verify real HTTPS headers, admin actions, guest link/QR and moderation on
   Hostinger. Do not publish test reviews. Verify /lib/, SQL, maintenance tools,
   private configuration and backups are inaccessible publicly. Browser/iPhone
   layout, printing and physical QR scanning still need a real browser/device.

No new credentials or GitHub secrets are needed. No manual database creation or
hPanel permission changes are needed. SSH should cover setup and cleanup cron;
only a hosting restriction on crontab would require the hPanel action above.

## Rollback

Keep a copy of the immediately preceding approved code and private database
backup. If deployment is interrupted before migration, schema 1 still works with
the new controller. After migration, do not blindly revert to phase-1's strict
schema-1 controller or drop the new tables: retain data and use an explicitly
planned rollback. Homepage can hide an unavailable feed without affecting its
inquiry form or WhatsApp. No production migration is part of GitHub Actions.

## Local tests

`tools/test_reviews.py` refuses any database except explicitly opted-in
`cms_phase1_test` on loopback. It expects schema 1 already created by the existing
CMS fixture. It runs the real migration in an isolated temporary private path,
checks backup checksum and idempotency, and exercises actual PHP HTTP endpoints.
The local Windows runner uses PHP 8.4, MariaDB 11.4 and a separate data-only runtime
user; migration uses only the disposable test owner's grants. HTTPS is simulated
only by the isolated loopback router, never bypassed in production application code.

`node tools/test_reviews_frontend.cjs` tests safe quote rendering, fragment removal
before POST, language-key/UTF-8 integrity and the guest CSP. Deployment tests enforce
the exact public file set; documentation, SQL, tools and backups are excluded.
The GitHub workflow repeats tests against its disposable MySQL service before any
upload. No workflow has been run for this uncommitted phase-2 work.

Additional final checks (2026-09-23): `tools/test_review_migration.py` passed on a
fresh disposable schema-1 database: missing explicit flag, existing partial DDL
and an unsafe backup destination all stop without modifying the fixture. A real
backup was restored into isolated table names and its original user/hash and
schema version compared successfully. Run this test on its own fresh schema-1
fixture, not after the end-to-end suite has migrated that fixture to version 2.
The final PHP/JavaScript syntax, vendor checksum, private-file exclusion and
secret-pattern checks passed. Existing successful phase-2 HTTP/QR/moderation
and frontend tests were retained; no production test or deployment was performed.
