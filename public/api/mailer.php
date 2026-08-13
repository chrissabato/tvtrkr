<?php
// Thin wrapper around the Mailgun HTTP API for transactional emails
// (invites, notifications). Email is a side effect, never the primary
// action, so failures are logged rather than thrown — a Mailgun outage
// must not break signing up a user or creating a watch invite.

require_once __DIR__ . '/config.php';

// Wraps a fragment of body HTML in tvtrkr's branded email shell (a
// table-based layout with inline styles, since Gmail/Outlook strip
// <style> blocks and CSS classes in transactional mail). Pass an optional
// button — most of these emails exist to get the recipient to click
// through into the app.
function email_layout(string $bodyHtml, ?string $buttonText = null, ?string $buttonUrl = null): string
{
    $button = '';
    if ($buttonText !== null && $buttonUrl !== null) {
        $button = '
            <p style="margin:28px 0 0;">
              <a href="' . htmlspecialchars($buttonUrl) . '" style="display:inline-block;background:#3b6df6;color:#ffffff;text-decoration:none;padding:11px 22px;border-radius:8px;font-weight:600;font-size:14px;">'
                . htmlspecialchars($buttonText) . '</a>
            </p>';
    }

    return '
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:32px 16px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">
      <tr>
        <td align="center">
          <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="max-width:480px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">
            <tr>
              <td style="background:#161b26;padding:18px 32px;">
                <span style="color:#ffffff;font-size:17px;font-weight:700;letter-spacing:0.02em;">tvtrkr</span>
              </td>
            </tr>
            <tr>
              <td style="padding:32px;color:#1f2430;font-size:15px;line-height:1.6;">
                ' . $bodyHtml . $button . '
              </td>
            </tr>
            <tr>
              <td style="padding:14px 32px;background:#f9fafb;color:#9096a2;font-size:12px;border-top:1px solid #eef0f3;">
                tvtrkr &middot; a shared TV show tracker
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>';
}

// Derives a reasonable plain-text alternative from an HTML fragment.
// Mailgun (like most providers) sends a multipart message when both
// "html" and "text" are given — mail lacking a text part is a common
// spam-filter signal, and some clients still prefer it.
function html_to_text(string $html): string
{
    $withBreaks = preg_replace('#<(br|/p|/tr|/li)\s*/?>#i', "\n", $html);
    $text = trim(html_entity_decode(strip_tags($withBreaks), ENT_QUOTES));
    return preg_replace("/\n{3,}/", "\n\n", $text);
}

function send_email(string $to, string $subject, string $html): void
{
    if (MAILGUN_API_KEY === '' || MAILGUN_DOMAIN === '') {
        error_log("Mailgun not configured; skipping email to $to: $subject");
        return;
    }

    $ch = curl_init('https://api.mailgun.net/v3/' . MAILGUN_DOMAIN . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => 'api:' . MAILGUN_API_KEY,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'from' => MAIL_FROM,
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
            'text' => html_to_text($html),
        ],
        CURLOPT_TIMEOUT => 10,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        error_log("Mailgun send to $to failed: $error");
        return;
    }
    if ($status < 200 || $status >= 300) {
        error_log("Mailgun send to $to failed: HTTP $status $body");
    }
}

// Account-wide count of emails accepted by Mailgun so far this calendar
// month (across all domains on the account, not just MAILGUN_DOMAIN).
// Used to watch usage against the free-tier cap — Mailgun's own
// account-level "custom limit" endpoint needs a higher-privilege API key
// than the per-domain sending key configured here, so this is tracked
// via the Stats API instead, compared against MAIL_MONTHLY_LIMIT.
function get_monthly_email_count(): ?int
{
    if (MAILGUN_API_KEY === '') {
        return null;
    }

    $ch = curl_init('https://api.mailgun.net/v3/stats/total?event=accepted&resolution=month');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => 'api:' . MAILGUN_API_KEY,
        CURLOPT_TIMEOUT => 10,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $status < 200 || $status >= 300) {
        error_log("Mailgun stats fetch failed: HTTP $status");
        return null;
    }

    $decoded = json_decode($body, true);
    $stats = $decoded['stats'][0] ?? null;
    return $stats !== null ? (int) ($stats['accepted']['total'] ?? 0) : 0;
}
