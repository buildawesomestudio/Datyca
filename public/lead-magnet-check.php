<?php
/**
 * TEMPORARY DIAGNOSTIC — delete this file once you have verified the setup.
 *
 * Checks:
 *   1. contact-config.php is loaded
 *   2. $BREVO_API_KEY is defined, non-empty, plausible format
 *   3. The key is accepted by Brevo (GET /v3/account — read-only)
 *   4. $TURNSTILE_SECRET is defined
 *
 * Never prints the full API key. Only the prefix (8 chars) + length, so a
 * transcript is safe to share.
 *
 * Usage:
 *   curl https://<your-domain>/lead-magnet-check.php
 *   or open it in a browser.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$report = [
    'generated_at'            => gmdate('Y-m-d\TH:i:s\Z'),
    'config_file_found'       => false,
    'brevo_api_key_defined'   => false,
    'brevo_api_key_prefix'    => null,
    'brevo_api_key_length'    => null,
    'turnstile_secret_defined'=> false,
    'brevo_account_http_code' => null,
    'brevo_account_ok'        => false,
    'brevo_account_message'   => null,
];

// ── Config load ────────────────────────────────────
$configPath = dirname(__DIR__) . '/contact-config.php';
$report['config_file_path_resolved'] = $configPath;

if (!file_exists($configPath)) {
    $report['error'] = 'contact-config.php not found at resolved path';
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
$report['config_file_found'] = true;
require $configPath;

// ── Variable presence ─────────────────────────────
if (isset($BREVO_API_KEY) && is_string($BREVO_API_KEY) && trim($BREVO_API_KEY) !== '') {
    $key = trim($BREVO_API_KEY);
    $report['brevo_api_key_defined'] = true;
    $report['brevo_api_key_prefix']  = substr($key, 0, 8) . '…';
    $report['brevo_api_key_length']  = strlen($key);
    $report['brevo_api_key_looks_like_brevo'] = str_starts_with($key, 'xkeysib-');
} else {
    $report['error'] = '$BREVO_API_KEY is missing, null, or empty in contact-config.php';
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$report['turnstile_secret_defined'] = isset($TURNSTILE_SECRET) && is_string($TURNSTILE_SECRET) && trim($TURNSTILE_SECRET) !== '';

// ── Brevo ping (GET /v3/account) ─────────────────
$ch = curl_init('https://api.brevo.com/v3/account');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => [
        'api-key: ' . $key,
        'accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$report['brevo_account_http_code'] = $code;
$parsed = json_decode($body, true);

if ($code === 200) {
    $report['brevo_account_ok']      = true;
    $report['brevo_account_message'] = 'Key accepted. Account: ' . ($parsed['email'] ?? '(no email in payload)');
} else {
    $report['brevo_account_ok']      = false;
    $report['brevo_account_message'] = $parsed['message'] ?? 'Brevo returned non-200 with no parseable message';
    $report['brevo_account_error_code'] = $parsed['code'] ?? null;
}

// ── Optional: transactional events for a specific email ─────
// Usage: /lead-magnet-check.php?email=someone@example.com
// Pulls the last Brevo events (sent, delivered, bounced, blocked, spam, etc.)
// for that address, so we can tell whether a send actually reached the inbox.
$queryEmail = isset($_GET['email']) ? trim($_GET['email']) : '';
if ($queryEmail !== '' && filter_var($queryEmail, FILTER_VALIDATE_EMAIL)) {
    $report['email_probe'] = [
        'email'            => $queryEmail,
        'events'           => [],
        'blocked_contact'  => null,
    ];

    // A. Transactional events for that address (last 20, most recent first)
    $eventsUrl = 'https://api.brevo.com/v3/smtp/statistics/events'
        . '?email=' . urlencode($queryEmail)
        . '&limit=20&sort=desc';
    $ch = curl_init($eventsUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['api-key: ' . $key, 'accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $evBody = curl_exec($ch);
    $evCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $evParsed = json_decode($evBody, true);
    $report['email_probe']['events_http_code'] = $evCode;
    if ($evCode === 200 && isset($evParsed['events'])) {
        foreach ($evParsed['events'] as $ev) {
            $report['email_probe']['events'][] = [
                'event'       => $ev['event']       ?? null,
                'date'        => $ev['date']        ?? null,
                'subject'     => $ev['subject']     ?? null,
                'reason'      => $ev['reason']      ?? null,
                'template_id' => $ev['templateId']  ?? null,
            ];
        }
    } elseif ($evCode !== 200) {
        $report['email_probe']['events_error'] = $evParsed['message'] ?? 'non-200';
    }

    // B. Is the contact on the transactional blocklist?
    $blockUrl = 'https://api.brevo.com/v3/smtp/blockedContacts/' . urlencode($queryEmail);
    $ch = curl_init($blockUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['api-key: ' . $key, 'accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $blBody = curl_exec($ch);
    $blCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $blParsed = json_decode($blBody, true);
    if ($blCode === 200) {
        $report['email_probe']['blocked_contact'] = $blParsed; // reason + blockedAt
    } elseif ($blCode === 404) {
        $report['email_probe']['blocked_contact'] = 'not in blocklist (good)';
    } else {
        $report['email_probe']['blocked_contact'] = 'probe http ' . $blCode . ': ' . ($blParsed['message'] ?? '');
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
