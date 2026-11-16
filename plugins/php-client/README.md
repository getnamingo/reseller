# Standalone PHP reseller API client

Use the Namingo reseller REST API from your own PHP application, CLI tool, queue worker or framework. This library has **no WHMCS, FOSSBilling, Swoole, database or Guzzle dependency**. It uses PHP cURL by default; an injectable transport interface allows another HTTP library.

Requires PHP **8.2+**, JSON and cURL. PHP OpenSSL is also used by the local HTTPS integration test. The client connects to the API, not the reseller panel, and authenticates with a reseller's API credentials or an existing bearer token.

## Install

Copy this `php-client` directory into your application and load:

```php
require '/path/to/php-client/bootstrap.php';

use Namingo\ResellerClient\Client;

$client = new Client(
    getenv('NAMINGO_API_URL'),         // https://api.registrar.example — without /v1
    getenv('NAMINGO_USERNAME'),
    getenv('NAMINGO_API_PASSWORD'),    // API password; may differ from the panel password
);
```

No Composer command is required. For applications already using Composer, the directory includes its own `composer.json` and PSR-4 mapping. Add it as a local path repository and require the package; this repository does not imply a published Packagist release:

```json
{
  "repositories": [{"type": "path", "url": "packages/php-client"}],
  "require": {"namingo/reseller-api-client": "@dev"}
}
```

Then use your app's `vendor/autoload.php` instead of `bootstrap.php`. Install from this directory rather than the reseller server's root Composer project, which has different PHP/extension requirements.

## Account, registries and prices

```php
$account = $client->account();
$wallet = $account['wallet']; // Decimal strings; do not convert money to binary floats.
$registries = $client->registries();
$catalogue = $client->commands(); // Exposed commands, profiles and native parameter contract.

$page = $client->prices(['registry_uuid' => $registryUuid, 'tld' => 'com', 'limit' => 50]);
$prices = $page->data();
$pagination = $page->pagination();
$requestId = $page->requestId();

foreach ($client->iterate('domains', ['registry_uuid' => $registryUuid], 100) as $domain) {
    // One row at a time; the next page is fetched lazily.
}
```

Use UUIDs from `registries()`. Your application chooses the registry for each command; the client does not infer TLD routing, grant permissions or create prices. The registrar must enable registry/TLD access and configure required wholesale prices. Registrations/renewals/transfers/restores need a funded wallet.

`prices()`, `domains()` and `jobs()` return a `Response` preserving data, pagination, request ID and HTTP status. `iterate()` supports these three resources only, with the same filters as their API endpoints. `ledger()` and `deposits()` return limited recent lists; the API does not expose offset pagination for them. Changing data during pagination is not a database snapshot.

## Read commands and availability

```php
$job = $client->epp($registryUuid, 'domain.check', [
    'domains' => ['example.com', 'example.net'],
], respondAsync: true);

$job = $client->waitForJob($job, timeoutSeconds: 30);
$result = Client::assertSucceeded($job);
foreach ($result['domains'] as $row) {
    // $row['name'], $row['avail'], $row['reason']
}

$infoJob = $client->epp($registryUuid, 'domain.info', ['domainname' => 'example.com']);
$info = Client::assertSucceeded($client->waitForJob($infoJob));
```

Names must use ASCII/punycode and belong to the selected registry. `respondAsync: true` sends `Prefer: respond-async`, supported by the API only for `domain.check`. Other commands already receive the API's usual bounded synchronous wait and may still return queued jobs.

The client passes `params` unchanged and supports all commands/profiles exposed by your server. It does not translate registry-specific legal fields or automatically create contact/host objects. Consult `commands()` and the server's native parameter contract for the selected profile. Registration often requires creating owned contacts first; VRSN is contactless. Referencing another reseller's objects is not permitted.

## Writes, paid operations and retries

**Save a stable operation ID, exact command/payload and idempotency key durably before submitting a write.** Use the same saved values after a timeout or process restart. The client never silently generates a write key or automatically retries HTTP calls.

```php
// This example assumes these values were loaded from a committed application operation record.
$key = $operation['idempotency_key'];
$params = $operation['params'];       // e.g. ['domainname' => 'example.com', 'regperiod' => 1]

if (!empty($operation['job_uuid'])) {
    $job = $client->job($operation['job_uuid']);
} else {
    $job = $client->epp($operation['registry_uuid'], 'domain.renew', $params, $key);
    // Persist $job['job_uuid'] against this same operation before continuing.
}
$job = $client->waitForJob($job, 30);
$result = Client::assertSucceeded($job);
// Mark the application operation complete only after confirmed success.
```

`Client::newIdempotencyKey()` generates a suitable key **when explicitly called**. Generate it once per intended operation and commit it with the payload before calling `epp()`. Do not call it inline on every attempt. Keep application request storage independent of a billing transaction that might roll back after the API accepts the command.

Resume outstanding renewals before deriving a new purchase from the current domain expiry or term: domain sync may advance expiry while the original API response is lost. The standalone client intentionally does not own your database or billing workflow; **your application must persist and resume outstanding operations**. The same key with changed payload is an API conflict, not a new operation.

All EPP commands except the known read commands require a key in `epp()`, including free contact/host/status updates and transfer cancel/approve/reject. Transfer `op=query` is a read. Commands added by a future server default to requiring a key. The API decides pricing: DNSSEC registration uses registration pricing; transfer requests are priced, while transfer follow-up operations are free.

Job states are independent of HTTP success:

| State | Meaning for the app |
| --- | --- |
| `queued`, `processing` | Not complete; keep the job reference and poll later |
| `pending` | Registry processing is still pending; an accepted transfer is not completed |
| `succeeded` | Completed; `assertSucceeded()` returns the native EPP result |
| `rejected` | Confirmed rejection; inspect the job result before deciding on a corrected attempt |
| `unknown` | Outcome uncertain; ask the registrar to reconcile it before any fresh write |

`waitForJob()` returns the latest job when its polling budget expires; it does not manufacture a failed/completed state. By default it stops at `pending`, `succeeded`, `rejected` or `unknown`. Set `waitForPending: true` to also poll pending lifecycle jobs within the budget. Transfers can take days, so normally store the reference and revisit later. Each polling HTTP request is capped to the remaining budget; authentication happens separately before polling. `assertSucceeded()` raises `JobException` for every state except `succeeded`.

`reconcile($jobUuid)` requests the API's reconciliation scheduler for eligible pending transfer/restore lifecycle jobs. It does not force success, repeat a paid request or resolve arbitrary `unknown` outcomes. Unknown outcomes require registrar review.

## Authentication and token reuse

Login happens lazily on the first request. Tokens are kept in memory for the client instance and refreshed on expiry when credentials are available. A server HTTP 401 clears the token and raises `ApiException`; the next explicit call logs in again. There is no hidden retry of the failed operation.

For separate application requests/processes, reuse a token from an **encrypted, application-controlled session store** to avoid the API's login rate limit:

```php
$client = new Client($baseUrl); // Existing-token-only client, without stored login credentials.
$client->setAccessToken($saved['access_token'], $saved['expires_at']); // Unix timestamp, or null if unknown.
$account = $client->account();

// A credential-configured client can export a newly logged-in session for your encrypted cache:
$clientWithCredentials->login();
$session = $clientWithCredentials->session(); // access_token + expires_at; sensitive!

$clientWithCredentials->logout(); // Revoke this token and clear local state.
```

Do not log session data, serialize client objects, expose credentials to browsers or persist tokens in plaintext. Debug views of client/response objects redact their sensitive payloads, but explicit access to returned arrays still contains real data. For token-only clients, obtain a replacement from your application's authentication layer when the token expires or is rejected.

## Deposits and errors

```php
$deposit = $client->requestDeposit('100.00', 'EUR', $savedDepositReference);
// This requests a deposit; it does not charge a card or credit the wallet automatically.
$recentDeposits = $client->deposits(50);
$recentLedger = $client->ledger(50);
```

Reuse the saved deposit reference on retries; changed amount/currency with that reference is a conflict. Pass money as decimal strings.

```php
try {
    $job = $client->job($savedJobUuid);
} catch (\Namingo\ResellerClient\ApiException $error) {
    // HTTP error: httpStatus, apiCode, details, requestId, headers (including Retry-After if supplied).
    // Example: HTTP 402 / insufficient_funds. Choose application-specific backoff for 429/503.
} catch (\Namingo\ResellerClient\TransportException | \Namingo\ResellerClient\ProtocolException $error) {
    // Connection/timeout or malformed response. A prior write may still have been accepted.
    // Preserve the operation key/payload and retry the same operation or fetch its saved job.
}
```

Registry rejections and unknown jobs can arrive with HTTP 200, so inspect the job status or explicitly call `assertSucceeded()`. No API or EPP result is logged automatically. Error details and native results can contain customer information/auth codes; avoid logging full response/exception objects.

## Transport and low-level requests

```php
use Namingo\ResellerClient\CurlTransport;

$client = new Client($baseUrl, $username, $password,
    new CurlTransport(connectTimeoutSeconds: 10, caFile: '/path/to/private-ca.pem'),
    timeoutSeconds: 40);

$response = $client->request('GET', '/v1/domains', query: ['name' => 'example', 'limit' => 25]);
```

TLS peer/hostname checks stay enabled and redirects are not followed. To use Guzzle or a framework HTTP client, implement `TransportInterface::send()` and return `HttpResponse` with lowercase response-header names. Honor the supplied timeout, verify HTTPS certificates, disable redirects and map connection/timeout failures to `TransportException` without automatic write retries. No Guzzle package is bundled. The low-level `request()` preserves the full API envelope; its caller is responsible for the native endpoint's body/key requirements.

## Examples and tests

Set `NAMINGO_API_URL`, `NAMINGO_USERNAME`, `NAMINGO_API_PASSWORD` and `NAMINGO_REGISTRY_UUID`, then:

```sh
php examples/check.php example.com example.net
```

`examples/renew.php` is a **paid** example requiring additional `NAMINGO_DOMAIN`, `NAMINGO_REQUEST_KEY` (already durably saved) and optional `NAMINGO_YEARS` (default 1). Use registrar test registries and funded test accounts for write testing. The script deliberately has no fallback that creates a new key each run.

From the repository root:

```sh
php plugins/php-client/tests/run.php
php plugins/php-client/tests/https.php
```

Contract tests use a fake HTTP transport and the server's real command/profile validator. The HTTPS integration test uses the real cURL transport against an ephemeral loopback TLS fixture with a generated test CA; it checks authentication, HTTP errors, certificate/hostname verification and redirect refusal without external credentials or paid commands. Full production API/EPP interoperability still needs your registrar test environment.
