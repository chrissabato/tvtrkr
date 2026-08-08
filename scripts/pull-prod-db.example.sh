#!/usr/bin/env bash
# Copy this file to pull-prod-db.local.sh and fill in real values.
# pull-prod-db.local.sh is gitignored — never commit real server details.

SSH_KEY="$HOME/.ssh/tvtrkr_deploy"
REMOTE="user@your.server.ip"
REMOTE_DB="/path/to/tvtrkr.chrissabato.com/data/tvtrkr.sqlite"
