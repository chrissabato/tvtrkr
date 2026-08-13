<?php
// Copy this file to config.local.php and fill in real values.
// config.local.php is gitignored — never commit real secrets.

return [
    // TMDB API Read Access Token (v4 auth "Bearer" token),
    // from https://www.themoviedb.org/settings/api.
    'tmdb_token' => 'PASTE_YOUR_TMDB_V4_READ_ACCESS_TOKEN_HERE',

    // Google OAuth 2.0 "Web application" client, from
    // https://console.cloud.google.com/apis/credentials.
    'google_client_id' => '',
    'google_client_secret' => '',

    // Public base URL this app is served at, no trailing slash.
    // Must match a registered Authorized redirect URI of
    // {app_base_url}/api/auth/google/callback in the Google Cloud console.
    'app_base_url' => 'http://localhost:8090',

    // Email address that is auto-approved and made admin on first login.
    // The admin can add more allowed emails from the Users screen.
    'admin_email' => '',

    // Mailgun (https://www.mailgun.com) sending credentials, used for
    // transactional emails (invites, watch-with notifications, admin
    // alerts). Leave blank to disable sending — the app still works
    // without them, it just skips email and logs instead.
    'mailgun_api_key' => '',
    'mailgun_domain' => '',
    // "From" header for outgoing mail, e.g. 'tvtrkr <noreply@tvtrkr.chrissabato.com>'.
    'mail_from' => '',
];
