#!/usr/bin/env bash
# Pure installer helpers; no packages, database, accounts or services are modified.
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
# shellcheck disable=SC1091
source "$root/deploy/install.sh"
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
checks=0
check() { "$@" || { printf 'FAIL: %s\n' "$*" >&2; exit 1; }; checks=$((checks + 1)); }
check test "$(os_codename ubuntu 26.04)" = resolute
check test "$(os_codename debian 13)" = trixie
for os in 'ubuntu 24.04' 'debian 12' 'fedora 43'; do
  read -r name version <<<"$os"
  if os_codename "$name" "$version" >/dev/null; then echo 'Unsupported OS accepted' >&2; exit 1; fi
  checks=$((checks + 1))
done
for host in panel.example.com api.example.com example.com xn--e1afmkfd.xn--p1ai a.b; do check valid_hostname "$host"; done
for host in '' localhost 127.0.0.1 '*.example.com' 'https://panel.example.com' 'example.com:443' 'example.com/path' \
  '.example.com' 'example.com.' 'a..example.com' '-a.example.com' 'a-.example.com' 'example.com{bad}' 'a b.example.com'; do
  if valid_hostname "$host"; then printf 'Invalid hostname accepted: %s\n' "$host" >&2; exit 1; fi
  checks=$((checks + 1))
done
long_label=$(printf '%064d' 0)
if valid_hostname "$long_label.example.com"; then echo 'Oversized DNS label accepted' >&2; exit 1; fi
checks=$((checks + 1))
render_env 'base64:TEST_KEY' 'test-db-password' panel.example.test >"$work/app.env"
check grep -Fxq 'APP_MASTER_KEY=base64:TEST_KEY' "$work/app.env"
check grep -Fxq 'DB_PASSWORD=test-db-password' "$work/app.env"
check grep -Fxq 'PANEL_ORIGIN=https://panel.example.test' "$work/app.env"
check grep -Fxq 'APP_ENV=production' "$work/app.env"
check grep -Fxq 'API_HOST=127.0.0.1' "$work/app.env"
check grep -Fxq 'PANEL_API_URL=http://127.0.0.1:9501' "$work/app.env"
check grep -Fxq 'PANEL_SESSION_DIR=/var/lib/namingo-api/panel-sessions' "$work/app.env"
render_fpm >"$work/pool.conf"
check grep -Fxq 'user = namingo-panel' "$work/pool.conf"
check grep -Fxq 'listen.owner = caddy' "$work/pool.conf"
check grep -Fxq 'listen.mode = 0660' "$work/pool.conf"
render_caddy api.example.test panel.example.test >"$work/Caddyfile"
check grep -Fq 'api.example.test {' "$work/Caddyfile"
check grep -Fq 'panel.example.test {' "$work/Caddyfile"
check grep -Fq 'reverse_proxy 127.0.0.1:9501' "$work/Caddyfile"
check grep -Fq 'root * /opt/namingo-reseller-api/panel/public' "$work/Caddyfile"
check grep -Fq 'php_fastcgi unix//run/php/namingo-reseller.sock' "$work/Caddyfile"
check grep -Fq 'env HTTPS on' "$work/Caddyfile"
check bash -n "$root/deploy/install.sh"
if [[ -n ${CADDY_BIN:-} ]]; then
  "$CADDY_BIN" fmt --overwrite "$work/Caddyfile"
  check "$CADDY_BIN" validate --config "$work/Caddyfile" --adapter caddyfile
else
  echo 'SKIP: native Caddy validation (set CADDY_BIN to a Caddy binary)'
fi
if [[ -n ${FPM_BIN:-} ]]; then
  # Validate directives with the local test user; deployment uses dedicated system users.
  test_user=$(id -un); test_group=$(id -gn)
  if [[ $EUID -eq 0 ]]; then test_user=nobody; test_group=$(id -gn nobody); fi
  sed -e "s/^user = .*/user = $test_user/" -e "s/^group = .*/group = $test_group/" \
    -e "s/^listen.owner = .*/listen.owner = $test_user/" -e "s/^listen.group = .*/listen.group = $test_group/" \
    "$work/pool.conf" >"$work/local-pool.conf"
  printf '[global]\nerror_log = %s/fpm.log\ninclude = %s/local-pool.conf\n' "$work" "$work" >"$work/fpm.conf"
  check "$FPM_BIN" -n -y "$work/fpm.conf" -t
else
  echo 'SKIP: native PHP-FPM validation (set FPM_BIN to a PHP-FPM binary)'
fi
printf 'PASS: %s installer assertions\n' "$checks"
