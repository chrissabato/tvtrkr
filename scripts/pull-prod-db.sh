#!/usr/bin/env bash
# Pulls a consistent snapshot of the production SQLite database down to this
# dev box, so local testing works against real-shaped data.
#
# Takes the snapshot via `sqlite3 .backup` on the remote side (not a raw file
# copy) so a concurrent write on production can never hand us a torn/corrupt
# file. The existing local db is backed up (never silently overwritten).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

LOCAL_CONFIG="$SCRIPT_DIR/pull-prod-db.local.sh"
if [ ! -f "$LOCAL_CONFIG" ]; then
  echo "Missing $LOCAL_CONFIG. Copy pull-prod-db.example.sh to pull-prod-db.local.sh and fill in your server details." >&2
  exit 1
fi
# shellcheck source=/dev/null
source "$LOCAL_CONFIG"

REMOTE_TMP="/tmp/tvtrkr-pull-$$.sqlite"
LOCAL_DB="$SCRIPT_DIR/../data/tvtrkr.sqlite"
LOCAL_TMP="$(mktemp /tmp/tvtrkr-pull-local-XXXXXX.sqlite)"

ssh_cmd() { ssh -i "$SSH_KEY" -o BatchMode=yes "$REMOTE" "$@"; }

cleanup() {
  rm -f "$LOCAL_TMP"
  ssh_cmd "rm -f '$REMOTE_TMP'" 2>/dev/null || true
}
trap cleanup EXIT

echo "==> Snapshotting production database..."
ssh_cmd "sqlite3 '$REMOTE_DB' \".backup '$REMOTE_TMP'\""

echo "==> Downloading snapshot..."
scp -i "$SSH_KEY" -q "$REMOTE:$REMOTE_TMP" "$LOCAL_TMP"

if [ -f "$LOCAL_DB" ]; then
  BACKUP_PATH="$LOCAL_DB.bak-$(date +%Y%m%d-%H%M%S)"
  echo "==> Backing up existing local db to $(basename "$BACKUP_PATH")"
  # sudo cp: the existing db is owned by www-data and may not be
  # group/other-readable, regardless of the current user's group membership.
  sudo cp "$LOCAL_DB" "$BACKUP_PATH"
  sudo chown "$(id -u):$(id -g)" "$BACKUP_PATH"
fi

mv "$LOCAL_TMP" "$LOCAL_DB"

# Match the ownership/permissions Apache (www-data) expects locally.
sudo chown www-data:www-data "$LOCAL_DB"
sudo chmod 644 "$LOCAL_DB"

echo "==> Done. $(du -h "$LOCAL_DB" | cut -f1) pulled to $LOCAL_DB"
