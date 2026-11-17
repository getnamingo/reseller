# Reseller and administration panel

[← Project README](../README.md)

Panel behavior, administration, pricing, access control and manual panel setup. For server deployment and upgrades, start with the [installation guide](install.md); API endpoints and lifecycle behavior are in the [API guide](api.md).

All shell commands and repository paths below assume the repository root as the working directory. Database test settings are documented in the [regression-test instructions](api.md#regression-tests).

In this guide:

- [Foundry reseller panel](#foundry-reseller-panel)
- [Pricing in the control panel](#pricing-in-the-control-panel)
- [Reseller account and access](#reseller-account-and-access)
- [Deposit and job administration](#deposit-and-job-administration)
- [Managing registries, TLDs and permissions](#managing-registries-tlds-and-permissions)
- [Managing resellers](#managing-resellers)
- [Install or upgrade](#install-or-upgrade)
- [TLD administration and enforcement](#tld-administration-and-enforcement)
- [Contacts and domains](#contacts-and-domains)

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
prices, registry/TLD permissions and owned objects. Availability checks accept mixed TLDs
without a registry dropdown. Each domain uses
the longest permitted configured suffix (including multi-label zones); equal matches
across registries are reported as ambiguous. Unsupported, invalid and failed names get
individual results without blocking other registry groups. The panel submits only
`domain.check` through the API with the current reseller's token; results are read
through the API too. They are always free and use a stable idempotency key per form/registry group. Multi-domain checks use `Prefer: respond-async` to queue without waiting for each registry.
A single-domain check uses the normal synchronous API wait and immediately displays the
returned result when complete. If work is still queued/processing, the results table
updates automatically using read-only polling, without reloading the page or losing new
form input. Polling stops when all jobs finish and pauses after repeated network errors;
the Refresh results link remains available. Completed job results are reused during
refreshes, which never submit another check.
The latest 10 batches remain in the current panel session; individual job links remain
in check history. API clients still specify `registry_uuid`; automatic routing is a panel feature.
The reseller Prices page uses a responsive Tabler table with one row per registry/suffix,
exact one-year registration, renewal and incoming-transfer prices, currency labels,
override indicators and per-operation restore prices. Registry-wide defaults have their
own row. TLD search filters rows locally; Export CSV downloads the matching rows only,
with quoted cells and spreadsheet formula protection. Legacy DNSSEC and period-0
transfer prices are excluded from both the table and API price listings.
Filtering `/v1/prices` by `command=domain.create_dnssec` returns shared registration rows.
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
to independently verify the outcome, as with the CLI. Registry credentials remain encrypted at rest and are loaded only into the administrator-only registry edit form. Use one registry UUID per upstream account as before.
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

### Pricing in the control panel

**Administration → Prices** focuses on the paginated price list. The **Add price**
button beside the title opens a large scrollable modal. Choose one registry/TLD, a
global or reseller scope, and currency, then fill the years-by-operation table. All 31 amount fields start blank
and are required: totals for registration, renewal and incoming transfer for years 1–10, plus a
per-operation restore price. DNSSEC registration shares registration pricing; transfer
follow-ups are free and have no price fields.
Enter `0` explicitly for a free operation. A save updates all exact rows for that TLD
and scope in one transaction; missing or invalid fields roll back the entire table.

The price list has one row per registry/TLD, scope and currency, with pagination over
whole groups rather than individual operation/year rows. **Edit** loads the same full
price table with existing amounts prefilled; registry/TLD, scope and currency are fixed
metadata, without selectors. All missing fields remain blank and required. A save updates
the complete supported table in one transaction. Registry-wide restore groups retain a
restore-only editor.

**Delete all** removes every price row in that exact group, including ignored legacy
operations. Other scopes, currencies and TLDs remain unchanged. Normal global/parent-zone
fallbacks apply afterward, or required operations fail with `price_not_configured` when
no price remains. Existing jobs keep their recorded amounts. The editor/deletion checks
whether a group changed since loading and asks for a reload rather than overwriting a
stale table. Legacy-only groups offer deletion only.

If an older TLD table contains supported prices split across currencies, grouped editing
refuses to overwrite the other currency silently. Use **Add price** to explicitly replace
the complete table in the intended currency. Separate DNSSEC registration prices and
transfer follow-up prices remain unsupported. The panel and CLI share validation;
`GET /v1/prices` remains read-only and there is no API price-creation endpoint.

Run `php8.5 tests/prices.php` with the documented `TEST_MYSQL_*` environment to exercise
complete-table saves, validation/rollback and pricing fallback. `php8.5 tests/price_groups.php`
covers grouped edits/deletion, fixed metadata, version checks, currency isolation and
registry-wide restore editing; `node tests/prices.js` checks editor prefilling and reset.
`node tests/customer_prices.js` checks customer TLD filtering and CSV export.

### Reseller account and access

The reseller **Account** page presents all existing account fields in a responsive
Tabler data grid, followed by the full **Registry and TLD access** table. The table
retains registry UUID/profile and all registry/access/TLD permission flags. Both sections
are scoped to the signed-in reseller and remain read-only. The separate Access menu
item is removed, and `/access` redirects to `/account` for existing bookmarks.

### Deposit and job administration

**Administration → Deposits** and **Jobs** focus on their paginated tables, with no
separate action cards or UUID entry. Pending deposits have **Approve** and **Reject**
buttons; decided deposits have none. Pending/unknown jobs have **Approve** (charge the
hold and apply ownership) and **Release** buttons; processing jobs have **Unknown**
(retain the hold). Queued and completed jobs have no manual decision buttons. Job UUIDs
still link to the full investigation page.

Row actions open a Tabler confirmation modal with the selected record's context and
require the existing independent-verification checkbox. Backend permissions, CSRF and
status checks still apply, and actions retain the current pagination page. Deposit
approval requires verified receipt of funds; job decisions require a verified registry
outcome and confirmation that the original worker is no longer running.

Run `php8.5 tests/admin_tables.php` with the documented `TEST_MYSQL_*` environment and
`node tests/admin_actions.js` to exercise row eligibility, confirmations, wallet effects,
pagination and modal state reset.

### Managing registries, TLDs and permissions

**Administration → Registries** focuses on the paginated registry table. Header buttons
open Tabler modals to **Add a registry** (no existing-UUID field) or **Add TLD**. Each
registry row links to **Edit registry**, **TLDs** and **TLD permissions**.

The add and edit registry forms use EPP host/port, username/password and a Debug radio instead of a raw JSON textarea. The edit page loads stored values into the fields, including the password and complete SSL PEM text for administrators, and includes a state toggle plus a separate
reseller access card accepting any reseller UUID. Its paginated permission table contains
only that registry's records, with permit/revoke toggles.

SSL has separate CA certificate, client certificate and private-key textareas plus a key-passphrase field. Paste complete PEM contents; blank PEM fields are saved as JSON `null`, and multiline PEMs are serialized with escaped `\n`. Passwords/passphrases retain their exact spaces. Debug defaults off (`verify_peer=true`, `verify_peer_name=true`, `allow_self_signed=false`); on uses the inverse settings.

On **Update registry** only, enabling Debug with all three SSL PEM fields blank reveals **Generate SSL**. It uses the existing PHP OpenSSL extension to create a 2048-bit RSA key and a SHA-256 self-signed certificate valid for 365 days. If a key passphrase is entered, the generated private key uses it. The pair fills the client certificate/private-key fields only; CA contents are untouched. Click **Save registry** afterward to store the configuration through the normal encrypted save. Generation alone makes no database or filesystem writes. The administrator-only POST action requires CSRF and returns a non-cacheable response; changes typed while generation is running are preserved instead of overwritten. No additional Composer package is needed.

Advanced fields default to prefix `api`, timeout 30 seconds, TLS 1.3 (TLS 1.2 is also selectable), and the object/extension URI lists in `deploy/registry.example.json`. URI lists accept commas or new lines. Blank bind disables binding; an IPv4 address is stored as `IP:0` and IPv6 as `[IP]:0`, replacing a supplied source port with 0. Editing preserves existing settings not represented in the form, and absent URI overrides remain absent so profile defaults still apply. JSON is assembled and validated on the server before the existing encrypted save operation; no schema or API/CLI configuration format changes are required. Registry edit responses are marked private/non-cacheable.

The TLD page lists that registry's suffixes with colored enable/disable buttons, an Add
TLD modal and the existing guarded unused-TLD removal controls. The TLD permissions page
has a **Permit a TLD** modal that selects only resellers already assigned to the registry, a scoped paginated table, colored enable/disable buttons and
permission-removal controls. Grant the reseller access first on **Edit registry → Reseller access**. An empty selector links there, and missing registry/TLD prerequisites return actionable validation messages rather than generic database conflicts. TLD assignment never creates or re-enables registry-level access. Toggles retain the current page. Registry UUIDs come from
the URL on these pages and cannot be redirected by submitted form values.

The standalone TLDs navigation/page is removed; `/admin/tlds` redirects to Registries.
Legacy POST actions at that URL remain accepted for compatibility. Runtime permission
checks and removal safeguards remain unchanged. Run `php8.5 tests/registries.php` with
the documented `TEST_MYSQL_*` environment for the registry-management regression suite.

### Managing resellers

**Administration → Resellers** focuses on the account table (50 accounts per page).
Select a reseller's username to manage it. **Add a reseller** is a button alongside the
page title and opens a Tabler modal with the existing account-creation fields.

Each reseller page has account details followed by three responsive cards for status,
role and password reset. Token/session revocation is also available in the credentials
card. These forms use the account UUID from the URL; no UUID entry is needed, and a
submitted UUID cannot redirect an action to another account. Existing administrator,
CSRF, self-demotion/self-suspension and session-revocation protections still apply.

### Install or upgrade

For the standard demo layout, use `deploy/upgrade.sh` as described in the [upgrade guide](install.md#upgrade-an-installer-created-demo).
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
Contact/host operations are always free; old prices are ignored and new prices are not supported.

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

## Contacts and domains

Both menus have **Contacts** and **Domains**. The reseller workspace lists only the signed-in account's records, even when an administrator uses it. Administration lists all owners. Existing ownership-import controls remain available separately.

Contacts use a paginated table (50 contacts per page). **Add contact** beside the title opens the same scrollable Tabler modal used elsewhere. Administrators choose the owning reseller; resellers cannot change ownership. Select one or more registries, complete the native contact fields, and optionally fill five parameter-name/value pairs. Registry-specific fields are available in an expandable section. Additional parameters follow the existing API/profile serializer contract; adding a field does not add support for an upstream EPP extension.

Contact create/edit forms in both workspaces, Manage reseller and billing-profile editing use a shared Tabler country dropdown populated by `league/iso3166`. Country names are sorted alphabetically and the submitted values remain ISO alpha-2 codes. Saved countries are preselected; optional account country fields remain optional. A saved code absent from the library stays selectable to prevent unrelated edits clearing legacy values. Run `php tests/countries.php` for dropdown edge cases. Standard Composer install/upgrade installs the locked dependency; no migration is needed.

The local contact stores registry names and their actual contact IDs in a JSON mapping. Registry-generated IDs, including LT handles, are recorded from confirmed create responses. Contact details, additional values, authorization codes, registry error text and frozen requests are encrypted with the application master key. Existing API/CLI-owned contacts are discovered from verified ownership records when the list is opened; use **Sync** to populate their details. Contacts are not guessed to be the same person merely because handles or names match across registries.

**Update** opens the same form prefilled. Saving submits native contact updates to selected existing links and creates the contact at selected new registries. Unselected links remain unchanged. Known registry IDs are taken from the stored mapping and cannot be retargeted by additional parameters. A stale form must be reloaded before saving over newer data.

The **Sync contact** card at the bottom lists every linked registry, its contact ID and the latest result/EPP code. **Sync from registry** submits `contact.info` and refreshes the editable local fields from that response; other registries change only when you subsequently save. Each row also offers the API's dedicated authorization-code update rather than pretending a normal contact update changes the auth code.

**Delete** on the list requests deletion at every linked registry. There is no local guess about domain associations: registry rejections such as EPP 2305 remain visible, and failed links are retained. Confirmed successful links are removed, and the local contact disappears only after its last link is removed. A definitively rejected create draft with no registry contact can be removed locally.

Actions use the existing durable job ledger, native profile validation, registry grants and object ownership, then the normal dispatcher/workers execute EPP. Admin actions use the contact owner's access rather than granting that owner administrator permissions. Contact operations remain free. Registry errors and unsupported profile errors are displayed per link with job details where available. Queued/processing results refresh automatically, but polling never discards unsaved form edits. Unknown/pending writes must be reconciled before replacement; an interrupted prepared submission can be resumed with its original stored payload/key. FPM contact submissions have a persistent per-actor limit using `API_RATE_LIMIT_PER_MINUTE`.

Apply migration **006_contacts** before enabling the updated panel (the standard upgrade script handles it):

```bash
composer install --no-dev --classmap-authoritative
php8.5 bin/manage.php migrate
```

The migration adds one `panel_contacts` table without changing existing ownership or EPP job tables. Fresh installs include it in `database/schema.sql`. It does not contact any registries or invent contact data during migration.

Run `php8.5 tests/contacts.php` with the same disposable-database `TEST_MYSQL_*` settings as the other suites. It covers authorization, encrypted data/JSON mappings, extra parameters, registry-generated IDs, updates, sync, partial deletion, outstanding-job recovery, discovery, pagination and migration. `node tests/contacts.js` checks polling and unsaved changes; `php8.5 tests/panel_router.php` verifies that the new asset is also served by the documented development router. Registry responses are simulated; no live EPP commands are sent by these tests.


## Domain management

Both menus now open a paginated domain table: `/domains` contains the signed-in account's domains, while `/admin/domains` allows administrators to manage every reseller's domains. Administrators using the reseller menu still see only their own account. Ownership, registry/TLD grants and wholesale billing remain enforced by the existing API ledger, including when an administrator acts for a reseller.

The domain tables show registered/expiry dates from the latest cached successful domain-info result and local state alongside all registry statuses. Unsynced values show a dash; listing does not submit live EPP queries. The Registry column and Operation results panel are shown only in administration; reseller domain pages link to Jobs for operation outcomes.

**Create domain** opens a header modal. Enter one name or up to 50 ASCII/punycode names separated by spaces, commas or lines. Each name is routed by its longest permitted TLD using the same rules as availability checks; unsupported or ambiguous routes produce individual errors. The selected years, registry contact IDs, five nameservers and up to two DS records apply to every bulk member. Contact roles are optional in the form; the target registry's policy and native serializer decide which are required. Registration generates a separate authorization code for each domain. Supplying DS records selects `domain.create_dnssec`, which uses normal registration pricing.

Open a domain to view synced creation/expiry/update dates, statuses and contact/nameserver data. Separate cards submit the existing API commands for contact/registrant changes, nameserver replacement, DNSSEC add/remove/replace, authorization code changes, statuses, renewal and deletion. Registrant changes use `domain.update_contact` with `contacttype=registrant`. A transfer/restore card accepts the native parameters for transfer follow-ups, restore and restore reports. Jobs and their native EPP codes/messages remain available; a successful HTTP submission is not proof of registry success.

Additional registry parameters accept a JSON object, using the same field names/types as the API (for example EE documents, IS card fields or an owned FRED `nsid`). The domain name and the structured form fields take precedence. See `GET /v1/commands`, the API guide and `src/ProfileParams.php` for profile contracts.

Nameserver adapters follow the pinned Tembo version:
- EE/EU/FI/GE/HR/IT/LT/LV receive `nss` host attributes, including optional IPv4/IPv6 glue.
- Host-object profiles check each host and create an available host before submitting the domain command. Bulk members wait on the same host reservation and reuse the completed host, even if all availability checks initially reported it available. An existing public host is referenced without importing ownership. Their host-create serializer accepts one glue address per host; supplying both is rejected instead of dropping one.
- FRED creates a two-host NSSET first, unless an existing owned `nsid` is supplied. Its pinned `domainUpdateNS` implementation does not change NSSET associations, so the panel reports this limitation explicitly.
- The library/registry may reject unavailable commands, unsupported terms or glue for an unregistered parent domain. Those errors are retained. A domain with more than five returned nameservers must be edited through the API to preserve its complete set.

One new table, `panel_domain_operations`, stores encrypted immutable command plans, auth codes and results. Saved host/domain sequences continue on the API service's five-second timer even when the browser closes. Stable per-step idempotency keys recover jobs after a crash between ledger commit and local bookkeeping. Outstanding/unknown operations block fresh requests for that domain, except follow-ups explicitly permitted by the API lifecycle rules (including restore reports, permitted sync, and transfer query/cancel). Uncertain outcomes need registrar reconciliation. Each step has its own outcome: later failures do not roll back successful earlier steps. The browser polls for results, retries transient read failures, and preserves unsaved forms. Reseller domain pages also show a concise error summary for rejected requests or steps that never created a job, since these cannot appear on Jobs.

Apply migration **007_panel_domains**, rebuild Composer's authoritative classmap, and restart both services before using the new pages:

```bash
cd /opt/namingo-reseller-api
sudo env COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --classmap-authoritative
sudo -u namingo-api php8.5 bin/manage.php migrate
sudo systemctl restart namingo-reseller-api php8.5-fpm
```

Use the normal upgrade script for installer-created installations; it handles dependency generation, schema permissions, migration and service restart. Manual migrations need the documented temporary schema privileges. Migration `database/007_panel_domains.sql` is also supplied separately and is safe to reapply.

Regression checks (use a disposable database with the documented `TEST_MYSQL_*` settings):
```bash
php8.5 tests/domain_params.php
php8.5 tests/domains.php
node tests/domains.js
php8.5 tests/panel_router.php
```

These fixtures simulate registry replies; live registry behavior must be checked in the registrar's test environments.

## Transfers

`/transfers` lists the account's incoming and outgoing transfers, including requests made through the API. `/admin/transfers` lists transfers for all resellers and lets an administrator choose the gaining reseller for a bulk request. Both pages use the domain/contact table layout and pagination.

Use **Request transfer** beside the header. Enter up to 50 ASCII/punycode domains, one per line, as `domain,authorization-code`. Only the first comma separates the fields, so a code can contain commas. A code can be omitted for profiles that support code-free transfers (currently LT); other profiles return a per-domain validation error. The years and optional native registry parameters apply to the batch. TLD permissions select the registry, and the existing API ledger validates ownership, holds funds, processes the job and settles its configured price. Codes and native request data stay encrypted in the existing saved plans/jobs and are not shown in the transfer list.

Pending incoming transfers offer **Refresh** and **Cancel**. Pending outgoing transfers offer **Refresh**, **Approve** and **Reject**. A confirmation modal accepts any additional parameters required by the registry. Decisions are checked against the transfer's direction, account and latest operation; queued, uncertain and terminal decisions do not offer another write. LT exposes requests only. Results retain links to the underlying jobs, and bulk failures are shown separately for each domain.

The reseller Jobs page defaults to **Your requests**, with a **Source** filter for **Automatic transfer checks** or **All jobs**. The dashboard also excludes these scheduled checks. Admin history retains every job with a Source label, and individual automatic job details explain that they are read-only queries. Registry errors stay recorded as rejected rather than being converted to successful transfers. A negative transfer query can mean there is no transfer to query, but authentication, connectivity or registry-policy errors must still be investigated in the job response. No EPP code means this list alone cannot establish the registry's reason. Existing history is retained and can be retrieved through the filter or API.

The API service discovers external outgoing requests by scheduling read-only `domain.transfer` queries for idle, active managed domains, up to eight every five seconds. Each domain is checked at most once per `API_TRANSFER_CHECK_SECONDS` (default 900; minimum 60). Checks require active account/registry/TLD access and a profile that can query transfers. Profiles needing extra query parameters must be queried through their native API or panel form. This does not consume the registry's shared poll queue. Registry `acID`, `reID`, domain and request date identify outgoing transfers; discovery cannot list domains outside the local ownership inventory or requests the registry does not expose. Confirmed transfers out release local ownership. New request dates create separate history entries.

Run the normal `php bin/manage.php migrate` upgrade before restarting the services. The `domain_transfers` index stores identifiers and statuses, without authorization codes. Migration imports existing transfer-job history without sending EPP commands or changing ownership. Run `php tests/transfers.php` with the same disposable MySQL test setup as the domain workflow tests.

## Invoices and company billing

**Billing → Invoices** lists the signed-in account's invoices, with a detailed view, inline PDF and PDF download. **Billing → Administration → Invoices** shows every reseller's invoices, email delivery state and confirmed retry action for unsent/uncertain emails. Invoice PDFs and views have the same account boundary; a UUID alone never grants access. Reseller workspace routes remain own-account-only even for an administrator.

Use **Account → Edit billing details** for registered company details and the invoice recipient. Administrators add the VAT policy on the reseller edit page. Legal identity, country or VAT ID changes by a reseller require renewed administrator tax review. Required billing details and an approved VAT policy must be complete before a monthly invoice can be generated. Issued invoices retain their original billing snapshot.

The monthly CLI, SMTP settings, private encrypted storage, migration and operational behavior are documented in [Monthly invoicing](install.md#monthly-invoicing). Install/upgrade scripts add the monthly cron entry; it stays inactive until `INVOICING_ENABLED=1` is configured. Default invoice periods are completed UTC calendar months, and amounts are the already-paid ledger service charges, inclusive of any configured VAT.
