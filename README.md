# Namingo Reseller

A compact PHP 8.5/Swoole JSON API for domain resellers. It authenticates resellers, keeps a MariaDB wallet and append-only ledger, encrypts registry credentials and job payloads, and runs each blocking EPP operation in a Swoole task worker using [Namingo EPP Client](https://github.com/getnamingo/epp-client).

The project deliberately stays small: two runtime source files, one HTTP entry point, one administration CLI, one schema, and one Guzzle client example.

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

## Ubuntu 26.04 installation

The package helper adds Ondřej Surý's PHP PPA, installs PHP 8.5 and `php8.5-swoole`, and configures the MariaDB Community 11.8 repository:

```bash
sudo bash deploy/install-ubuntu-26.04.sh
sudo mariadb-secure-installation
```

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
```

The CLI encrypts the whole normalized registry JSON before insertion. PEM material is briefly written as mode `0600` under `/run/namingo-epp-api` by a task worker and removed in `finally`.

Every command needs an explicit price, including zero for free operations. Missing prices
return `409 price_not_configured` before an EPP job or wallet hold is committed.
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
# Explicit free operations: repeat for each command you intend to offer for free.
sudo -u namingo-api php8.5 bin/manage.php price:set REGISTRY_UUID domain.check 0 EUR
sudo -u namingo-api php8.5 bin/manage.php price:set REGISTRY_UUID contact.create 0 EUR
sudo -u namingo-api php8.5 bin/manage.php price:set REGISTRY_UUID domain.info 0 EUR
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

4. Configure explicit prices (including free operations) and exact zone/term totals,
   then restart the API and workers.

Successful creates activate ownership; rejected creates release it. Successful deletes
and outgoing transfer approvals remove it. Pending EPP responses (`1001`), unconfirmed
incoming transfers and uncertain writes remain `unknown`, preserving ownership
reservations and wallet holds. Verify the final registry outcome, then use the existing
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

Submit a read command:

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

Other endpoints:

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
2. `charge`: remove held money after a successful or accepted EPP response.
3. `release`: return held money after a definite rejection before/at the registry.
4. `unknown`: retain the hold after an ambiguous transport failure.

For an ambiguous job, check the registry or poll data first, then resolve exactly once:

```bash
sudo -u namingo-api php8.5 bin/manage.php job:resolve JOB_UUID charge
# or
sudo -u namingo-api php8.5 bin/manage.php job:resolve JOB_UUID release
```

If a process or host crash leaves an old job in `processing`, an operator may first run `job:mark-unknown JOB_UUID`; this deliberately keeps its hold for reconciliation. A queued job can be dispatched again by retrying the identical request with the identical Idempotency-Key.

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

The suite covers ownership and contact references, concurrent create reservations,
unknown/rejected/deleted objects, idempotency, FRED/LT identifiers, pricing by zone and
term, longest-zone precedence, explicit free operations, wallet rollback and schema upgrade.
