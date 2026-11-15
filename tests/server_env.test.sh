#!/usr/bin/env bash
# Checks of the deploy scripts that need no server: syntax, the server.env loaders, and that no script
# carries a server address or a fixed instance port again.   bash tests/server_env.test.sh
set -euo pipefail
cd "$(dirname "$0")/.."
fail() { echo "FAIL: $*"; exit 1; }

for f in run_all.sh deploy/scripts/*.sh deploy/web/entrypoint.sh tools/*.sh modules/*/build.sh modules/*/tools/*.sh modules/*/tests/*.sh; do
  bash -n "$f" || fail "syntax $f"
done
for f in tools/*.mjs modules/SamenAcademy/tools/*.mjs; do node --check "$f" || fail "syntax $f"; done

T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
mkdir -p "$T/tools"; cp tools/server_env.sh tools/server_env.mjs "$T/tools/"
printf '# comment\nSSH_TARGET=root@203.0.113.10\nSSH_PORT=2222\nWEB_PORT=9071\n' > "$T/server.env"

# shell loader: file values, defaults derived from SSH_TARGET, environment wins
out=$(env -u SSH_TARGET -u SSH_PORT -u SSH_ALIAS -u SERVER_HOST bash -c ". '$T/tools/server_env.sh'; echo \"\$SSH_TARGET \$SSH_PORT \$SSH_ALIAS \$SERVER_HOST\"")
[ "$out" = "root@203.0.113.10 2222 root@203.0.113.10 203.0.113.10" ] || fail "server_env.sh: $out"
out=$(env -u SSH_ALIAS -u SERVER_HOST SSH_TARGET=me@198.51.100.7 bash -c ". '$T/tools/server_env.sh'; echo \"\$SSH_TARGET \$SERVER_HOST\"")
[ "$out" = "me@198.51.100.7 198.51.100.7" ] || fail "server_env.sh env override: $out"

# node loader
js="import('$T/tools/server_env.mjs').then(m => console.log(m.baseUrl()))"
out=$(env -u BASE -u SERVER_HOST -u SSH_TARGET -u WEB_PORT node -e "$js")
[ "$out" = "http://203.0.113.10:9071/" ] || fail "server_env.mjs: $out"
out=$(BASE=http://example.test:1/ node -e "$js")
[ "$out" = "http://example.test:1/" ] || fail "server_env.mjs BASE: $out"
rm "$T/server.env"
env -u BASE -u SERVER_HOST -u SSH_TARGET node -e "$js" >/dev/null 2>&1 && fail "server_env.mjs must stop without an address"

# run_all.sh refuses server commands without an address, but build/help need none
if [ ! -f server.env ]; then
  env -u SSH_TARGET ./run_all.sh persian >/dev/null 2>&1 && fail "run_all.sh ran without SSH_TARGET"
fi
./run_all.sh help | grep -q 'run_all.sh build' || fail "run_all.sh help"

# nothing server-specific in tracked files; the importer follows the instance's port
if git grep -nE '([0-9]{1,3}\.){3}[0-9]{1,3}' -- . ':!tests' ':!server.env.example' ':!modules/*/layouts' ':!modules/SamenTheme/tools/source_*' ':!i18n/source' \
   | grep -vE '127\.0\.0\.1|0\.0\.0\.0|[0-9]\.[0-9]+\.[0-9]+\.[0-9]+\.(js|css)|Chart\.js|version' ; then fail "an IP address is committed (use server.env)"; fi
grep -q 'http://127.0.0.1:${WEB_PORT:-9081}' deploy/scripts/post_install.sh || fail "post_install.sh importer port"
echo "OK deploy script checks"
