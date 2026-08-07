# tvtrkr

A self-hosted, multi-user TV show tracker (a lightweight TV Time clone) — a
vanilla JS PWA frontend backed by a small PHP + SQLite API, using TMDB for
show data. Access is gated behind Google sign-in and an admin-managed
allowlist, so it's safe to run on a public server for you and a few invited
people.

## Features (v1)

- **Discover / Search** — browse popular shows or search TMDB directly.
- **Show detail** — overview, seasons, and episode lists.
- **My Shows (watchlist)** — add/remove shows you're tracking.
- **Watched tracking** — check off individual episodes or mark a whole season
  watched at once. Can't check off episodes that haven't aired yet.
- **Calendar** — upcoming air dates for the shows in your watchlist.
- **Google sign-in** with an allowlist — only invited emails can log in; the
  admin manages the list from a Users screen in the app.
- Installable as a PWA (works offline for previously-viewed pages; live data
  needs a connection).

Each user has their own private watchlist and watched history — the
underlying tables are keyed by `user_id`, so tracked shows and watched
episodes never leak between accounts.

## Requirements

- PHP 8.1+ with the `pdo_sqlite` and `curl` extensions (both are enabled by
  default in most PHP installs).
- A free TMDB account and API Read Access Token.
- A Google Cloud OAuth 2.0 client (see below).
- HTTPS, except when accessed as literally `http://localhost` — Google
  rejects non-loopback `http://` redirect URIs.

## Setup

1. **Get a TMDB API key**
   Sign up at https://www.themoviedb.org/settings/api and request an API key
   (personal/non-commercial use is approved instantly). Copy the **API Read
   Access Token** (the long v4 "Bearer" token, not the short v3 key).

2. **Create a Google OAuth client**
   - Go to https://console.cloud.google.com/apis/credentials (create a
     project first if you don't have one).
   - Configure the **OAuth consent screen** (External, or Internal if using
     a Workspace account) — app name, your email, that's enough for personal
     use in Testing mode.
   - Create credentials → **OAuth client ID** → Application type
     **Web application**.
   - Under **Authorized redirect URIs**, add:
     `{app_base_url}/api/auth/google/callback`
     e.g. `https://tvtrkr.chrissabato.com/api/auth/google/callback` for
     production, or `http://localhost:8090/api/auth/google/callback` for
     local dev (must be literally `localhost`, not a LAN IP — Google only
     exempts the loopback hostname from its HTTPS requirement).
   - Copy the **Client ID** and **Client secret**.

3. **Configure the app**
   Copy `public/api/config.example.php` to `public/api/config.local.php` and
   fill in your TMDB token, Google client ID/secret, `app_base_url` (no
   trailing slash, must match the redirect URI above), and `admin_email`
   (whoever logs in with this address first is auto-approved and made
   admin). This file is gitignored — never commit real secrets.

4. **Run it**

   Two ways to serve it locally:

   **A. PHP's built-in dev server** (quick, no root needed):
   ```bash
   php -S localhost:8091 -t public public/router.php
   ```
   Then open http://localhost:8091. Google login only works here if
   `app_base_url` is `http://localhost:8091` and you access it that way
   (not via `127.0.0.1` or a LAN IP).

   **B. Apache** (persistent, matches production more closely):
   Already set up on this dev machine — see [Apache setup](#apache-setup)
   below. Open http://localhost:8090.

   Either way, the SQLite database is created automatically at
   `data/tvtrkr.sqlite` on first request — no migrations to run. If an older
   single-user version of this app already has data in `shows`/
   `watched_episodes`, it's automatically preserved and assigned to whoever
   logs in first.

5. **Install as a PWA** (optional)
   Open the site in Chrome/Edge/Safari and use "Add to Home Screen" /
   "Install App".

## Managing users

The first person to log in with `admin_email` becomes an admin. Admins get
a **Users** tab where they can add or remove allowed email addresses.
Anyone not on the allowlist who tries to sign in sees a clear "not
authorized" page and no account is created for them.

## Apache setup (local dev)

The site is configured as its own Apache VirtualHost rather than touching
the existing `000-default.conf`, so it doesn't collide with whatever else
is running on port 80.

- Config: `/etc/apache2/sites-available/tvtrkr.conf` (source of truth kept
  in this repo at `apache/tvtrkr.conf` — copy it over and
  `sudo a2ensite tvtrkr.conf && sudo systemctl reload apache2` if you ever
  need to recreate it, e.g. on a fresh machine).
- Port: `8090` (added via a `Listen 8090` line in `/etc/apache2/ports.conf`).
- DocumentRoot: `public/`, with `mod_rewrite` handling what `router.php`
  does for the PHP dev server — `/api/*` goes to `api/index.php`, everything
  else that isn't a real file falls back to `index.html` for the SPA's
  hash-based routing.
- Apache runs as `www-data`, which needs write access to `data/` to create
  the SQLite file; that directory's group was changed to `www-data` with
  `g+w` for this reason.

To take it down: `sudo a2dissite tvtrkr.conf && sudo systemctl reload apache2`.

## Production deployment (GitHub Actions)

Pushes to `main` deploy automatically via `.github/workflows/deploy.yml`:
it renders `public/api/config.local.php` from repo secrets, rsyncs the app
to the server (excluding `data/`, which is never touched), and reloads
Apache.

**One-time server setup**, since it needs real access to the box:

1. On the server, run `scripts/server-bootstrap.sh` as root, passing the
   deploy public key:
   ```bash
   sudo bash scripts/server-bootstrap.sh "ssh-ed25519 AAAA... tvtrkr-deploy@github-actions"
   ```
   This installs PHP + Apache + the `pdo_sqlite`/`curl` extensions, creates
   a low-privilege `deploy` user (SSH key auth only, and sudo scoped to
   *just* `systemctl reload apache2`), and lays out `/var/www/tvtrkr` with
   the right permissions for the SQLite file.

2. Point your existing HTTPS vhost's `DocumentRoot` at
   `/var/www/tvtrkr/public` and add the `<Directory>` rewrite block the
   script prints at the end (same rules as the local Apache config, just at
   a different path). This step is manual on purpose — it edits your
   existing certbot-managed vhost, which is too environment-specific to
   script safely from here.

3. Set these as GitHub Actions repo secrets (Settings → Secrets and
   variables → Actions): `SSH_HOST`, `SSH_USER`, `SSH_PRIVATE_KEY`,
   `DEPLOY_PATH`, `TMDB_TOKEN`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`,
   `APP_BASE_URL`, `ADMIN_EMAIL`.

After that, every push to `main` deploys itself.

## Project layout

```
public/
  index.html          App shell
  manifest.json        PWA manifest
  sw.js                 Service worker (app-shell caching, network-first API)
  router.php            Dev-server router (php -S ... router.php)
  css/style.css
  img/icon.svg          (not /icons/ — that collides with Apache's built-in icon alias)
  js/
    app.js              Auth bootstrap + hash-based view router
    api.js              Fetch wrapper for the PHP backend
    views/              login, discover, show-detail, calendar, watchlist, users
  api/
    index.php           REST router — all backend routes live here
    auth.php             Sessions + Google OAuth (authorization code flow)
    tmdb.php             Server-side TMDB proxy (keeps your token off the client)
    db.php               SQLite bootstrap + schema + legacy-data migration
    http.php             Shared JSON request/response helpers
    config.php            Loads config.local.php, defines constants
    config.local.php      Your secrets (gitignored)
data/
  tvtrkr.sqlite          SQLite database (created on first run, gitignored)
scripts/
  server-bootstrap.sh    One-time production server setup (see above)
.github/workflows/
  deploy.yml             Push-to-deploy pipeline
apache/
  tvtrkr.conf             Reference vhost config for local dev
```

## API overview

All backend routes are under `/api`. Everything except `/auth/*` requires an
active session.

| Method | Path | Purpose |
|---|---|---|
| GET | `/auth/google/login` | Redirects to Google's consent screen |
| GET | `/auth/google/callback` | OAuth callback; establishes the session |
| POST | `/auth/logout` | Ends the session |
| GET | `/auth/me` | Current user, or 401 |
| GET | `/admin/users` | (admin) List the allowlist + who's signed in |
| POST | `/admin/users` | (admin) Add an allowed email |
| DELETE | `/admin/users/{email}` | (admin) Revoke an allowed email |
| GET | `/tmdb/search?query=` | Proxy: TMDB TV search |
| GET | `/tmdb/discover` | Proxy: popular TV shows |
| GET | `/tmdb/show/{id}` | Proxy: show details (incl. next episode to air) |
| GET | `/tmdb/show/{id}/season/{n}` | Proxy: season + episode list |
| GET | `/shows` | Your watchlist, with watched-episode counts |
| POST | `/shows` | Add a show to your watchlist |
| DELETE | `/shows/{id}` | Remove a show (cascades watched history) |
| GET | `/shows/{id}/watched` | List watched episodes for a show |
| POST | `/shows/{id}/watched` | Mark one episode watched |
| DELETE | `/shows/{id}/watched?season=&episode=` | Unmark one episode |
| POST | `/shows/{id}/watched/season/{n}` | Mark a whole season watched |
| DELETE | `/shows/{id}/watched/season/{n}` | Unmark a whole season |
| GET | `/calendar` | Upcoming episodes across your watchlist, sorted by date |

## Notes / future ideas

- Stats (time watched, shows completed, etc.) weren't in v1 scope but the
  `watched_episodes` table already has everything needed to compute them
  later.
- The calendar endpoint calls TMDB once per tracked show on every load
  (fine at personal scale); add caching if your watchlist gets large.
- Removing someone from the allowlist blocks future logins but doesn't kill
  an already-active session.
