#!/usr/bin/env bash
# Real ZIP fixtures, fake child installers; no network, packages or root operations.
set -Eeuo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
if [[ ${1:-} == scenario ]]; then
  script=$2 scenario=$3 work=$4
  mkdir -p "$work/script directory" "$work/elsewhere" "$work/payload/reseller-main/deploy"
  cp "$root/deploy/$script.sh" "$work/script directory/$script.sh"
  cat >"$work/payload/reseller-main/deploy/$script.sh" <<'CHILD'
#!/usr/bin/env bash
set -eu
[[ $# == 1 && $1 == --from-checkout ]]
printf 'child\n' >"$LOCAL_TEST_WORK/child"
[[ $LOCAL_TEST_CASE != child-failure ]] || exit 23
CHILD
  [[ $scenario == incomplete ]] || touch "$work/payload/reseller-main/composer.lock"
  case $scenario in
    missing) : ;;
    invalid) printf 'not a zip' >"$work/script directory/reseller-main.zip" ;;
    *) (cd "$work/payload"; zip -qr "$work/script directory/reseller-main.zip" reseller-main) ;;
  esac
  source "$work/script directory/$script.sh"
  bootstrap_require_root() { :; }
  git() { touch "$work/git-called"; return 91; }
  apt-get() { touch "$work/packages-called"; return 92; }
  mktemp() {
    local dir
    dir=$(command mktemp "$@")
    printf '%s\n' "$dir" >"$work/extracted"
    printf '%s\n' "$dir"
  }
  export TMPDIR=$work LOCAL_TEST_WORK=$work LOCAL_TEST_CASE=$scenario
  cd "$work/elsewhere"
  bootstrap local
  exit
fi
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
checks=0
check() { "$@" || { printf 'FAIL: %s\n' "$*" >&2; exit 1; }; checks=$((checks+1)); }
for script in install upgrade; do
  for scenario in success child-failure missing invalid incomplete; do
    dir=$work/$script-$scenario
    mkdir "$dir"
    set +e
    bash "$0" scenario "$script" "$scenario" "$dir" >"$dir/output" 2>&1
    status=$?
    set -e
    case $scenario in
      success) check test "$status" = 0 ;;
      child-failure) check test "$status" = 23 ;;
      *) check test "$status" != 0 ;;
    esac
    check test ! -e "$dir/git-called"
    check test ! -e "$dir/packages-called"
    if [[ -f $dir/extracted ]]; then check test ! -e "$(cat "$dir/extracted")"; fi
    if [[ $scenario == success || $scenario == child-failure ]]; then
      check grep -Fxq child "$dir/child"
    else
      check test ! -e "$dir/child"
    fi
    if [[ $scenario != missing ]]; then check test -f "$dir/script directory/reseller-main.zip"; fi
  done
done
printf 'PASS: %s local-ZIP bootstrap assertions\n' "$checks"
