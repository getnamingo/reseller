#!/usr/bin/env bash
# Upgrade the local layout created by deploy/install.sh using a temporary current-main checkout.
# shellcheck disable=SC2016
set -Eeuo pipefail

SOURCE_ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
APP_DIR=/opt/namingo-reseller-api
BACKUP_ROOT=/var/backups/namingo-reseller
UNIT_FILE=/etc/systemd/system/namingo-reseller-api.service
STAGE=
BACKUP=
MIGRATION_GRANTED=0
SERVICES_STOPPED=0

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

db_root() {
  mariadb --no-defaults --protocol=socket --socket=/run/mysqld/mysqld.sock --user=root "$@"
}

manage() {
  runuser -u namingo-api -- env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin \
    /usr/bin/php8.5 "$APP_DIR/bin/manage.php" "$@"
}

setup_invoicing() {
  install -d -o namingo-api -g namingo-api -m 0750 /var/lib/namingo-api/data /var/lib/namingo-api/data/invoices
  touch /var/log/namingo-invoicing.log
  chown namingo-api:namingo-api /var/log/namingo-invoicing.log
  chmod 0640 /var/log/namingo-invoicing.log
  install -o root -g root -m 0644 "$APP_DIR/deploy/namingo-reseller-invoicing.cron" /etc/cron.d/namingo-reseller-invoicing
  install -o root -g root -m 0644 "$APP_DIR/deploy/namingo-reseller-invoicing.logrotate" /etc/logrotate.d/namingo-reseller-invoicing
  systemctl enable --now cron
}

revoke_migration() {
  db_root -e "REVOKE CREATE, ALTER, INDEX, REFERENCES ON namingo_api.* FROM 'namingo_api'@'127.0.0.1';"
  MIGRATION_GRANTED=0
}

cleanup() {
  local status=$?
  trap - EXIT
  if (( MIGRATION_GRANTED )); then
    revoke_migration || { printf 'Could not revoke migration privileges; revoke them manually.\n' >&2; status=1; }
  fi
  if (( status != 0 )); then
    if (( SERVICES_STOPPED )); then
      # Do not run potentially mixed code/schema after a failed deployment or health check.
      systemctl stop namingo-reseller-api php8.5-fpm || true
      printf 'Upgrade failed; API and PHP-FPM left stopped. Inspect the error before restarting.\n' >&2
    fi
    printf 'Backup directory (if created): %s\n' "${BACKUP:-not created}" >&2
  fi
  [[ -z $STAGE ]] || rm -rf -- "$STAGE"
  exit "$status"
}

apply_upgrade() {
  # Stop submissions as well as workers before taking a consistent application backup.
  SERVICES_STOPPED=1
  systemctl stop php8.5-fpm namingo-reseller-api
  tar --acls --xattrs -czf "$BACKUP/application.tar.gz" -C "$APP_DIR" .
  if [[ -d /var/lib/namingo-api/data ]]; then
    tar --acls --xattrs -czf "$BACKUP/data.tar.gz" -C /var/lib/namingo-api data
  fi
  cp -a "$UNIT_FILE" "$BACKUP/namingo-reseller-api.service"
  mariadb-dump --no-defaults --protocol=socket --socket=/run/mysqld/mysqld.sock --user=root \
    --single-transaction --routines --events --triggers --databases namingo_api | gzip >"$BACKUP/database.sql.gz"
  printf 'Backup complete: %s\n' "$BACKUP"

  # .env and any installed Git metadata belong to the existing installation.
  rsync -a --delete --exclude='/.env' --exclude='/.git' "$STAGE/" "$APP_DIR/"
  chown -R root:namingo-api "$APP_DIR"
  find "$APP_DIR" -type d -exec chmod 0750 {} +
  find "$APP_DIR" -type f -exec chmod 0640 {} +
  chmod 0750 "$APP_DIR"/bin/*.php "$APP_DIR"/deploy/*.sh
  setfacl -m u:caddy:--x "$APP_DIR" "$APP_DIR/panel"
  setfacl -R -m u:caddy:rX "$APP_DIR/panel/public"

  # install.sh deliberately removes these privileges after installation.
  db_root -e "GRANT CREATE, ALTER, INDEX, REFERENCES ON namingo_api.* TO 'namingo_api'@'127.0.0.1';"
  MIGRATION_GRANTED=1
  setup_invoicing
  manage migrate
  revoke_migration
  install -m 0644 "$APP_DIR/deploy/namingo-reseller-api.service" "$UNIT_FILE"
  systemctl daemon-reload
  systemctl start namingo-reseller-api php8.5-fpm
  systemctl is-active --quiet namingo-reseller-api
  systemctl is-active --quiet php8.5-fpm
  check_ready
  SERVICES_STOPPED=0
}

check_ready() {
  curl -fsS --retry 15 --retry-delay 2 --retry-all-errors --max-time 5 \
    http://127.0.0.1:9501/readyz -o "$STAGE/ready.json"
  /usr/bin/php8.5 -r '$r=json_decode(file_get_contents($argv[1]),true); exit(($r["data"]["status"]??null)==="ready"?0:1);' "$STAGE/ready.json"
}

main() {
  if [[ ${1:-} == --help || ${1:-} == -h ]]; then
    printf 'Usage: sudo bash deploy/upgrade.sh\nUpgrades the standard local installer layout from the downloaded checkout.\n'
    return
  fi
  [[ $# == 0 ]] || fail 'Unknown argument. Use --help.'
  [[ $EUID -eq 0 ]] || fail 'Run this upgrade as root.'
  [[ -d /run/systemd/system ]] || fail 'A booted systemd host is required.'
  [[ -f $APP_DIR/.env && -f $APP_DIR/vendor/autoload.php && -f $UNIT_FILE \
     && -f /etc/php/8.5/fpm/pool.d/namingo-reseller.conf ]] || fail 'Expected the existing deploy/install.sh layout.'
  local target_root
  target_root=$(realpath "$APP_DIR")
  [[ $SOURCE_ROOT != "$target_root" && $SOURCE_ROOT != "$target_root/"* ]] || fail 'Run from a separate checkout, outside the installed application.'
  [[ -f $SOURCE_ROOT/composer.lock && -f $SOURCE_ROOT/bin/manage.php \
     && -f $SOURCE_ROOT/deploy/namingo-reseller-api.service ]] || fail 'Incomplete source checkout.'
  local cmd
  for cmd in rsync tar gzip mariadb mariadb-dump runuser setfacl flock curl /usr/bin/php8.5 /usr/bin/composer; do
    command -v "$cmd" >/dev/null || fail "Required command is missing: $cmd"
  done
  exec 9>/run/lock/namingo-reseller-upgrade.lock
  flock -n 9 || fail 'Another upgrade is already running.'
  systemctl is-active --quiet namingo-reseller-api || fail 'API must be running before upgrading; resolve its current failure first.'
  systemctl is-active --quiet php8.5-fpm || fail 'PHP-FPM must be running before upgrading.'
  # Refuse custom/remote databases: the root-socket backup and grants below are local.
  runuser -u namingo-api -- env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin /usr/bin/php8.5 -r '
    require $argv[1]."/vendor/autoload.php";
    \Namingo\ResellerApi\Config::load($argv[1]);
    foreach (["DB_HOST"=>"127.0.0.1", "DB_PORT"=>"3306", "DB_NAME"=>"namingo_api", "DB_USER"=>"namingo_api", "DB_SOCKET"=>"", "API_HOST"=>"127.0.0.1", "API_PORT"=>"9501"] as $key=>$expected) {
      if (\Namingo\ResellerApi\Config::get($key, $expected) !== $expected) { fwrite(STDERR,"Custom $key: use the manual upgrade steps.\n"); exit(1); }
    }
    \Namingo\ResellerApi\Db::pdo()->query("SELECT 1 FROM schema_migrations LIMIT 1");
  ' "$APP_DIR"
  db_root -e 'SELECT 1 FROM namingo_api.schema_migrations LIMIT 1' >/dev/null

  apt-get update
  apt-get install -y cron fonts-dejavu-core logrotate
  umask 077
  trap cleanup EXIT
  STAGE=$(mktemp -d /opt/namingo-reseller-upgrade.XXXXXX)
  rsync -a --exclude='/.env' --exclude='/.git' --exclude='/vendor' --exclude='/var' "$SOURCE_ROOT/" "$STAGE/"
  # Install locked dependencies and panel assets before interrupting the running demo.
  (cd "$STAGE"; COMPOSER_ALLOW_SUPERUSER=1 /usr/bin/php8.5 /usr/bin/composer install --no-dev --classmap-authoritative --no-interaction)
  install -d -m 0700 "$BACKUP_ROOT"
  BACKUP=$(mktemp -d "$BACKUP_ROOT/$(date -u +%Y%m%dT%H%M%SZ).XXXXXX")
  apply_upgrade
  printf '\nUpgrade complete. Secrets, accounts and registry configuration preserved.\nBackup: %s\nCheck the existing panel URL and an availability check.\n' "$BACKUP"
}

# Self-contained entry point: process substitution has no adjacent repository files.
# Keep this parent alive so its EXIT trap removes temporary source after the child finishes.
bootstrap_require_root() { [[ $EUID -eq 0 ]] || fail 'Run this upgrade as root.'; }

bootstrap() (
  if [[ ${1:-} == --help || ${1:-} == -h ]]; then
    printf 'Usage: sudo bash deploy/upgrade.sh [local]\nDownloads current main, or uses reseller-main.zip beside this script with local. Temporary source is removed afterward.\n'
    exit 0
  fi
  [[ $# == 0 || ( $# == 1 && ${1:-} == local ) ]] || fail 'Unknown argument. Use --help.'
  bootstrap_require_root
  if [[ ${1:-} == local ]]; then
    [[ -f ${BASH_SOURCE[0]} ]] || fail 'Local mode requires a saved script file beside reseller-main.zip.'
    archive_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
    archive=$archive_dir/reseller-main.zip
    [[ -f $archive ]] || fail "Local archive not found: $archive"
    if ! command -v unzip >/dev/null; then
      command -v apt-get >/dev/null || fail 'Install Unzip before using local mode.'
      apt-get update
      DEBIAN_FRONTEND=noninteractive apt-get install -y unzip
    fi
  elif ! command -v git >/dev/null || [[ ! -s /etc/ssl/certs/ca-certificates.crt ]]; then
    command -v apt-get >/dev/null || fail 'Install Git and CA certificates before running this script.'
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y git ca-certificates
  fi
  umask 077
  bootstrap_dir=$(mktemp -d "${TMPDIR:-/tmp}/namingo-reseller-upgrade.XXXXXX")
  trap 'rm -rf -- "$bootstrap_dir"' EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM
  if [[ ${1:-} == local ]]; then
    unzip -q "$archive" -d "$bootstrap_dir"
    checkout=$bootstrap_dir/reseller-main
  else
    git clone --depth 1 --single-branch --branch main https://github.com/getnamingo/reseller.git "$bootstrap_dir/source"
    checkout=$bootstrap_dir/source
  fi
  [[ -f $checkout/deploy/upgrade.sh && -f $checkout/composer.lock ]] \
    || fail 'Source is incomplete; local ZIPs must contain reseller-main/deploy/upgrade.sh and reseller-main/composer.lock.'
  bash "$checkout/deploy/upgrade.sh" --from-checkout
)

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  if [[ ${1:-} == --from-checkout ]]; then
    shift
    main "$@"
  else
    bootstrap "$@"
  fi
fi
