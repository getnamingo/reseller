#!/usr/bin/env bash
# Exercise the upgrade workflow with mocked services/DB; never touches /opt or /etc.
set -Eeuo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)

if [[ ${1:-} == scenario ]]; then
  scenario=$2
  work=$3
  # shellcheck disable=SC1091
  source "$root/deploy/upgrade.sh"
  APP_DIR=$work/app
  STAGE=$work/stage
  BACKUP=$work/backup
  UNIT_FILE=$work/unit
  mkdir -p "$APP_DIR/bin" "$APP_DIR/deploy" "$APP_DIR/panel/public" "$STAGE/bin" "$STAGE/deploy" "$STAGE/panel/public" "$BACKUP"
  printf 'original-secret\n' >"$APP_DIR/.env"
  printf old >"$APP_DIR/old-code"
  printf 'old-unit\n' >"$UNIT_FILE"
  printf new >"$STAGE/new-code"
  printf php >"$STAGE/bin/manage.php"
  printf shell >"$STAGE/deploy/upgrade.sh"
  printf 'new-unit\n' >"$STAGE/deploy/namingo-reseller-api.service"
  systemctl() { printf 'service %s\n' "$*" >>"$work/log"; }
  db_root() { printf 'db %s\n' "$*" >>"$work/log"; }
  manage() {
    printf 'manage %s\n' "$*" >>"$work/log"
    [[ $scenario != migrate-failure ]]
  }
  mariadb-dump() {
    printf 'dump\n' >>"$work/log"
    [[ $scenario != dump-failure ]] || return 1
    printf 'database snapshot\n'
  }
  rsync() {
    # Assert the production call protects local secrets/metadata and deletes old code.
    [[ $1 == -a && $2 == --delete && $3 == --exclude=/.env && $4 == --exclude=/.git ]]
    printf 'copy\n' >>"$work/log"
    rm "$APP_DIR/old-code"
    cp -a "$STAGE/." "$APP_DIR/"
  }
  chown() { :; }
  setfacl() { :; }
  setup_invoicing() { printf 'invoicing setup\n' >>"$work/log"; }
  check_ready() {
    printf 'ready\n' >>"$work/log"
    [[ $scenario != ready-failure ]]
  }
  trap cleanup EXIT
  apply_upgrade
  exit
fi

work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
checks=0
check() { "$@" || { printf 'FAIL: %s\n' "$*" >&2; exit 1; }; checks=$((checks + 1)); }
check bash -n "$root/deploy/upgrade.sh"
check bash "$root/deploy/upgrade.sh" --help
for scenario in success migrate-failure ready-failure dump-failure; do
  dir=$work/$scenario
  mkdir "$dir"
  set +e
  bash "$0" scenario "$scenario" "$dir" >"$dir/output" 2>&1
  status=$?
  set -e
  if [[ $scenario == success ]]; then
    check test "$status" = 0
    check grep -q '^ready$' "$dir/log"
    check grep -q '^new-unit$' "$dir/unit"
  else
    check test "$status" != 0
    check grep -q 'API and PHP-FPM left stopped' "$dir/output"
    check test "$(tail -n 1 "$dir/log")" = 'service stop namingo-reseller-api php8.5-fpm'
  fi
  check test "$(cat "$dir/app/.env")" = original-secret
  check test ! -e "$dir/stage"
  tar -xOf "$dir/backup/application.tar.gz" ./.env >"$dir/saved-env"
  check cmp "$dir/saved-env" "$dir/app/.env"
  check grep -q '^old-unit$' "$dir/backup/namingo-reseller-api.service"
  if [[ $scenario == dump-failure ]]; then
    check test -f "$dir/app/old-code"
    check test ! -e "$dir/app/new-code"
    if grep -q '^copy\|^db .*GRANT' "$dir/log"; then echo 'Deployment ran after failed backup' >&2; exit 1; fi
  else
    check test -f "$dir/app/new-code"
    check test ! -e "$dir/app/old-code"
    check test "$(gzip -dc "$dir/backup/database.sql.gz")" = 'database snapshot'
    check grep -q '^db .*REVOKE CREATE' "$dir/log"
    check grep -q '^invoicing setup$' "$dir/log"
    # Privileges must be revoked before any restart.
    if [[ $scenario == migrate-failure ]]; then
      if grep -q '^service start' "$dir/log"; then echo 'Services restarted after failed migration' >&2; exit 1; fi
    else
      revoke=$(sed -n '/^db .*REVOKE/=' "$dir/log")
      start=$(sed -n '/^service start/=' "$dir/log")
      check test "$revoke" -lt "$start"
    fi
  fi
done
printf 'PASS: %s upgrade assertions\n' "$checks"
