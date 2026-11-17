#!/usr/bin/env bash
# Exercise downloaded-script bootstrap behavior with a fake Git clone; no network/root/services.
set -Eeuo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)

if [[ ${1:-} == scenario ]]; then
  script=$2 scenario=$3 work=$4
  source "$root/deploy/$script.sh"
  export TMPDIR=$work
  bootstrap_require_root() { :; }
  apt-get() { printf 'apt %s\n' "$*" >>"$work/packages"; }
  git() {
    [[ $1 == clone && $2 == --depth && $3 == 1 && $4 == --single-branch && $5 == --branch && $6 == main && $7 == https://github.com/getnamingo/reseller.git ]]
    local dest=$8
    printf '%s\n' "$(dirname -- "$dest")" >"$work/checkout"
    mkdir -p "$dest/deploy"
    if [[ $scenario == clone-failure ]]; then return 17; fi
    touch "$dest/composer.lock"
    if [[ $scenario == incomplete ]]; then return; fi
    cat >"$dest/deploy/$script.sh" <<'CHILD'
#!/usr/bin/env bash
set -eu
[[ $# == 1 && $1 == --from-checkout ]]
printf 'child\n' >>"$BOOTSTRAP_TEST_WORK/log"
case "$BOOTSTRAP_TEST_SCENARIO" in
  child-failure) exit 23 ;;
  signal) kill -TERM "$PPID" ;;
esac
CHILD
  }
  export BOOTSTRAP_TEST_WORK=$work BOOTSTRAP_TEST_SCENARIO=$scenario
  bootstrap
  exit
fi

work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
checks=0
check() { "$@" || { printf 'FAIL: %s\n' "$*" >&2; exit 1; }; checks=$((checks + 1)); }
for script in install upgrade; do
  check bash -n "$root/deploy/$script.sh"
  check bash "$root/deploy/$script.sh" --help
  for scenario in success child-failure clone-failure incomplete signal; do
    dir=$work/$script-$scenario
    mkdir "$dir"
    set +e
    bash "$0" scenario "$script" "$scenario" "$dir" >"$dir/output" 2>&1
    status=$?
    set -e
    case $scenario in
      success) check test "$status" = 0 ;;
      child-failure) check test "$status" = 23 ;;
      clone-failure) check test "$status" = 17 ;;
      incomplete) check test "$status" != 0 ;;
      signal) check test "$status" = 143 ;;
    esac
    check test -f "$dir/checkout"
    check test ! -e "$(cat "$dir/checkout")"
    if [[ $scenario == clone-failure || $scenario == incomplete ]]; then
      check test ! -e "$dir/log"
    else
      check grep -Fxq child "$dir/log"
    fi
  done
  # Execute a source stream through the same /dev/fd process substitution shape as Wget.
  check bash -c 'bash <(cat "$1") --help' _ "$root/deploy/$script.sh"
done
printf 'PASS: %s bootstrap assertions\n' "$checks"
