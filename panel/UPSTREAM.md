# Foundry integration

The panel uses the Slim 4 / Twig / Tabler foundation and layout conventions from
[Argora Foundry](https://github.com/terborahq/foundry), revision
`3f71f78d500558cff51fdca0b52931250e654949`. The bootstrap, page shell, login card
and CSS partial were adapted for Namingo Reseller; `partials/js.twig` is retained.
The upstream MIT notice is in `LICENSE-FOUNDRY`.

Foundry's separate users, financials, payment integrations, registration, password
reset, CRUD/Spark API, impersonation and provisioning modules are not installed.
All panel account, role, pricing, access and financial data uses Namingo's existing
database and ledger. Availability runs through the existing API, not a second EPP
client. Tabler assets are supplied by Foundry's `argora/tabler-assets` dependency.
