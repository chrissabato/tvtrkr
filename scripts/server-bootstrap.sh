#!/usr/bin/env bash
# One-time setup for a fresh server, run manually (not by CI):
#   sudo bash scripts/server-bootstrap.sh
#
# Creates a low-privilege 'deploy' user for GitHub Actions to push code as,
# installs PHP + the extensions this app needs, and lays out the deploy
# directory. It deliberately does NOT touch your existing Apache vhost /
# certbot config for the domain — that's environment-specific enough that
# it's safer for a human to do it once. See the printed instructions at the
# end for exactly what to add.

set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Run this as root (sudo bash scripts/server-bootstrap.sh)." >&2
  exit 1
fi

DEPLOY_USER="deploy"
DEPLOY_PATH="/var/www/tvtrkr"
PUBLIC_KEY="${1:-}"

if [ -z "$PUBLIC_KEY" ]; then
  echo "Usage: sudo bash scripts/server-bootstrap.sh 'ssh-ed25519 AAAA... tvtrkr-deploy@github-actions'" >&2
  exit 1
fi

echo "==> Installing packages"
apt-get update -qq
apt-get install -y -qq apache2 php php-sqlite3 php-curl rsync >/dev/null
a2enmod rewrite >/dev/null
a2enmod php* >/dev/null 2>&1 || true

echo "==> Creating $DEPLOY_USER user"
if ! id "$DEPLOY_USER" >/dev/null 2>&1; then
  useradd --create-home --shell /bin/bash "$DEPLOY_USER"
fi

DEPLOY_HOME=$(getent passwd "$DEPLOY_USER" | cut -d: -f6)
mkdir -p "$DEPLOY_HOME/.ssh"
touch "$DEPLOY_HOME/.ssh/authorized_keys"
grep -qxF "$PUBLIC_KEY" "$DEPLOY_HOME/.ssh/authorized_keys" || echo "$PUBLIC_KEY" >> "$DEPLOY_HOME/.ssh/authorized_keys"
chmod 700 "$DEPLOY_HOME/.ssh"
chmod 600 "$DEPLOY_HOME/.ssh/authorized_keys"
chown -R "$DEPLOY_USER:$DEPLOY_USER" "$DEPLOY_HOME/.ssh"

echo "==> Setting up $DEPLOY_PATH"
mkdir -p "$DEPLOY_PATH/data"
chown -R "$DEPLOY_USER:www-data" "$DEPLOY_PATH"
chmod -R u+rwX,g+rX "$DEPLOY_PATH"
chmod g+w "$DEPLOY_PATH/data"

echo "==> Granting $DEPLOY_USER passwordless reload-only sudo"
cat > /etc/sudoers.d/tvtrkr-deploy <<EOF
$DEPLOY_USER ALL=(root) NOPASSWD: /bin/systemctl reload apache2
EOF
chmod 440 /etc/sudoers.d/tvtrkr-deploy
visudo -c -f /etc/sudoers.d/tvtrkr-deploy

cat <<EOF

==> Bootstrap done. Two things left, both manual:

1. Point your existing HTTPS vhost for tvtrkr.chrissabato.com at the app.
   Find it with:
     grep -rl tvtrkr.chrissabato.com /etc/apache2/sites-available/

   Inside its <VirtualHost *:443> block, set:
     DocumentRoot $DEPLOY_PATH/public

   And add:
     <Directory $DEPLOY_PATH/public>
         Options FollowSymLinks
         AllowOverride None
         Require all granted

         RewriteEngine On
         RewriteCond %{REQUEST_FILENAME} !-f
         RewriteRule ^api/ api/index.php [L]

         RewriteCond %{REQUEST_FILENAME} !-f
         RewriteCond %{REQUEST_FILENAME} !-d
         RewriteRule ^ index.html [L]
     </Directory>

   Then: sudo apache2ctl configtest && sudo systemctl reload apache2

2. Add these as GitHub Actions secrets on the repo (Settings > Secrets and
   variables > Actions), if not already set:
     SSH_USER       = $DEPLOY_USER
     DEPLOY_PATH    = $DEPLOY_PATH
     SSH_HOST, SSH_PRIVATE_KEY, TMDB_TOKEN, ADMIN_EMAIL, APP_BASE_URL,
     GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET

Push to main and the deploy workflow will take it from there.
EOF
