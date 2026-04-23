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

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
