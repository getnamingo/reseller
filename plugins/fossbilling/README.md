# Namingo reseller registrar for FOSSBilling 0.8.9

Connect a reseller's FOSSBilling installation to the registrar's existing reseller REST API. This is an independent adapter: it does not require the WHMCS plugin, direct EPP credentials or access to the registrar database.

## Installation

1. Copy `library/Registrar/Adapter/Namingo.php` and the adjacent `Namingo` directory into those same paths under the FOSSBilling installation root. Keep both; no Composer installation or core modifications are needed.
2. Use FOSSBilling **0.8.9**, PHP **8.3+**, and its normal PDO MySQL, OpenSSL, mbstring and cURL extensions. PHP intl is required for Unicode domains; ASCII/punycode names work without it.
3. Recommended: import `install.sql` into the **FOSSBilling database** before enabling orders. Otherwise the adapter creates its request table on first use if the database user has CREATE permission.
4. In the FOSSBilling admin area, open **System → Domain registration**, install/select **Namingo**, and configure its settings. Assign Namingo to the required TLDs and set their retail prices and supported registration periods.
5. The registrar administrator must enable this reseller's registry/TLD permissions, configure wholesale prices for the required operations/terms and fund the wallet. API workers and the lifecycle scheduler must be running.

| Setting | Value |
| --- | --- |
| API base URL | HTTPS API endpoint, for example `https://api.registrar.example`, without `/v1`; not the reseller panel URL |
| Reseller username | API account username |
| Reseller API password | API password; it can differ from the panel password; marked secret in the FOSSBilling settings schema |
| Default registry UUID | Optional fallback for TLDs not in the map |
| TLD registry map | JSON object mapping TLD suffixes to registry UUIDs |
| Allow registry deletion on order cancellation | Default **No**; see cancellation behavior below |

Example TLD map (replace the UUIDs):

```json
{
  "com": "11111111-1111-4111-8111-111111111111",
  "net": "11111111-1111-4111-8111-111111111111",
  "org.ua": "22222222-2222-4222-8222-222222222222"
}
```

The longest suffix wins; `org.ua` overrides `ua`. A leading dot is accepted. Unmapped TLDs use the default registry, or fail explicitly when no default exists. Obtain allowed registry UUIDs from the registrar or authenticated `GET /v1/registries`. Keep existing domains mapped to their original registry.

The FOSSBilling **Test mode** checkbox does not simulate registry operations. When enabled, the adapter refuses API operations. To test, create a separate registrar configuration using the real sandbox API URL, sandbox credentials and matching registry UUIDs, and leave that checkbox off. HTTPS certificate validation is always enabled; install private CA certificates in the system trust store if needed.

## Coverage

| Feature | Behavior |
| --- | --- |
| Availability | API domain check; API/permission failures raise registrar errors rather than claiming a domain is available |
| Transfer precheck | Confirms the domain is registered; the registry validates transfer authorization and eligibility on submission |
| Registration | Creates dedicated registrant/admin/technical/billing contacts, then registers with the supplied 2–4 nameservers |
| Incoming transfer | Persists the transfer request and waits for confirmed registry approval before allowing order activation |
| Renewal | Uses the billing order's expiry date to identify one renewal cycle; retains its exact term |
| Domain details | Reads registry expiry, creation date, nameservers, transfer lock and owned contact data |
| Contact updates | Updates existing owned EPP contacts; shared contacts also change at their other domains |
| Nameservers and lock | Replaces delegation; reads/enables/disables `clientTransferProhibited` |
| EPP code | Explicitly retrieves the registry's current authorization code |
| Registry deletion | Available only when explicitly enabled in the registrar settings |

Profiles **generic**, **UA** and **VRSN** are supported. VRSN is contactless: registration skips EPP contacts, details retain the billing contact, and registry contact editing raises an explicit error. Other registry profiles fail before writes until adapters for their extra legal data/operation semantics are provided. Registry-specific eligibility rules still apply.

For an incoming transfer, registry contacts may not be owned by this reseller in the API. If contact reads are forbidden, domain details preserve the supplied billing contacts rather than replacing them with blanks. A contact update still requires API ownership and may need registrar assistance. Other contact-read failures remain errors.

WHOIS privacy, hosted DNS, forwarding, premium-fee negotiation, DNSSEC editing, restore/report workflows and automatic price import are not provided. The required privacy methods return explicit unsupported errors. Use the reseller panel/API for functions outside this adapter. Configure FOSSBilling retail prices manually: wholesale multi-year totals can differ from a per-year pricing model. Only offer terms that have wholesale prices and are accepted by the registry.

FOSSBilling 0.8.9 supplies four nameserver fields and has no separate registrar transfer-sync callback or domain-status setter. This adapter uses its native domain-details/synchronization flow for expiry and contact data; it does not pretend to offer extra platform callbacks or a client-area registry-status display.

## Pending transfers, retries and storage

**A pending incoming transfer deliberately does not return success to FOSSBilling.** Stock 0.8.9 would activate the billing order on success. The adapter instead raises a registrar message with the saved job reference. After registry approval and API lifecycle reconciliation, retry activation of the **same order**. It reuses the original transfer job, queries transfer status and returns success only for `clientApproved`/`serverApproved`. A rejected/cancelled transfer is reported as failed. This needs no FOSSBilling core patch, but pending transfers require staff to retry activation; there is no added background activation task.

Registration, renewal, contact changes and other writes save their exact payload and idempotency key **before** API submission. Timeouts, failed job reads and unknown outcomes retain this record. Retry the same action with the same input; do not place another order to get past a timeout. Unknown jobs must be reconciled through the registrar before retrying. Definitively rejected jobs permit a fresh corrected attempt. Changed input cannot replace an uncertain saved request.

The `namingoregistrar_request` table lives in the FOSSBilling database and uses a **separate autocommit connection** created through FOSSBilling's database factory. A billing transaction rollback therefore cannot erase an idempotency key after a registry command has been submitted. Renewal identity uses the **billing order's expiry date**, which advances after successful renewal processing, rather than the separately synchronized registry expiry. An expiry sync during recovery therefore does not turn a retry into a second paid renewal. Concurrent actions for the same order/domain use database advisory locks. API bearer tokens are cached to avoid repeated logins and removed on HTTP 401 so the next attempt authenticates again.

Payloads, contact data, authorization codes and cached tokens use FOSSBilling's `Box_Crypt` encryption and installation `info.salt`. Back up that salt with the request table. Never truncate the table as routine maintenance: retained registration/transfer and billing-cycle renewal records prevent duplicate submissions. No raw registry responses, API passwords or contact details are logged by this adapter. FOSSBilling continues to maintain its normal service/order data.

Completed acquisitions remain tied to the FOSSBilling order ID. A later separate acquisition needs a new order or a registrar-assisted review of the saved records. Do not manually change the billing order's expiry date to bypass an uncertain renewal.

### Cancellation and deletion

FOSSBilling calls `deleteDomain()` when cancelling/deleting a domain order. The adapter blocks registry deletion by default with an explicit error. If you enable **Allow registry deletion on order cancellation**, that action really submits `domain.delete` to the registrar. Cancelling billing and deleting a registered domain have different consequences; use the reseller panel for explicit deletions if you leave the setting disabled. A pending registry deletion is not reported as completed.

## Tests and staging

Run the contract/recovery suite against a checkout of the **0.8.9** release:

```sh
FOSSBILLING_ROOT=/path/to/FOSSBilling/src php plugins/fossbilling/tests/run.php
```

Tests load the release's real registrar abstract class, domain/contact classes, exception and encryption implementation. The order model, API transport and in-memory request store are test doubles; optional database checks exercise the real PDO store and encryption. Submitted parameters are checked against this repository's real command/profile validator. Database checks use `FOSSBILLING_TEST_MYSQL_DSN`, `FOSSBILLING_TEST_MYSQL_USER` and `FOSSBILLING_TEST_MYSQL_PASSWORD`; point these at a **disposable database** because the suite creates/drops `namingoregistrar_request` there.

Before production, use a FOSSBilling 0.8.9 staging installation and funded registrar test registries to verify checkout/activation, pending/approved/rejected transfer activation retries, renewal and expiry synchronization, contacts, nameservers, lock/unlock and EPP retrieval. Interrupt an API write response and confirm that the retry uses one original job and only one debit. Verify cancellation with deletion disabled before enabling it. The automated suite does not exercise the full web checkout, invoice processing or live EPP registries.

Interface reference: [FOSSBilling registrar integration guide](https://docs.fossbilling.org/extensions-and-development/guides/creating-a-registrar-integration/), checked against the [0.8.9 registrar contract](https://github.com/FOSSBilling/FOSSBilling/blob/0.8.9/src/library/Registrar/AdapterAbstract.php).
