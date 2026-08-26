#!/bin/bash
# ONE-TIME SETUP: installs this machine's local SSH public key on the Pinoy Ride
# server so tunnel.ps1 / start-admin.bat connect WITHOUT ever asking for a password.
#
# Usage (from PowerShell or Git Bash):
#   PR_SSH_PW='<server password>' bash install_ssh_key.sh
# ...or just:  bash install_ssh_key.sh     (prompts for the password, hidden input)
#
# Nothing secret is stored by this script: the password lives only in memory,
# and afterwards you never need it again for tunneling.

set -u

HOST="markangelogonzalespinoyride@54.251.171.207"
PORT=2222
KEY_PRIV="$HOME/.ssh/pinoyride_ed25519"
KEY_PUB="${KEY_PRIV}.pub"

# 1) make sure a local key exists
if [ ! -f "$KEY_PRIV" ]; then
    echo "Generating SSH key $KEY_PRIV ..."
    ssh-keygen -q -t ed25519 -N "" -f "$KEY_PRIV" -C pinoyride-admin
fi
PUBKEY=$(cat "$KEY_PUB")

# 2) get the password (env var or hidden prompt)
if [ -z "${PR_SSH_PW:-}" ]; then
    printf "Server SSH password: "
    read -rs PR_SSH_PW
    echo
fi

# 3) install the pubkey via SSH_ASKPASS so no interactive prompt appears
ASKPASS=$(mktemp)
printf '#!/bin/sh\necho "$PR_SSH_PW"\n' > "$ASKPASS"
chmod +x "$ASKPASS"

export SSH_ASKPASS="$ASKPASS"
export SSH_ASKPASS_REQUIRE=force
export DISPLAY="${DISPLAY:-:0}"

RESULT=$(ssh -p "$PORT" -o StrictHostKeyChecking=accept-new "$HOST" \
  "mkdir -p ~/.ssh && chmod 700 ~/.ssh && touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys && (grep -qxF '$PUBKEY' ~/.ssh/authorized_keys || echo '$PUBKEY' >> ~/.ssh/authorized_keys) && echo KEY_INSTALLED" \
) || true

rm -f "$ASKPASS"

case "$RESULT" in
  *KEY_INSTALLED*)
      echo "OK: public key installed. Tunnels will now connect automatically."
      exit 0 ;;
  *)
      echo "FAILED to install key. Output was:"
      echo "$RESULT"
      exit 1 ;;
esac
