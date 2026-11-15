# Sourced by run_all.sh and the tools: where the server is.  Values come from server.env at the repo root
# (copy server.env.example; the file is not in git); variables already set in the environment win.
#   SSH_TARGET   user@host for ssh/scp          SSH_PORT   ssh port (default 22)
#   SSH_ALIAS    Host entry of ~/.ssh/config used by the dev tools (default: SSH_TARGET)
#   SERVER_HOST  address users open in the browser (default: host part of SSH_TARGET)
_se_file="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/server.env"
if [ -f "$_se_file" ]; then
  while IFS='=' read -r _se_k _se_v; do
    case "$_se_k" in ''|\#*) continue ;; esac
    [[ "$_se_k" =~ ^[A-Z_][A-Z0-9_]*$ ]] || continue
    [ -n "${!_se_k:-}" ] || export "$_se_k=$_se_v"
  done < "$_se_file"
fi
unset _se_file _se_k _se_v
[ -n "${SERVER_HOST:-}" ] || { SERVER_HOST="${SSH_TARGET:-}"; SERVER_HOST="${SERVER_HOST#*@}"; }
SSH_ALIAS="${SSH_ALIAS:-${SSH_TARGET:-}}"
export SERVER_HOST SSH_ALIAS
