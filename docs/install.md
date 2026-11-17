# Installation and upgrades

[← Project README](../README.md)

Fresh and manual installation, initial configuration, migrations, backups and recovery. See also the [API guide](api.md) and [panel guide](panel.md).

All shell commands and repository paths below assume the repository root as the working directory.

In this guide:

- [Ubuntu 26.04 and Debian 13 installation](#ubuntu-2604-and-debian-13-installation)
- [Manual installation alternative](#manual-installation-alternative)
- [Initial reseller and registry](#initial-reseller-and-registry)
- [Upgrade an installer-created demo](#upgrade-an-installer-created-demo)
- [Object ownership and upgrading existing installations](#object-ownership-and-upgrading-existing-installations)
- [TLS and systemd](#tls-and-systemd)
- [Operational notes](#operational-notes)

## Ubuntu 26.04 and Debian 13 installation

On a fresh **Ubuntu 26.04** or **Debian 13** systemd server, point separate panel and
API DNS hostnames at the server and allow inbound TCP 80/443 before installing:

```bash
sudo bash -c 'bash <(wget -qO- https://raw.githubusercontent.com/getnamingo/reseller/main/deploy/install.sh)'
```

Wget must be installed to fetch the entry script. The entry script installs Git/CA certificates if needed, clones current `main` into a private temporary directory, runs the cloned installer, and deletes the clone on success or failure. The `sudo bash -c` form creates process substitution inside the privileged shell, so its file descriptor is available to Bash. A local `bash deploy/install.sh` invocation uses the same fresh-clone workflow.

The installer asks for both hostnames, the first administrator username and wallet
currency, then asks you to confirm the installation summary. Its password prompt is
hidden; the password is shared by the API and panel and is never passed on the command
line. Use an interactive terminal rather than piping the installer into a shell.

It installs PHP 8.5/Swoole/FPM from [Surý's current repository](https://deb.sury.org/)
(`https://packages.sury.org/php/`, using `resolute` or `trixie`, not the old Ubuntu PPA),
the distribution's MariaDB 11.8 packages, Composer and [official Caddy packages](https://caddyserver.com/docs/install).
It then copies the application to `/opt/namingo-reseller-api`, installs locked Composer
dependencies/assets, generates `.env` secrets, creates and migrates the local database,
removes migration privileges from the application DB account and creates a role-0
administrator. It installs/enables the [documented systemd API service](#tls-and-systemd), a dedicated PHP-FPM
pool and Caddy HTTPS sites for the API and panel. Public HTTPS readiness checks must
pass before it reports success. Registry credentials, TLD grants and prices are configured
after login; the installer does not create registry accounts or credit wallets.

The API remains bound to `127.0.0.1:9501`; Caddy publishes it on the API hostname. Only
`panel/public` is web-accessible on the panel hostname. The separate `namingo-panel`
FPM account can read application configuration, while Caddy receives access only to
public files. Panel sessions live outside the web root. Existing Caddy sites are preserved;
the original Caddyfile is backed up before adding the Namingo import.

The installer sets `API_TRUSTED_PROXIES=127.0.0.1` and makes Caddy **overwrite**
`X-Forwarded-For` with its socket client's IP. The API uses that single address for both
login limiters and IP audit hashes only when its socket peer is explicitly trusted.
Direct installations default to no trusted proxies. For a manually configured proxy,
list exact proxy IPs separated by commas (no CIDRs/wildcards), restrict access to the
backend listener, and overwrite this header rather than passing a caller's value or a
forwarding chain. Missing/malformed/multi-address values fall back to the socket peer;
untrusted peers' forwarded headers are ignored. The PHP-FPM panel continues to use
its FastCGI `REMOTE_ADDR`. Run `tests/proxy.php` with the `TEST_MYSQL_*`
settings in the [regression-test instructions](api.md#regression-tests) to check identity resolution, independent login limits and audit hashing.

This is a fresh-install helper, not an upgrade/reset command. It refuses existing app
configuration, deployment files or an existing `namingo_api` database/user. If installation
stops after creating credentials, fix the reported problem and complete the remaining
steps manually; do not delete `.env` or recreate the database. For HTTPS failures, check
DNS (including AAAA records), firewall/NAT and `journalctl -u caddy`. You can inspect all
services with:

```bash
sudo systemctl status namingo-reseller-api php8.5-fpm mariadb caddy
```

Installer regression checks can be run without root or installing services:

```bash
bash tests/install.sh
bash tests/bootstrap.sh # mocked download/cleanup and child-failure scenarios
# Optionally also validate generated configurations with installed binaries:
CADDY_BIN=/usr/bin/caddy FPM_BIN=/usr/sbin/php-fpm8.5 bash tests/install.sh
```

### Install or upgrade from a local ZIP

Put GitHub's `reseller-main.zip` beside the saved `install.sh` or `upgrade.sh`. The ZIP must contain the `reseller-main/` directory with the repository files beneath it:

```bash
sudo bash /path/to/install.sh local
sudo bash /path/to/upgrade.sh local
```

The archive is resolved beside the **script**, regardless of your current working directory. Local mode skips Git cloning, installs Unzip if necessary, extracts into a private temporary directory and runs the matching deploy script from `reseller-main/deploy/`. Missing, invalid or incomplete ZIPs fail before installation/upgrade starts. Temporary extracted files are removed afterward, including on failure; the original ZIP is kept. Package and Composer downloads still need their usual network access. Local mode requires a saved script, rather than the remote process-substitution one-liner.

### Manual installation alternative

The following is the manual setup reference; the automatic installer already performs
these steps. Install the PHP 8.5 CLI/FPM extensions, Swoole, MariaDB and Caddy packages
from the repositories above first.

Verify the exact packages before deploying:

```bash
php8.5 -v
php8.5 --ri swoole
mariadb --version
```

Install the application as root-owned, service-readable code:

```bash
sudo useradd --system --home-dir /var/lib/namingo-api --create-home \
  --shell /usr/sbin/nologin namingo-api
sudo mkdir -p /opt/namingo-reseller-api
sudo cp -a . /opt/namingo-reseller-api/
cd /opt/namingo-reseller-api
sudo COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --classmap-authoritative
sudo chown -R root:namingo-api /opt/namingo-reseller-api
sudo find /opt/namingo-reseller-api -type d -exec chmod 0750 {} \;
sudo find /opt/namingo-reseller-api -type f -exec chmod 0640 {} \;
sudo chmod 0750 bin/*.php deploy/*.sh
```

Create MariaDB credentials. Keep the same password in `.env`:

```sql
CREATE DATABASE namingo_api CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'namingo_api'@'127.0.0.1' IDENTIFIED BY 'replace-with-a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
    ON namingo_api.* TO 'namingo_api'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Create and protect the environment file:

```bash
cd /opt/namingo-reseller-api
sudo cp .env.example .env
php8.5 bin/manage.php key:generate
sudoedit .env
sudo chown root:namingo-api .env
sudo chmod 0640 .env
sudo -u namingo-api php8.5 bin/manage.php migrate
```

After the migration, remove DDL privileges from the long-running account (temporarily
grant them again only for future migrations):

```sql
REVOKE CREATE, ALTER, INDEX, REFERENCES
    ON namingo_api.* FROM 'namingo_api'@'127.0.0.1';
FLUSH PRIVILEGES;
```

`APP_MASTER_KEY` is required to decrypt registry access and historical job results. Back it up separately from MariaDB. Losing it makes encrypted records unrecoverable; copying it into the database defeats the design.

## Initial reseller and registry

Create a reseller. The password prompt is hidden and the CLI never accepts the password as a command-line argument:

```bash
sudo -u namingo-api php8.5 bin/manage.php user:create reseller1 EUR
```

It prints the reseller UUID.

Prepare a registry JSON file from `deploy/registry.example.json`. Remove PEM keys that the registry does not require. In production, peer and hostname verification must remain enabled and self-signed certificates are rejected. Registry JSON has this structure:

```json
{
  "connection": {
    "host": "epp.registry.example",
    "port": 700,
    "timeout": 30,
    "tls": "1.3",
    "verify_peer": true,
    "verify_peer_name": true,
    "allow_self_signed": false,
    "client_cert_pem": "-----BEGIN CERTIFICATE-----\n...\n-----END CERTIFICATE-----",
    "client_key_pem": "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----",
    "client_key_passphrase": ""
  },
  "login": {
    "clID": "registrar-id",
    "pw": "registry-password",
    "prefix": "api"
  },
  "loginObjects": [
    "urn:ietf:params:xml:ns:domain-1.0",
    "urn:ietf:params:xml:ns:contact-1.0",
    "urn:ietf:params:xml:ns:host-1.0"
  ],
  "loginExtensions": [
    "urn:ietf:params:xml:ns:secDNS-1.1",
    "urn:ietf:params:xml:ns:rgp-1.0"
  ]
}
```

Add and grant it:

```bash
sudo -u namingo-api php8.5 bin/manage.php registry:add generic "Example Registry" registry.json
sudo -u namingo-api php8.5 bin/manage.php registry:grant RESELLER_UUID REGISTRY_UUID
sudo -u namingo-api php8.5 bin/manage.php tld:add REGISTRY_UUID com
sudo -u namingo-api php8.5 bin/manage.php tld:grant RESELLER_UUID REGISTRY_UUID com
```

The CLI encrypts the whole normalized registry JSON before insertion. PEM material is briefly written as mode `0600` under `/run/namingo-epp-api` by a task worker and removed in `finally`.

Registration (`domain.create`, including `domain.create_dnssec`), renewal, restore and
incoming transfer requests require an explicit price (zero is allowed). DNSSEC creation
always uses the matching `domain.create` row, including reseller overrides. Separate
DNSSEC prices are unsupported and old rows are ignored. Transfer query, cancel, approve
and reject are always free, even if old period-0 transfer rows exist. Missing required
prices return `409 price_not_configured` before an EPP job or wallet hold is committed.
All other commands are free, even if old price rows exist. These rows are ignored and
excluded from reseller/API price lists. The panel and CLI reject adding or updating
them; administrators can still list and delete them. Existing jobs retain their recorded prices.
Domain creation (`domain.create` and `domain.create_dnssec`), renewal and incoming transfer
requests require a zone and an exact term price. Amounts are the **total for that term**,
not an annual amount multiplied automatically. The API derives the zone from the domain
and the term from `period`, `regperiod`, or transfer `years` (integers 1–10).
The longest matching configured zone wins (for example, `co.uk` before `uk`), then a
reseller override wins over the global price for that same zone and term. There is no
catch-all price fallback for these term operations. For restore, period `0`
means per operation and `-` means an explicit registry-wide restore price.

```bash
# Total registration prices, with separate prices for each zone and term.
sudo -u namingo-api php8.5 bin/manage.php price:set REGISTRY_UUID domain.create 12.50 EUR global com 1
sudo -u namingo-api php8.5 bin/manage.php price:set REGISTRY_UUID domain.create 24.00 EUR global com 2
sudo -u namingo-api php8.5 bin/manage.php price:set REGISTRY_UUID domain.create 10.00 EUR RESELLER_UUID com 1
sudo -u namingo-api php8.5 bin/manage.php price:set REGISTRY_UUID domain.create 11.00 EUR global net 1
# Restore is priced per operation (period 0).
sudo -u namingo-api php8.5 bin/manage.php price:set REGISTRY_UUID domain.restore 25.00 EUR
# Incoming request: domain.transfer with op=request, years=1 and authInfoPw.
sudo -u namingo-api php8.5 bin/manage.php price:set REGISTRY_UUID domain.transfer 12.50 EUR global com 1
# Transfer query/cancel/approve/reject are always free; do not configure prices for them.
# Delete only the specified price row.
sudo -u namingo-api php8.5 bin/manage.php price:delete REGISTRY_UUID domain.create global com 2
```

Profiles whose pinned methods use a fixed/implicit term reject requests for longer
terms: LV/NO creation, PT renewal, and EE/FRED/FI/IT/LT transfer requests.

## Upgrade an installer-created demo

Use the upgrade entry script for an existing installation made by `deploy/install.sh`:

```bash
sudo bash -c 'bash <(wget -qO- https://raw.githubusercontent.com/getnamingo/reseller/main/deploy/upgrade.sh)'
```

The entry script clones current `main` into a temporary checkout outside the installation, then invokes the upgrade there. It removes that checkout on success, failure or a handled interruption. Local `bash deploy/upgrade.sh` uses the same workflow. The existing upgrade staging directory is also removed; retained backups stay under `/var/backups/namingo-reseller`.

Review the incoming code first. The one-liner always downloads current `main` and installs its locked dependencies. To deploy an explicitly reviewed release/commit instead, use a separate checkout outside `/opt/namingo-reseller-api` and run `sudo bash deploy/upgrade.sh --from-checkout` there. This bypasses only the download wrapper; the existing layout checks, backup, migration and recovery behavior still apply. The upgrade installs required cron/font packages, preserves accounts and secrets, and does not automatically change PHP/extension versions. If those requirements change, install the relevant packages before upgrading.

The script checks the standard local database/service layout, installs Composer
dependencies and panel assets in a temporary directory, then briefly stops the API and
`php8.5-fpm`. This interrupts **all pools on that PHP-FPM service**, so schedule downtime
if it hosts other sites. Avoid provisioning during the upgrade; reconcile unknown jobs
beforehand and allow active registry operations to finish. Old ownership migrations
also require unfinished jobs to be reconciled, as enforced by `manage.php migrate`.

Before replacing files it saves the application (including `.env`, dependencies and
permissions), the installed API service unit and a MariaDB dump under a private
`/var/backups/namingo-reseller/TIMESTAMP.SUFFIX/` directory. It preserves `.env`, runs
migrations as `namingo-api` with temporary schema privileges, revokes those privileges,
updates the API service unit, restores public-file ACLs, restarts the services and checks
API `/readyz`. Caddy/FPM configuration, hostnames, database credentials, accounts and
registry settings are retained. Check the existing panel URL and an availability check
after success. Backups contain secrets; keep them private and remove old backups when
no longer needed.

A dependency-install failure leaves the running demo untouched. A failure after services
stop leaves the API and PHP-FPM stopped, reports the backup directory and attempts to
revoke migration privileges. Fix the error before restarting. There is no automatic
schema rollback: migrations can partially apply. To restore the pre-upgrade snapshot,
keep submissions stopped and restore **both code and database**, for example as root:

```bash
sudo -i
BACKUP=/var/backups/namingo-reseller/TIMESTAMP.SUFFIX
systemctl stop namingo-reseller-api php8.5-fpm
RESTORE_DIR=$(mktemp -d)
tar --acls --xattrs -xzf "$BACKUP/application.tar.gz" -C "$RESTORE_DIR"
rsync -aA --delete "$RESTORE_DIR/" /opt/namingo-reseller-api/
cp -a "$BACKUP/namingo-reseller-api.service" /etc/systemd/system/namingo-reseller-api.service
# Replaces the local app database with the snapshot; later changes will be lost.
mariadb --no-defaults --protocol=socket --socket=/run/mysqld/mysqld.sock -u root -e 'DROP DATABASE namingo_api;'
gzip -dc "$BACKUP/database.sql.gz" | mariadb --no-defaults --protocol=socket --socket=/run/mysqld/mysqld.sock -u root
mariadb --no-defaults --protocol=socket --socket=/run/mysqld/mysqld.sock -u root -e "REVOKE CREATE, ALTER, INDEX, REFERENCES ON namingo_api.* FROM 'namingo_api'@'127.0.0.1';"
rm -rf -- "$RESTORE_DIR"
systemctl daemon-reload
systemctl start namingo-reseller-api php8.5-fpm
curl -fsS http://127.0.0.1:9501/readyz
exit
```

For custom paths, database names/users, remote databases or a different service layout,
use the manual upgrade steps below. Upgrade-script regression checks use mocked
services/database commands and can run without root: `bash tests/upgrade.sh`.

## Object ownership and upgrading existing installations

Each upstream account must have **one registry UUID**, shared through registry grants.
Do not duplicate the same credentials under multiple registry UUIDs, or repoint an
existing UUID to another account; ownership is scoped to that UUID. Objects created
through the API are reserved atomically for the submitting reseller. Other resellers
cannot read, change or delete them, even when they know the upstream authInfo.
Contact references must also belong to the caller. Public nameservers may be shared;
creating a glue host beneath a managed domain requires that domain's owner. FRED
NSSETs are tracked as host objects by `nsid` on create/update and `hostname` on info/delete.
LT contacts are tracked using the registry-generated response `id`.

Only hello and availability checks skip ownership checks. Unmapped existing objects
are denied for info/update/delete; incoming transfer requests may reserve an unmapped
domain with authInfo, but cannot claim a domain already mapped to another reseller.
All operations on one object are serialized through a job reservation; busy objects
return `409`, and unauthorized objects return `403`. Referenced contacts/NSSETs are
checked again under the registry session lock immediately before execution.

For an existing installation:

1. Stop submissions, finish queued jobs and reconcile processing/unknown jobs before
   stopping workers. `migrate` refuses unfinished jobs during this upgrade.
2. Back up the database and run `php8.5 bin/manage.php migrate`. This applies migration
   002 once; fresh installations already include it. Old prices remain as period-0,
   registry-wide rows and cannot price term operations.
3. Import **all** verified existing domains, contacts and hosts into their correct reseller
   before reopening submissions. Never infer ownership from the shared upstream client ID.

   ```bash
   php8.5 bin/manage.php object:assign REGISTRY_UUID domain example.com RESELLER_UUID
   php8.5 bin/manage.php object:assign REGISTRY_UUID contact CONTACT_ID RESELLER_UUID
   php8.5 bin/manage.php object:assign REGISTRY_UUID host ns1.example.com RESELLER_UUID
   ```

4. Configure prices for the four priced domain operations and exact zone/term totals,
   then restart the API and workers.

Successful creates activate ownership; rejected creates release it. Domain deletions retain ownership in state `deleted` through redemption until a registry
observation confirms purge. Successful contact/host deletions and outgoing transfer approvals remove ownership. Registry-accepted EPP responses (`1001`) and unconfirmed incoming transfers remain
`pending`, preserving ownership reservations and wallet holds while read-only observations
check completion. Transport-uncertain writes remain `unknown` and require operator verification. Verify the final registry outcome, then use the existing
`job:resolve JOB_UUID charge|release`; `charge` means the operation actually completed,
`release` means it did not. Reconcile any registry-generated LT contact ID lost with an
unknown response using `object:assign` after resolving the job. Never retry an uncertain
write under a new idempotency key. Ownership changes made outside this API must also
be reconciled by the operator before enabling access to affected objects.

## TLS and systemd

For direct Swoole HTTPS, set `API_HOST=0.0.0.0`, `API_TLS_CERT`, and `API_TLS_KEY`. Ensure the service account can read the certificate and key. Alternatively leave the API bound to `127.0.0.1:9501` and terminate TLS in a reverse proxy. The server refuses public plaintext binding in production unless it is explicitly overridden. A reverse proxy must add HSTS itself because the loopback hop is HTTP.

Install the hardened unit:

```bash
sudo cp deploy/namingo-reseller-api.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now namingo-reseller-api
sudo systemctl status namingo-reseller-api
curl --fail http://127.0.0.1:9501/healthz
```

Use `https://` for the health check when direct API TLS is enabled.

## Operational notes

- Back up MariaDB and `APP_MASTER_KEY` separately. Test restores.
- Keep `.env`, registry JSON working files, TLS private keys, and database dumps mode `0600`/`0640` and out of Git.
- Put the API behind a firewall and allow only reseller source networks where practical.
- Do not enable Namingo EPP raw XML logging in this service; it can contain contact data and authInfo values.
- A single EPP login is opened per task and closed afterward. This favors correctness and simplicity over connection pooling.
- MariaDB advisory locks serialize a registry across multiple API processes or hosts. Swoole rate limits are per API instance; database login lockout remains global.
- Before changing the pinned EPP commit, compare its factory profiles and every method in `src/Registries`, test in each registry OTE, then update the pin.
- Run `composer audit`, keep Ubuntu/MariaDB/PHP patched, and exercise restores and unknown-job reconciliation before production launch.

## Monthly invoicing

The installer and `deploy/upgrade.sh` install `cron`, DejaVu fonts and a monthly entry at `/etc/cron.d/namingo-reseller-invoicing`. It runs `bin/invoicing.php` as `namingo-api` at **04:15 on the second day of each month, in the server's timezone**. Using the second day ensures the previous UTC calendar month has closed in every server timezone. `flock` prevents overlapping installed cron runs; per-account database locks and unique monthly invoice keys also protect manual reruns. Logs go to `/var/log/namingo-invoicing.log` with monthly rotation.

Invoicing starts disabled. Before enabling it:

1. Run `composer install --no-dev --classmap-authoritative` and `php bin/manage.php migrate`. Migration `009_invoicing.php` adds company billing/VAT fields without replacing existing account details, then creates the invoice index and number sequences. It can resume after partially completed DDL.
2. Set the `INVOICE_SELLER_*` fields in `.env`: registered legal name, company registration number, registered street address/city/postcode/country, billing email, VAT ID where applicable, and national VAT reporting currency. Use two-letter uppercase country codes and three-letter currency codes. Keep these details accurate before the first invoice is issued.
3. Complete each reseller's billing details in **Administration → Resellers → account**: registered company name and number, street address, city, postal code, country, invoice email, and VAT ID if registered. Administrators select `standard`, `reverse_charge`, or `exempt`, the VAT percentage, and the tax basis note. The default `unconfigured` blocks issuance. Standard VAT requires the supplier VAT ID; a zero standard rate requires a tax-basis note. Reverse charge requires both VAT IDs and a legal-basis note; exemption requires a note and zero rate. Validate tax treatment and registration status outside the application. No VAT/VIES decision is inferred merely from entering a number. A reseller can update its own details at `/account/billing`; legal identity/country/VAT changes reset its tax treatment for administrator review.
4. Configure `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURITY=tls` (STARTTLS) or `smtps`, and optional `SMTP_USERNAME`/`SMTP_PASSWORD`. TLS certificate verification remains enabled. `none` is allowed only for a loopback mail relay. Email comes from the invoice's snapshotted issuer email. Set `INVOICING_ENABLED=1` after configuration is complete. An incomplete reseller is reported and skipped without blocking other accounts.

Monthly invoices cover **completed ledger service charges**, using each charge's UTC posting date. Wallet top-ups, temporary holds/releases and manual adjustments are excluded; deposits are not invoiced a second time. Suspended resellers are included, and a reseller that existed during the period but had no charges gets a zero-activity invoice. Administrators do not receive reseller invoices merely because they have an account. Late-settled operations appear in the month their charge posts. This is a service-consumption invoice workflow, not an advance-payment/credit-note workflow; advance invoices, refunds and statutory structured e-invoice submissions remain outside this feature.

Existing ledger charges are treated as **VAT-inclusive, already paid**. Invoicing never adds a new wallet debit or retroactively increases a price. Decimal arithmetic splits the charged total into net and VAT, preserving the ledger's four-decimal precision. One approved tax treatment applies per reseller and invoice period. Configure accounting/tax policy accordingly before enabling automation; the application does not infer historical tax changes or country-specific obligations. The invoice includes issuer/customer details, sequential number, issue/service dates, quantities, net unit prices, VAT breakdown and the applicable tax note. Reference: [European Commission VAT invoice information](https://taxation-customs.ec.europa.eu/taxation/vat/vat-businesses/invoicing_en).

For nonzero VAT in a currency different from `INVOICE_SELLER_TAX_CURRENCY`, configure a month-specific conversion rate, as decimal **strings**. Example: if EUR is the tax currency, this means 1 USD = 0.92 EUR for October 2026:

```dotenv
INVOICE_TAX_FX_RATES='{"2026-10":{"USD":"0.92"}}'
```

The selected rate and national-currency VAT amount are frozen on the invoice. A missing conversion blocks that invoice. Set an appropriate accounting rate before generation; the application does not fetch exchange rates.

Manual runs are safe to repeat:

```bash
# Default: generate the previous completed UTC month and send definitely unsent emails.
sudo -u namingo-api php8.5 /opt/namingo-reseller-api/bin/invoicing.php
# Backfill/recover a particular month. This creates real numbered invoices but sends no email.
sudo -u namingo-api php8.5 /opt/namingo-reseller-api/bin/invoicing.php --month=2026-10 --no-email
```

Numbers and billing snapshots are reserved atomically. An interrupted PDF build resumes the same invoice; issued invoices retain their original details and amount even after a profile change. Email uses PHPMailer with the PDF attached and a stable Message-ID. Sent invoices are not automatically emailed again. A send interrupted around SMTP acceptance is marked `sending`/`unknown`; check recipient/mail-server delivery and use the confirmed admin **Send invoice email** action if retry is necessary. SMTP cannot provide exactly-once delivery across a crash. Routine runs also retry up to 100 definitely unsent older issued invoices. Failed generation is reported by a nonzero exit code; rerun the affected month after correcting configuration or an interruption.

PDFs use `dragonofmercy/phppdf`, with embedded Unicode DejaVu fonts and multipage line tables. `INVOICE_FONT_REGULAR` and `INVOICE_FONT_BOLD` can point to other installed, compatible TTF fonts. No plaintext PDF is written to disk: the bytes are encrypted with XChaCha20-Poly1305 and an invoice-specific authenticated context before Utopia's local storage writes them atomically.

The single private file location is **`DATA_DIRECTORY/invoices`**, default `/var/lib/namingo-api/data/invoices`, outside `panel/public` and outside the application checkout. The installer creates directories mode `0750` owned by `namingo-api:namingo-api`; files are `0640`. PHP-FPM's `namingo-panel` user reads through the shared group. Caddy has no direct access. Invoice snapshots are also encrypted in MariaDB; the database stores invoice identifiers, totals, statuses and integrity hashes alongside them. HTTP PDF/view access requires a current panel session; resellers see only their own records and administrators can use `/admin/invoices` for all accounts. Paths are generated from invoice UUIDs, not request filenames, and responses are private/non-cacheable.

Back up **MariaDB, the private data directory and `APP_MASTER_KEY`**. Preserve the key securely and separately: losing it makes both snapshots and PDFs unreadable. The standard upgrade now also saves the default private data directory to `data.tar.gz`, and never rsyncs/deletes it with application source. A custom `DATA_DIRECTORY` must be provisioned with equivalent ownership/permissions and backed up separately. Do not put it under a web root, use a symlink, or allow group/other writes. Retention is deliberate: this feature does not delete issued invoices or rewrite them as corrections.

For a manual installation, install the schedule after provisioning the standard paths above:

```bash
sudo install -o root -g root -m 0644 deploy/namingo-reseller-invoicing.cron /etc/cron.d/namingo-reseller-invoicing
sudo install -o root -g root -m 0644 deploy/namingo-reseller-invoicing.logrotate /etc/logrotate.d/namingo-reseller-invoicing
sudo systemctl enable --now cron
```

Run `php tests/invoices.php` against a disposable MariaDB database with the existing `TEST_MYSQL_*` settings. Tests generate real PDF bytes and MIME messages, simulate SMTP success/failure, verify encrypted file integrity and account isolation, exercise period/VAT arithmetic and migration recovery, and never contact a real recipient.
