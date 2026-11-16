# WHMCS Namingo registrar module

Registrar module targeting **WHMCS 9.1.x**, PHP 8.2–8.4, using this repository's reseller REST API. Install it in each reseller's own WHMCS installation. It does not connect directly to EPP or the registrar database.

## Install

1. Copy `modules/registrars/namingo` from this directory to the same path inside WHMCS. Do not upload the tests.
2. Ensure PHP cURL is enabled and the WHMCS server can reach the registrar's HTTPS API. Keep certificate verification enabled; private certificate authorities must be installed in the server's trust store.
3. In WHMCS **System Settings → Domain Registrars**, activate **Namingo Reseller API**. Enter the API base URL (for example `https://api.registrar.example`, without `/v1`), reseller username and reseller **API password**. A panel password may be different.
4. Configure either a default registry UUID or the TLD registry map below. The registrar administrator supplies registry UUIDs; resellers can also obtain their enabled registries with authenticated `GET /v1/registries`.
5. In **Domain Pricing**, assign Namingo to the required TLDs, configure retail prices and enable EPP codes for transfers. Enable WHMCS domain synchronization and its normal cron tasks. Do not offer DNS management, email forwarding, ID protection or premium domains through this module.
6. The registrar administrator must enable this reseller's registry/TLD access, configure the required wholesale prices and fund the reseller wallet. Its API workers and lifecycle scheduler must be running.

The module creates `mod_namingo_requests` in the **WHMCS database**, using its existing database connection. The WHMCS database user needs CREATE permission on first use. Request payloads, auth codes and API tokens are encrypted with WHMCS `encrypt()`/`decrypt()`; back up this table and the WHMCS encryption key together. Do not truncate it as routine maintenance: retained paid-operation records prevent duplicate submissions after timeouts.

Example map (replace UUIDs):

```json
{
  "com": "11111111-1111-4111-8111-111111111111",
  "net": "11111111-1111-4111-8111-111111111111",
  "org.ua": "22222222-2222-4222-8222-222222222222"
}
```

Keys accept a leading dot. The longest suffix wins, so `org.ua` takes precedence over `ua`. An unmapped TLD uses the optional default registry; without a default it is unsupported. Registry selection never silently falls back to the first available registry. Keep existing domains mapped to their original registry.

## Supported operations

| WHMCS feature | Behavior |
| --- | --- |
| Availability | API `domain.check`; unsupported mapping is distinguished from API errors |
| Register | Creates dedicated registrant/admin/technical/billing contacts, then registers with 2–5 nameservers |
| Transfer in | Submits the transfer request with EPP code; TransferSync checks approval or rejection separately |
| Renew | Uses the WHMCS domain expiry date and term to identify one renewal safely |
| Nameservers | Read and replace delegation |
| Registrar lock | Read/add/remove `clientTransferProhibited` |
| Contact details | Read/update owned contact objects; existing shared contacts also affect their other domains |
| EPP code | Returns the registry's current authorization code |
| Child nameservers | Register, modify IP and delete owned host objects |
| Domain deletion | Explicit WHMCS registrar delete action; pending registry deletion remains pending |
| Synchronization | Reads registry expiry and pending status; never infers transfer-out or deletion from a failed API request |

This first version supports the API's **generic, UA and VRSN** profiles. VRSN is contactless: registration skips EPP contacts and contact editing reports that the registry does not support it. Other profiles require adapters for their legal data, nameserver models or different operation semantics; the module refuses them before submitting writes. Registry-specific eligibility rules (for example `.ua` trademark requirements) still apply.

There is no automatic wholesale-to-retail price import: API prices are exact totals for each term and can differ from WHMCS's per-year pricing model. Set retail prices and supported terms in WHMCS. No DNS hosting, mail forwarding, privacy service, premium-fee negotiation, DNSSEC editor, restore-report workflow, IRTP verification workflow, domain suggestions or outgoing-transfer approval UI is advertised. Use the reseller panel/API for functions outside the module's coverage. For gTLDs, the registrar remains responsible for its registrant/IRTP verification workflow.

## Jobs, retries and recovery

Writes are saved **before** contacting the API and use persistent `Idempotency-Key` values. A network timeout is not treated as failure of the registry operation. Retry the same WHMCS action with the same input: the module resumes its saved job instead of charging/registering/renewing again. It polls briefly after the API's normal synchronous wait. `queued`, `processing`, `pending` (except an accepted incoming transfer), rejected and unknown outcomes are never falsely reported as completed writes.

An accepted pending transfer returns success to WHMCS so it stays in its normal transfer workflow. `TransferSync` only reports completion after the registry returns `clientApproved` or `serverApproved`; it reports registry cancellation/rejection separately. Registrations and renewals that have not completed return an error with a job UUID for staff to review/retry.

Definitively rejected jobs can be retried with corrected input. Uncertain jobs retain their original payload and cannot be replaced with different input. Resolve `unknown` jobs through the registrar's job reconciliation tools, then retry the original WHMCS action. Never delete a saved request to get past an unknown outcome. Expired/revoked bearer tokens are removed from the encrypted cache on HTTP 401; the next attempt logs in again. Password changes use a different token-cache key.

Completed registration/transfer attempts remain tied to the WHMCS domain ID. A later, separate acquisition of the same name must use a new WHMCS domain record, or a registrar-assisted review of the retained records. Completed renewals are retained for each WHMCS expiry-date/term pair. Do not change the expiry date manually to bypass a pending renewal. Free completed updates can be repeated later. Raw credentials, contact data and registry responses are not written to module logs.

## Validation

Run the standalone contract/recovery tests from the repository root:

```sh
php plugins/whmcs/tests/run.php
```

The tests use API fixtures and the server's real parameter validator, not a licensed WHMCS runtime. For optional database coverage, provide `WHMCS_TEST_MYSQL_DSN`, `WHMCS_TEST_MYSQL_USER` and `WHMCS_TEST_MYSQL_PASSWORD` pointing to a **disposable database**; the test creates and drops `mod_namingo_requests` there using a test double for WHMCS's encryption interface.

Before production, test in a licensed WHMCS 9.1.x staging installation against funded registrar test registries: availability, registration/contact ownership, nameservers, lock/unlock, EPP retrieval, renewal/expiry sync, transfer pending/completion/rejection, host IP updates and deletion. Also interrupt one API write response and verify that a retry uses the original job and only one debit. Do not test registration/renewal/deletion against production domains.

Module interfaces follow the [WHMCS registrar developer documentation](https://developers.whmcs.com/domain-registrars/). No WHMCS SDK source or dependencies are bundled.
