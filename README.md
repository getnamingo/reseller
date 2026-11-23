# Namingo Reseller

**Namingo Reseller** is a modern, self-hosted domain reseller platform that enables ICANN-accredited registrars and other domain registrars to launch and manage their own reseller channel.

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

Built for registrars looking to expand their distribution network, Namingo Reseller provides a complete API and web-based control panel for managing resellers, domain registrations, pricing, account balances, and transactions.

The platform supports multiple registry connections, flexible reseller-specific pricing, prepaid wallets, automated domain provisioning, and secure transaction processing. Registrars retain full control over their infrastructure, registry credentials, reseller relationships, and business operations.

Powered by PHP 8.5 and Swoole, Namingo Reseller is designed for performance, reliability, and straightforward self-hosted deployment.

## License

Namingo Reseller is source-available under the
[PolyForm Noncommercial License 1.0.0](LICENSE) (`PolyForm-Noncommercial-1.0.0`).

Noncommercial use, modification, and redistribution are permitted only as
provided by that license. **Commercial use requires separate prior written
authorization from Terbora Ltd.**, including use in a commercial registrar,
reseller, or hosting business. Permission may be granted free of charge or
under paid terms.

See [Commercial licensing](COMMERCIAL-LICENSE.md) for permission requests,
or contact **hello@terbora.com**. See [NOTICE](NOTICE) for copyright notices.
The independently usable PHP adapters and client code under [`plugins/`](plugins/)
are licensed separately under the [Apache License 2.0](LICENSE-APACHE-2.0).
**Apache 2.0 does not apply to the reseller core, API, panel, or root tests.**
Use of the plugins does not grant rights to use the core commercially.

Third-party dependencies remain under their respective licenses.

## Documentation

- [Installation and upgrades](docs/install.md) — server setup, registry configuration, pricing, migrations, backups and recovery.
- [API reference and operations](docs/api.md) — authentication, commands, examples, self-service endpoints, deposits, ownership and job lifecycle.
- [Reseller and administration panel](docs/panel.md) — panel setup, reseller accounts, registries/TLDs, prices, deposits and jobs.

## Installation

For a fresh **Ubuntu 26.04** or **Debian 13** server with Wget installed, point separate API and panel hostnames at the server and allow inbound TCP 80/443, then run:

```bash
sudo bash -c 'bash <(wget -qO- https://raw.githubusercontent.com/getnamingo/reseller/main/deploy/install.sh)'
```

The script clones current `main` into a temporary directory, runs the installer from that checkout, and removes it on completion or failure. Git and CA certificates are installed automatically if needed. The interactive installer configures PHP/Swoole/FPM, MariaDB, Caddy HTTPS, application secrets and the first administrator. It is for fresh installations only. See the [full installation guide](docs/install.md#ubuntu-2604-and-debian-13-installation) for requirements, manual setup and post-install configuration.

## Upgrade

For an installer-created deployment, run:

```bash
sudo bash -c 'bash <(wget -qO- https://raw.githubusercontent.com/getnamingo/reseller/main/deploy/upgrade.sh)'
```

The script clones current `main` into a temporary directory and removes that checkout after the upgrade, including on failure. Review the incoming code and schedule brief API/PHP-FPM downtime. The script preserves configuration, backs up code and database, and runs migrations. Read the [upgrade and recovery instructions](docs/install.md#upgrade-an-installer-created-demo) first; custom deployments should follow the [manual migration and ownership guidance](docs/install.md#object-ownership-and-upgrading-existing-installations).

To use a downloaded GitHub ZIP, put `reseller-main.zip` beside the saved `install.sh` or `upgrade.sh` (the ZIP must contain the `reseller-main/` directory), then run:

```bash
sudo bash /path/to/install.sh local
# Or, for an existing installation:
sudo bash /path/to/upgrade.sh local
```

Local mode extracts into a temporary directory, runs the ZIP's installer/upgrader, and removes the extracted files afterward. It keeps the original ZIP and skips Git cloning. Package and Composer downloads still run as usual.

## Integrations

- [Standalone PHP API client](plugins/php-client/README.md)
- [WHMCS registrar module](plugins/whmcs/README.md)
- [FOSSBilling registrar adapter](plugins/fossbilling/README.md)
