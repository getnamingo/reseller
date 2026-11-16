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
The reseller Prices page uses a compact card per registry/suffix with a term comparison
table, currency labels, override indicators and a separate restore amount. Legacy DNSSEC
and period-0 transfer prices are excluded from both cards and API price listings.
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

The registry edit page preloads its name/profile, accepts a full replacement configuration
JSON without revealing encrypted credentials, and includes a state toggle plus a separate
reseller access card accepting any reseller UUID. Its paginated permission table contains
only that registry's records, with permit/revoke toggles.

The TLD page lists that registry's suffixes with colored enable/disable buttons, an Add
TLD modal and the existing guarded unused-TLD removal controls. The TLD permissions page
has a **Permit a TLD** modal, a scoped paginated table, colored enable/disable buttons and
permission-removal controls. Toggles retain the current page. Registry UUIDs come from
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
