# Changelog

## 1.0.0 (2026-11-23)

First release, for Silverstripe CMS 5.4. Same features as 2.0.0 for CMS 6.

- Client task `sake dev/tasks/composer-report`: runs `composer outdated` and `composer audit` and posts the result to a monitor, with `--dry-run` and exit codes 0 to 3 (passed on with `exit()`, since CMS 5 sake always exits with 0).
- Monitor, enabled with `COMPOSER_REPORT_SERVER_ENABLED=true`: authenticated ingest endpoint with size and rate limits, a "Composer monitor" CMS section with project status, report details and history, and one-time token generation.
- Email notifications for new advisories, stale projects and failing audits via `sake dev/tasks/composer-report-notify`.
- English and Dutch translations.
