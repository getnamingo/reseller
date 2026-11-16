# Namingo Reseller

A PHP 8.5/Swoole reseller API and optional web control panel built on [Namingo EPP Client](https://github.com/getnamingo/epp-client). Manage shared registry connections with reseller/TLD permissions, a MariaDB wallet and ledger, global and reseller-specific pricing, and durable EPP jobs.

Registry credentials and job payloads are encrypted. Mutating commands use idempotency keys, wallet holds and explicit reconciliation for uncertain outcomes. Deposits are credited only after an administrator verifies receipt of funds.

## Documentation

- [Installation and upgrades](docs/install.md) — server setup, registry configuration, pricing, migrations, backups and recovery.
- [API reference and operations](docs/api.md) — authentication, commands, examples, self-service endpoints, deposits, ownership and job lifecycle.
- [Reseller and administration panel](docs/panel.md) — panel setup, reseller accounts, registries/TLDs, prices, deposits and jobs.

## Installation

For a fresh **Ubuntu 26.04** or **Debian 13** server with Git installed, point separate API and panel hostnames at the server and allow inbound TCP 80/443, then run:

```bash
git clone https://github.com/getnamingo/reseller.git
cd reseller
sudo bash deploy/install.sh
```

The interactive installer configures PHP/Swoole/FPM, MariaDB, Caddy HTTPS, application secrets and the first administrator. It is for fresh installations only. See the [full installation guide](docs/install.md#ubuntu-2604-and-debian-13-installation) for requirements, manual setup and post-install configuration.

## Upgrade

For an installer-created deployment, run from a separate checkout **outside** `/opt/namingo-reseller-api`:

```bash
cd /path/to/reseller
git pull --ff-only
sudo bash deploy/upgrade.sh
```

Review the incoming code and schedule brief API/PHP-FPM downtime. The script preserves configuration, backs up code and database, and runs migrations. Read the [upgrade and recovery instructions](docs/install.md#upgrade-an-installer-created-demo) first; custom deployments should follow the [manual migration and ownership guidance](docs/install.md#object-ownership-and-upgrading-existing-installations).

## Integrations

- [Standalone PHP API client](plugins/php-client/README.md)
- [WHMCS registrar module](plugins/whmcs/README.md)
- [FOSSBilling registrar adapter](plugins/fossbilling/README.md)
