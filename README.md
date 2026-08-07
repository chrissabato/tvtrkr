# tvtrkr

A self-hosted, single-user TV show tracker (a lightweight TV Time clone) — a
vanilla JS PWA frontend backed by a small PHP + SQLite API, using TMDB for
show data.

## Features (v1)

- **Discover / Search** — browse popular shows or search TMDB directly.
- **Show detail** — overview, seasons, and episode lists.
- **My Shows (watchlist)** — add/remove shows you're tracking.
- **Watched tracking** — check off individual episodes or mark a whole season
  watched at once.
- **Calendar** — upcoming air dates for the shows in your watchlist.
- Installable as a PWA (works offline for previously-viewed pages; live data
  needs a connection).

## Requirements

- PHP 8.1+ with the `pdo_sqlite` and `curl` extensions (both are enabled by
  default in most PHP installs).
- A free TMDB account and API Read Access Token.

## Setup

1. **Get a TMDB API key**
   Sign up at https://www.themoviedb.org/settings/api and request an API key
   (personal/non-commercial use is approved instantly). Copy the **API Read
   Access Token** (the long v4 "Bearer" token, not the short v3 key).

2. **Configure the app**
   Edit `public/api/config.local.php` and paste your token:
   ```php
   return [
       'tmdb_token' => 'your-real-token-here',
   ];
   ```
   This file is gitignored, so your key never gets committed.

3. **Run it**

   Two ways to serve it locally:

   **A. PHP's built-in dev server** (quick, no root needed):
   ```bash
   php -S localhost:8091 -t public public/router.php
   ```
   Then open http://localhost:8091.

   **B. Apache** (persistent, survives terminal close, matches production more closely):
   Already set up on this machine — see [Apache setup](#apache-setup) below.
   Open http://localhost:8090.

   Either way, the SQLite database is created automatically at
   `data/tvtrkr.sqlite` on first request — no migrations to run.

## Apache setup

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
  `g+w` for this reason. `public/api/config.local.php` just needs to stay
  world-readable (it already is).

To take it down: `sudo a2dissite tvtrkr.conf && sudo systemctl reload apache2`.

4. **Install as a PWA** (optional)
   Open the site in Chrome/Edge/Safari and use "Add to Home Screen" /
   "Install App". Note: full PWA install prompts and service worker caching
   generally require HTTPS — `localhost` is exempted by browsers, but if you
   deploy this to a real host, put it behind TLS.

## Project layout

```
public/
  index.html          App shell
  manifest.json        PWA manifest
  sw.js                 Service worker (app-shell caching, network-first API)
  router.php            Dev-server router (php -S ... router.php)
  css/style.css
  js/
    app.js              Hash-based view router
    api.js              Fetch wrapper for the PHP backend
    views/              discover, show-detail, calendar, watchlist
  api/
    index.php           REST router — all backend routes live here
    tmdb.php             Server-side TMDB proxy (keeps your token off the client)
    db.php               SQLite bootstrap + schema
    config.php            Loads config.local.php, defines constants
    config.local.php      Your TMDB token (gitignored)
data/
  tvtrkr.sqlite          SQLite database (created on first run, gitignored)
```

## API overview

All backend routes are under `/api`:

| Method | Path | Purpose |
|---|---|---|
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

- No auth — this is meant to run locally or on a private/personal server.
  Don't expose it to the public internet as-is.
- Stats (time watched, shows completed, etc.) weren't in v1 scope but the
  `watched_episodes` table already has everything needed to compute them
  later.
- The calendar endpoint calls TMDB once per tracked show on every load
  (fine at personal scale); add caching if your watchlist gets large.
