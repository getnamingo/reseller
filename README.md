# Namingo Reseller

A compact PHP 8.5/Swoole JSON API for domain resellers. It authenticates resellers, keeps a MariaDB wallet and append-only ledger, encrypts registry credentials and job payloads, and runs each blocking EPP operation in a Swoole task worker using [Namingo EPP Client](https://github.com/getnamingo/epp-client).

The API keeps a compact core, with one HTTP entry point, one administration CLI and a Guzzle client example. An optional Foundry-based reseller panel uses the same database.

## What is implemented

- Opaque, short-lived bearer tokens; Argon2id reseller passwords; login lockout and shared-memory rate limits.
- A UUID for every reseller, registry, job, deposit, ledger entry, and token.
- XChaCha20-Poly1305 authenticated encryption for registry configuration, EPP requests, and EPP results. The key is kept outside MariaDB.
- Reseller-to-registry access control. Registry credentials and certificate PEMs are never returned by the API.
- Swoole task workers for synchronous EPP-over-TLS or the HTTPS profiles; one MariaDB advisory lock per registry prevents excess parallel EPP sessions.
- Idempotency-Key enforcement for every mutating operation.
- Wallet holds, final charges, releases, manual deposits, per-registry pricing, and per-reseller price overrides.
- Safe ambiguous-outcome handling. A network failure after an EPP command starts leaves its money on hold as `unknown`; it is not automatically refunded.
- Encrypted idempotent job results and polling when a registry takes longer than the HTTP wait window.
- No raw EPP XML logging and no secrets or request bodies in application logs.
- Direct Swoole TLS, or plaintext on loopback only for a TLS reverse proxy. Registry credentials are sent only after the worker verifies that TCP profiles negotiated TLS 1.2/1.3; HTTPS profiles have their minimum cURL TLS version enforced before login.

This is a working base, not a payment gateway. A reseller may request a deposit, but only the local administrator can approve it after funds are actually received.

## Upstream EPP compatibility

The API exposes these commands:

| API command | Namingo method |
| --- | --- |
| `contact.check` | `contactCheck` |
| `contact.create` | `contactCreate` |
| `contact.delete` | `contactDelete` |
| `contact.info` | `contactInfo` |
| `contact.update` | `contactUpdate` |
| `contact.update_authinfo` | `contactUpdateAuthinfo` |
| `domain.check` | `domainCheck` |
| `domain.create` | `domainCreate` |
| `domain.create_dnssec` | `domainCreateDNSSEC` |
| `domain.delete` | `domainDelete` |
| `domain.info` | `domainInfo` |
| `domain.renew` | `domainRenew` |
| `domain.restore` | `domainRestore` |
| `domain.restore_report` | Validated RFC 3915 report adapter |
| `domain.transfer` | `domainTransfer` |
| `domain.update_authinfo` | `domainUpdateAuthinfo` |
| `domain.update_contact` | `domainUpdateContact` |
| `domain.update_dnssec` | `domainUpdateDNSSEC` |
| `domain.update_ns` | `domainUpdateNS` |
| `domain.update_status` | `domainUpdateStatus` |
| `hello` | `hello` |
| `host.check` | `hostCheck` |
| `host.create` | `hostCreate` |
| `host.delete` | `hostDelete` |
| `host.info` | `hostInfo` |
| `host.update` | `hostUpdate` |

An exposed command can still be unsupported by a particular registry protocol—for example, EURid does not support host objects. That registry rejection is returned as a rejected job.

## Ubuntu 26.04 and Debian 13 installation

On a fresh **Ubuntu 26.04** or **Debian 13** systemd server, point separate panel and
API DNS hostnames at the server and allow inbound TCP 80/443 before installing:

```bash
sudo apt-get update
sudo apt-get install -y git
git clone https://github.com/getnamingo/reseller.git
cd reseller
sudo bash deploy/install.sh
```

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
administrator. It installs/enables the README systemd API service, a dedicated PHP-FPM
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
its FastCGI `REMOTE_ADDR`. Run `tests/proxy.php` with the documented `TEST_MYSQL_*`
settings to check identity resolution, independent login limits and audit hashing.

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
# Optionally also validate generated configurations with installed binaries:
CADDY_BIN=/usr/bin/caddy FPM_BIN=/usr/sbin/php-fpm8.5 bash tests/install.sh
```

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
sudo chmod 0750 bin/*.php client/*.php deploy/*.sh
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

Only `domain.create`, `domain.create_dnssec`, `domain.renew`, `domain.restore` and
`domain.transfer` require an explicit price (zero is allowed). Missing prices for these
commands return `409 price_not_configured` before an EPP job or wallet hold is committed.
All other commands are free without a price row. Existing legacy prices remain effective,
but the panel and CLI reject setting or updating prices for those commands; legacy rows
can still be listed and deleted. Existing jobs retain their recorded prices.
Domain creation (`domain.create` and `domain.create_dnssec`), renewal and incoming transfer
requests require a zone and an exact term price. Amounts are the **total for that term**,
not an annual amount multiplied automatically. The API derives the zone from the domain
and the term from `period`, `regperiod`, or transfer `years` (integers 1–10).
The longest matching configured zone wins (for example, `co.uk` before `uk`), then a
reseller override wins over the global price for that same zone and term. There is no
catch-all price fallback for these term operations. For other commands, period `0`
means per operation and `-` means an explicit registry-wide price.

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
# Query/cancel/approve/reject use period 0, independently of incoming request prices.
sudo -u namingo-api php8.5 bin/manage.php price:set REGISTRY_UUID domain.transfer 0 EUR
# Delete only the specified price row.
sudo -u namingo-api php8.5 bin/manage.php price:delete REGISTRY_UUID domain.create global com 2
```

Profiles whose pinned methods use a fixed/implicit term reject requests for longer
terms: LV/NO creation, PT renewal, and EE/FRED/FI/IT/LT transfer requests.

### Object ownership and upgrading existing installations

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

4. Configure prices for the five priced domain commands and exact zone/term totals,
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

## API use

Login:

```http
POST /v1/login
Content-Type: application/json

{"username":"reseller1","password":"correct horse battery staple"}
```

Submit a read command (replace `.test` examples with names under an enabled TLD granted to your account):

```http
POST /v1/epp/domain.check
Authorization: Bearer TOKEN
Content-Type: application/json

{
  "registry_uuid": "REGISTRY_UUID",
  "params": {"domains": ["example.test"]}
}
```

Submit a mutating command. Reuse the same idempotency key when retrying the same logical request:

```http
POST /v1/epp/domain.create
Authorization: Bearer TOKEN
Idempotency-Key: order-2026-00001234
Content-Type: application/json

{
  "registry_uuid": "REGISTRY_UUID",
  "params": {
    "domainname": "example.test",
    "period": 1,
    "nss": ["ns1.example.net", "ns2.example.net"],
    "registrant": "contact-123",
    "contacts": {
      "admin": "contact-123",
      "tech": "contact-123",
      "billing": "contact-123"
    },
    "authInfoPw": "generated-domain-authinfo"
  }
}
```

A completed call returns its job. A slow call returns HTTP `202`; poll `GET /v1/jobs/JOB_UUID`. A registry rejection is a completed HTTP response with `status: rejected`. Only API validation and transport-level API errors use an HTTP error response.

Command submission validates the selected profile's local serializer requirements before
committing a job, hold or ownership reservation. Missing fields return HTTP `422` with
the parameter name; for example, generic registration requires `authInfoPw` as well as
`domainname` and `period`. Profile-specific contact fields, transfer operation/fields,
DNSSEC record fields and parameter types are checked too. Optional contact address
fields read unconditionally by the client must be supplied as strings (empty when
appropriate). Profiles with unimplemented methods reject the command before queueing.
The local contracts are in `src/ProfileParams.php` and track the pinned client version;
the registry still validates its own policies and extensions. Check these contracts when
upgrading the client. Existing queued jobs are revalidated before connection: local
validation failures reject the job and release its reservation, while uncertain writes
continue to require reconciliation. Linked transfer follow-ups inherit required original
parameters server-side, so the panel need not expose the request's authInfo.

Other endpoints:

- `GET /v1/jobs`, `GET /v1/prices`, and `GET /v1/domains` (paginated; see self-service below)
- `GET /v1/account`
- `GET /v1/registries`
- `GET /v1/commands`
- `GET /v1/ledger?limit=50`
- `POST /v1/deposits` and `GET /v1/deposits`
- `GET /v1/jobs/{uuid}`
- `POST /v1/logout`

Run the Guzzle example:

```bash
export API_BASE_URL=https://api.example.test or API_BASE_URL=http://127.0.0.1:9501
export API_USERNAME=reseller1
export API_PASSWORD='reseller-password'
export API_REGISTRY_UUID=REGISTRY_UUID

php8.5 client/example.php domain.check '{"domains":["example.test"]}'
```

`API_CA_BUNDLE=/path/to/private-ca.pem` may be set for a private CA. The sample never disables TLS verification and refuses non-HTTPS URLs except loopback development addresses.

## Deposits and reconciliation

A reseller creates a pending request:

```json
{"amount":"100.00","currency":"EUR","reference":"wire-2026-00042"}
```

After independently confirming receipt, an administrator credits it once:

```bash
sudo -u namingo-api php8.5 bin/manage.php deposit:approve DEPOSIT_UUID
```

Ledger behavior for a priced EPP job is:

1. `hold`: move money from available to held before dispatch.
2. `charge`: remove held money after confirmed successful completion.
3. `release`: return held money after a definite rejection before/at the registry.
4. `pending`: retain the hold after registry acceptance while completion is checked.
5. `unknown`: retain the hold after an ambiguous transport failure.

For an ambiguous job, check the registry or poll data first, then resolve exactly once:

```bash
sudo -u namingo-api php8.5 bin/manage.php job:resolve JOB_UUID charge
# or
sudo -u namingo-api php8.5 bin/manage.php job:resolve JOB_UUID release
```

If a process or host crash leaves an old job in `processing`, an operator may first run `job:mark-unknown JOB_UUID`; this deliberately keeps its hold for reconciliation. Queued jobs are dispatched automatically by API worker 0. Retrying the identical request
with the identical Idempotency-Key remains safe; do not submit uncertain writes under a new key.

## Operational notes

- Back up MariaDB and `APP_MASTER_KEY` separately. Test restores.
- Keep `.env`, registry JSON working files, TLS private keys, and database dumps mode `0600`/`0640` and out of Git.
- Put the API behind a firewall and allow only reseller source networks where practical.
- Do not enable Namingo EPP raw XML logging in this service; it can contain contact data and authInfo values.
- A single EPP login is opened per task and closed afterward. This favors correctness and simplicity over connection pooling.
- MariaDB advisory locks serialize a registry across multiple API processes or hosts. Swoole rate limits are per API instance; database login lockout remains global.
- Before changing the pinned EPP commit, compare its factory profiles and every method in `src/Registries`, test in each registry OTE, then update the pin.
- Run `composer audit`, keep Ubuntu/MariaDB/PHP patched, and exercise restores and unknown-job reconciliation before production launch.


## Regression tests

Run with PHP CLI, PDO MySQL, mbstring and sodium against a disposable local MariaDB/MySQL
server. The test account needs CREATE/DROP DATABASE privileges; the script creates and
removes only its own randomly named database, and sends no EPP commands.

```bash
TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306' \
TEST_MYSQL_USER=root TEST_MYSQL_PASSWORD=secret php tests/isolation_pricing.php
```

Run `tests/validation.php` with the same settings for profile parameter validation,
zero-write reproduction of the missing-authInfo failure, submission rollback and
worker rejection/release of invalid jobs queued by an older version.

The suite covers ownership and contact references, concurrent create reservations,
unknown/rejected/deleted objects, idempotency, FRED/LT identifiers, pricing by zone and
term, longest-zone precedence, explicit free operations, wallet rollback and schema upgrade.

## Foundry reseller panel

The optional PHP-FPM web panel uses [Argora Foundry](https://github.com/terborahq/foundry)'s
Slim 4 / Twig / Tabler foundation. It shares the **same reseller database and API login**;
there is no second users table, separate balance, or separate password. The panel uses
the existing Argon2id verifier, account lockout and API token revocation, plus a private
server-side session, session ID rotation, idle expiry, HttpOnly/Secure/SameSite cookies
and CSRF protection. The API token stays on the server and is never placed in browser
storage or rendered HTML. Password resets, suspensions and token revocation invalidate
panel sessions on their next request. API and panel logins share a persistent per-IP
rate limit; the panel does not trust client-supplied forwarding headers.

Resellers can view their account, wallet/ledger, deposits, effective global/override
prices, registry/TLD permissions and owned objects. Availability checks submit only
`domain.check` through the API with the current reseller's token; results are read
through the API too. They are free unless a legacy check price is configured,
with an idempotency key per form submission. Refreshing a result only polls that job.
The Wallet page also submits deposit requests through the API in the wallet currency.
Keep the same request/payment reference when retrying; funds are credited only after
administrator approval following independent verification of payment.
There is no domain registration or public account-registration route. The panel offers
scoped restoration, restore-report and transfer query/cancellation follow-ups for existing lifecycle work.

Role **0 is administrator**, role **1 is reseller**. Role 0 has explicit server-side
checks on every administration request and action; roles are reread from MariaDB,
not trusted from the session. The administration pages cover reseller creation,
password resets, suspension, roles, token revocation, encrypted registry configuration,
registry and TLD access, prices, deposit decisions, unknown-job reconciliation and
verified ownership imports. Financial and registry reconciliation require the operator
to independently verify the outcome, as with the CLI. Registry credentials cannot be
viewed back in the panel. Use one registry UUID per upstream account as before.
The admin job table links to each reseller's job details, including registry response,
EPP code, timestamps and latest error. Pending/unknown jobs offer the existing verified
resolution controls with the selected job reference filled in. Resellers cannot access
these cross-account investigation pages.

Select a reseller UUID in the admin account table to edit its username, contact name,
company, email, phone, postal address and two-letter country code. Contact fields are
optional; email is a contact address, not a replacement login. Username changes revoke
API tokens and panel sessions, but retain the existing password and reseller UUID.
Wallet currency stays fixed to preserve the meaning of historical amounts. Password,
role and suspension controls remain on the account administration page. Resellers see
their current details on Account and in the `profile` object of `GET /v1/account`.

The equivalent CLI command accepts partial account changes as a JSON object:

```bash
php bin/manage.php user:update RESELLER_UUID account.json
# Or pass the same JSON on stdin with '-' as the file argument.
```

For example: `{"username":"new-login","display_name":"Contact Name","company":"Example Ltd","email":"contact@example.com","phone":"+359 123456789","address":"Street 1, Sofia","country_code":"BG"}`.
Only supplied fields change; an empty string clears an optional contact field. UUID,
currency, password and role are not accepted by this command.

The TLD administration table now has per-row enable/disable and removal controls, and
permission rows offer removal of an assignment. Existing add-TLD, grant/revoke and price
forms remain available. Removing an unused TLD removes its assignments and exact TLD
prices across reseller/global scopes, retaining registry-wide prices and historical data.
Owned domains (including redemption reservations) or any unsettled jobs in that registry
block deletion; disable the TLD instead to preserve the longest-suffix access boundary.
To correct an unused suffix, remove it and add the correct suffix. CLI equivalents are:

```bash
php bin/manage.php tld:delete REGISTRY_UUID TLD
php bin/manage.php tld:unassign RESELLER_UUID REGISTRY_UUID TLD
```

Apply migration `005_account_details` using `php bin/manage.php migrate` before running
the updated API/panel. Existing accounts receive empty optional details. Run
`tests/admin_editing.php` with the documented `TEST_MYSQL_*` settings to verify account
editing, token behavior, CLI commands, migration and safe TLD removal.

### Install or upgrade

The automatic `deploy/install.sh` setup already installs the panel with a dedicated
`namingo-panel` PHP-FPM pool. The steps below are the manual installation/upgrade
alternative; keep the generated dedicated pool and Caddy configuration when upgrading.

1. Stop submissions/workers during the database upgrade and back up the database.
   Run `composer install --no-dev --classmap-authoritative`, then `php8.5 bin/manage.php migrate`.
   Composer installs the panel dependencies and copies just the required Tabler CSS/JS
   into `panel/public/assets`. To recopy them later, run `composer panel-assets`.
2. Migration 003 adds `resellers.role` (default 1), `registry_tlds`, `reseller_tlds`
   and `auth_login_limits` **in the main database**. Existing explicitly priced zones
   are imported and granted to existing registry members to preserve their known
   access. Review these grants; add and permit any unpriced zones before resuming.
   There are no automatic administrator accounts.
3. Create/promote the first administrator from the CLI:

   ```bash
   php8.5 bin/manage.php user:create administrator EUR
   php8.5 bin/manage.php user:role ADMIN_RESELLER_UUID 0
   ```

   Sign in with that account's existing API username/password. New accounts created
   in the panel are resellers until explicitly promoted. Administrators still need
   normal registry/TLD grants for their own availability checks.
4. Configure `PANEL_ORIGIN` (the exact public HTTPS origin), `PANEL_API_URL` (the API
   root URL), optionally `PANEL_API_CA`, and `PANEL_SESSION_DIR` in the same `.env`.
   API HTTPS certificate verification stays enabled. Plain HTTP is permitted for a
   loopback API connection only. Set `PANEL_IDLE_SECONDS` as desired (default 1800).
5. Install PHP 8.5 FPM and configure a separate panel hostname with **only
   `panel/public` as the document root**, for example `deploy/Caddyfile.panel`.
   Never expose the repository root, `.env`, `vendor`, or session directory.
   Allow the PHP-FPM user to read the application/.env and create its private session
   files; for the installation paths and accounts above:

   ```bash
   sudo usermod -aG namingo-api www-data
   sudo install -d -o www-data -g www-data -m 0700 /var/lib/namingo-api/panel-sessions
   sudo systemctl restart php8.5-fpm
   ```

   Caddy handles public TLS and sets FastCGI `HTTPS=on`. With another trusted TLS
   proxy, set the equivalent server parameter; the panel does not trust arbitrary
   `X-Forwarded-Proto` headers. Cookies are host-only. Multiple panel nodes need a
   shared private PHP session store. Keep the session directory outside the web root.
6. Start the API workers and web panel. Add registry/TLD permissions and explicit
   prices as needed. Test an availability check with an actual reseller account.

For a local HTTP smoke test only, use `APP_ENV=local`, a loopback `PANEL_ORIGIN`,
its matching port, and a private writable `PANEL_SESSION_DIR`:

```bash
php8.5 -S 127.0.0.1:8080 -t panel/public panel/public/index.php
```

Serve the generated static assets through your web server in production; the PHP
built-in server is only for local testing.

### TLD administration and enforcement

Registry access alone no longer authorizes every suffix. Domains must match an
enabled `registry_tlds` row and an explicit enabled reseller grant. The longest
configured suffix wins, including disabled/revoked rows; revoking `co.uk` cannot be
bypassed through a broader `uk` grant. Checks accept 1–50 domain names. Both enqueue
and the worker recheck TLD access; the worker also rechecks account/registry access
before connecting. Other object commands retain the existing ownership isolation.

The equivalent CLI commands are:

```bash
php8.5 bin/manage.php tld:add REGISTRY_UUID com
php8.5 bin/manage.php registry:grant RESELLER_UUID REGISTRY_UUID
php8.5 bin/manage.php tld:grant RESELLER_UUID REGISTRY_UUID com
php8.5 bin/manage.php tld:revoke RESELLER_UUID REGISTRY_UUID com
php8.5 bin/manage.php tld:status REGISTRY_UUID com disabled
# Run daily to remove stale rate-limit buckets and expired authentication tokens.
php8.5 bin/manage.php auth:prune
```

TLD permissions and prices are independent: allowing a TLD does not create a price,
and setting a price does not permit that TLD. New TLDs need both where applicable.
Contact/host operations are free unless a legacy price is configured. New prices for these operations are not supported.

Run the panel suite after Composer installation against a disposable database server:

```bash
TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306' \
TEST_MYSQL_USER=root TEST_MYSQL_PASSWORD=secret php tests/panel.php
```

It creates/removes its own randomly named database and uses a mocked HTTP API for
availability. It tests real authentication, HTML views, administrator authorization,
CSRF/origin checks, role changes, session/token revocation, scoped prices and objects,
TLD revocation before EPP execution, and upgrading a v2 database. No live registry
commands are sent. Upstream Foundry attribution is in `panel/UPSTREAM.md` and its MIT
notice is preserved in `panel/LICENSE-FOUNDRY`.

## Reseller self-service API

These authenticated, read-only endpoints use the bearer token's reseller identity.
Role 0 accounts also receive only their own data through these endpoints. A query
parameter cannot select another reseller. No database migration is needed.

| Endpoint | Optional filters | Returned data |
| --- | --- | --- |
| `GET /v1/jobs` | `registry_uuid`, `command`, `status` | All historical job metadata: UUID, registry, command, status, price/currency, EPP code and timestamps. Request/response payloads and secrets are excluded; use the existing `GET /v1/jobs/{uuid}` for a permitted individual result. |
| `GET /v1/prices` | `registry_uuid`, `command`, `tld`, `period` | Effective global/reseller price rows for enabled registry/TLD access, with registry UUID/name, command, TLD, exact term, total amount/currency and scope. Own overrides replace matching global rows. |
| `GET /v1/domains` | `registry_uuid`, `name`, `active` | Currently owned or reserved domains, registry UUID/name, boolean active flag and optional pending-job UUID. Contacts and hosts are excluded. |

All three accept `limit` (1–100, default 50) and `offset` (0–1000000, default 0).
Responses follow this shape, plus the normal `request_id`:

```json
{
  "data": [],
  "pagination": {
    "limit": 50,
    "offset": 0,
    "has_more": false,
    "next_offset": null
  }
}
```

Use `next_offset` while `has_more` is true. Jobs sort newest first with a UUID
tie-breaker; domains sort by name and registry UUID. Offset pages reflect current
data, so newly submitted jobs may shift page boundaries between requests. Amounts
remain exact decimal strings. `status` accepts `queued`, `processing`, `succeeded`,
`rejected`, `pending` or `unknown`; `command` must be an exposed command. Domain `name` is a
case-insensitive literal ASCII fragment (no wildcards); `active` accepts `1` or `0`.
Price `period` accepts 0–10 (0 means per operation) and `tld` is an exact configured
price suffix, optionally with a leading dot. Missing prices are never synthesized.
Price rows retain the existing longest-zone and reseller-override semantics; this is
a price list rather than a live registry premium-domain quote.

```bash
curl -H "Authorization: Bearer $TOKEN" "$API_BASE_URL/v1/jobs?status=succeeded&limit=50"
curl -H "Authorization: Bearer $TOKEN" "$API_BASE_URL/v1/prices?command=domain.create&tld=com&period=1"
curl -H "Authorization: Bearer $TOKEN" "$API_BASE_URL/v1/domains?name=example&active=1"
```

Own historical jobs and ownership records remain visible after registry access is
revoked; unavailable current prices are hidden. None of these listings sends an EPP
command, creates a charge or reveals registry credentials.

The panel's **Jobs** page provides paginated history with status, command and registry
filters. Its existing **Prices** and **Objects** pages already provide the other two
browsing functions; availability jobs link to their existing result page. No domain
provisioning controls are added.

Run `tests/self_service.php` with the same `TEST_MYSQL_*` settings as the other suites.
It creates/removes its own test database and checks cross-reseller/admin isolation,
stable pagination, filters, effective pricing, pending domains, revoked access and
payload redaction without sending registry commands.

## Deletion, restoration, transfers and durable dispatch

Apply migration **004** with `php8.5 bin/manage.php migrate` before starting the new API
or panel. Stop both during the upgrade and back up the database. Migration 004 adds queue
leases/due dates, parent-job references, retained deletion state and the `pending` job
status. Historical `unknown` jobs carrying an explicit EPP `1001` are promoted to accepted
`pending`; transport uncertainty is not promoted or replayed. Ownership already removed
by the old implementation cannot be reconstructed safely from deletion history alone:
independently verify and import any still-redeemable legacy domains with
`object:assign REGISTRY_UUID domain example.com RESELLER_UUID deleted` (or choose Deleted
in the administrator ownership-import form). Existing mappings are never overwritten.

### Dispatch and recovery

API worker 0 runs a database dispatcher every second. It leases due queued jobs and sends
them to the existing task workers. Claims at execution are atomic, so duplicate/lost task
delivery cannot execute the same queued job twice. A busy registry defers a never-attempted
job for five seconds; the dispatcher retries it without needing the client to POST again.
Expired delivery leases make lost queued tasks eligible again. A database advisory lock
coordinates dispatchers across API instances and the in-flight budget prevents an
unbounded background task backlog.

A processing job older than `API_JOB_STALE_SECONDS` becomes `unknown` only after recovery
can acquire its registry session lock. Its wallet hold/reservation remain. It is **never
replayed automatically**. The original worker also rechecks its job state before executing,
so an interrupted pre-lock worker cannot proceed after recovery. Operators must still stop
an original worker and verify the registry outcome before manual reconciliation.

| Setting | Default | Purpose |
| --- | --- | --- |
| `API_QUEUE_TICK_MS` | 1000 | Dispatcher interval |
| `API_QUEUE_BATCH` | 8 | Maximum background tasks selected per tick |
| `API_QUEUE_MAX_INFLIGHT` | 8 | Background/processing task budget; omit to derive it as twice the configured task-worker count |
| `API_DISPATCH_LEASE_SECONDS` | 60 | Lost-delivery retry lease |
| `API_JOB_STALE_SECONDS` | 900 | Stale-processing recovery threshold |
| `API_RECONCILE_SECONDS` | 30 | Accepted-pending observation retry interval |
| `API_PURGE_CHECK_SECONDS` | 300 | Retained deleted-domain purge-check interval |

A job in `pending` was accepted by the registry; a job in `unknown` has an uncertain write
outcome. Both retain funds until confirmed settlement. Pending domain creation, deletion,
restoration and transfer operations are observed using read-only registry queries.
Transient query errors retain holds; they do not become rejection/refund evidence.
Pending commands whose effects cannot be proved through the available profile parser need
operator reconciliation. Background observations do not add reseller job charges.

`GET /v1/jobs` can filter `status=pending`. Single-job details also expose the domain,
transfer operation, parent-job UUID, next attempt/reconciliation time and last observation
error, without exposing request authInfo or registry credentials. To request an earlier
safe check of an owned pending job, or reschedule an owned queued job:

```http
POST /v1/jobs/JOB_UUID/reconcile
Authorization: Bearer TOKEN
```

This returns HTTP 202 with the current job. It schedules work; GET polling stays read-only.
The endpoint refuses unknown writes and cannot select another reseller's job.

### Delete and restore

A successful deletion retains the domain's owner with `domain_state=deleted`. It remains
visible in `GET /v1/domains` and the panel's Objects page. Its `active` flag describes
confirmed ownership, not whether the domain resolves or is outside redemption. Ordinary
updates/renewals/new creates are blocked on retained deleted names; owner info and restore
operations remain available. Background info checks remove the retained mapping only after
registry code 2303 confirms absence. Transient failures never release the name to another
reseller. Profiles hiding absence or returning insufficient lifecycle information require
operator verification; no fixed redemption duration is guessed.

Request restoration through the existing command with its configured restore price:

```http
POST /v1/epp/domain.restore
Authorization: Bearer TOKEN
Idempotency-Key: restore-example-0001
Content-Type: application/json

{"registry_uuid":"REGISTRY_UUID","params":{"domainname":"example.com"}}
```

Restoration remains pending until an observation confirms completion, so a report-required
restore is not charged/completed prematurely. The original restore job stays reserved while
its report is submitted as a linked follow-up. Reports are free unless a legacy report
price exists; new report prices are not supported.

The new `domain.restore_report` command supports RFC 3915 reports for **generic/VRSN**
profiles. It builds XML from validated actual data; it does not call the pinned client's
report method containing sample registration data/dates. Other profiles are rejected for
this report adapter until an appropriate profile-specific mapping is implemented.

```json
{
  "registry_uuid": "REGISTRY_UUID",
  "params": {
    "domainname": "example.com",
    "pre_data": "Verified registration data before deletion",
    "post_data": "Verified registration data after restoration",
    "deleted_at": "2026-11-01T12:00:00Z",
    "restored_at": "2026-11-13T12:00:00Z",
    "reason": "Registrant requested restoration",
    "statements": ["Actual first registrar statement required by the registry", "Actual second registrar statement required by the registry"],
    "other": "Optional supporting information"
  }
}
```

Send this to `POST /v1/epp/domain.restore_report` with the normal bearer token and a fresh
Idempotency-Key. Dates must be UTC `YYYY-MM-DDTHH:MM:SSZ`, with deletion no later than
restoration. Supply accurate free-text data and the two registry-required statements.
Completion reactivates retained ownership and settles the original restore hold once.

### Pending transfers

An incoming transfer request keeps the original reservation/hold while pending. Its owner
can submit `domain.transfer` with `op=query` or, while acceptance is known, `op=cancel`.
These linked follow-ups do not take ownership of the original reservation or release its
funds merely because a query succeeded. Query/cancel operations use their existing explicit
period-0 prices; the original request uses its term price.

Automatic transfer observations correlate the domain, gaining/losing client ID and transfer
request date before settling. Approval activates incoming ownership and charges the original
hold once; a confirmed cancellation/rejection releases it and removes only the incoming
reservation. Stale/mismatched/unsupported observations leave the original pending. A queued
query remains authorized if its original transfer completes before the query executes.
For profile methods requiring original request parameters, workers merge them server-side;
the panel never receives stored authInfo.

A transport-unknown incoming request may be queried for diagnosis, but is not silently
finalized from an ambiguous observation. Cancellation of such an uncertain request remains
blocked until an operator verifies it. No registry mutation is automatically retried.
This uses transfer-query reconciliation rather than consuming or acknowledging a shared
upstream poll queue, avoiding accidental loss of unrelated registry notifications.

The panel links every history row to scoped job details. Pending jobs offer **Check registry
outcome**, transfers offer query/cancellation, retained deleted objects offer restore, and
pending restores offer the validated report form. Registration and new transfer-request
controls remain API-only. Administrators can manually resolve verified pending or unknown
jobs using the existing reconciliation controls/CLI.

Run `tests/lifecycle.php` with the same `TEST_MYSQL_*` settings as the other suites. It tests
real database reservations/ledger transitions, queue leases, registry-lock recovery,
transfer correlation, cancellation, purge retention, report XML and v3-to-v4 migration.
Registry observations are simulated; no live registry commands are sent.
