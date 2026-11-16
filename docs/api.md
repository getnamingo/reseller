# API reference and operations

[← Project README](../README.md)

Authentication, commands, pricing/ownership behavior, wallet settlement, self-service listings and job lifecycle. For registry and price setup, see [initial reseller and registry configuration](install.md#initial-reseller-and-registry); for web controls, see the [panel guide](panel.md).

All shell commands and repository paths below assume the repository root as the working directory.

In this guide:

- [Overview](#overview)
- [What is implemented](#what-is-implemented)
- [Upstream EPP compatibility](#upstream-epp-compatibility)
- [API use](#api-use)
- [Deposits and reconciliation](#deposits-and-reconciliation)
- [Regression tests](#regression-tests)
- [Reseller self-service API](#reseller-self-service-api)
- [Deletion, restoration, transfers and durable dispatch](#deletion-restoration-transfers-and-durable-dispatch)
- [Dispatch and recovery](#dispatch-and-recovery)
- [Delete and restore](#delete-and-restore)
- [Pending transfers](#pending-transfers)

## Overview

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

Use the [standalone PHP client](../plugins/php-client/README.md) from your own applications or run its availability example:

```bash
export API_BASE_URL=https://api.example.test
export NAMINGO_API_URL="$API_BASE_URL"
export NAMINGO_USERNAME=reseller1
export NAMINGO_API_PASSWORD='reseller-api-password'
export NAMINGO_REGISTRY_UUID=REGISTRY_UUID

php plugins/php-client/examples/check.php example.test
```

The client requires PHP 8.2+, JSON and cURL, independently of the server's Composer project. Use an HTTPS API endpoint. For a private CA, configure `CurlTransport(caFile: '/path/to/private-ca.pem')` as shown in the [transport documentation](../plugins/php-client/README.md#transport-and-low-level-requests); certificate verification stays enabled.

The client supports all exposed commands through `epp()`, job polling, account/price access and deposit requests. For writes, persist the exact payload and idempotency key before submitting, and reuse them on retries; see the [write and retry guidance](../plugins/php-client/README.md#writes-paid-operations-and-retries).

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
filters. Its existing **Prices** and **Domains** pages already provide the other two
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
visible in `GET /v1/domains` and the panel's Domains page. Its `active` flag describes
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
its report is submitted as a linked follow-up. Reports are always free; old report prices are
ignored and new report prices are not supported.

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
funds merely because a query succeeded. Query/cancel operations are always free, even if
legacy period-0 transfer prices exist; the original request uses its term price.

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
