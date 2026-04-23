<?php
/**
 * ONE-SHOT MAINTENANCE — surgically remove the unsubscribe paragraph from
 * Brevo template #1 ("Consegna Guida AI Governance 10 Step") because this
 * is a transactional email (GDPR art. 6.1.b, not consent-based), so an
 * opt-out footer is out of place and the last unsubscribe click caused
 * Brevo to hard-block future sends to that address.
 *
 * Flow:
 *   1. GET  /v3/smtp/templates/1         → read current htmlContent
 *   2. regex-remove the unsubscribe <p>  → surgical, preserves the rest
 *   3. PUT  /v3/smtp/templates/1         → upload cleaned htmlContent
 *
 * Guarded by a one-time token in the URL. Delete this file after running.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$TOKEN = 'datyca-template-sync-7c4e9a1f';
if (($_GET['token'] ?? '') !== $TOKEN) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$configPath = dirname(__DIR__) . '/contact-config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'config missing']);
    exit;
}
require $configPath;

if (!isset($BREVO_API_KEY) || trim($BREVO_API_KEY) === '') {
    http_response_code(500);
    echo json_encode(['error' => 'BREVO_API_KEY missing']);
    exit;
}

$TEMPLATE_ID = 1;
$report = [
    'template_id' => $TEMPLATE_ID,
    'steps'       => [],
];

function brevoCall($method, $url, $apiKey, $body = null) {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'api-key: ' . $apiKey,
            'accept: application/json',
            'content-type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($resp, true), 'raw' => $resp];
}

// 1. Fetch current template
$getRes = brevoCall('GET', 'https://api.brevo.com/v3/smtp/templates/' . $TEMPLATE_ID, $BREVO_API_KEY);
$report['steps'][] = [
    'step' => 'fetch template',
    'http' => $getRes['code'],
];
if ($getRes['code'] !== 200) {
    $report['error'] = 'fetch failed: ' . ($getRes['body']['message'] ?? '');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
$html = $getRes['body']['htmlContent'] ?? '';
$originalLen = strlen($html);
$report['original_html_length'] = $originalLen;

// 2. Surgically remove the unsubscribe paragraph.
// Match any <p ...>…Non desideri…{{ unsubscribe }}…</p> — tolerant to
// whitespace, attribute order, entity encoding variants.
$pattern = '#\s*<p\b[^>]*>\s*Non\s+desideri[^<]*<a[^>]*\{\{\s*unsubscribe\s*\}\}[^<]*</a>[^<]*</p>\s*#si';
$matches = [];
if (!preg_match($pattern, $html, $matches)) {
    $report['warning'] = 'unsubscribe paragraph not found — template may already be clean, or structure changed';
    $report['found'] = false;
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
$report['found'] = true;
$report['removed_snippet_length'] = strlen($matches[0]);

$cleanedHtml = preg_replace($pattern, "\n\n", $html, 1);
if ($cleanedHtml === null || $cleanedHtml === $html) {
    $report['error'] = 'regex replace failed';
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
$report['new_html_length'] = strlen($cleanedHtml);

// 3. PUT updated template
$putRes = brevoCall('PUT', 'https://api.brevo.com/v3/smtp/templates/' . $TEMPLATE_ID, $BREVO_API_KEY, [
    'htmlContent' => $cleanedHtml,
]);
$report['steps'][] = [
    'step' => 'update template',
    'http' => $putRes['code'],
    'body' => $putRes['body'],
];

if ($putRes['code'] === 204 || $putRes['code'] === 200) {
    $report['success'] = true;
    $report['message'] = 'Template #1 updated — unsubscribe paragraph removed.';
} else {
    $report['success'] = false;
    $report['message'] = 'Update failed: ' . ($putRes['body']['message'] ?? '');
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
