#!/usr/bin/env bash
# Fresh installation only. Run from a checked-out release: sudo bash deploy/install.sh
# PHP snippets intentionally use single quotes so PHP, not the shell, expands variables.
# shellcheck disable=SC2016
set -Eeuo pipefail

SOURCE_ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
APP_DIR=/opt/namingo-reseller-api

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

os_codename() {
  case "$1:$2" in
    ubuntu:26.04) echo resolute ;;
    debian:13) echo trixie ;;
    *) return 1 ;;
  esac
}

valid_hostname() {
  local host=$1 label
  [[ ${#host} -le 253 && $host == *.* && $host != *..* && $host != *[!a-z0-9.-]* && $host != *[.] ]] || return 1
  [[ ${host##*.} == *[a-z]* ]] || return 1
  local -a labels
  IFS=. read -r -a labels <<< "$host"
  for label in "${labels[@]}"; do
    [[ ${#label} -ge 1 && ${#label} -le 63 && $label != -* && $label != *- ]] || return 1
  done
}

ask_hostname() {
  local prompt=$1 default=${2:-} value
  while true; do
    printf '%s%s: ' "$prompt" "${default:+ [$default]}" >&3
    IFS= read -r value <&3 || fail 'Could not read hostname.'
    value=${value:-$default}; value=${value,,}
    if valid_hostname "$value"; then printf '%s' "$value"; return; fi
    printf 'Enter a DNS hostname, such as panel.example.com, without a scheme, port or path.\n' >&3
  done
}

# Never let caller DB_* variables redirect setup away from the generated local .env.
manage() {
  runuser -u namingo-api -- env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin \
    /usr/bin/php8.5 "$APP_DIR/bin/manage.php" "$@"
}

db_root() {
  mariadb --no-defaults --protocol=socket --socket=/run/mysqld/mysqld.sock --user=root "$@"
}

render_env() {
  sed -e "s|^APP_MASTER_KEY=.*|APP_MASTER_KEY=$1|" \
      -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$2|" \
      -e 's|^API_TRUSTED_PROXIES=.*|API_TRUSTED_PROXIES=127.0.0.1|' \
      -e "s|^PANEL_ORIGIN=.*|PANEL_ORIGIN=https://$3|" "$SOURCE_ROOT/.env.example"
}

render_fpm() {
  cat <<'POOL'
[namingo-reseller]
user = namingo-panel
group = namingo-api
listen = /run/php/namingo-reseller.sock
listen.owner = caddy
listen.group = caddy
listen.mode = 0660
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 10s
pm.max_requests = 500
clear_env = yes
security.limit_extensions = .php
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
POOL
}

render_caddy() {
  cat <<CADDY
$1 {
    request_body {
        max_size 256KB
    }
    reverse_proxy 127.0.0.1:9501 {
        header_up X-Forwarded-For {remote_host}
    }
    header {
        -Server
        Strict-Transport-Security "max-age=31536000"
        X-Content-Type-Options nosniff
    }
}

$2 {
    root * $APP_DIR/panel/public
    request_body {
        max_size 256KB
    }
    php_fastcgi unix//run/php/namingo-reseller.sock {
        env HTTPS on
    }
    encode zstd gzip
    file_server
    header {
        -Server
        Strict-Transport-Security "max-age=31536000"
        X-Content-Type-Options nosniff
        X-Frame-Options DENY
        Referrer-Policy same-origin
    }
}
CADDY
}

main() {
  [[ ${EUID} -eq 0 ]] || fail 'Run this installer as root.'
  [[ -d /run/systemd/system ]] || fail 'A booted systemd host is required.'
  # shellcheck disable=SC1091
  . /etc/os-release
  local codename
  codename=$(os_codename "$ID" "$VERSION_ID") || fail 'Supported systems: Ubuntu 26.04 and Debian 13.'
  [[ -f $SOURCE_ROOT/composer.lock && -f $SOURCE_ROOT/bin/manage.php ]] || fail 'Run this script from a complete reseller checkout.'
  [[ ! -e $APP_DIR/.env && ! -L $APP_DIR/.env && ! -e /etc/systemd/system/namingo-reseller-api.service \
      && ! -e /etc/php/8.5/fpm/pool.d/namingo-reseller.conf && ! -e /etc/caddy/sites/namingo-reseller.caddy ]] \
    || fail 'An installation already exists. Use deploy/upgrade.sh from a separate updated checkout; this installer does not replace it.'
  if grep -RqsE 'ppa\.launchpad(content)?\.net/ondrej/php|ppa:ondrej/php' /etc/apt/sources.list /etc/apt/sources.list.d 2>/dev/null; then
    fail 'Remove the old ondrej/php PPA before installing from packages.sury.org/php.'
  fi
  for service in nginx apache2; do
    if systemctl is-active --quiet "$service"; then fail "Stop $service before installing Caddy on ports 80/443."; fi
  done
  exec 3<>/dev/tty || fail 'An interactive terminal is required for hostname confirmation and administrator password entry.'
  local panel_host api_host admin_name currency answer
  panel_host=$(ask_hostname 'Panel hostname')
  while true; do
    api_host=$(ask_hostname 'API hostname' "api.${panel_host#panel.}")
    [[ $api_host != "$panel_host" ]] && break
    printf 'Use different API and panel hostnames.\n' >&3
  done
  printf 'Administrator username [administrator]: ' >&3
  IFS= read -r admin_name <&3; admin_name=${admin_name:-administrator}
  [[ $admin_name =~ ^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$ ]] || fail 'Username must contain 3-64 safe ASCII characters.'
  printf 'Wallet currency [EUR]: ' >&3
  IFS= read -r currency <&3; currency=${currency:-EUR}; currency=${currency^^}
  [[ $currency =~ ^[A-Z]{3}$ ]] || fail 'Currency must be a three-letter code.'
  printf '\nInstall on %s %s:\n  Panel: https://%s\n  API:   https://%s\n  Code:  %s\n  Database: namingo_api (local MariaDB)\n  Administrator: %s (%s)\n\nBoth DNS A/AAAA records must point here, and inbound TCP 80/443 must be reachable.\nCaddy will obtain public HTTPS certificates. Existing Caddy sites are preserved.\nProceed with these hostnames and install packages/services? [y/N]: ' \
    "$ID" "$VERSION_ID" "$panel_host" "$api_host" "$APP_DIR" "$admin_name" "$currency" >&3
  IFS= read -r answer <&3
  [[ $answer == [yY] || $answer == [yY][eE][sS] ]] || fail 'Installation cancelled; no changes made.'

  umask 077
  local work
  work=$(mktemp -d)
  # Expand the temporary path now; the EXIT trap runs after main's locals expire.
  # shellcheck disable=SC2064
  trap "$(printf 'rm -rf -- %q' "$work")" EXIT
  trap 'printf "Installation failed near line %s. Review the error above; existing secrets were not replaced.\\n" "$LINENO" >&2' ERR
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  apt-get install -y ca-certificates curl gnupg unzip git rsync acl openssl debian-keyring debian-archive-keyring apt-transport-https
  curl -fsSL --retry 3 https://packages.sury.org/debsuryorg-archive-keyring.deb -o "$work/sury-keyring.deb"
  dpkg -i "$work/sury-keyring.deb"
  printf 'deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ %s main\n' "$codename" >/etc/apt/sources.list.d/namingo-php.list
  chmod 0644 /etc/apt/sources.list.d/namingo-php.list
  curl -fsSL --retry 3 https://dl.cloudsmith.io/public/caddy/stable/gpg.key -o "$work/caddy.key"
  gpg --batch --yes --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg "$work/caddy.key"
  curl -fsSL --retry 3 https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt -o /etc/apt/sources.list.d/caddy-stable.list
  chmod 0644 /usr/share/keyrings/caddy-stable-archive-keyring.gpg /etc/apt/sources.list.d/caddy-stable.list
  apt-get update
  apt-get install -y composer mariadb-client mariadb-server caddy \
    php8.5-cli php8.5-common php8.5-fpm php8.5-curl php8.5-mbstring php8.5-mysql \
    php8.5-swoole php8.5-xml php8.5-zip
  php8.5 -r 'foreach (["curl","openssl","pdo_mysql","mbstring","SimpleXML","sodium","swoole","xmlwriter"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR,"Missing extension: $e\n"); exit(1); } }'
  systemctl enable --now mariadb
  [[ $(db_root --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='namingo_api'") == 0 ]] \
    || fail 'Database namingo_api already exists; refusing to replace its data or credentials.'
  [[ $(db_root --batch --skip-column-names -e "SELECT COUNT(*) FROM mysql.user WHERE User='namingo_api'") == 0 ]] \
    || fail 'Database user namingo_api already exists; refusing to change its password.'

  id namingo-api &>/dev/null || useradd --system --user-group --home-dir /var/lib/namingo-api --create-home --shell /usr/sbin/nologin namingo-api
  id namingo-panel &>/dev/null || useradd --system --no-create-home --home-dir /var/lib/namingo-api --gid namingo-api --shell /usr/sbin/nologin namingo-panel
  install -d -o root -g namingo-api -m 0750 "$APP_DIR"
  if [[ $(realpath "$SOURCE_ROOT") != $(realpath "$APP_DIR") ]]; then
    rsync -a --exclude='/.git' --exclude='/.env' --exclude='/vendor' "$SOURCE_ROOT/" "$APP_DIR/"
  fi
  (
    cd "$APP_DIR"
    COMPOSER_ALLOW_SUPERUSER=1 php8.5 /usr/bin/composer install --no-dev --classmap-authoritative --no-interaction
  )
  local master_key db_password admin_uuid
  master_key=$(php8.5 "$APP_DIR/bin/manage.php" key:generate)
  db_password=$(openssl rand -hex 32)
  render_env "$master_key" "$db_password" "$panel_host" >"$APP_DIR/.env"
  db_root <<SQL
CREATE DATABASE namingo_api CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'namingo_api'@'127.0.0.1' IDENTIFIED BY '$db_password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON namingo_api.* TO 'namingo_api'@'127.0.0.1';
SQL
  unset db_password master_key
  chown -R root:namingo-api "$APP_DIR"
  find "$APP_DIR" -type d -exec chmod 0750 {} +
  find "$APP_DIR" -type f -exec chmod 0640 {} +
  chmod 0750 "$APP_DIR"/bin/*.php "$APP_DIR"/deploy/*.sh
  install -d -o namingo-api -g namingo-api -m 0750 /var/lib/namingo-api
  install -d -o namingo-panel -g namingo-api -m 0700 /var/lib/namingo-api/panel-sessions
  manage migrate
  db_root <<'SQL'
REVOKE CREATE, ALTER, INDEX, REFERENCES ON namingo_api.* FROM 'namingo_api'@'127.0.0.1';
SQL
  printf '\nSet the first administrator password (shared API/panel login):\n' >&3
  admin_uuid=$(manage user:create "$admin_name" "$currency" <&3)
  manage user:role "$admin_uuid" 0

  # Caddy can traverse to and read only public files, never .env or application code.
  setfacl -m u:caddy:--x "$APP_DIR" "$APP_DIR/panel"
  setfacl -R -m u:caddy:rX "$APP_DIR/panel/public"
  render_fpm >/etc/php/8.5/fpm/pool.d/namingo-reseller.conf
  chmod 0644 /etc/php/8.5/fpm/pool.d/namingo-reseller.conf
  php-fpm8.5 -t
  install -m 0644 "$APP_DIR/deploy/namingo-reseller-api.service" /etc/systemd/system/namingo-reseller-api.service
  install -d -m 0755 /etc/caddy/sites
  render_caddy "$api_host" "$panel_host" >/etc/caddy/sites/namingo-reseller.caddy
  chmod 0644 /etc/caddy/sites/namingo-reseller.caddy
  cp -aL /etc/caddy/Caddyfile "/etc/caddy/Caddyfile.before-namingo-$(date +%Y%m%d%H%M%S)"
  printf '\nimport /etc/caddy/sites/namingo-reseller.caddy\n' >>/etc/caddy/Caddyfile
  caddy fmt --overwrite /etc/caddy/sites/namingo-reseller.caddy
  caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
  systemctl daemon-reload
  systemctl enable --now namingo-reseller-api
  systemctl enable php8.5-fpm caddy
  systemctl restart php8.5-fpm
  systemctl restart caddy
  for service in mariadb namingo-reseller-api php8.5-fpm caddy; do
    systemctl is-active --quiet "$service" || fail "$service is not running; check its journal."
  done
  curl -fsS --retry 20 --retry-delay 2 --retry-all-errors --max-time 5 http://127.0.0.1:9501/readyz -o "$work/ready.json"
  php8.5 -r '$r=json_decode(file_get_contents($argv[1]),true); exit(($r["data"]["status"]??null)==="ready"?0:1);' "$work/ready.json"
  printf '\nWaiting for Caddy HTTPS certificates and checking both hostnames...\n'
  curl -fsS --retry 30 --retry-delay 2 --retry-all-errors --max-time 10 \
    --resolve "$api_host:443:127.0.0.1" "https://$api_host/readyz" -o "$work/api.json" \
    || fail 'API HTTPS is not ready. Check DNS/firewalls and journalctl -u caddy; services and credentials were retained.'
  php8.5 -r '$r=json_decode(file_get_contents($argv[1]),true); exit(($r["data"]["status"]??null)==="ready"?0:1);' "$work/api.json"
  curl -fsS --retry 30 --retry-delay 2 --retry-all-errors --max-time 10 \
    --resolve "$panel_host:443:127.0.0.1" "https://$panel_host/login" -o /dev/null \
    || fail 'Panel HTTPS is not ready. Check journalctl -u caddy -u php8.5-fpm; services and credentials were retained.'
  printf '\nNamingo Reseller is running.\n  Panel: https://%s/login\n  API: https://%s\n  Administrator: %s\n  Administrator UUID: %s\n\nBack up %s/.env and MariaDB separately.\nAdd registry credentials, TLD permissions and prices in the panel before provisioning.\n' \
    "$panel_host" "$api_host" "$admin_name" "$admin_uuid" "$APP_DIR"
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then main "$@"; fi

