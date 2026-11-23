# Changelog

## 2.0.0 (2026-11-23)

First release, for Silverstripe CMS 6.

- Client task `sake tasks:composer-report`: runs `composer outdated` and `composer audit` and posts the result to a monitor, with `--dry-run` and exit codes 0 to 3.
- Monitor, enabled with `COMPOSER_REPORT_SERVER_ENABLED=true`: authenticated ingest endpoint with size and rate limits, a "Composer monitor" CMS section with project status, report details and history, and one-time token generation.
- Email notifications for new advisories, stale projects and failing audits via `sake tasks:composer-report-notify`.
- English and Dutch translations.
