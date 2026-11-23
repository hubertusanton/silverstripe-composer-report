# webium/silverstripe-composer-report

Sends `composer outdated` and `composer audit` reports from Silverstripe sites to a central monitor, so you get one overview of outdated packages and security advisories across all your projects. No client site exposes its `composer.lock` or any other endpoint.

One module, two roles:

- **Client** (every site): a CLI task runs Composer and posts the result to the monitor.
- **Monitor** (one site): receives the reports, shows them in the CMS and sends email notifications. Off by default.

| Branch | Version | Silverstripe CMS | PHP |
|---|---|---|---|
| `2` | 2.x | 6 | 8.3+ |
| `1` | 1.x | 5.4 | 8.1+ |

## Contents

- [Requirements](#requirements)
- [Client](#client)
- [Monitor](#monitor)
- [Hosting notes](#hosting-notes)
- [Security](#security)
- [Development](#development)

## Requirements

- Composer 2.4 or newer on the site's server; 2.7+ is recommended (abandoned packages and advisory severity).
- PHP's process functions `proc_open`, `proc_get_status`, `proc_close` and `proc_terminate` must be enabled for the user that runs the task.

## Client

### Installation

Require the version for your CMS and flush:

```bash
composer require webium/silverstripe-composer-report:^2.0   # CMS 6
composer require webium/silverstripe-composer-report:^1.0   # CMS 5
vendor/bin/sake flush
```

### Configuration

In `.env`. Quote the token:

```dotenv
COMPOSER_REPORT_ENDPOINT="https://monitor.example.com/composer-report/ingest"
COMPOSER_REPORT_PROJECT="client-x"
COMPOSER_REPORT_TOKEN="crt_..."
```

- `COMPOSER_REPORT_PROJECT` is the project's slug in the monitor: lowercase letters, digits and single hyphens.
- The endpoint must use `https://`. Use the exact URL, without a redirect (no www or http variant): the client does not follow redirects.
- The token comes from the monitor ("Generate new token"). It is never printed.

Optional YAML, with the defaults:

```yaml
Webium\ComposerReport\Client\ClientSettings:
  composer_bin: composer          # or a list, e.g. ['/opt/php83/bin/php', '/usr/local/bin/composer.phar']
  timeout: 120                    # seconds per Composer command
  http_timeout: 15                # seconds for the request to the monitor
  direct_only: true               # composer outdated --direct
  composer_root_version: dev-main # set when the environment has none; null leaves it unset
```

`COMPOSER_REPORT_COMPOSER_BIN` in `.env` overrides `composer_bin`, for servers where Composer lives elsewhere (split on spaces, e.g. `"/opt/php83/bin/php /usr/local/bin/composer.phar"`). The PHP that runs Composer decides what "latest" means, so on hosts with several PHP versions point it at the site's PHP.

### Running

```bash
vendor/bin/sake tasks:composer-report            # run and send
vendor/bin/sake tasks:composer-report --dry-run  # print the payload, send nothing
vendor/bin/sake tasks:composer-report -v         # also show the Composer commands and output
```

On CMS 5 the task is `vendor/bin/sake dev/tasks/composer-report`, with the same `--dry-run` and `--verbose` (or `-v`) options; other arguments are ignored. Its output starts with sake's "Running Task" line.

The task only runs on the command line. A dry run needs only `COMPOSER_REPORT_PROJECT`.

| Exit code | Meaning |
|---|---|
| 0 | Report sent (or dry run without Composer errors) |
| 1 | Configuration missing or invalid |
| 2 | Composer failed (no usable JSON, process functions disabled, timeout); the report is still sent with the errors |
| 3 | The monitor could not be reached or rejected the report |

When several apply, the order is 1, 3, 2, 0. Errors go to stderr, so `-q` keeps stdout empty and cron only mails on problems.

### Cron

Once a day:

```cron
# CMS 5
0 3 * * * cd /var/www/site && vendor/bin/sake dev/tasks/composer-report
# CMS 6
0 3 * * * cd /var/www/site && vendor/bin/sake tasks:composer-report
```

Better in practice:

- Vary the minute per site, so not every site hits Packagist at :00.
- Discard stdout, so cron only mails when something fails:

  ```cron
  # CMS 6
  17 3 * * * cd /var/www/site && vendor/bin/sake tasks:composer-report -q
  # CMS 5
  17 3 * * * cd /var/www/site && vendor/bin/sake dev/tasks/composer-report > /dev/null
  ```

- For random jitter, set `SHELL=/bin/bash` in the crontab and use `sleep $((RANDOM \% 3600)) &&` before the command (`%` must be escaped in a crontab).
- Cron often has no `HOME`; the task then gives Composer its own `COMPOSER_HOME` under Silverstripe's temp folder.

## Monitor

### Enabling

On the monitor site only, in `.env`:

```dotenv
COMPOSER_REPORT_SERVER_ENABLED=true
```

Leave `true` **unquoted**: only an unquoted `true` (or `yes`) enables the monitor, a quoted or OS-level string does not. Flush after every change of this variable, then build the database:

```bash
vendor/bin/sake db:build --flush
```

Without this variable the site has no ingest route, no menu item, no permission and no notification task. The three tables are still created on every install.

### Projects and tokens

1. In the CMS, open "Composer monitor" and add a project. Its slug must match `COMPOSER_REPORT_PROJECT` on the site.
2. Save, then click "Generate new token". The token is shown once; put it in the site's `.env` as `COMPOSER_REPORT_TOKEN`. Generating a new token invalidates the old one immediately.

Editing projects requires sudo mode (re-entering your password).

Permissions:

- Viewing: access to the "Composer monitor" section.
- Creating, editing, deleting and generating tokens: "Manage Composer monitor projects and tokens" (`COMPOSER_REPORT_MANAGE`).

### What you see

- A project list with the latest counts, a status (Advisories, Error, OK, Inactive) and a stale column ("Stale" when no report arrived within `stale_after_days`, "Audit failing" when reports arrive but the audit keeps failing). Projects with advisories come first.
- Per project: the latest report (advisories with CVE and link, outdated packages with major/minor/patch, errors, environment) and the history of reports.

### Notifications

Add a cron job on the monitor:

```cron
*/15 * * * * cd /var/www/monitor && vendor/bin/sake tasks:composer-report-notify -q
```

On CMS 5: `vendor/bin/sake dev/tasks/composer-report-notify > /dev/null`.

It sends an email for new advisories (compared with the previous report; the first report of a project counts as new), when a project goes stale and when its audit keeps failing. Each event is sent once; failed mails are retried up to `notify_max_attempts` times.

Recipient, in this order: the project's "Notification email", then `COMPOSER_REPORT_NOTIFY_EMAIL`, then YAML `notify_email`. Sender: `COMPOSER_REPORT_NOTIFY_FROM`, then YAML `notify_from`, then `Email.admin_email`.

### Settings

```yaml
Webium\ComposerReport\Server\ServerSettings:
  max_body_bytes: 262144            # larger requests get 413
  rate_limit_per_hour: 10           # reports per project per hour
  auth_failure_limit_per_hour: 20   # failed authentications per IP (IPv6 per /64)
  retention_count: 30               # reports kept per project; never below rate_limit_per_hour
  stale_after_days: 3
  notify_email: ''
  notify_from: ''
  notify_max_attempts: 5
```

### Deployment checklist

- Limit the request body for `/composer-report/ingest` in the web server as well (e.g. nginx `client_max_body_size 256k;`); the body is read into memory before PHP sees it.
- Behind a proxy or load balancer, set `SS_TRUSTED_PROXY_IPS` to the exact proxy IPs (never `*`), otherwise every client shares one IP for the failed-auth limit.
- nginx/FPM and CGI setups can drop the `Authorization` header; the monitor falls back to `REDIRECT_HTTP_AUTHORIZATION`. Apache may need `CGIPassAuth On`.
- No www or https redirect on the ingest URL: clients do not follow redirects and report the redirect as an error.
- Basic auth (`SS_USE_BASIC_AUTH`) is skipped for the ingest URL automatically.

### Upgrading

When a new version changes the report format, update the monitor first, then the sites.

## Hosting notes

For each client site:

- The four `proc_*` functions must be allowed (check `disable_functions`). Without them the task still sends a report, with the reason in its errors, and exits with 2; the monitor then shows "Audit failing".
- Composer must be in cron's `PATH`, or set `composer_bin` / `COMPOSER_REPORT_COMPOSER_BIN`.
- Outgoing HTTPS must be allowed to the monitor, `repo.packagist.org` and `packagist.org`. If the host uses an egress proxy, set `HTTPS_PROXY` in the crontab.
- Packages from VCS repositories cannot be checked on hosts without `proc_get_status`.
- Development dependencies are included in the outdated list.

## Security

- The monitor stores only a SHA-256 hash of each token. The plaintext is shown once and is never kept in the session or the database.
- Clients authenticate with the project header and token before the monitor parses the body. All authentication failures give the same response.
- The token is masked in output, error messages and Silverstripe backtraces, and is not passed on to the Composer process.
- Advisory links are only rendered for `http(s)` URLs; all report data is escaped in the CMS.

## Development

The sandbox for this module is a Silverstripe 6 install that acts as client and monitor at the same time.

```bash
vendor/bin/phpunit vendor/webium/silverstripe-composer-report/tests/php
vendor/bin/phpcs --standard=vendor/webium/silverstripe-composer-report/phpcs.xml.dist vendor/webium/silverstripe-composer-report/src vendor/webium/silverstripe-composer-report/tests/php
bash vendor/webium/silverstripe-composer-report/tests/bin/generate-fixtures.sh   # regenerate the Composer fixtures
```

The test fixtures come from real Composer runs on synthetic lock files in `tests/fixtures/locks/`. Never add output from real projects.

## License

BSD-3-Clause, see [LICENSE](LICENSE).
